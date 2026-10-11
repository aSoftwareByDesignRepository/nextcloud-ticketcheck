<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * OAuth begin/callback flow for XOAUTH2 inbound mailboxes (Google/Microsoft).
 *
 * State tokens are stored in a distributed cache, bound to the initiating
 * admin UID, expire after 10 minutes, and are consumed on first use
 * (single-use — replayed callbacks fail closed).
 */
final class InboundMailOAuthService
{
	private const STATE_TTL = 600;
	private const CACHE_NS = 'ticketcheck_imap_oauth';

	public function __construct(
		private readonly InboundMailConfigService $mailboxConfig,
		private readonly ICacheFactory $cacheFactory,
		private readonly ISecureRandom $random,
		private readonly ITimeFactory $timeFactory,
		private readonly IURLGenerator $url,
		private readonly IL10N $l,
	) {
	}

	/**
	 * Start the provider consent flow. Returns the absolute authorize URL the
	 * controller redirects the admin to.
	 *
	 * @return array{authorizeUrl: string, state: string, provider: string, callbackUrl: string}
	 */
	public function beginAuthorize(string $adminUid, string $provider): array
	{
		$provider = strtolower(trim($provider));
		if (!in_array($provider, [InboundMailConfigService::PROVIDER_GOOGLE, InboundMailConfigService::PROVIDER_MICROSOFT], true)) {
			throw new ValidationException(['oauth_provider' => $this->l->t('inbound_err_provider_required')]);
		}
		if ($this->mailboxConfig->oauthClientId($provider) === '') {
			throw new ValidationException(['oauth_provider' => $this->l->t('inbound_err_oauth_not_configured')]);
		}

		$state = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
		$cache = $this->cacheFactory->createDistributed(self::CACHE_NS);
		$cache->set($state, [
			'adminUid' => $adminUid,
			'provider' => $provider,
			'exp' => $this->timeFactory->getTime() + self::STATE_TTL,
		], self::STATE_TTL);

		$redirectUri = $this->callbackUrl();
		if ($provider === InboundMailConfigService::PROVIDER_GOOGLE) {
			$scope = rawurlencode('https://mail.google.com/ https://www.googleapis.com/auth/userinfo.email');
			$authorizeUrl = 'https://accounts.google.com/o/oauth2/v2/auth'
				. '?client_id=' . rawurlencode($this->mailboxConfig->oauthClientId($provider))
				. '&redirect_uri=' . rawurlencode($redirectUri)
				. '&response_type=code'
				. '&scope=' . $scope
				. '&access_type=offline'
				. '&prompt=consent'
				. '&state=' . rawurlencode($state);
		} else {
			$scope = rawurlencode('https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send offline_access openid email');
			$authorizeUrl = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize'
				. '?client_id=' . rawurlencode($this->mailboxConfig->oauthClientId($provider))
				. '&redirect_uri=' . rawurlencode($redirectUri)
				. '&response_type=code'
				. '&scope=' . $scope
				. '&state=' . rawurlencode($state);
		}

		return [
			'authorizeUrl' => $authorizeUrl,
			'state' => $state,
			'provider' => $provider,
			'callbackUrl' => $redirectUri,
		];
	}

	/**
	 * Consume the state and exchange the code. Stores the encrypted tokens and
	 * the provider/verified mailbox address back into the mailbox config.
	 *
	 * @return array{provider: string, email: string}
	 */
	public function completeAuthorize(string $state, string $code, string $adminUid): array
	{
		$state = trim($state);
		$code = trim($code);
		if ($state === '' || $code === '') {
			throw new ValidationException(['oauth' => $this->l->t('inbound_err_oauth_callback')]);
		}

		$cache = $this->cacheFactory->createDistributed(self::CACHE_NS);
		$payload = $cache->get($state);
		// Single-use: consume the state even on failure so replay dies here.
		$cache->remove($state);

		if (!is_array($payload)
			|| (int)($payload['exp'] ?? 0) < $this->timeFactory->getTime()
			|| (string)($payload['adminUid'] ?? '') !== $adminUid
		) {
			throw new ValidationException(['oauth' => $this->l->t('inbound_err_oauth_state')]);
		}
		$provider = (string)$payload['provider'];

		$data = $this->exchangeCode($provider, $code);
		$access = (string)$data['access_token'];
		$refresh = (string)($data['refresh_token'] ?? '');
		$expiresIn = (int)($data['expires_in'] ?? 3600);
		if ($refresh === '') {
			// Without a refresh token the mailbox dies after the first access
			// token expiry — refuse rather than silently wire a broken config.
			throw new ValidationException(['oauth' => $this->l->t('inbound_err_oauth_no_refresh')]);
		}

		$this->mailboxConfig->storeOAuthTokens($access, $refresh, $expiresIn);
		$this->mailboxConfig->clearAuthFailures();

		$email = (string)($data['email'] ?? '');

		return ['provider' => $provider, 'email' => $email];
	}

	private function callbackUrl(): string
	{
		return $this->url->linkToRouteAbsolute('ticketcheck.settings.inboundImapOAuthCallback');
	}

	/**
	 * @return array{access_token: string, refresh_token?: string, expires_in?: int, email?: string}
	 */
	private function exchangeCode(string $provider, string $code): array
	{
		$clientId = $this->mailboxConfig->oauthClientId($provider);
		$clientSecret = $this->mailboxConfig->oauthClientSecret($provider);
		if ($clientId === '' || $clientSecret === '') {
			throw new ValidationException(['oauth' => $this->l->t('inbound_err_oauth_not_configured')]);
		}
		$tokenUrl = $provider === InboundMailConfigService::PROVIDER_GOOGLE
			? 'https://oauth2.googleapis.com/token'
			: 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
		$body = http_build_query([
			'code' => $code,
			'client_id' => $clientId,
			'client_secret' => $clientSecret,
			'redirect_uri' => $this->callbackUrl(),
			'grant_type' => 'authorization_code',
		]);
		$ctx = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
				'content' => $body,
				'timeout' => 20,
				'ignore_errors' => true,
			],
		]);
		$raw = @file_get_contents($tokenUrl, false, $ctx);
		if ($raw === false) {
			throw new ValidationException(['oauth' => $this->l->t('inbound_err_oauth_unreachable')]);
		}
		$data = json_decode($raw, true);
		if (!is_array($data) || empty($data['access_token'])) {
			throw new ValidationException(['oauth' => $this->l->t('inbound_err_oauth_rejected')]);
		}
		$data['email'] = $this->fetchEmail($provider, (string)$data['access_token']);

		return $data;
	}

	private function fetchEmail(string $provider, string $accessToken): string
	{
		$url = $provider === InboundMailConfigService::PROVIDER_GOOGLE
			? 'https://www.googleapis.com/oauth2/v2/userinfo'
			: 'https://graph.microsoft.com/v1.0/me';
		$ctx = stream_context_create([
			'http' => [
				'method' => 'GET',
				'header' => "Authorization: Bearer {$accessToken}\r\n",
				'timeout' => 15,
				'ignore_errors' => true,
			],
		]);
		$raw = @file_get_contents($url, false, $ctx);
		if ($raw === false) {
			return '';
		}
		$data = json_decode($raw, true);
		if (!is_array($data)) {
			return '';
		}
		if ($provider === InboundMailConfigService::PROVIDER_GOOGLE) {
			return strtolower(trim((string)($data['email'] ?? '')));
		}

		return strtolower(trim((string)($data['mail'] ?? $data['userPrincipalName'] ?? '')));
	}
}

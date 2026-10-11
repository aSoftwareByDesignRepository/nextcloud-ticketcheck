<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Support\ImapConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Resolve IMAP/SMTP credentials for the inbound mailbox — password or
 * XOAUTH2 bearer. Ported from CustomerCheck MailboxAuthService, adapted to
 * appconfig-backed token storage.
 *
 * OAuth tokens are refreshed before expiry (60s skew buffer) and rotated
 * refresh tokens are persisted back encrypted. Tokens never appear in
 * return values' metadata, logs, or thrown messages.
 */
final class InboundMailAuthService
{
	public const MODE_PASSWORD = 'password';
	public const MODE_XOAUTH2 = 'xoauth2';

	private const TOKEN_URLS = [
		'google' => 'https://oauth2.googleapis.com/token',
		'microsoft' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
	];

	public function __construct(
		private readonly InboundMailConfigService $mailboxConfig,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string, mixed> $cfg InboundMailConfigService::mailboxConfig()
	 * @return array{mode: string, user: string, secret: string, email: string}
	 * @throws \RuntimeException when credentials cannot be resolved (sync must
	 *         treat this as an auth-class failure, not a crash)
	 */
	public function resolve(array $cfg): array
	{
		$user = ImapConnection::normalizeUsername((string)($cfg['username'] ?? ''));
		$email = strtolower(trim((string)($cfg['address'] ?? $user)));
		$mode = (string)($cfg['auth_mode'] ?? self::MODE_PASSWORD) === self::MODE_XOAUTH2
			? self::MODE_XOAUTH2
			: self::MODE_PASSWORD;

		if ($mode === self::MODE_XOAUTH2) {
			$token = $this->accessToken($cfg);
			if ($token === '') {
				throw new \RuntimeException('oauth_expired');
			}
			return [
				'mode' => self::MODE_XOAUTH2,
				'user' => $email !== '' ? $email : $user,
				'secret' => $token,
				'email' => $email !== '' ? $email : $user,
			];
		}

		$secret = $this->mailboxConfig->decryptField((string)($cfg['secret_enc'] ?? ''));
		if ($secret === '') {
			throw new \RuntimeException('password_undecryptable');
		}

		return [
			'mode' => self::MODE_PASSWORD,
			'user' => $user !== '' ? $user : $email,
			'secret' => $secret,
			'email' => $email,
		];
	}

	/**
	 * Same resolution for the SMTP override block (own secret field).
	 *
	 * @param array{host: string, port: int, enc: string, username: string, secret_enc: string, from: string} $smtp
	 * @param array<string, mixed> $cfg mailbox config (for xoauth2 fallback)
	 * @return array{mode: string, user: string, secret: string}
	 * @throws \RuntimeException
	 */
	public function resolveSmtp(array $smtp, array $cfg): array
	{
		$mode = (string)($cfg['auth_mode'] ?? self::MODE_PASSWORD) === self::MODE_XOAUTH2
			? self::MODE_XOAUTH2
			: self::MODE_PASSWORD;

		if ($mode === self::MODE_XOAUTH2) {
			$token = $this->accessToken($cfg);
			if ($token === '') {
				throw new \RuntimeException('oauth_expired');
			}
			$user = trim((string)($smtp['username'] ?? ''));
			return [
				'mode' => self::MODE_XOAUTH2,
				'user' => $user !== '' ? $user : strtolower((string)($cfg['address'] ?? '')),
				'secret' => $token,
			];
		}

		// Explicit SMTP password wins; otherwise reuse the IMAP password (the
		// common hosted-mail case where IMAP+SMTP share credentials).
		$secret = $this->mailboxConfig->decryptField((string)($smtp['secret_enc'] ?? ''));
		if ($secret === '') {
			$secret = $this->mailboxConfig->decryptField((string)($cfg['secret_enc'] ?? ''));
		}
		if ($secret === '') {
			throw new \RuntimeException('password_undecryptable');
		}
		$user = trim((string)($smtp['username'] ?? ''));
		if ($user === '') {
			$user = trim((string)($cfg['username'] ?? ''));
		}

		return ['mode' => self::MODE_PASSWORD, 'user' => $user, 'secret' => $secret];
	}

	/**
	 * SASL initial response for AUTHENTICATE XOAUTH2 (IMAP + SMTP).
	 */
	public static function xoauth2Sasl(string $userEmail, string $accessToken): string
	{
		$raw = 'user=' . $userEmail . "\x01auth=Bearer " . $accessToken . "\x01\x01";

		return base64_encode($raw);
	}

	/**
	 * Access token for the mailbox — returns a still-valid token, or refreshes
	 * via the stored refresh token and persists the rotation.
	 *
	 * @param array<string, mixed> $cfg
	 */
	private function accessToken(array $cfg): string
	{
		$access = $this->mailboxConfig->decryptField((string)($cfg['oauth_access_enc'] ?? ''));
		$expiresAt = trim((string)($cfg['oauth_expires_at'] ?? ''));
		$skew = $this->timeFactory->getTime() + 60;
		// Missing expiry metadata → treat as still-valid; the server rejects
		// a dead token and the next sync run refreshes anyway.
		$expired = false;
		if ($expiresAt !== '') {
			$ts = strtotime($expiresAt);
			$expired = $ts !== false && $ts <= $skew;
		}
		if ($access !== '' && !$expired) {
			return $access;
		}

		$refresh = $this->mailboxConfig->decryptField((string)($cfg['oauth_refresh_enc'] ?? ''));
		if ($refresh === '') {
			$this->mailboxConfig->recordAuthFailure();
			return '';
		}
		$provider = strtolower(trim((string)($cfg['oauth_provider'] ?? '')));
		$fresh = $this->refreshAccessToken($provider, $refresh);
		if ($fresh['status'] === 'ok') {
			$data = $fresh['data'];
			$this->mailboxConfig->storeOAuthTokens(
				(string)$data['access_token'],
				(string)($data['refresh_token'] ?? $refresh),
				(int)($data['expires_in'] ?? 3600),
			);
			$this->mailboxConfig->clearAuthFailures();
			return (string)$data['access_token'];
		}
		if ($fresh['status'] === 'auth') {
			$this->mailboxConfig->recordAuthFailure();
			$this->mailboxConfig->setNeedsReauth(true);
		}

		return '';
	}

	/**
	 * @return array{status: 'ok', data: array{access_token: string, refresh_token?: string, expires_in?: int}}|array{status: 'auth'|'network'|'config'}
	 */
	private function refreshAccessToken(string $provider, string $refreshToken): array
	{
		$tokenUrl = self::TOKEN_URLS[$provider] ?? '';
		if ($tokenUrl === '') {
			return ['status' => 'config'];
		}
		$clientId = $this->mailboxConfig->oauthClientId($provider);
		$clientSecret = $this->mailboxConfig->oauthClientSecret($provider);
		if ($clientId === '' || $clientSecret === '') {
			return ['status' => 'config'];
		}
		$body = http_build_query([
			'client_id' => $clientId,
			'client_secret' => $clientSecret,
			'refresh_token' => $refreshToken,
			'grant_type' => 'refresh_token',
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
			return ['status' => 'network'];
		}
		$data = json_decode($raw, true);
		if (!is_array($data) || empty($data['access_token'])) {
			$this->logger->warning('Inbound mail OAuth refresh rejected', ['app' => 'ticketcheck', 'provider' => $provider]);
			return ['status' => 'auth'];
		}

		return ['status' => 'ok', 'data' => $data];
	}
}

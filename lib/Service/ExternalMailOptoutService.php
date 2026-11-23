<?php

declare(strict_types=1);

/**
 * Per-ticket opt-out for the account-less external mail leg (NH-02).
 *
 * Capability model: the link carries only the ticket id, an e-mail hash and
 * an HMAC token — never the plaintext address (NN-06). Possession of a
 * valid (ticketId, emailHash, token) triple IS the authorization, so a
 * verifier response carries no existence oracle beyond the capability
 * itself.
 *
 * Storage: appconfig key `extmail_optout:{ticketId}:{emailHash}` = unix ts.
 * No schema change; keys are hashed, and orphaned rows on ticket deletion
 * are inert metadata (ticket id + hash, no PII).
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;

final class ExternalMailOptoutService
{
	private const KEY = 'tc-extmail-optout';
	private const TOKEN_LEN = 16;
	private const HASH_LEN = 32;
	private const APP_KEY_PREFIX = 'extmail_optout:';
	private const APP_NAME = 'ticketcheck';

	public function __construct(
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	private function emailHash(string $email): string
	{
		return substr(hash('sha256', strtolower(trim($email))), 0, self::HASH_LEN);
	}

	private function storeKey(int $ticketId, string $emailHash): string
	{
		return self::APP_KEY_PREFIX . $ticketId . ':' . $emailHash;
	}

	public function token(int $ticketId, string $emailHash): string
	{
		$secret = (string)$this->config->getSystemValue('secret', '');
		$payload = self::KEY . '|' . $ticketId . '|' . $emailHash;
		return substr(hash_hmac('sha256', $payload, $secret), 0, self::TOKEN_LEN);
	}

	public function verify(int $ticketId, string $token, string $emailHash): bool
	{
		$token = strtolower(trim($token));
		if ($token === '' || preg_match('/^[a-f0-9]{' . self::TOKEN_LEN . '}$/', $token) !== 1
			|| preg_match('/^[a-f0-9]{' . self::HASH_LEN . '}$/', $emailHash) !== 1) {
			return false;
		}
		return hash_equals($this->token($ticketId, $emailHash), $token);
	}

	public function optoutUrl(int $ticketId, string $email): string
	{
		$emailHash = $this->emailHash($email);
		// getAbsoluteURL on the literal path — linkToRoute(Absolute) is empty
		// in CLI/job contexts where app routes are unregistered, which is
		// exactly where inbound-mail-triggered sends run.
		return $this->urlGenerator->getAbsoluteURL(
			'/apps/' . self::APP_NAME . '/extmail/optout?'
			. http_build_query(['t' => $ticketId, 'e' => $emailHash, 'k' => $this->token($ticketId, $emailHash)])
		);
	}

	public function isOptedOut(int $ticketId, string $email): bool
	{
		return $this->appConfig->getValueString(
			self::APP_NAME,
			$this->storeKey($ticketId, $this->emailHash($email)),
			'',
			lazy: true
		) !== '';
	}

	public function optOut(int $ticketId, string $emailHash): void
	{
		$this->appConfig->setValueString(
			self::APP_NAME,
			$this->storeKey($ticketId, $emailHash),
			(string)time()
		);
	}
}

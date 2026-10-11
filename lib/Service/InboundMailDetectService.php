<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Support\ImapConnection;
use OCA\Ticketcheck\Support\ImapSyncClient;

/**
 * Auto-detection of IMAP host/port/encryption from the support address —
 * the happy path only asks for email + password/OAuth.
 *
 * Bounded by spec: 3 host candidates × 2 (port,enc) pairs, ≤6 dials,
 * ≤8s per dial, ≤45s total. First working combination wins.
 */
final class InboundMailDetectService
{
	private const PER_DIAL_TIMEOUT = 8.0;
	private const TOTAL_BUDGET = 45.0;

	public function __construct(
		private readonly InboundMailAuthService $auth,
	) {
	}

	/**
	 * Ordered candidate matrix — pure, unit tested.
	 *
	 * @return list<array{host: string, port: int, enc: string}>
	 */
	public static function candidates(string $address): array
	{
		$address = strtolower(trim($address));
		$at = strrpos($address, '@');
		if ($at === false) {
			return [];
		}
		$domain = substr($address, $at + 1);
		if ($domain === '' || preg_match('/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/', $domain) !== 1) {
			return [];
		}
		// Literal IPv4/IPv6 address — only itself is a candidate.
		if (filter_var($domain, FILTER_VALIDATE_IP)) {
			return self::matrix([$domain]);
		}
		$hosts = array_values(array_unique([
			'imap.' . $domain,
			'mail.' . $domain,
			$domain,
		]));

		return self::matrix($hosts);
	}

	/**
	 * @param list<string> $hosts
	 * @return list<array{host: string, port: int, enc: string}>
	 */
	private static function matrix(array $hosts): array
	{
		$out = [];
		foreach ($hosts as $host) {
			$out[] = ['host' => $host, 'port' => 993, 'enc' => 'ssl'];
			$out[] = ['host' => $host, 'port' => 143, 'enc' => 'starttls'];
		}
		return $out;
	}

	/**
	 * Try candidates until one authenticates. Private hosts are skipped
	 * unless allowPrivateHost is set (SSRF — detection must not probe LANs).
	 *
	 * @param array<string, mixed> $cfg mailbox config (needs auth_mode,
	 *        username/secret via $auth->resolve or explicit 'secret')
	 * @return array{ok: bool, host?: string, port?: int, enc?: string,
	 *               error?: string, attempted?: int}
	 */
	public function detect(array $cfg, string $secret, bool $allowPrivateHost = false): array
	{
		$address = (string)($cfg['address'] ?? '');
		$user = ImapConnection::normalizeUsername((string)($cfg['username'] ?? ''));
		if ($user === '') {
			$user = $address;
		}
		$authMode = (string)($cfg['auth_mode'] ?? 'password');
		if ($address === '' || $user === '' || $secret === '') {
			return ['ok' => false, 'error' => 'missing_credentials'];
		}

		$deadline = microtime(true) + self::TOTAL_BUDGET;
		$attempted = 0;
		foreach (self::candidates($address) as $cand) {
			if (microtime(true) >= $deadline) {
				break;
			}
			if (!$allowPrivateHost && ImapConnection::isBlockedMailHost($cand['host'])) {
				continue;
			}
			$attempted++;
			$res = ImapSyncClient::connect([
				'host' => $cand['host'],
				'port' => $cand['port'],
				'enc' => $cand['enc'],
				'user' => $user,
				'secret' => $secret,
				'authMode' => $authMode,
				'allowSelfSigned' => !empty($cfg['allow_self_signed']),
				'timeoutSeconds' => min(self::PER_DIAL_TIMEOUT, max(1.0, $deadline - microtime(true))),
			]);
			if (!empty($res['ok'])) {
				$res['client']->close();
				return [
					'ok' => true,
					'host' => $cand['host'],
					'port' => $cand['port'],
					'enc' => $cand['enc'],
					'attempted' => $attempted,
				];
			}
			// Auth failure is definitive: the host answered, credentials are
			// wrong — retrying other hosts with the same creds is pointless.
			if (($res['reason'] ?? '') === 'auth') {
				return ['ok' => false, 'error' => 'auth', 'attempted' => $attempted];
			}
		}

		return ['ok' => false, 'error' => 'unreachable', 'attempted' => $attempted];
	}
}

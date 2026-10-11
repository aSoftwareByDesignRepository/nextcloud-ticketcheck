<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Support;

/**
 * Pure IMAP mailbox-spec helpers — path building, credential cleanup, error triage.
 *
 * Keeps php-imap string quirks out of MailboxSyncService so unit/mutation tests
 * can prove host/enc/username edge cases without a live mail server.
 */
final class ImapConnection
{
	public const ENC_SSL = 'ssl';
	public const ENC_TLS = 'tls';
	public const ENC_NONE = 'none';

	/**
	 * Strip provider “account id” suffixes such as " (m06367b4)" that All-Inkl
	 * prints next to the real IMAP username (the full email address).
	 */
	public static function normalizeUsername(string $username): string
	{
		$user = trim($username);
		if ($user === '' || preg_match('/[\x00-\x1f\x7f]/', $user) === 1) {
			return '';
		}
		// "info@example.com (m06367b4)" or "info@example.com (w01d1cf3)"
		$stripped = preg_replace('/\s*\([mw]\d+[a-z0-9]*\)\s*$/i', '', $user);
		if (is_string($stripped) && $stripped !== '') {
			$user = trim($stripped);
		}
		return $user;
	}

	/**
	 * Trim and strip accidental scheme prefixes from host fields.
	 */
	public static function normalizeHost(string $host): string
	{
		$host = trim($host);
		// Whitespace/control chars can never be valid in a hostname.
		if ($host === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $host) === 1) {
			return '';
		}
		$host = preg_replace('#^(imaps?|smtps?)://#i', '', $host) ?? $host;
		$host = rtrim($host, '/');
		// Path separators can never be valid in a hostname — fail closed.
		if (str_contains($host, '/')) {
			return '';
		}
		// Drop accidental ":993" if the operator pasted host:port into the host field.
		if (preg_match('/^([a-z0-9.-]+):\d{2,5}$/i', $host, $m) === 1) {
			return strtolower($m[1]);
		}
		return strtolower($host);
	}

	/**
	 * Whether a host/IP must not be dialed from the app server (SSRF / metadata pivot).
	 * Resolves A + AAAA when possible; fails closed on empty host.
	 * Any resolved private/reserved address blocks the host (fail closed on mixed records).
	 */
	public static function isBlockedMailHost(string $host): bool
	{
		$host = self::normalizeHost($host);
		if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
			return true;
		}
		// Bare IPv4 / IPv6 literals (incl. ::1, fc00::/7, link-local).
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			return self::isBlockedIp($host);
		}
		// Hostname shape only (no spaces / path injection).
		if (preg_match('/^[a-z0-9.-]+$/i', $host) !== 1 || str_contains($host, '..')) {
			return true;
		}
		$ips = self::resolveHostIps($host);
		if ($ips === []) {
			// Unresolvable today — allow save (provider DNS may appear later).
			// Live dialers must re-check after connect-time resolve / refuse empty resolve.
			return false;
		}
		foreach ($ips as $ip) {
			if (self::isBlockedIp($ip)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve A + AAAA records for SSRF checks. Empty = DNS miss (not an allow signal for dialers).
	 *
	 * @return list<string>
	 */
	public static function resolveHostIps(string $host): array
	{
		$host = self::normalizeHost($host);
		if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
			return $host !== '' && filter_var($host, FILTER_VALIDATE_IP) ? [$host] : [];
		}
		$ips = [];
		$a = @gethostbynamel($host);
		if (is_array($a)) {
			foreach ($a as $ip) {
				$ip = trim((string)$ip);
				if ($ip !== '' && !in_array($ip, $ips, true)) {
					$ips[] = $ip;
				}
			}
		}
		if (\function_exists('dns_get_record')) {
			$aaaa = @dns_get_record($host, DNS_AAAA);
			if (is_array($aaaa)) {
				foreach ($aaaa as $rec) {
					$ip = trim((string)($rec['ipv6'] ?? ''));
					if ($ip !== '' && !in_array($ip, $ips, true)) {
						$ips[] = $ip;
					}
				}
			}
		}

		return $ips;
	}

	/**
	 * Live IMAP UIDs are positive decimal integers (as stored after sync).
	 * CRM-first sends use synthetic `outbound:…` ids until Sent re-ingest; empty/garbage
	 * values also must never be dialed for delete/junk (Assign closes CRM only).
	 */
	public static function isLiveUid(string $uid): bool
	{
		$uid = trim($uid);

		return $uid !== '' && preg_match('/^\d+$/', $uid) === 1;
	}

	/**
	 * Reject folder paths that can break IMAP quoting, SELECT injection, or php-imap `{host}` framing.
	 * Accepts UTF-8 (umlauts) and Modified UTF-7 wire forms; validates the wire octet length.
	 */
	public static function isSafeImapFolder(string $folder): bool
	{
		$folder = trim($folder);
		if ($folder === '') {
			return false;
		}
		$utf8 = ImapUtf7::toUtf8($folder);
		if ($utf8 === '') {
			return false;
		}
		// Control chars, quotes, backslash, and braces (mailbox-spec breakout) — UTF-8 text.
		if (preg_match('/[\x00-\x1f\x7f"\\\\{}]/u', $utf8) === 1) {
			return false;
		}
		$wire = ImapUtf7::toWire($utf8);
		// IMAP mailbox names are bounded; refuse absurd / DoS paths.
		if ($wire === '' || strlen($wire) > 255) {
			return false;
		}

		return true;
	}

	/**
	 * Normalize LIST / stored folder path to UTF-8 for CRM storage + UI.
	 */
	public static function normalizeFolderPath(string $folder): string
	{
		return ImapUtf7::toUtf8(trim($folder));
	}

	/**
	 * Wire form for SELECT / imap_reopen / imap_open append.
	 */
	public static function folderToWire(string $folder): string
	{
		$folder = trim($folder);
		if ($folder === '') {
			return 'INBOX';
		}

		return ImapUtf7::toWire($folder);
	}

	public static function isBlockedIp(string $ip): bool
	{
		$ip = trim($ip);
		if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
			return true;
		}
		// FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE covers RFC1918, loopback, link-local,
		// and reserved ranges including 169.254.0.0/16 (cloud metadata).
		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
			return true;
		}

		return false;
	}

	/**
	 * Resolve SMTP encryption for SharedMailboxSmtpClient.
	 * Port 465 → ssl (SMTPS), 587 → tls (STARTTLS).
	 *
	 * When an explicit enc conflicts with a well-known port (legacy mis-saves
	 * like 465+tls from older form defaults), the port wins.
	 */
	public static function resolveSmtpEnc(?string $enc, int $port): string
	{
		$enc = strtolower(trim((string)$enc));
		if ($enc === 'smtps') {
			$enc = self::ENC_SSL;
		} elseif ($enc === 'starttls') {
			$enc = self::ENC_TLS;
		}

		// Port/enc reconciliation for provider-standard ports.
		if ($port === 465) {
			return self::ENC_SSL;
		}
		if ($port === 587) {
			return self::ENC_TLS;
		}

		if (in_array($enc, [self::ENC_SSL, self::ENC_TLS, self::ENC_NONE], true)) {
			return $enc;
		}
		return $port <= 0 ? self::ENC_TLS : self::ENC_TLS;
	}

	/**
	 * Resolve IMAP encryption for php-imap mailbox specs.
	 * Port 993 → ssl (implicit), 143 → tls (STARTTLS).
	 * Provider-standard ports win over conflicting explicit values.
	 */
	public static function resolveImapEnc(?string $enc, int $port): string
	{
		$enc = strtolower(trim((string)$enc));
		if ($port === 993) {
			return self::ENC_SSL;
		}
		if ($port === 143) {
			return self::ENC_TLS;
		}
		if (in_array($enc, [self::ENC_SSL, self::ENC_TLS, self::ENC_NONE], true)) {
			return $enc;
		}
		if ($port <= 0) {
			return self::ENC_SSL;
		}
		return self::ENC_SSL;
	}

	/**
	 * Build a php-imap mailbox root like "{host:993/imap/ssl/novalidate-cert}".
	 * Caller appends folder path (e.g. "INBOX").
	 */
	public static function buildMailboxRoot(
		string $host,
		int $port,
		string $enc,
		bool $novalidateCert = false,
	): string {
		$host = self::normalizeHost($host);
		$port = $port > 0 ? $port : ($enc === self::ENC_TLS ? 143 : 993);
		$enc = self::resolveImapEnc($enc, $port);

		$flags = ['imap'];
		if ($enc === self::ENC_SSL) {
			$flags[] = 'ssl';
		} elseif ($enc === self::ENC_TLS) {
			$flags[] = 'tls';
		} else {
			$flags[] = 'notls';
		}
		if ($novalidateCert && ($enc === self::ENC_SSL || $enc === self::ENC_TLS)) {
			$flags[] = 'novalidate-cert';
		}

		return sprintf('{%s:%d/%s}', $host, $port, implode('/', $flags));
	}

	/**
	 * Whether an imap_last_error / imap_errors blob looks like TLS/cert mismatch.
	 */
	public static function isCertificateError(string $error): bool
	{
		$e = strtolower($error);
		foreach ([
			'certificate verify failed',
			'certificate problem',
			'self-signed certificate',
			'self signed certificate',
			'unable to get local issuer',
			'peer certificate',
			'ssl3_get_server_certificate',
			'cert verify',
			'verify error',
			'hostname mismatch',
			'name mismatch',
		] as $needle) {
			if (str_contains($e, $needle)) {
				return true;
			}
		}
		return (bool)preg_match('/\b(certificate|certificat).{0,40}(fail|error|invalid|untrust)/i', $error);
	}

	/**
	 * Whether the error looks like bad credentials (not network).
	 */
	public static function isAuthError(string $error): bool
	{
		$e = strtolower($error);
		foreach ([
			'authentication failed',
			'auth failed',
			'invalid credentials',
			'login failed',
			'authenticate failed',
			'can not authenticate',
			'cannot authenticate',
			'incorrect password',
			'wrong password',
			'authcancel',
			'[authenticationfailed]',
			'[auth]',
			'no matching login',
			'unknown user',
			'invalid login',
			'login denial',
			'authenticationfailed',
		] as $needle) {
			if (str_contains($e, $needle)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sanitize php-imap errors for the UI — never echo passwords; truncate noise.
	 */
	public static function sanitizeError(?string $raw): string
	{
		$raw = trim((string)$raw);
		if ($raw === '') {
			return '';
		}
		// Drop obvious secret material if a library ever echoes it.
		$raw = preg_replace('/password[=:\s]+\S+/i', 'password=***', $raw) ?? $raw;
		$raw = preg_replace('/pass[=:\s]+\S+/i', 'pass=***', $raw) ?? $raw;
		$raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;
		if (strlen($raw) > 240) {
			$raw = substr($raw, 0, 237) . '…';
		}
		return $raw;
	}

	/**
	 * Collect and clear php-imap error buffers into one sanitized string.
	 *
	 * @param list<string>|false|null $errors
	 */
	public static function collectErrors(array|false|null $errors, ?string $last): string
	{
		$parts = [];
		if (is_array($errors)) {
			foreach ($errors as $e) {
				$e = self::sanitizeError((string)$e);
				if ($e !== '' && !in_array($e, $parts, true)) {
					$parts[] = $e;
				}
			}
		}
		$last = self::sanitizeError($last);
		if ($last !== '' && !in_array($last, $parts, true)) {
			$parts[] = $last;
		}
		return implode(' · ', array_slice($parts, 0, 3));
	}

	/**
	 * Map low-level IMAP errors to a stable reason code for i18n.
	 *
	 * @return 'auth'|'cert'|'network'|'unknown'
	 */
	public static function classifyError(string $error): string
	{
		if ($error === '') {
			return 'unknown';
		}
		if (self::isAuthError($error)) {
			return 'auth';
		}
		if (self::isCertificateError($error)) {
			return 'cert';
		}
		$e = strtolower($error);
		foreach ([
			'connection refused',
			'connection timed out',
			'timed out',
			'no route to host',
			'network is unreachable',
			'name or service not known',
			'getaddrinfo',
			'could not connect',
			'unable to connect',
			"couldn't connect",
			'host not found',
		] as $needle) {
			if (str_contains($e, $needle)) {
				return 'network';
			}
		}
		return 'unknown';
	}

	/**
	 * Pick the mailbox’s Junk/Spam folder from LIST results (server-discovered only).
	 * Prefer SPECIAL-USE \Junk / \Spam, then common names. Never returns INBOX.
	 *
	 * @param list<string|array{name?:string,flags?:list<string>}> $folders
	 */
	public static function pickJunkFolder(array $folders): ?string
	{
		$entries = [];
		foreach ($folders as $raw) {
			if (is_string($raw)) {
				$name = trim($raw);
				if ($name === '') {
					continue;
				}
				$entries[] = ['name' => $name, 'flags' => []];
				continue;
			}
			if (!is_array($raw)) {
				continue;
			}
			$name = trim((string)($raw['name'] ?? ''));
			if ($name === '') {
				continue;
			}
			$flags = [];
			if (isset($raw['flags']) && is_array($raw['flags'])) {
				foreach ($raw['flags'] as $flag) {
					if (is_string($flag) || is_int($flag)) {
						$flags[] = (string)$flag;
					}
				}
			}
			$entries[] = ['name' => $name, 'flags' => $flags];
		}

		$usable = static function (string $name): bool {
			if (!self::isSafeImapFolder($name)) {
				return false;
			}
			return strcasecmp($name, 'INBOX') !== 0;
		};

		foreach ($entries as $entry) {
			foreach ($entry['flags'] as $flag) {
				$norm = ltrim(strtolower(trim($flag)), '\\');
				if ($norm === 'junk' || $norm === 'spam') {
					if ($usable($entry['name'])) {
						return $entry['name'];
					}
				}
			}
		}

		$preferred = [
			'Junk',
			'Spam',
			'INBOX.Junk',
			'INBOX.Spam',
			'Junk E-Mail',
			'Junk Email',
			'Bulk Mail',
			'[Gmail]/Spam',
		];
		foreach ($preferred as $want) {
			foreach ($entries as $entry) {
				if (strcasecmp($entry['name'], $want) === 0 && $usable($entry['name'])) {
					return $entry['name'];
				}
			}
		}

		foreach ($entries as $entry) {
			$base = $entry['name'];
			if (str_contains($base, '/') || str_contains($base, '.')) {
				$parts = preg_split('/[\/.]/', $base) ?: [];
				$base = (string)end($parts);
			}
			if (preg_match('/^(junk|spam)$/i', $base) === 1 && $usable($entry['name'])) {
				return $entry['name'];
			}
		}

		return null;
	}
}

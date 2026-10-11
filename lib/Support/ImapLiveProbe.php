<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Support;



/**
 * Live IMAP TLS probe that does NOT require php-imap.
 * Uses MailPreviewText for safe plain-text body previews.
 *
 * Speaks enough of the wire protocol to prove: TCP+TLS reachability, server
 * greeting, and LOGIN rejection/acceptance. Used by integration tests and as a
 * diagnostic fallback when diagnosing provider connectivity.
 *
 * Never logs passwords. Timeouts are hard-bounded.
 */
final class ImapLiveProbe
{
	private const MAIL_FORWARD_BODY_PEEK_BYTES = 8192;
	private const MAIL_BODY_PLAIN_MAX_BYTES = 204800;
	private const MAIL_BODY_HTML_MAX_BYTES = 524288;
	private const MAIL_PREVIEW_MAX_BYTES = 51200;
	private const MAILBOX_LIST_FOLDERS_MAX = 200;

	/**
	 * @return array{
	 *   ok: bool,
	 *   reachable: bool,
	 *   greeting: string,
	 *   reason: 'ok'|'auth'|'network'|'protocol'|'unknown',
	 *   detail: string
	 * }
	 */
	public static function probeLogin(
		string $host,
		int $port,
		string $username,
		string $password,
		float $timeoutSeconds = 12.0,
		string $authMode = 'password',
	): array {
		$host = ImapConnection::normalizeHost($host);
		$user = ImapConnection::normalizeUsername($username);
		$port = $port > 0 ? $port : 993;
		$authMode = strtolower(trim($authMode)) === 'xoauth2' ? 'xoauth2' : 'password';
		if ($host === '' || $user === '' || $password === '') {
			return [
				'ok' => false,
				'reachable' => false,
				'greeting' => '',
				'reason' => 'unknown',
				'detail' => 'host, username and password are required',
			];
		}

		$remote = 'ssl://' . $host . ':' . $port;
		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client(
			$remote,
			$errno,
			$errstr,
			max(1.0, $timeoutSeconds),
			STREAM_CLIENT_CONNECT,
		);
		if ($fp === false) {
			$detail = ImapConnection::sanitizeError($errstr !== '' ? $errstr : ('connect errno ' . $errno));
			return [
				'ok' => false,
				'reachable' => false,
				'greeting' => '',
				'reason' => ImapConnection::classifyError($detail) === 'network' ? 'network' : 'network',
				'detail' => $detail !== '' ? $detail : 'TLS connect failed',
			];
		}

		stream_set_timeout($fp, (int)max(1, (int)ceil($timeoutSeconds)));
		try {
			$greeting = self::readLine($fp);
			if ($greeting === '' || !str_starts_with(strtoupper(ltrim($greeting)), '* OK')) {
				return [
					'ok' => false,
					'reachable' => true,
					'greeting' => ImapConnection::sanitizeError($greeting),
					'reason' => 'protocol',
					'detail' => 'Unexpected IMAP greeting: ' . ImapConnection::sanitizeError($greeting),
				];
			}

			$auth = self::authenticateAfterGreeting($fp, $user, $password, $authMode);
			$sanitized = ImapConnection::sanitizeError((string)($auth['response'] ?? ''));
			if (!empty($auth['ok'])) {
				$folders = self::listFoldersAfterLogin($fp);
				// Logout best-effort; ignore result.
				fwrite($fp, "A099 LOGOUT\r\n");
				self::readUntilTagged($fp, 'A099');
				return [
					'ok' => true,
					'reachable' => true,
					'greeting' => ImapConnection::sanitizeError($greeting),
					'reason' => 'ok',
					'detail' => '',
					'folders' => $folders !== [] ? $folders : ['INBOX'],
				];
			}

			$reason = ImapConnection::classifyError($sanitized);
			$upper = strtoupper($sanitized);
			if ($reason === 'unknown' && (
				str_contains($upper, ' NO ')
				|| str_contains($upper, 'AUTHENTICATIONFAILED')
				|| str_contains($upper, 'LOGIN')
				|| str_contains($upper, 'AUTHENTICATE')
			)) {
				$reason = 'auth';
			}
			if (($auth['reason'] ?? '') === 'auth') {
				$reason = 'auth';
			}

			return [
				'ok' => false,
				'reachable' => true,
				'greeting' => ImapConnection::sanitizeError($greeting),
				'reason' => $reason === 'network' ? 'auth' : ($reason !== 'unknown' ? $reason : 'auth'),
				'detail' => $sanitized !== '' ? $sanitized : (string)($auth['reason'] ?? 'auth'),
				'folders' => [],
			];
		} finally {
			fclose($fp);
		}
	}

	/**
	 * Parse RFC822 header block into CRM ingest fields (pure — unit tested).
	 *
	 * @return array{from: string, to: list<string>, cc: list<string>, replyTo: ?string, resentFrom: ?string, xOriginalFrom: ?string, subject: string, sentAt: ?string, messageId: ?string}
	 */
	public static function parseRfc822Headers(string $raw): array
	{
		$from = '';
		$to = [];
		$cc = [];
		$replyTo = null;
		$resentFrom = null;
		$xOriginalFrom = null;
		$subject = '';
		$date = '';
		$messageId = null;
		// Unfold continued header lines.
		$folded = preg_replace("/\r?\n[ \t]+/", ' ', str_replace("\r\n", "\n", $raw)) ?? $raw;
		foreach (explode("\n", $folded) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			if (preg_match('/^From:\s*(.+)$/i', $line, $m) === 1) {
				$from = self::extractEmailAddress($m[1]);
			} elseif (preg_match('/^To:\s*(.+)$/i', $line, $m) === 1) {
				$to = self::extractEmailAddresses($m[1]);
			} elseif (preg_match('/^Cc:\s*(.+)$/i', $line, $m) === 1) {
				$cc = self::extractEmailAddresses($m[1]);
			} elseif (preg_match('/^Reply-To:\s*(.+)$/i', $line, $m) === 1) {
				$replyTo = self::extractEmailAddress($m[1]);
			} elseif (preg_match('/^Resent-From:\s*(.+)$/i', $line, $m) === 1) {
				$resentFrom = self::extractEmailAddress($m[1]);
			} elseif (preg_match('/^X-Original-From:\s*(.+)$/i', $line, $m) === 1) {
				$xOriginalFrom = self::extractEmailAddress($m[1]);
			} elseif (preg_match('/^Subject:\s*(.+)$/i', $line, $m) === 1) {
				$subject = MimeHeader::decode(trim($m[1]));
			} elseif (preg_match('/^Date:\s*(.+)$/i', $line, $m) === 1) {
				$date = trim($m[1]);
			} elseif (preg_match('/^Message-ID:\s*(.+)$/i', $line, $m) === 1) {
				$messageId = trim($m[1]);
			}
		}
		$sentAt = null;
		if ($date !== '') {
			$ts = strtotime($date);
			if ($ts !== false) {
				$sentAt = date('Y-m-d H:i:s', $ts);
			}
		}

		return [
			'from' => $from,
			'to' => $to,
			'cc' => $cc,
			'replyTo' => ($replyTo !== null && $replyTo !== '' && str_contains($replyTo, '@')) ? $replyTo : null,
			'resentFrom' => ($resentFrom !== null && $resentFrom !== '' && str_contains($resentFrom, '@')) ? $resentFrom : null,
			'xOriginalFrom' => ($xOriginalFrom !== null && $xOriginalFrom !== '' && str_contains($xOriginalFrom, '@')) ? $xOriginalFrom : null,
			'subject' => $subject,
			'sentAt' => $sentAt,
			'messageId' => $messageId,
		];
	}

	/**
	 * Extract mailbox@host from a From header value.
	 */
	public static function extractEmailAddress(string $raw): string
	{
		$raw = trim($raw);
		if (preg_match('/<([^>]+)>/', $raw, $m) === 1) {
			return strtolower(trim($m[1]));
		}
		if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $raw, $m) === 1) {
			return strtolower(trim($m[0]));
		}

		return strtolower($raw);
	}

	/**
	 * Extract every mailbox@host from a To/Cc header value.
	 *
	 * @return list<string>
	 */
	public static function extractEmailAddresses(string $raw): array
	{
		$raw = trim($raw);
		if ($raw === '') {
			return [];
		}
		$out = [];
		if (preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $raw, $m) === false) {
			return [];
		}
		foreach ($m[0] as $addr) {
			$email = strtolower(trim((string)$addr));
			if ($email !== '') {
				$out[$email] = true;
			}
		}

		return array_keys($out);
	}

	/**
	 * Parse EXISTS count from SELECT untagged lines (pure).
	 *
	 * @param list<string> $lines
	 */
	public static function parseExistsCount(array $lines): int
	{
		$max = 0;
		foreach ($lines as $line) {
			if (preg_match('/^\*\s+(\d+)\s+EXISTS\b/i', trim((string)$line), $m) === 1) {
				$max = max($max, (int)$m[1]);
			}
		}

		return $max;
	}

	/**
	 * Parse FETCH responses that include UID + header literal/body (pure).
	 *
	 * @param list<string> $lines
	 * @return list<array{uid: string, from: string, to: list<string>, cc: list<string>, replyTo: ?string, resentFrom: ?string, xOriginalFrom: ?string, subject: string, sentAt: ?string, messageId: ?string}>
	 */
	public static function parseFetchHeaderResponses(array $lines): array
	{
		$out = [];
		$i = 0;
		$n = count($lines);
		while ($i < $n) {
			$line = (string)$lines[$i];
			if (preg_match('/^\*\s+\d+\s+FETCH\b/i', $line) !== 1) {
				$i++;
				continue;
			}
			$uid = '0';
			if (preg_match('/\bUID\s+(\d+)\b/i', $line, $um) === 1) {
				$uid = $um[1];
			}
			$headerText = '';
			if (preg_match('/\{(\d+)\}\s*$/', $line, $lm) === 1) {
				$i++;
				$headerText = $i < $n ? (string)$lines[$i] : '';
				$i++;
			} elseif (preg_match('/BODY(?:\.PEEK)?\[HEADER(?:\.FIELDS)?[^\]]*\]\s+"((?:\\\\.|[^"\\\\])*)"/i', $line, $bm) === 1) {
				$headerText = stripcslashes($bm[1]);
				$i++;
			} else {
				// Collect following non-FETCH lines until closing paren / next FETCH / tagged OK.
				$i++;
				$chunk = [];
				while ($i < $n) {
					$next = (string)$lines[$i];
					if (preg_match('/^\*\s+\d+\s+FETCH\b/i', $next) === 1 || preg_match('/^[A-Z0-9]+\s+(OK|NO|BAD)\b/i', $next) === 1) {
						break;
					}
					if (trim($next) === ')') {
						$i++;
						break;
					}
					$chunk[] = $next;
					$i++;
				}
				$headerText = implode("\n", $chunk);
			}
			$parsed = self::parseRfc822Headers($headerText);
			$out[] = [
				'uid' => $uid !== '0' ? $uid : (string)(count($out) + 1),
				'from' => $parsed['from'],
				'to' => $parsed['to'],
				'cc' => $parsed['cc'],
				'replyTo' => $parsed['replyTo'],
				'resentFrom' => $parsed['resentFrom'],
				'xOriginalFrom' => $parsed['xOriginalFrom'],
				'subject' => $parsed['subject'],
				'sentAt' => $parsed['sentAt'],
				'messageId' => $parsed['messageId'],
			];
		}

		return $out;
	}

	/**
	 * IMAP AUTH after greeting — LOGIN / AUTHENTICATE PLAIN / AUTHENTICATE XOAUTH2.
	 *
	 * @param resource $fp
	 * @return array{ok: bool, response: string, reason: string}
	 */
	public static function authenticateAfterGreeting($fp, string $user, string $secret, string $authMode = 'password'): array
	{
		$user = ImapConnection::normalizeUsername($user);
		if ($authMode === 'xoauth2') {
			$tag = 'X001';
			$sasl = \OCA\Ticketcheck\Service\InboundMailAuthService::xoauth2Sasl($user, $secret);
			fwrite($fp, $tag . ' AUTHENTICATE XOAUTH2 ' . $sasl . "\r\n");
			$response = self::readUntilTagged($fp, $tag);
			$upper = strtoupper($response);
			if (str_contains($upper, $tag . ' OK') || preg_match('/\bOK\b/', $upper) === 1) {
				return ['ok' => true, 'response' => $response, 'reason' => 'ok'];
			}

			return [
				'ok' => false,
				'response' => $response,
				'reason' => (str_contains($upper, 'NO') || str_contains($upper, 'AUTHENTICATIONFAILED')) ? 'auth' : 'protocol',
			];
		}

		$tag = 'S001';
		fwrite($fp, $tag . ' LOGIN ' . self::quoteAtom($user) . ' ' . self::quoteAtom($secret) . "\r\n");
		$response = self::readUntilTagged($fp, $tag);
		$upper = strtoupper($response);
		if (str_contains($upper, $tag . ' OK')) {
			return ['ok' => true, 'response' => $response, 'reason' => 'ok'];
		}
		if (str_contains($upper, 'LOGIN') && (str_contains($upper, 'DISABLED') || str_contains($upper, 'NOT SUPPORTED'))) {
			$tag = 'S010';
			$plain = base64_encode("\0" . $user . "\0" . $secret);
			fwrite($fp, $tag . ' AUTHENTICATE PLAIN ' . $plain . "\r\n");
			$response = self::readUntilTagged($fp, $tag);
			$upper = strtoupper($response);
			if (str_contains($upper, $tag . ' OK')) {
				return ['ok' => true, 'response' => $response, 'reason' => 'ok'];
			}
		}

		return [
			'ok' => false,
			'response' => $response,
			'reason' => (str_contains($upper, 'NO') || str_contains($upper, 'AUTHENTICATIONFAILED') || str_contains($upper, 'LOGIN'))
				? 'auth'
				: 'protocol',
		];
	}

	/**
	 * Login + SELECT + FETCH recent headers for one folder (no php-imap).
	 *
	 * @return array{
	 *   ok: bool,
	 *   reason: 'ok'|'auth'|'network'|'protocol'|'unknown',
	 *   detail: string,
	 *   messages: list<array{uid: string, from: string, to: list<string>, cc: list<string>, resentFrom: ?string, xOriginalFrom: ?string, subject: string, sentAt: ?string, messageId: ?string, snippet: string, direction: string}>
	 * }
	 */
	public static function fetchRecentMessages(
		string $host,
		int $port,
		string $username,
		string $password,
		string $folder,
		int $limit = 500,
		float $timeoutSeconds = 20.0,
		string $authMode = 'password',
	): array {
		$host = ImapConnection::normalizeHost($host);
		$user = ImapConnection::normalizeUsername($username);
		$folder = ImapConnection::folderToWire(trim($folder) !== '' ? trim($folder) : 'INBOX');
		$port = $port > 0 ? $port : 993;
		$limit = max(1, min(500, $limit));
		if ($host === '' || $user === '' || $password === '') {
			return [
				'ok' => false,
				'reason' => 'unknown',
				'detail' => 'host, username and password are required',
				'messages' => [],
			];
		}

		$remote = 'ssl://' . $host . ':' . $port;
		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client(
			$remote,
			$errno,
			$errstr,
			max(1.0, $timeoutSeconds),
			STREAM_CLIENT_CONNECT,
		);
		if ($fp === false) {
			$detail = ImapConnection::sanitizeError($errstr !== '' ? $errstr : ('connect errno ' . $errno));
			return [
				'ok' => false,
				'reason' => 'network',
				'detail' => $detail !== '' ? $detail : 'TLS connect failed',
				'messages' => [],
			];
		}

		stream_set_timeout($fp, (int)max(1, (int)ceil($timeoutSeconds)));
		try {
			$greeting = self::readLine($fp);
			if ($greeting === '' || !str_starts_with(strtoupper(ltrim($greeting)), '* OK')) {
				return [
					'ok' => false,
					'reason' => 'protocol',
					'detail' => 'Unexpected IMAP greeting: ' . ImapConnection::sanitizeError($greeting),
					'messages' => [],
				];
			}

			$auth = self::authenticateAfterGreeting($fp, $user, $password, $authMode);
			if (!$auth['ok']) {
				$sanitized = ImapConnection::sanitizeError($auth['response']);
				$reason = $auth['reason'] === 'auth' ? 'auth' : ImapConnection::classifyError($sanitized);
				if ($reason === 'unknown') {
					$reason = 'auth';
				}

				return [
					'ok' => false,
					'reason' => $reason === 'network' ? 'auth' : $reason,
					'detail' => $sanitized,
					'messages' => [],
				];
			}

			$tag = 'S020';
			fwrite($fp, $tag . ' SELECT ' . self::quoteAtom($folder) . "\r\n");
			$selectLines = self::readLinesUntilTagged($fp, $tag);
			$selectOk = false;
			foreach ($selectLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$selectOk = true;
					break;
				}
			}
			if (!$selectOk) {
				return [
					'ok' => false,
					'reason' => 'protocol',
					'detail' => 'SELECT failed for folder ' . ImapConnection::sanitizeError($folder),
					'messages' => [],
				];
			}
			$exists = self::parseExistsCount($selectLines);
			if ($exists < 1) {
				fwrite($fp, "S099 LOGOUT\r\n");
				self::readUntilTagged($fp, 'S099');

				return ['ok' => true, 'reason' => 'ok', 'detail' => '', 'messages' => []];
			}

			$start = max(1, $exists - $limit + 1);
			$tag = 'S030';
			$cmd = $tag . ' FETCH ' . $start . ':' . $exists
				. ' (UID BODY.PEEK[HEADER.FIELDS (FROM TO CC REPLY-TO RESENT-FROM X-ORIGINAL-FROM SUBJECT DATE MESSAGE-ID)])' . "\r\n";
			fwrite($fp, $cmd);
			$fetchLines = self::readFetchLinesUntilTagged($fp, $tag);
			$parsed = self::parseFetchHeaderResponses($fetchLines);
			$messages = [];
			foreach ($parsed as $msg) {
				$messages[] = [
					'uid' => $msg['uid'],
					'from' => $msg['from'],
					'to' => $msg['to'],
					'cc' => $msg['cc'],
					'replyTo' => $msg['replyTo'] ?? null,
					'resentFrom' => $msg['resentFrom'],
					'xOriginalFrom' => $msg['xOriginalFrom'],
					'subject' => $msg['subject'],
					'snippet' => mb_substr($msg['subject'], 0, 200),
					'sentAt' => $msg['sentAt'],
					'messageId' => $msg['messageId'],
					'direction' => 'inbound',
				];
			}

			fwrite($fp, "S099 LOGOUT\r\n");
			self::readUntilTagged($fp, 'S099');

			return ['ok' => true, 'reason' => 'ok', 'detail' => '', 'messages' => $messages];
		} finally {
			fclose($fp);
		}
	}

	/**
	 * Peek capped plain-text prefixes for specific UIDs (one IMAP session).
	 * Used to recover original From from forwarded bodies without full preview.
	 *
	 * @param list<string> $uids
	 * @return array<string, string> uid => plain text prefix
	 */
	public static function peekTextPrefixesForUids(
		string $host,
		int $port,
		string $username,
		string $password,
		string $folder,
		array $uids,
		int $maxBytes = self::MAIL_FORWARD_BODY_PEEK_BYTES,
		float $timeoutSeconds = 20.0,
		string $authMode = 'password',
	): array {
		$host = ImapConnection::normalizeHost($host);
		$user = ImapConnection::normalizeUsername($username);
		$folder = ImapConnection::folderToWire(trim($folder) !== '' ? trim($folder) : 'INBOX');
		$port = $port > 0 ? $port : 993;
		$maxBytes = max(512, min(self::MAIL_BODY_PLAIN_MAX_BYTES, $maxBytes));
		$authMode = strtolower(trim($authMode)) === 'xoauth2' ? 'xoauth2' : 'password';
		$cleanUids = [];
		foreach ($uids as $uid) {
			$u = trim((string)$uid);
			if ($u !== '' && preg_match('/^\d+$/', $u) === 1) {
				$cleanUids[$u] = true;
			}
		}
		$cleanUids = array_keys($cleanUids);
		if ($host === '' || $user === '' || $password === '' || $cleanUids === []) {
			return [];
		}

		$remote = 'ssl://' . $host . ':' . $port;
		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client(
			$remote,
			$errno,
			$errstr,
			max(1.0, $timeoutSeconds),
			STREAM_CLIENT_CONNECT,
		);
		if ($fp === false) {
			return [];
		}
		stream_set_timeout($fp, (int)max(1, (int)ceil($timeoutSeconds)));
		$out = [];
		try {
			$greeting = self::readLine($fp);
			if ($greeting === '' || !str_starts_with(strtoupper(ltrim($greeting)), '* OK')) {
				return [];
			}
			$auth = self::authenticateAfterGreeting($fp, $user, $password, $authMode);
			if (!$auth['ok']) {
				return [];
			}
			$tag = 'F020';
			fwrite($fp, $tag . ' SELECT ' . self::quoteAtom($folder) . "\r\n");
			$selectLines = self::readLinesUntilTagged($fp, $tag);
			$selectOk = false;
			foreach ($selectLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$selectOk = true;
					break;
				}
			}
			if (!$selectOk) {
				return [];
			}
			$i = 0;
			foreach ($cleanUids as $uid) {
				$i++;
				$tag = 'F' . str_pad((string)(30 + $i), 3, '0', STR_PAD_LEFT);
				$octet = $maxBytes;
				$cmd = $tag . ' UID FETCH ' . $uid
					. ' (BODY.PEEK[TEXT]<0.' . $octet . '>)' . "\r\n";
				fwrite($fp, $cmd);
				$lines = self::readFetchLinesUntilTagged($fp, $tag, $maxBytes + 2048);
				$raw = self::parseFetchBodyLiteral($lines);
				if ($raw === '') {
					$tag2 = 'G' . str_pad((string)(30 + $i), 3, '0', STR_PAD_LEFT);
					fwrite($fp, $tag2 . ' UID FETCH ' . $uid . ' (BODY.PEEK[1]<0.' . $octet . '>)' . "\r\n");
					$lines = self::readFetchLinesUntilTagged($fp, $tag2, $maxBytes + 2048);
					$raw = self::parseFetchBodyLiteral($lines);
				}
				if ($raw !== '') {
					$out[$uid] = MailPreviewText::toPlainPreview($raw, $maxBytes);
				}
			}
			fwrite($fp, "F099 LOGOUT\r\n");
			self::readUntilTagged($fp, 'F099');
		} finally {
			fclose($fp);
		}

		return $out;
	}

	/**
	 * Peek capped plain + HTML bodies for UIDs (MH-05 / OAuth live-probe path).
	 *
	 * @param list<string> $uids
	 * @return array<string, array{plain: string, html: string}>
	 */
	public static function peekBodiesForUids(
		string $host,
		int $port,
		string $username,
		string $password,
		string $folder,
		array $uids,
		int $maxBytes = self::MAIL_BODY_PLAIN_MAX_BYTES,
		float $timeoutSeconds = 20.0,
		string $authMode = 'password',
	): array {
		$host = ImapConnection::normalizeHost($host);
		$user = ImapConnection::normalizeUsername($username);
		$folder = ImapConnection::folderToWire(trim($folder) !== '' ? trim($folder) : 'INBOX');
		$port = $port > 0 ? $port : 993;
		$maxBytes = max(512, min(self::MAIL_BODY_PLAIN_MAX_BYTES, $maxBytes));
		$htmlMax = self::MAIL_BODY_HTML_MAX_BYTES;
		$authMode = strtolower(trim($authMode)) === 'xoauth2' ? 'xoauth2' : 'password';
		$cleanUids = [];
		foreach ($uids as $uid) {
			$u = trim((string)$uid);
			if ($u !== '' && preg_match('/^\d+$/', $u) === 1) {
				$cleanUids[$u] = true;
			}
		}
		$cleanUids = array_keys($cleanUids);
		if ($host === '' || $user === '' || $password === '' || $cleanUids === []) {
			return [];
		}

		$remote = 'ssl://' . $host . ':' . $port;
		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client(
			$remote,
			$errno,
			$errstr,
			max(1.0, $timeoutSeconds),
			STREAM_CLIENT_CONNECT,
		);
		if ($fp === false) {
			return [];
		}
		stream_set_timeout($fp, (int)max(1, (int)ceil($timeoutSeconds)));
		$out = [];
		try {
			$greeting = self::readLine($fp);
			if ($greeting === '' || !str_starts_with(strtoupper(ltrim($greeting)), '* OK')) {
				return [];
			}
			$auth = self::authenticateAfterGreeting($fp, $user, $password, $authMode);
			if (!$auth['ok']) {
				return [];
			}
			$tag = 'B020';
			fwrite($fp, $tag . ' SELECT ' . self::quoteAtom($folder) . "\r\n");
			$selectLines = self::readLinesUntilTagged($fp, $tag);
			$selectOk = false;
			foreach ($selectLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$selectOk = true;
					break;
				}
			}
			if (!$selectOk) {
				return [];
			}
			$i = 0;
			foreach ($cleanUids as $uid) {
				$i++;
				$plain = '';
				$html = '';
				$octet = $maxBytes;
				$tag = 'B' . str_pad((string)(30 + $i), 3, '0', STR_PAD_LEFT);
				fwrite($fp, $tag . ' UID FETCH ' . $uid . ' (BODY.PEEK[TEXT]<0.' . $octet . '>)' . "\r\n");
				$lines = self::readFetchLinesUntilTagged($fp, $tag, $maxBytes + 2048);
				$raw = self::parseFetchBodyLiteral($lines);
				if ($raw === '') {
					$tag2 = 'C' . str_pad((string)(30 + $i), 3, '0', STR_PAD_LEFT);
					fwrite($fp, $tag2 . ' UID FETCH ' . $uid . ' (BODY.PEEK[1]<0.' . $octet . '>)' . "\r\n");
					$lines = self::readFetchLinesUntilTagged($fp, $tag2, $maxBytes + 2048);
					$raw = self::parseFetchBodyLiteral($lines);
				}
				if ($raw !== '') {
					if (preg_match('/<(?:html|body|div|p|br|table)\b/i', $raw) === 1) {
						$html = strlen($raw) > $htmlMax ? substr($raw, 0, $htmlMax) : $raw;
					}
					$plain = MailPreviewText::toPlainPreview($raw, $maxBytes);
				}
				// Multipart/alternative: part 2 often HTML when part 1 was plain.
				if ($html === '') {
					$tag3 = 'D' . str_pad((string)(30 + $i), 3, '0', STR_PAD_LEFT);
					fwrite($fp, $tag3 . ' UID FETCH ' . $uid . ' (BODY.PEEK[2]<0.' . min($htmlMax, $octet * 2) . '>)' . "\r\n");
					$lines = self::readFetchLinesUntilTagged($fp, $tag3, $htmlMax + 2048);
					$raw2 = self::parseFetchBodyLiteral($lines);
					if ($raw2 !== '' && preg_match('/<(?:html|body|div|p|br|table)\b/i', $raw2) === 1) {
						$html = strlen($raw2) > $htmlMax ? substr($raw2, 0, $htmlMax) : $raw2;
					}
				}
				if ($html === '') {
					$tag4 = 'E' . str_pad((string)(30 + $i), 3, '0', STR_PAD_LEFT);
					fwrite($fp, $tag4 . ' UID FETCH ' . $uid . ' (BODY.PEEK[1.2]<0.' . min($htmlMax, $octet * 2) . '>)' . "\r\n");
					$lines = self::readFetchLinesUntilTagged($fp, $tag4, $htmlMax + 2048);
					$raw12 = self::parseFetchBodyLiteral($lines);
					if ($raw12 !== '' && preg_match('/<(?:html|body|div|p|br|table)\b/i', $raw12) === 1) {
						$html = strlen($raw12) > $htmlMax ? substr($raw12, 0, $htmlMax) : $raw12;
					}
				}
				if ($plain !== '' || $html !== '') {
					$out[$uid] = ['plain' => $plain, 'html' => $html];
				}
			}
			fwrite($fp, "B099 LOGOUT\r\n");
			self::readUntilTagged($fp, 'B099');
		} finally {
			fclose($fp);
		}

		return $out;
	}

	/**
	 * Fetch RFC822 header fields for specific UIDs (one IMAP session).
	 *
	 * @param list<string> $uids
	 * @return array<string, array{from: string, to: list<string>, cc: list<string>, resentFrom: ?string, xOriginalFrom: ?string}>
	 */
	public static function fetchUidHeadersForUids(
		string $host,
		int $port,
		string $username,
		string $password,
		string $folder,
		array $uids,
		float $timeoutSeconds = 20.0,
	): array {
		$host = ImapConnection::normalizeHost($host);
		$user = ImapConnection::normalizeUsername($username);
		$folder = ImapConnection::folderToWire(trim($folder) !== '' ? trim($folder) : 'INBOX');
		$port = $port > 0 ? $port : 993;
		$cleanUids = [];
		foreach ($uids as $uid) {
			$u = trim((string)$uid);
			if ($u !== '' && preg_match('/^\d+$/', $u) === 1) {
				$cleanUids[$u] = true;
			}
		}
		$cleanUids = array_keys($cleanUids);
		if ($host === '' || $user === '' || $password === '' || $cleanUids === []) {
			return [];
		}

		$remote = 'ssl://' . $host . ':' . $port;
		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client(
			$remote,
			$errno,
			$errstr,
			max(1.0, $timeoutSeconds),
			STREAM_CLIENT_CONNECT,
		);
		if ($fp === false) {
			return [];
		}
		stream_set_timeout($fp, (int)max(1, (int)ceil($timeoutSeconds)));
		$out = [];
		try {
			$greeting = self::readLine($fp);
			if ($greeting === '' || !str_starts_with(strtoupper(ltrim($greeting)), '* OK')) {
				return [];
			}
			$tag = 'H001';
			fwrite($fp, $tag . ' LOGIN ' . self::quoteAtom($user) . ' ' . self::quoteAtom($password) . "\r\n");
			$response = self::readUntilTagged($fp, $tag);
			if (!str_contains(strtoupper($response), ' OK')) {
				return [];
			}
			$tag = 'H020';
			fwrite($fp, $tag . ' SELECT ' . self::quoteAtom($folder) . "\r\n");
			$selectLines = self::readLinesUntilTagged($fp, $tag);
			$selectOk = false;
			foreach ($selectLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$selectOk = true;
					break;
				}
			}
			if (!$selectOk) {
				return [];
			}
			$i = 0;
			foreach ($cleanUids as $uid) {
				$i++;
				$tag = 'H' . str_pad((string)(30 + $i), 3, '0', STR_PAD_LEFT);
				$cmd = $tag . ' UID FETCH ' . $uid
					. ' (UID BODY.PEEK[HEADER.FIELDS (FROM TO CC REPLY-TO RESENT-FROM X-ORIGINAL-FROM SUBJECT DATE MESSAGE-ID)])' . "\r\n";
				fwrite($fp, $cmd);
				$lines = self::readFetchLinesUntilTagged($fp, $tag);
				foreach (self::parseFetchHeaderResponses($lines) as $parsed) {
					$key = (string)($parsed['uid'] ?? $uid);
					$out[$key] = [
						'from' => (string)($parsed['from'] ?? ''),
						'to' => isset($parsed['to']) && is_array($parsed['to']) ? $parsed['to'] : [],
						'cc' => isset($parsed['cc']) && is_array($parsed['cc']) ? $parsed['cc'] : [],
						'replyTo' => isset($parsed['replyTo']) ? (string)$parsed['replyTo'] : null,
						'resentFrom' => isset($parsed['resentFrom']) ? (string)$parsed['resentFrom'] : null,
						'xOriginalFrom' => isset($parsed['xOriginalFrom']) ? (string)$parsed['xOriginalFrom'] : null,
					];
				}
			}
			fwrite($fp, "H099 LOGOUT\r\n");
			self::readUntilTagged($fp, 'H099');
		} finally {
			fclose($fp);
		}

		return $out;
	}

	/**
	 * Delete one message by UID (STORE \Deleted + EXPUNGE). Idempotent when UID is gone.
	 *
	 * @return array{ok: bool, reason: string, detail: string, alreadyGone: bool}
	 */
	public static function deleteUid(
		string $host,
		int $port,
		string $username,
		string $password,
		string $folder,
		string $uid,
		float $timeoutSeconds = 20.0,
	): array {
		$host = ImapConnection::normalizeHost($host);
		$user = ImapConnection::normalizeUsername($username);
		$folder = ImapConnection::folderToWire(trim($folder) !== '' ? trim($folder) : 'INBOX');
		$uid = trim($uid);
		$port = $port > 0 ? $port : 993;
		if ($host === '' || $user === '' || $password === '' || $uid === '' || !preg_match('/^\d+$/', $uid)) {
			return [
				'ok' => false,
				'reason' => 'unknown',
				'detail' => 'host, username, password and numeric uid are required',
				'alreadyGone' => false,
			];
		}
		if (!ImapConnection::isSafeImapFolder($folder)) {
			return [
				'ok' => false,
				'reason' => 'protocol',
				'detail' => 'unsafe folder',
				'alreadyGone' => false,
			];
		}

		$remote = 'ssl://' . $host . ':' . $port;
		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client(
			$remote,
			$errno,
			$errstr,
			max(1.0, $timeoutSeconds),
			STREAM_CLIENT_CONNECT,
		);
		if ($fp === false) {
			$detail = ImapConnection::sanitizeError($errstr !== '' ? $errstr : ('connect errno ' . $errno));

			return [
				'ok' => false,
				'reason' => 'network',
				'detail' => $detail !== '' ? $detail : 'TLS connect failed',
				'alreadyGone' => false,
			];
		}

		stream_set_timeout($fp, (int)max(1, (int)ceil($timeoutSeconds)));
		try {
			$greeting = self::readLine($fp);
			if ($greeting === '' || !str_starts_with(strtoupper(ltrim($greeting)), '* OK')) {
				return [
					'ok' => false,
					'reason' => 'protocol',
					'detail' => 'Unexpected IMAP greeting: ' . ImapConnection::sanitizeError($greeting),
					'alreadyGone' => false,
				];
			}

			$tag = 'D001';
			fwrite($fp, $tag . ' LOGIN ' . self::quoteAtom($user) . ' ' . self::quoteAtom($password) . "\r\n");
			$response = self::readUntilTagged($fp, $tag);
			$upper = strtoupper($response);
			if (str_contains($upper, 'LOGIN') && (str_contains($upper, 'DISABLED') || str_contains($upper, 'NOT SUPPORTED'))) {
				$tag = 'D010';
				$plain = base64_encode("\0" . $user . "\0" . $password);
				fwrite($fp, $tag . ' AUTHENTICATE PLAIN ' . $plain . "\r\n");
				$response = self::readUntilTagged($fp, $tag);
				$upper = strtoupper($response);
			}
			if (!str_contains($upper, $tag . ' OK')) {
				$sanitized = ImapConnection::sanitizeError($response);
				$reason = ImapConnection::classifyError($sanitized);
				if ($reason === 'unknown') {
					$reason = 'auth';
				}

				return [
					'ok' => false,
					'reason' => $reason === 'network' ? 'auth' : $reason,
					'detail' => $sanitized,
					'alreadyGone' => false,
				];
			}

			$tag = 'D020';
			fwrite($fp, $tag . ' SELECT ' . self::quoteAtom($folder) . "\r\n");
			$selectLines = self::readLinesUntilTagged($fp, $tag);
			$selectOk = false;
			foreach ($selectLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$selectOk = true;
					break;
				}
			}
			if (!$selectOk) {
				return [
					'ok' => false,
					'reason' => 'protocol',
					'detail' => 'SELECT failed for folder ' . ImapConnection::sanitizeError($folder),
					'alreadyGone' => false,
				];
			}

			// Probe existence — missing UID is success (already deleted).
			$tag = 'D030';
			fwrite($fp, $tag . ' UID FETCH ' . $uid . " (UID)\r\n");
			$fetchLines = self::readLinesUntilTagged($fp, $tag);
			$seen = false;
			foreach ($fetchLines as $line) {
				if (preg_match('/\bUID\s+' . preg_quote($uid, '/') . '\b/i', $line) === 1) {
					$seen = true;
					break;
				}
			}
			$fetchOk = false;
			foreach ($fetchLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$fetchOk = true;
					break;
				}
			}
			if ($fetchOk && !$seen) {
				fwrite($fp, "D099 LOGOUT\r\n");
				self::readUntilTagged($fp, 'D099');

				return [
					'ok' => true,
					'reason' => 'ok',
					'detail' => '',
					'alreadyGone' => true,
				];
			}

			$tag = 'D040';
			fwrite($fp, $tag . ' UID STORE ' . $uid . " +FLAGS.SILENT (\\Deleted)\r\n");
			$storeLines = self::readLinesUntilTagged($fp, $tag);
			$storeOk = false;
			foreach ($storeLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$storeOk = true;
					break;
				}
			}
			if (!$storeOk) {
				return [
					'ok' => false,
					'reason' => 'protocol',
					'detail' => 'UID STORE \\Deleted failed for uid ' . $uid,
					'alreadyGone' => false,
				];
			}

			$tag = 'D050';
			fwrite($fp, $tag . " EXPUNGE\r\n");
			$expungeLines = self::readLinesUntilTagged($fp, $tag);
			$expungeOk = false;
			foreach ($expungeLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$expungeOk = true;
					break;
				}
			}
			if (!$expungeOk) {
				return [
					'ok' => false,
					'reason' => 'protocol',
					'detail' => 'EXPUNGE failed after marking deleted',
					'alreadyGone' => false,
				];
			}

			fwrite($fp, "D099 LOGOUT\r\n");
			self::readUntilTagged($fp, 'D099');

			return [
				'ok' => true,
				'reason' => 'ok',
				'detail' => '',
				'alreadyGone' => false,
			];
		} finally {
			fclose($fp);
		}
	}

	/**
	 * Fetch a capped plain-text preview for one UID (BODY.PEEK[TEXT], then BODY.PEEK[1]).
	 *
	 * @return array{ok: bool, reason: string, detail: string, text: string, truncated: bool}
	 */
	public static function fetchUidTextPreview(
		string $host,
		int $port,
		string $username,
		string $password,
		string $folder,
		string $uid,
		int $maxBytes = self::MAIL_PREVIEW_MAX_BYTES,
		float $timeoutSeconds = 20.0,
		string $authMode = 'password',
	): array {
		$host = ImapConnection::normalizeHost($host);
		$user = ImapConnection::normalizeUsername($username);
		$folder = ImapConnection::folderToWire(trim($folder) !== '' ? trim($folder) : 'INBOX');
		$uid = trim($uid);
		$port = $port > 0 ? $port : 993;
		// Allow full-body peeks for MH-05 open/preview backfill (still hard-capped).
		$maxBytes = max(512, min(self::MAIL_BODY_PLAIN_MAX_BYTES, $maxBytes));
		$authMode = strtolower(trim($authMode)) === 'xoauth2' ? 'xoauth2' : 'password';
		if ($host === '' || $user === '' || $password === '' || $uid === '' || !preg_match('/^\d+$/', $uid)) {
			return [
				'ok' => false,
				'reason' => 'unknown',
				'detail' => 'host, username, password and numeric uid are required',
				'text' => '',
				'truncated' => false,
			];
		}

		$remote = 'ssl://' . $host . ':' . $port;
		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client(
			$remote,
			$errno,
			$errstr,
			max(1.0, $timeoutSeconds),
			STREAM_CLIENT_CONNECT,
		);
		if ($fp === false) {
			$detail = ImapConnection::sanitizeError($errstr !== '' ? $errstr : ('connect errno ' . $errno));

			return [
				'ok' => false,
				'reason' => 'network',
				'detail' => $detail !== '' ? $detail : 'TLS connect failed',
				'text' => '',
				'truncated' => false,
			];
		}

		stream_set_timeout($fp, (int)max(1, (int)ceil($timeoutSeconds)));
		try {
			$greeting = self::readLine($fp);
			if ($greeting === '' || !str_starts_with(strtoupper(ltrim($greeting)), '* OK')) {
				return [
					'ok' => false,
					'reason' => 'protocol',
					'detail' => 'Unexpected IMAP greeting: ' . ImapConnection::sanitizeError($greeting),
					'text' => '',
					'truncated' => false,
				];
			}

			$auth = self::authenticateAfterGreeting($fp, $user, $password, $authMode);
			if (!$auth['ok']) {
				return [
					'ok' => false,
					'reason' => $auth['reason'] !== '' ? $auth['reason'] : 'auth',
					'detail' => $auth['detail'],
					'text' => '',
					'truncated' => false,
				];
			}

			$tag = 'P020';
			fwrite($fp, $tag . ' SELECT ' . self::quoteAtom($folder) . "\r\n");
			$selectLines = self::readLinesUntilTagged($fp, $tag);
			$selectOk = false;
			foreach ($selectLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$selectOk = true;
					break;
				}
			}
			if (!$selectOk) {
				return [
					'ok' => false,
					'reason' => 'protocol',
					'detail' => 'SELECT failed for folder ' . ImapConnection::sanitizeError($folder),
					'text' => '',
					'truncated' => false,
				];
			}

			$text = '';
			foreach (['BODY.PEEK[TEXT]', 'BODY.PEEK[1]'] as $section) {
				$tag = $section === 'BODY.PEEK[TEXT]' ? 'P030' : 'P031';
				fwrite($fp, $tag . ' UID FETCH ' . $uid . ' (' . $section . ")\r\n");
				$fetchLines = self::readFetchLinesUntilTagged($fp, $tag, $maxBytes + 2048);
				$candidate = self::parseFetchBodyLiteral($fetchLines);
				if (trim($candidate) !== '') {
					$text = $candidate;
					break;
				}
			}

			fwrite($fp, "P099 LOGOUT\r\n");
			self::readUntilTagged($fp, 'P099');

			$plain = MailPreviewText::toPlainPreview($text, $maxBytes);
			$truncated = strlen($text) > $maxBytes || str_ends_with($plain, '…');

			return [
				'ok' => true,
				'reason' => 'ok',
				'detail' => '',
				'text' => $plain,
				'truncated' => $truncated && $plain !== '',
			];
		} finally {
			fclose($fp);
		}
	}

	/**
	 * Extract the first IMAP FETCH body literal from wire lines.
	 *
	 * @param list<string> $lines
	 */
	public static function parseFetchBodyLiteral(array $lines): string
	{
		$n = count($lines);
		for ($i = 0; $i < $n; $i++) {
			$line = (string)$lines[$i];
			if (preg_match('/\{(\d+)\}\s*$/', $line) === 1 && isset($lines[$i + 1])) {
				return (string)$lines[$i + 1];
			}
			// Inline quoted body (rare): BODY[TEXT] "hello"
			if (preg_match('/BODY(?:\.PEEK)?\[[^\]]*\]\s+"((?:\\\\.|[^"\\\\])*)"/i', $line, $m) === 1) {
				return stripcslashes($m[1]);
			}
		}

		return '';
	}

	/**
	 * @param resource $fp
	 * @return list<string>
	 */
	public static function readLinesUntilTagged($fp, string $tag): array
	{
		$lines = [];
		$deadline = microtime(true) + 20.0;
		while (microtime(true) < $deadline) {
			$line = self::readLine($fp);
			if ($line === '') {
				$meta = stream_get_meta_data($fp);
				if (!empty($meta['timed_out']) || feof($fp)) {
					break;
				}
				continue;
			}
			$lines[] = $line;
			if (str_starts_with($line, $tag . ' ')) {
				break;
			}
		}

		return $lines;
	}

	/**
	 * Read FETCH responses including IMAP literals ({n} then n bytes).
	 *
	 * @param resource $fp
	 * @return list<string>
	 */
	public static function readFetchLinesUntilTagged($fp, string $tag, int $maxLiteralBytes = 0): array
	{
		$lines = [];
		$deadline = microtime(true) + 30.0;
		while (microtime(true) < $deadline) {
			$line = self::readLine($fp);
			if ($line === '') {
				$meta = stream_get_meta_data($fp);
				if (!empty($meta['timed_out']) || feof($fp)) {
					break;
				}
				continue;
			}
			if (preg_match('/\{(\d+)\}\s*$/', $line, $m) === 1) {
				$len = (int)$m[1];
				$readCap = $len;
				$skipTail = 0;
				if ($maxLiteralBytes > 0 && $len > $maxLiteralBytes) {
					$readCap = $maxLiteralBytes;
					$skipTail = $len - $maxLiteralBytes;
				}
				$literal = '';
				while (strlen($literal) < $readCap && microtime(true) < $deadline) {
					$chunk = fread($fp, $readCap - strlen($literal));
					if ($chunk === false || $chunk === '') {
						$meta = stream_get_meta_data($fp);
						if (!empty($meta['timed_out']) || feof($fp)) {
							break;
						}
						continue;
					}
					$literal .= $chunk;
				}
				while ($skipTail > 0 && microtime(true) < $deadline) {
					$chunk = fread($fp, min(8192, $skipTail));
					if ($chunk === false || $chunk === '') {
						$meta = stream_get_meta_data($fp);
						if (!empty($meta['timed_out']) || feof($fp)) {
							break;
						}
						continue;
					}
					$skipTail -= strlen($chunk);
				}
				$lines[] = $line;
				// Keep literal as one entry so parseFetchHeaderResponses can take it whole.
				$lines[] = $literal;
				continue;
			}
			$lines[] = $line;
			if (str_starts_with($line, $tag . ' ')) {
				break;
			}
		}

		return $lines;
	}

	/**
	 * Parse IMAP LIST untagged lines into UTF-8 folder paths (pure — unit tested).
	 * Skips \\Noselect parents by default (cannot SELECT / sync).
	 *
	 * @param list<string> $lines
	 * @return list<string>
	 */
	public static function parseListLines(array $lines, int $max = 0, bool $selectableOnly = true): array
	{
		if ($max <= 0) {
			$max = self::MAILBOX_LIST_FOLDERS_MAX;
		}
		$out = [];
		foreach (self::parseListEntries($lines, $max, $selectableOnly) as $entry) {
			$out[] = $entry['name'];
		}

		return $out;
	}

	/**
	 * Parse IMAP LIST lines into UTF-8 name + flags (SPECIAL-USE aware).
	 * Names are Normalized from Modified UTF-7 so umlauts are human-readable.
	 *
	 * @param list<string> $lines
	 * @return list<array{name: string, flags: list<string>, selectable: bool, delimiter: string}>
	 */
	public static function parseListEntries(array $lines, int $max = 50, bool $selectableOnly = false): array
	{
		$out = [];
		$seen = [];
		foreach ($lines as $line) {
			$line = trim((string)$line);
			if ($line === '' || !preg_match('/^\* LIST\b/i', $line)) {
				continue;
			}
			// * LIST (\HasNoChildren \Junk) "/" Junk
			// * LIST () "/" "Sent Items"
			// * LIST (\HasNoChildren) "/" "INBOX/&AOQ-rger"
			if (preg_match('/^\* LIST\s+\(([^)]*)\)\s+(\S+)\s+(.+)$/i', $line, $m) !== 1) {
				continue;
			}
			$flagRaw = trim($m[1]);
			$flags = $flagRaw === '' ? [] : (preg_split('/\s+/', $flagRaw) ?: []);
			$flags = array_values(array_filter(array_map('strval', $flags), static fn (string $f): bool => $f !== ''));
			$delimiterRaw = trim($m[2]);
			if ($delimiterRaw !== '' && $delimiterRaw[0] === '"' && str_ends_with($delimiterRaw, '"')) {
				$delimiterRaw = stripcslashes(substr($delimiterRaw, 1, -1));
			}
			$delimiter = $delimiterRaw !== '' ? $delimiterRaw : '/';
			$name = trim($m[3]);
			if ($name !== '' && $name[0] === '"' && str_ends_with($name, '"')) {
				$name = stripcslashes(substr($name, 1, -1));
			}
			$name = ImapConnection::normalizeFolderPath($name);
			if ($name === '' || !ImapConnection::isSafeImapFolder($name)) {
				continue;
			}
			$selectable = true;
			foreach ($flags as $flag) {
				if (strcasecmp($flag, '\\Noselect') === 0 || strcasecmp($flag, '\\Nonexistent') === 0) {
					$selectable = false;
					break;
				}
			}
			if ($selectableOnly && !$selectable) {
				continue;
			}
			$key = ImapUtf7::folderKey($name);
			if ($key === '' || isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$out[] = [
				'name' => $name,
				'flags' => $flags,
				'selectable' => $selectable,
				'delimiter' => $delimiter,
			];
			if (count($out) >= $max) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Move one UID into the server Junk/Spam folder (LIST discover + UID MOVE, else COPY+delete).
	 *
	 * @return array{ok: bool, reason: string, detail: string, junkFolder: string, alreadyGone: bool}
	 */
	public static function moveUidToJunk(
		string $host,
		int $port,
		string $username,
		string $password,
		string $folder,
		string $uid,
		float $timeoutSeconds = 25.0,
	): array {
		$host = ImapConnection::normalizeHost($host);
		$user = ImapConnection::normalizeUsername($username);
		$folder = ImapConnection::folderToWire(trim($folder) !== '' ? trim($folder) : 'INBOX');
		$uid = trim($uid);
		$port = $port > 0 ? $port : 993;
		$empty = static fn (string $reason, string $detail, bool $gone = false): array => [
			'ok' => false,
			'reason' => $reason,
			'detail' => $detail,
			'junkFolder' => '',
			'alreadyGone' => $gone,
		];
		if ($host === '' || $user === '' || $password === '' || $uid === '' || !preg_match('/^\d+$/', $uid)) {
			return $empty('unknown', 'host, username, password and numeric uid are required');
		}
		if (!ImapConnection::isSafeImapFolder($folder)) {
			return $empty('protocol', 'unsafe source folder');
		}

		$remote = 'ssl://' . $host . ':' . $port;
		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client(
			$remote,
			$errno,
			$errstr,
			max(1.0, $timeoutSeconds),
			STREAM_CLIENT_CONNECT,
		);
		if ($fp === false) {
			$detail = ImapConnection::sanitizeError($errstr !== '' ? $errstr : ('connect errno ' . $errno));

			return $empty('network', $detail !== '' ? $detail : 'TLS connect failed');
		}

		stream_set_timeout($fp, (int)max(1, (int)ceil($timeoutSeconds)));
		try {
			$greeting = self::readLine($fp);
			if ($greeting === '' || !str_starts_with(strtoupper(ltrim($greeting)), '* OK')) {
				return $empty('protocol', 'Unexpected IMAP greeting: ' . ImapConnection::sanitizeError($greeting));
			}

			$tag = 'J001';
			fwrite($fp, $tag . ' LOGIN ' . self::quoteAtom($user) . ' ' . self::quoteAtom($password) . "\r\n");
			$response = self::readUntilTagged($fp, $tag);
			$upper = strtoupper($response);
			if (str_contains($upper, 'LOGIN') && (str_contains($upper, 'DISABLED') || str_contains($upper, 'NOT SUPPORTED'))) {
				$tag = 'J010';
				$plain = base64_encode("\0" . $user . "\0" . $password);
				fwrite($fp, $tag . ' AUTHENTICATE PLAIN ' . $plain . "\r\n");
				$response = self::readUntilTagged($fp, $tag);
				$upper = strtoupper($response);
			}
			if (!str_contains($upper, $tag . ' OK')) {
				$sanitized = ImapConnection::sanitizeError($response);
				$reason = ImapConnection::classifyError($sanitized);
				if ($reason === 'unknown') {
					$reason = 'auth';
				}

				return $empty($reason === 'network' ? 'auth' : $reason, $sanitized);
			}

			$entries = self::parseListEntries(self::listFolderLinesAfterLogin($fp), 80);
			$junk = ImapConnection::pickJunkFolder($entries);
			if ($junk === null || !ImapConnection::isSafeImapFolder($junk)) {
				return $empty('protocol', 'No Junk/Spam folder on this mailbox');
			}
			if (strcasecmp($junk, $folder) === 0) {
				fwrite($fp, "J099 LOGOUT\r\n");
				self::readUntilTagged($fp, 'J099');

				return [
					'ok' => true,
					'reason' => 'ok',
					'detail' => '',
					'junkFolder' => $junk,
					'alreadyGone' => false,
				];
			}

			$tag = 'J020';
			fwrite($fp, $tag . ' SELECT ' . self::quoteAtom($folder) . "\r\n");
			$selectLines = self::readLinesUntilTagged($fp, $tag);
			$selectOk = false;
			foreach ($selectLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$selectOk = true;
					break;
				}
			}
			if (!$selectOk) {
				return $empty('protocol', 'SELECT failed for folder ' . ImapConnection::sanitizeError($folder));
			}

			$tag = 'J030';
			fwrite($fp, $tag . ' UID FETCH ' . $uid . " (UID)\r\n");
			$fetchLines = self::readLinesUntilTagged($fp, $tag);
			$seen = false;
			foreach ($fetchLines as $line) {
				if (preg_match('/\bUID\s+' . preg_quote($uid, '/') . '\b/i', $line) === 1) {
					$seen = true;
					break;
				}
			}
			$fetchOk = false;
			foreach ($fetchLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$fetchOk = true;
					break;
				}
			}
			if ($fetchOk && !$seen) {
				fwrite($fp, "J099 LOGOUT\r\n");
				self::readUntilTagged($fp, 'J099');

				return [
					'ok' => true,
					'reason' => 'ok',
					'detail' => '',
					'junkFolder' => $junk,
					'alreadyGone' => true,
				];
			}

			$tag = 'J040';
			fwrite($fp, $tag . ' UID MOVE ' . $uid . ' ' . self::quoteAtom($junk) . "\r\n");
			$moveLines = self::readLinesUntilTagged($fp, $tag);
			$moveOk = false;
			foreach ($moveLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$moveOk = true;
					break;
				}
			}
			if (!$moveOk) {
				$tag = 'J050';
				fwrite($fp, $tag . ' UID COPY ' . $uid . ' ' . self::quoteAtom($junk) . "\r\n");
				$copyLines = self::readLinesUntilTagged($fp, $tag);
				$copyOk = false;
				foreach ($copyLines as $line) {
					if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
						$copyOk = true;
						break;
					}
				}
				if (!$copyOk) {
					return $empty('protocol', 'UID MOVE/COPY to Junk failed for uid ' . $uid);
				}
				$tag = 'J060';
				fwrite($fp, $tag . ' UID STORE ' . $uid . " +FLAGS.SILENT (\\Deleted)\r\n");
				$storeLines = self::readLinesUntilTagged($fp, $tag);
				$storeOk = false;
				foreach ($storeLines as $line) {
					if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
						$storeOk = true;
						break;
					}
				}
				if (!$storeOk) {
					return $empty('protocol', 'UID STORE \\Deleted failed after COPY for uid ' . $uid);
				}
				$tag = 'J070';
				fwrite($fp, $tag . " EXPUNGE\r\n");
				$expungeLines = self::readLinesUntilTagged($fp, $tag);
				$expungeOk = false;
				foreach ($expungeLines as $line) {
					if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
						$expungeOk = true;
						break;
					}
				}
				if (!$expungeOk) {
					return $empty('protocol', 'EXPUNGE failed after COPY to Junk');
				}
			}

			fwrite($fp, "J099 LOGOUT\r\n");
			self::readUntilTagged($fp, 'J099');

			return [
				'ok' => true,
				'reason' => 'ok',
				'detail' => '',
				'junkFolder' => $junk,
				'alreadyGone' => false,
			];
		} finally {
			fclose($fp);
		}
	}

	/**
	 * @param resource $fp
	 * @return list<string>
	 */
	private static function listFolderLinesAfterLogin($fp): array
	{
		$tag = 'A050';
		fwrite($fp, $tag . " LIST \"\" \"*\"\r\n");
		$lines = [];
		$deadline = microtime(true) + 12.0;
		while (microtime(true) < $deadline) {
			$line = self::readLine($fp);
			if ($line === '') {
				$meta = stream_get_meta_data($fp);
				if (!empty($meta['timed_out']) || feof($fp)) {
					break;
				}
				continue;
			}
			$lines[] = $line;
			if (str_starts_with($line, $tag . ' ')) {
				break;
			}
		}

		return $lines;
	}

	/**
	 * @param resource $fp
	 * @return list<string>
	 */
	private static function listFoldersAfterLogin($fp): array
	{
		$folders = self::parseListLines(
			self::listFolderLinesAfterLogin($fp),
			self::MAILBOX_LIST_FOLDERS_MAX,
			true,
		);
		return $folders !== [] ? $folders : ['INBOX'];
	}

	/** @param resource $fp */
	public static function readLine($fp): string
	{
		$line = fgets($fp, 8192);
		return is_string($line) ? rtrim($line, "\r\n") : '';
	}

	/** @param resource $fp */
	public static function readUntilTagged($fp, string $tag): string
	{
		$buf = [];
		$deadline = microtime(true) + 12.0;
		while (microtime(true) < $deadline) {
			$line = self::readLine($fp);
			if ($line === '') {
				$meta = stream_get_meta_data($fp);
				if (!empty($meta['timed_out']) || feof($fp)) {
					break;
				}
				continue;
			}
			$buf[] = $line;
			if (str_starts_with($line, $tag . ' ')) {
				break;
			}
		}
		return implode(' ', $buf);
	}

	/**
	 * IMAP quoted string — escapes " and \. Never used for logging.
	 */
	public static function quoteAtom(string $value): string
	{
		return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
	}
}

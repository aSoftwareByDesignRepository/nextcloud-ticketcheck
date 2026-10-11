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
	private const MAILBOX_LIST_FOLDERS_MAX = 200;

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
					$chunk = fread($fp, max(1, $readCap - strlen($literal)));
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

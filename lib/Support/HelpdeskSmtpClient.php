<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Support;

use OCA\Ticketcheck\Exception\ValidationException;

/**
 * Minimal SMTP client for shared-mailbox send (product §14.3).
 *
 * Speaks EHLO + STARTTLS/SMTPS + AUTH LOGIN + DATA. Supports plain text and
 * multipart/mixed Files attachments (PDF/PNG/JPEG/TXT). Failures throw
 * ValidationException so drafts are kept.
 */
final class HelpdeskSmtpClient
{
	/**
	 * Fail closed before any network I/O — empty username/password must never
	 * reach AUTH LOGIN (product §14.3).
	 *
	 * @param array<string, mixed> $cfg
	 */
	public static function assertConfig(array $cfg): void
	{
		$host = trim((string)($cfg['host'] ?? ''));
		$user = (string)($cfg['username'] ?? '');
		$pass = (string)($cfg['password'] ?? '');
		$from = (string)($cfg['from'] ?? '');
		$to = (string)($cfg['to'] ?? '');
		$authMode = strtolower(trim((string)($cfg['authMode'] ?? 'password')));
		if ($host === '' || $from === '' || $to === '' || $user === '') {
			throw new ValidationException(['smtp' => 'SMTP configuration incomplete.']);
		}
		if ($pass === '' && $authMode !== 'xoauth2') {
			throw new ValidationException(['smtp' => 'SMTP configuration incomplete.']);
		}
		if ($authMode === 'xoauth2' && $pass === '') {
			throw new ValidationException(['smtp' => 'SMTP configuration incomplete.']);
		}
	}

	/**
	 * Build the DATA payload (headers + body). Pure — used by unit tests.
	 *
	 * Spec MH-19: always multipart/alternative when HTML is present (never HTML-only).
	 * With attachments: multipart/mixed wrapping alternative + parts.
	 *
	 * @param array<string, mixed> $cfg caller-supplied config — keys are
	 *   cast/normalized defensively below (from, to, subject, body required;
	 *   fromName, bodyHtml, messageId, replyTo, cc, attachments optional).
	 */
	public static function buildDataPayload(array $cfg): string
	{
		$from = (string)($cfg['from'] ?? '');
		$to = (string)($cfg['to'] ?? '');
		$subject = (string)($cfg['subject'] ?? '');
		$body = (string)($cfg['body'] ?? '');
		$bodyHtml = trim((string)($cfg['bodyHtml'] ?? ''));
		$fromName = (string)($cfg['fromName'] ?? $from);
		/** @var list<array{filename?: string, mime?: string, data?: string}> $attachments */
		$attachments = isset($cfg['attachments']) && is_array($cfg['attachments'])
			? $cfg['attachments']
			: [];

		$headers = [
			'From: ' . self::encodeAddress($fromName, $from),
			'To: ' . self::encodeAddress($to, $to),
			'Subject: ' . self::encodeHeader($subject),
			'MIME-Version: 1.0',
			'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
		];
		$messageId = self::normalizeMessageId((string)($cfg['messageId'] ?? ''));
		if ($messageId === '') {
			$messageId = self::generateMessageId($from);
		}
		$headers[] = 'Message-ID: ' . $messageId;
		// Stable thread anchor (NH-04): every ticket-N mail hangs under the
		// same virtual root so customer MUAs thread the whole conversation —
		// and inbound replies can route via References even when +tags or
		// subjects are mangled in transit.
		$references = self::normalizeMessageId((string)($cfg['references'] ?? ''));
		if ($references !== '') {
			$headers[] = 'References: ' . $references;
		}
		$replyTo = self::addr((string)($cfg['replyTo'] ?? ''));
		if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
			$headers[] = 'Reply-To: <' . $replyTo . '>';
		}
		// Loop prevention (spec MH-19): every automated outbound carries these
		// so a vacation responder can't start a ticket ping-pong.
		$headers[] = 'Auto-Submitted: auto-generated';
		$headers[] = 'X-Auto-Response-Suppress: All';
		$ccRaw = $cfg['cc'] ?? [];
		if (is_array($ccRaw) && $ccRaw !== []) {
			$ccHeaders = [];
			foreach ($ccRaw as $ccAddr) {
				$ccAddr = trim((string)$ccAddr);
				if ($ccAddr !== '') {
					$ccHeaders[] = self::encodeAddress($ccAddr, $ccAddr);
				}
			}
			if ($ccHeaders !== []) {
				$headers[] = 'Cc: ' . implode(', ', $ccHeaders);
			}
		}

		$bodyPart = self::buildBodyPart($body, $bodyHtml);

		if ($attachments === []) {
			if ($bodyHtml === '') {
				$headers[] = 'Content-Type: text/plain; charset=UTF-8';
				$headers[] = 'Content-Transfer-Encoding: 8bit';
				return implode("\r\n", $headers) . "\r\n\r\n"
					. self::dotStuff($body) . "\r\n.";
			}
			$boundary = 'crm_alt_' . bin2hex(random_bytes(10));
			$headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
			$parts = self::wrapAlternative($boundary, $body, $bodyHtml);

			return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts) . "\r\n.";
		}

		$boundary = 'crm_' . bin2hex(random_bytes(12));
		$headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

		$parts = [];
		$parts[] = '--' . $boundary;
		if ($bodyHtml !== '') {
			$altBoundary = 'crm_alt_' . bin2hex(random_bytes(8));
			$parts[] = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';
			$parts[] = '';
			foreach (self::wrapAlternative($altBoundary, $body, $bodyHtml) as $line) {
				$parts[] = $line;
			}
		} else {
			$parts[] = 'Content-Type: text/plain; charset=UTF-8';
			$parts[] = 'Content-Transfer-Encoding: 8bit';
			$parts[] = '';
			$parts[] = self::dotStuff($body);
		}
		unset($bodyPart);

		foreach ($attachments as $att) {
			$filename = self::safeAttachName((string)($att['filename'] ?? 'attachment.bin'));
			$mime = self::safeMime((string)($att['mime'] ?? 'application/octet-stream'));
			$data = (string)($att['data'] ?? '');
			$parts[] = '--' . $boundary;
			$parts[] = 'Content-Type: ' . $mime . '; name="' . self::asciiFilename($filename) . '"';
			$parts[] = 'Content-Transfer-Encoding: base64';
			$parts[] = 'Content-Disposition: attachment; filename="' . self::asciiFilename($filename) . '"';
			$parts[] = '';
			$parts[] = trim(chunk_split(base64_encode($data), 76, "\r\n"));
		}
		$parts[] = '--' . $boundary . '--';

		return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts) . "\r\n.";
	}

	/**
	 * @return list<string>
	 */
	private static function wrapAlternative(string $boundary, string $plain, string $html): array
	{
		$parts = [];
		$parts[] = '--' . $boundary;
		$parts[] = 'Content-Type: text/plain; charset=UTF-8';
		$parts[] = 'Content-Transfer-Encoding: 8bit';
		$parts[] = '';
		$parts[] = self::dotStuff($plain);
		$parts[] = '--' . $boundary;
		$parts[] = 'Content-Type: text/html; charset=UTF-8';
		$parts[] = 'Content-Transfer-Encoding: 8bit';
		$parts[] = '';
		$parts[] = self::dotStuff($html);
		$parts[] = '--' . $boundary . '--';

		return $parts;
	}

	private static function buildBodyPart(string $plain, string $html): string
	{
		return $html !== '' ? $html : $plain;
	}

	/**
	 * @param array<string, mixed> $cfg
	 */
	public static function send(array $cfg): void
	{
		$fp = self::openSession($cfg);

		try {
			self::cmd($fp, 'MAIL FROM:<' . self::addr((string)$cfg['from']) . '>', [250]);
			self::cmd($fp, 'RCPT TO:<' . self::addr((string)$cfg['to']) . '>', [250, 251]);
			$ccRaw = $cfg['cc'] ?? [];
			$ccList = is_array($ccRaw) ? $ccRaw : [];
			foreach ($ccList as $ccAddr) {
				$ccAddr = trim((string)$ccAddr);
				if ($ccAddr === '') {
					continue;
				}
				self::cmd($fp, 'RCPT TO:<' . self::addr($ccAddr) . '>', [250, 251]);
			}
			self::cmd($fp, 'DATA', [354]);

			fwrite($fp, self::buildDataPayload($cfg) . "\r\n");
			self::expect($fp, [250]);
			self::cmd($fp, 'QUIT', [221, 250]);
		} finally {
			fclose($fp);
		}
	}

	/**
	 * Probe only: greeting → EHLO → STARTTLS → AUTH → QUIT. No MAIL/DATA, so
	 * the settings test can verify connectivity + credentials without sending
	 * a mail. Shares the exact handshake path with send() — a divergent probe
	 * would green-light configs that real sends then fail on.
	 *
	 * @param array<string, mixed> $cfg
	 */
	public static function probe(array $cfg): void
	{
		$fp = self::openSession($cfg);
		try {
			self::cmd($fp, 'QUIT', [221, 250]);
		} finally {
			fclose($fp);
		}
	}

	/**
	 * Connect + greet + EHLO (+ STARTTLS) + AUTH. Returns the open socket.
	 * AUTH failures are sanitized — a server echoing challenge data must
	 * never put credential bytes into an exception message.
	 *
	 * @param array<string, mixed> $cfg
	 * @return resource
	 */
	private static function openSession(array $cfg)
	{
		self::assertConfig($cfg);

		$host = ImapConnection::normalizeHost((string)($cfg['host'] ?? ''));
		if (empty($cfg['allowPrivateHost']) && ImapConnection::isBlockedMailHost($host)) {
			throw new ValidationException(['smtp' => 'Use a public mail host — localhost and private network addresses are not allowed.']);
		}
		$port = (int)($cfg['port'] ?? 587);
		$enc = strtolower(trim((string)($cfg['enc'] ?? 'tls')));
		$user = (string)($cfg['username'] ?? '');
		$pass = (string)($cfg['password'] ?? '');
		$authMode = strtolower(trim((string)($cfg['authMode'] ?? 'password')));

		// TLS peer verification is ON by default (MH-19); opting out requires the
		// explicit allowSelfSignedCert flag — never silently downgrade.
		$verify = empty($cfg['allowSelfSignedCert']);
		$ctx = stream_context_create([
			'ssl' => [
				'verify_peer' => $verify,
				'verify_peer_name' => $verify,
				'allow_self_signed' => !$verify,
			],
		]);
		$remote = ($enc === 'ssl' || $enc === 'smtps' ? 'ssl://' : '') . $host . ':' . ($port > 0 ? $port : ($enc === 'ssl' ? 465 : 587));
		$fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
		if ($fp === false) {
			throw new ValidationException(['smtp' => 'SMTP connect failed: ' . $errstr]);
		}
		// DNS-rebinding TOCTOU: verify the address actually dialed.
		if (ImapConnection::dialedPeerIpOrNull($fp, !empty($cfg['allowPrivateHost'])) === null) {
			fclose($fp);
			throw new ValidationException(['smtp' => 'Use a public mail host — localhost and private network addresses are not allowed.']);
		}
		stream_set_timeout($fp, 20);

		try {
			self::expect($fp, [220]);
			self::cmd($fp, 'EHLO ticketcheck.local', [250]);
			if ($enc === 'tls' || $enc === 'starttls') {
				self::cmd($fp, 'STARTTLS', [220]);
				if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
					throw new ValidationException(['smtp' => 'STARTTLS negotiation failed.']);
				}
				self::cmd($fp, 'EHLO ticketcheck.local', [250]);
			}
			try {
				if ($authMode === 'xoauth2') {
					$sasl = \OCA\Ticketcheck\Service\InboundMailAuthService::xoauth2Sasl($user, $pass);
					self::cmd($fp, 'AUTH XOAUTH2 ' . $sasl, [235]);
				} else {
					self::cmd($fp, 'AUTH LOGIN', [334]);
					self::cmd($fp, base64_encode($user), [334]);
					self::cmd($fp, base64_encode($pass), [235]);
				}
			} catch (ValidationException) {
				throw new ValidationException(['smtp' => 'SMTP authentication rejected.']);
			}
		} catch (\Throwable $e) {
			fclose($fp);
			throw $e;
		}

		return $fp;
	}

	/**
	 * @param resource $fp
	 * @param list<int> $ok
	 */
	private static function cmd($fp, string $line, array $ok): void
	{
		fwrite($fp, $line . "\r\n");
		self::expect($fp, $ok);
	}

	/**
	 * @param resource $fp
	 * @param list<int> $ok
	 */
	private static function expect($fp, array $ok): void
	{
		$resp = '';
		while (($line = fgets($fp, 515)) !== false) {
			$resp .= $line;
			if (isset($line[3]) && $line[3] === ' ') {
				break;
			}
		}
		$code = (int)substr($resp, 0, 3);
		if (!in_array($code, $ok, true)) {
			throw new ValidationException(['smtp' => 'SMTP rejected: ' . trim($resp)]);
		}
	}

	private static function addr(string $email): string
	{
		return preg_replace('/[<>\r\n]/', '', $email) ?? $email;
	}

	private static function encodeAddress(string $name, string $email): string
	{
		$email = self::addr($email);
		$name = trim(str_replace(["\r", "\n"], '', $name));
		if ($name === '' || $name === $email) {
			return '<' . $email . '>';
		}

		return '"' . addcslashes($name, '"\\') . '" <' . $email . '>';
	}

	private static function encodeHeader(string $value): string
	{
		$value = str_replace(["\r", "\n"], '', $value);
		if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
			return $value;
		}

		return '=?UTF-8?B?' . base64_encode($value) . '?=';
	}

	private static function dotStuff(string $body): string
	{
		// Single pass: a str_replace pair would re-match inside its own output
		// (\r\n. -> \r\n.. then \n. inside it -> \r\n...) and corrupt the body.
		return preg_replace('/^\./m', '..', $body) ?? $body;
	}

	private static function safeAttachName(string $name): string
	{
		$name = str_replace(["\r", "\n", "\0", '"'], '', $name);
		$name = trim($name);
		if ($name === '' || $name === '.' || $name === '..') {
			return 'attachment.bin';
		}

		return mb_substr($name, 0, 180);
	}

	private static function asciiFilename(string $name): string
	{
		$safe = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'attachment.bin';
		$safe = str_replace(['\\', '"'], '_', $safe);
		$safe = trim($safe);
		return $safe !== '' ? $safe : 'attachment.bin';
	}

	private static function safeMime(string $mime): string
	{
		$mime = strtolower(trim(str_replace(["\r", "\n", ';'], '', $mime)));
		if ($mime === '' || !preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/', $mime)) {
			return 'application/octet-stream';
		}

		return $mime;
	}

	/** Normalize RFC 5322 Message-ID (always angle-bracketed). */
	public static function normalizeMessageId(string $raw): string
	{
		$s = trim($raw);
		if ($s === '') {
			return '';
		}
		$s = trim($s, " \t\"'");
		if ($s === '') {
			return '';
		}
		// CRLF/control chars inside the value = header injection — fail closed.
		if (preg_match('/[\x00-\x1f\x7f]/', $s) === 1) {
			return '';
		}
		if ($s[0] !== '<') {
			$s = '<' . $s;
		}
		if (!str_ends_with($s, '>')) {
			$s .= '>';
		}
		if (strlen($s) > 512 || preg_match('/^<[^<>\s]+>$/', $s) !== 1) {
			return '';
		}

		return $s;
	}

	/** Stable Message-ID for SMTP + CRM outbound dedupe against IMAP Sent. */
	public static function generateMessageId(string $fromEmail): string
	{
		$domain = 'ticketcheck.local';
		$fromEmail = strtolower(trim($fromEmail));
		if (str_contains($fromEmail, '@')) {
			$right = substr($fromEmail, strrpos($fromEmail, '@') + 1);
			$right = preg_replace('/[^a-z0-9.-]/i', '', $right) ?? '';
			if ($right !== '') {
				$domain = strtolower($right);
			}
		}

		return '<' . bin2hex(random_bytes(12)) . '.' . dechex(time()) . '@' . $domain . '>';
	}
}

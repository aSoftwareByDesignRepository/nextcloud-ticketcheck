<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

/**
 * Pure classification/parsing helpers for inbound mail — no I/O, no config.
 * Every method is deterministic so unit tests can pin the full matrix
 * (spec MH-05/MH-06, ARGUS auto-reply rules).
 *
 * A normalized message is:
 *   array{
 *     from: string, fromName?: string,
 *     to: list<string>, cc: list<string>,
 *     subject: string, messageId: ?string,
 *     internalDate?: ?string, rawHeader?: string,
 *     text: string, html?: string,
 *   }
 */
final class InboundMailClassifier
{
	/**
	 * Dedupe hash — sender-bound so an attacker cannot pre-claim a victim's
	 * Message-ID (ARGUS). Falls back to sender+subject+date+uid when the
	 * Message-ID is missing (both channels share the same shape).
	 */
	public static function msgHash(?string $messageId, string $from, string $subject, ?string $internalDate, int $uid): string
	{
		$from = strtolower(trim($from));
		$mid = strtolower(trim((string)$messageId));
		if ($mid !== '') {
			return hash('sha256', $mid . '|' . $from);
		}
		$base = strtolower(trim(preg_replace('/\s+/', ' ', $subject) ?? $subject));
		return hash('sha256', 'fallback|' . $from . '|' . $base . '|' . (string)$internalDate . '|' . $uid);
	}

	/**
	 * Auto-response / daemon / self-mail detection. Such mail is classified
	 * ignored_auto and never triggers an automatic reply (no backscatter).
	 *
	 * @param array{from: string, subject: string, rawHeader?: string} $msg
	 */
	public static function isAutomated(array $msg, string $ownAddress): bool
	{
		$from = strtolower(trim((string)($msg['from'] ?? '')));
		$raw = strtolower((string)($msg['rawHeader'] ?? ''));

		if ($from === '') {
			return false;
		}
		// Self-mail: the configured address mailing itself (notification echo).
		$ownAddress = strtolower(trim($ownAddress));
		if ($ownAddress !== '' && $from === $ownAddress) {
			return true;
		}
		if (preg_match('/^(mailer-daemon|postmaster|hostmaster|noreply-daemon)@/i', $from) === 1) {
			return true;
		}
		if ($raw === '') {
			return false;
		}
		if (preg_match('/^auto-submitted:\s*(?!no\b)\S+/mi', $raw) === 1) {
			return true;
		}
		if (preg_match('/^precedence:\s*(bulk|list|junk)/mi', $raw) === 1) {
			return true;
		}
		if (preg_match('/^x-autoreply:\s*\S+/mi', $raw) === 1
			|| preg_match('/^x-autorespond:\s*\S+/mi', $raw) === 1
			|| preg_match('/^x-auto-response-suppress:\s*\S+/mi', $raw) === 1) {
			return true;
		}
		// Empty Return-Path (<> or missing angle content) = bounce/daemon.
		if (preg_match('/^return-path:\s*<>\s*$/mi', $raw) === 1) {
			return true;
		}
		if (preg_match('/^list-(id|unsubscribe|post):/mi', $raw) === 1) {
			return true;
		}

		return false;
	}

	/**
	 * Scan To + Cc for the ticket reply address:
	 *   local+{id}-{token}@domain  (tokenized, preferred)
	 *   local+{id}@domain          (legacy, compatibility)
	 * Matching is constrained to the configured inbound mailbox local+domain
	 * so random +{n} addresses elsewhere can't inject tickets.
	 *
	 * @param list<string> $recipients
	 * @return array{id: int, token: ?string, legacy: bool}|null
	 */
	public static function extractReplyTarget(array $recipients, string $inboundAddress): ?array
	{
		$inboundAddress = strtolower(trim($inboundAddress));
		$at = strpos($inboundAddress, '@');
		if ($at === false) {
			return null;
		}
		$local = substr($inboundAddress, 0, $at);
		$domain = substr($inboundAddress, $at + 1);
		if ($local === '' || $domain === '') {
			return null;
		}

		foreach ($recipients as $rcpt) {
			$rcpt = strtolower(trim((string)$rcpt));
			if ($rcpt === '' || !str_ends_with($rcpt, '@' . $domain)) {
				continue;
			}
			$rcptLocal = substr($rcpt, 0, strlen($rcpt) - strlen($domain) - 1);
			// local+{id}-{token} or local+{id}
			if (preg_match('/^' . preg_quote($local, '/') . '\+(\d+)(?:-([a-f0-9]{10}))?$/', $rcptLocal, $m) !== 1) {
				continue;
			}
			$id = (int)$m[1];
			if ($id <= 0) {
				continue;
			}
			$token = isset($m[2]) && $m[2] !== '' ? $m[2] : null;
			return ['id' => $id, 'token' => $token, 'legacy' => $token === null];
		}

		return null;
	}

	/**
	 * Subject fallback: "#123" or "ticket 123" — only used when no address
	 * target exists and require_reply_token is off (spec §9.4).
	 */
	public static function extractSubjectTicketId(string $subject): ?int
	{
		if (preg_match('/#(\d+)/', $subject, $m) === 1) {
			$id = (int)$m[1];
			return $id > 0 ? $id : null;
		}
		if (preg_match('/\bticket\s*(?:#|id[:.\s]*)?(\d+)/i', $subject, $m) === 1) {
			$id = (int)$m[1];
			return $id > 0 ? $id : null;
		}
		return null;
	}

	/**
	 * Routing key for the project/category map (spec DEC-27):
	 *  1. named plus-tag on the inbound address (support+billing@ → 'billing')
	 *  2. the full recipient address (alias landing in the same mailbox)
	 *  3. the recipient local part
	 * Numeric tags are never routing keys — they are ticket addresses.
	 *
	 * @param list<string> $recipients
	 * @param array<string, mixed> $routingMap
	 * @return array{project_id: int|null, category: string}|null
	 */
	public static function routingTarget(array $recipients, string $inboundAddress, array $routingMap): ?array
	{
		if ($routingMap === []) {
			return null;
		}
		$inboundAddress = strtolower(trim($inboundAddress));
		$at = strpos($inboundAddress, '@');
		$inLocal = $at !== false ? substr($inboundAddress, 0, $at) : '';
		$inDomain = $at !== false ? substr($inboundAddress, $at + 1) : '';

		foreach ($recipients as $rcpt) {
			$rcpt = strtolower(trim((string)$rcpt));
			if ($rcpt === '') {
				continue;
			}
			// Named plus-tag on the inbound local part.
			if ($inLocal !== '' && $inDomain !== '' && str_ends_with($rcpt, '@' . $inDomain)) {
				$rcptLocal = substr($rcpt, 0, strlen($rcpt) - strlen($inDomain) - 1);
				if (str_starts_with($rcptLocal, $inLocal . '+')) {
					$tag = substr($rcptLocal, strlen($inLocal) + 1);
					if ($tag !== '' && preg_match('/^\d+$/', $tag) !== 1 && isset($routingMap[$tag])) {
						return $routingMap[$tag];
					}
				}
			}
			// Full alias address.
			if (isset($routingMap[$rcpt])) {
				return $routingMap[$rcpt];
			}
			// Local part key.
			$atPos = strpos($rcpt, '@');
			if ($atPos !== false) {
				$lp = substr($rcpt, 0, $atPos);
				if ($lp !== '' && isset($routingMap[$lp])) {
					return $routingMap[$lp];
				}
			}
		}

		return null;
	}

	/**
	 * Strip quoted reply history + signatures from a plain-text body.
	 * Bounded and conservative — only trims trailing quote blocks, never the
	 * middle of a message.
	 */
	public static function trimQuotedText(string $text): string
	{
		$lines = explode("\n", str_replace("\r\n", "\n", $text));
		$quoteMarker = '/^(>|on .+ wrote:|am .+ schrieb|from:\s|-----+\s*original|_{5,})/i';

		// Cut at the first quote marker ONLY when the entire tail is quote
		// content — interleaved replies ("First\n> quoted\nSecond") keep
		// their post-quote text instead of losing it silently.
		$cut = null;
		$n = count($lines);
		for ($i = 0; $i < $n; $i++) {
			if (preg_match($quoteMarker, trim($lines[$i])) !== 1) {
				continue;
			}
			$tailAllQuote = true;
			for ($j = $i + 1; $j < $n; $j++) {
				$t = trim($lines[$j]);
				if ($t === '' || str_starts_with($t, '>') || preg_match($quoteMarker, $t) === 1) {
					continue;
				}
				$tailAllQuote = false;
				break;
			}
			if ($tailAllQuote) {
				$cut = $i;
				break;
			}
		}
		if ($cut !== null) {
			$lines = array_slice($lines, 0, $cut);
		}

		// Signature block: "-- " near the end of the remaining body.
		$n = count($lines);
		for ($i = 0; $i < $n; $i++) {
			if (trim($lines[$i]) === '--' && ($n - $i) <= 8) {
				$lines = array_slice($lines, 0, $i);
				break;
			}
		}

		$trimmed = trim(implode("\n", $lines));
		// Never produce an empty comment from over-eager trimming.
		return $trimmed !== '' ? $trimmed : trim($text);
	}

	/**
	 * Body normalization shared by both channels: prefer text, decode the
	 * HTML fallback, cap length on a multibyte boundary.
	 */
	public static function normalizeBody(string $text, string $html, int $maxBytes = 40960): string
	{
		$body = trim($text);
		if ($body === '') {
			$body = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		}
		if (strlen($body) > $maxBytes) {
			$body = mb_strcut($body, 0, $maxBytes, 'UTF-8');
		}
		return self::trimQuotedText($body);
	}

	/**
	 * Attachment filename hardening (ARGUS): basename, strip bidi/control
	 * chars, cap length — before the name ever reaches storage or UI text.
	 */
	public static function sanitizeFilename(string $name): string
	{
		$name = str_replace("\0", '', $name);
		// Remove bidi/control chars that make displayed names lie (U+202A-E, U+2066-9, etc.).
		$name = (string)preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}\x{061C}\x{0000}-\x{001F}\x{007F}]/u', '', $name);
		$name = basename(str_replace('\\', '/', $name));
		$name = trim($name);
		if ($name === '' || $name === '.' || $name === '..') {
			return '';
		}
		return mb_strcut($name, 0, 180, 'UTF-8');
	}
}

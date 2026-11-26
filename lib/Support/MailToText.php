<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Support;

/**
 * Mail body → readable UTF-8 plain text for ticket descriptions/comments.
 *
 * Two failure modes this class exists to kill (issue #11):
 *  - raw strip_tags() on HTML mail leaves <style>/<script> SOURCE as visible
 *    "code" and glues every word together (no line breaks for <br>/<p>);
 *  - transfer-decoding a MIME part without its declared charset produces
 *    mojibake for anything that is not UTF-8 or Windows-1252.
 *
 * Pure functions — no I/O, fully unit-testable.
 */
final class MailToText
{
	/**
	 * Decode a MIME leaf part by its declared Content-Transfer-Encoding and
	 * Content-Type charset into guaranteed-valid UTF-8.
	 */
	public static function decodeTransfer(string $raw, string $encoding, string $charset = ''): string
	{
		if ($raw === '') {
			return '';
		}
		$enc = strtolower(trim($encoding));
		if ($enc === 'base64') {
			$decoded = base64_decode(preg_replace('/\s+/', '', $raw) ?? '', true);
			$raw = $decoded !== false ? $decoded : '';
		} elseif ($enc === 'quoted-printable') {
			$raw = quoted_printable_decode($raw);
		}

		return self::toUtf8($raw, $charset);
	}

	/**
	 * Interpret bytes under the declared charset; fall back to lossless-ish
	 * UTF-8 repair when the declaration is missing or unusable.
	 */
	public static function toUtf8(string $raw, string $charset = ''): string
	{
		if ($raw === '') {
			return '';
		}
		// Declared charset wins. The name is wire data — unknown/bogus
		// charsets make iconv return false and mb_convert_encoding throw
		// ValueError on PHP 8, so both attempts are guarded and fall
		// through to repair on failure. iconv first: it covers charsets
		// mbstring lacks (e.g. windows-1250).
		$charset = strtolower(trim($charset, " \t\"'"));
		$charset = preg_replace('/^charset\s*=\s*/i', '', $charset) ?? $charset;
		if ($charset !== '' && !in_array($charset, ['utf-8', 'utf8', 'us-ascii', 'ascii'], true)) {
			$converted = false;
			if (function_exists('iconv')) {
				$converted = @iconv($charset, 'UTF-8//IGNORE', $raw);
			}
			if (!is_string($converted) || $converted === '') {
				try {
					$converted = @mb_convert_encoding($raw, 'UTF-8', $charset);
				} catch (\Throwable) {
					$converted = false;
				}
			}
			if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
				return $converted;
			}
		}

		return self::ensureUtf8($raw);
	}

	/**
	 * Guarantee valid UTF-8 (stored bodies/comments never reach the UI or a
	 * JSON response with broken byte sequences).
	 *
	 * Prefer dropping invalid sequences (keeps real UTF-8 intact). If that
	 * yields empty content from a non-empty body, reinterpret as Windows-1252
	 * (the dominant undeclared Western-mail encoding).
	 */
	public static function ensureUtf8(string $value): string
	{
		if ($value === '') {
			return '';
		}
		if (mb_check_encoding($value, 'UTF-8')) {
			return $value;
		}
		if (function_exists('iconv')) {
			$ignored = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
			if (is_string($ignored) && mb_check_encoding($ignored, 'UTF-8') && ($ignored !== '' || trim($value) === '')) {
				return $ignored;
			}
		}
		$as1252 = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
		if (is_string($as1252) && mb_check_encoding($as1252, 'UTF-8')) {
			return $as1252;
		}
		$prev = null;
		if (function_exists('mb_substitute_character')) {
			$prev = mb_substitute_character();
			mb_substitute_character(0xFFFD);
		}
		$substituted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
		if ($prev !== null) {
			mb_substitute_character($prev);
		}
		if (is_string($substituted) && mb_check_encoding($substituted, 'UTF-8')) {
			return $substituted;
		}
		// Last resort: printable ASCII + whitespace only (never invalid UTF-8).
		$ascii = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $value);

		return is_string($ascii) ? $ascii : '';
	}

	/**
	 * HTML mail → plain text. Drops non-rendered subtrees outright, converts
	 * block-level boundaries to real line breaks, then strips remaining
	 * markup and decodes entities.
	 */
	public static function htmlToText(string $html): string
	{
		if (trim($html) === '') {
			return '';
		}
		$html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
		// script/style/head/template contents are SOURCE, not mail text —
		// strip_tags alone would leave them as readable "code" in the ticket.
		$html = preg_replace('#<(script|style|head|title|template|noscript|svg)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
		// Block boundaries → line breaks BEFORE tag removal, otherwise every
		// word in the mail is glued to the next one.
		$html = preg_replace('/<br\b[^>]*>/i', "\n", $html) ?? $html;
		$html = preg_replace('/<hr\b[^>]*>/i', "\n", $html) ?? $html;
		$html = preg_replace('/<(p|blockquote|tr)\b[^>]*>/i', "\n", $html) ?? $html;
		$html = preg_replace('/<li\b[^>]*>/i', "\n- ", $html) ?? $html;
		$html = preg_replace('#</(p|div|table|tr|ul|ol|h[1-6]|blockquote|pre|section|article|header|footer|form|fieldset|dl|dt|dd|address|main|aside|nav|figure|figcaption)>#i', "\n", $html) ?? $html;
		$html = preg_replace('#</(td|th)>#i', "\t", $html) ?? $html;
		$text = strip_tags($html);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = str_replace("\xC2\xA0", ' ', $text); // &nbsp; → normal space
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$text = preg_replace('/[^\S\n]+\n/', "\n", $text) ?? $text; // trailing ws
		$text = preg_replace('/\n[^\S\n]+/', "\n", $text) ?? $text; // leading ws
		$text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

		return self::ensureUtf8(trim($text));
	}

	/**
	 * Does this text/plain payload actually contain HTML markup? Some
	 * senders/gateways stuff a full HTML document into the plain part —
	 * showing it raw is exactly the "showing code" complaint.
	 */
	public static function looksLikeHtml(string $text): bool
	{
		return preg_match('/<(html|body|div|p|br|table|span|head)\b/i', $text) === 1;
	}

	/**
	 * Fallback for when no BODYSTRUCTURE is available: IMAP BODY[TEXT] on a
	 * multipart message returns the WHOLE MIME payload — boundaries, part
	 * headers and base64 included. Extract the best readable text part
	 * (text/plain preferred, text/html converted); non-multipart input is
	 * returned transfer-decoded as-is.
	 */
	public static function extractBestMimeText(string $raw): string
	{
		if (trim($raw) === '') {
			return '';
		}
		$boundary = self::detectBoundary($raw);
		if ($boundary === null) {
			// No multipart markers — may still be a lone encoded part header
			// blob or plain text; hand back decoded.
			return self::maybeDecodeSinglePart($raw);
		}

		$plain = null;
		$html = null;
		foreach (explode('--' . $boundary, $raw) as $chunk) {
			$chunk = ltrim($chunk, "\r\n");
			if ($chunk === '' || str_starts_with($chunk, '--')) {
				continue;
			}
			[$headers, $body] = self::splitHeadersBody($chunk);
			if ($body === '') {
				continue;
			}
			$ct = mb_strtolower(self::headerValue($headers, 'content-type'));
			$charset = '';
			if (preg_match('/charset\s*=\s*"?([a-z0-9._-]+)"?/i', $ct, $m) === 1) {
				$charset = $m[1];
			}
			$decoded = self::decodeTransfer(
				$body,
				self::headerValue($headers, 'content-transfer-encoding'),
				$charset,
			);
			if (str_starts_with($ct, 'text/plain')) {
				$plain = $decoded;
			} elseif (str_starts_with($ct, 'text/html')) {
				$html = $decoded;
			}
			// Nested multipart/related inside the boundary: ignored — the
			// readable part is almost always a sibling leaf here.
		}
		if (is_string($plain) && trim($plain) !== '') {
			return trim($plain);
		}
		if (is_string($html) && trim($html) !== '') {
			return $html; // caller's looksLikeHtml → htmlToText
		}

		return '';
	}

	private static function detectBoundary(string $raw): ?string
	{
		if (preg_match('/\bboundary\s*=\s*"([^"]+)"/i', $raw, $m) === 1) {
			return $m[1];
		}
		if (preg_match('/\bboundary\s*=\s*([^;\s]+)/i', $raw, $m) === 1) {
			return trim($m[1], " \t\"'");
		}
		if (preg_match('/^--([^\r\n]+)\r?$/m', $raw, $m) === 1) {
			$candidate = trim($m[1]);
			if ($candidate !== '' && !str_ends_with($candidate, '--')) {
				return $candidate;
			}
		}

		return null;
	}

	/** @return array{0:string,1:string} */
	private static function splitHeadersBody(string $part): array
	{
		$norm = str_replace(["\r\n", "\r"], "\n", $part);
		$pos = strpos($norm, "\n\n");
		if ($pos === false) {
			return ['', $part];
		}

		return [substr($norm, 0, $pos), substr($norm, $pos + 2)];
	}

	private static function headerValue(string $headers, string $name): string
	{
		$headers = str_replace(["\r\n", "\r"], "\n", $headers);
		$headers = preg_replace("/\n[ \t]+/", ' ', $headers) ?? $headers;
		if (preg_match('/^' . preg_quote($name, '/') . '\s*:\s*(.+)$/im', $headers, $m) !== 1) {
			return '';
		}

		return trim($m[1]);
	}

	/**
	 * Single-part heuristic decode for unlabeled bodies: QP/base64 only when
	 * the payload is clearly encoded, never guessing on readable text.
	 */
	private static function maybeDecodeSinglePart(string $raw): string
	{
		$head = substr($raw, 0, 8192);
		$cte = self::headerValue($head, 'content-transfer-encoding');
		$charset = '';
		$ct = self::headerValue($head, 'content-type');
		if (preg_match('/charset\s*=\s*"?([a-z0-9._-]+)"?/i', $ct, $m) === 1) {
			$charset = $m[1];
		}
		if ($cte !== '') {
			[$h, $body] = self::splitHeadersBody($raw);
			$decoded = self::decodeTransfer($body !== '' ? $body : $raw, $cte, $charset);
			if (trim($decoded) !== '') {
				return $decoded;
			}
		}

		return self::ensureUtf8($raw);
	}
}

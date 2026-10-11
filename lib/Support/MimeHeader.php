<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Support;

/**
 * RFC 2047 encoded-word decoder for IMAP/SMTP header values (Subject, etc.).
 *
 * Live IMAP probes and some servers leave subjects as
 * {@code =?UTF-8?Q?Anfrage_f=C3=BCr_…?=}. Those must never reach the CRM UI raw.
 */
final class MimeHeader
{
	/**
	 * Decode one header value to a UTF-8 display string.
	 * Plain text (no {@code =?} markers) is returned trimmed as-is.
	 */
	public static function decode(string $raw): string
	{
		$raw = trim($raw);
		if ($raw === '') {
			return '';
		}
		// Fast path — most subjects are already human text.
		if (!str_contains($raw, '=?')) {
			return $raw;
		}

		if (function_exists('iconv_mime_decode')) {
			$decoded = @iconv_mime_decode($raw, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
			if (is_string($decoded) && $decoded !== '' && !self::stillEncoded($decoded)) {
				return self::collapseWs($decoded);
			}
		}

		if (function_exists('mb_decode_mimeheader')) {
			$prev = mb_internal_encoding();
			@mb_internal_encoding('UTF-8');
			$decoded = @mb_decode_mimeheader($raw);
			if (is_string($prev) && $prev !== '') {
				@mb_internal_encoding($prev);
			}
			if (is_string($decoded) && $decoded !== '' && !self::stillEncoded($decoded)) {
				return self::collapseWs($decoded);
			}
		}

		$manual = self::decodeManual($raw);
		if ($manual !== null) {
			return self::collapseWs($manual);
		}

		return $raw;
	}

	private static function stillEncoded(string $value): bool
	{
		return (bool)preg_match('/=\?[\w-]+\?[BQbq]\?[^\?]*\?=/', $value);
	}

	private static function collapseWs(string $value): string
	{
		return trim(preg_replace('/[ \t]+/', ' ', $value) ?? $value);
	}

	/**
	 * Minimal Q/B encoded-word fallback when iconv/mbstring are unavailable or fail.
	 */
	private static function decodeManual(string $raw): ?string
	{
		$out = preg_replace_callback(
			'/=\?([\w-]+)\?([BQbq])\?([^\?]*)\?=/',
			static function (array $m): string {
				$charset = strtoupper($m[1]);
				$encoding = strtoupper($m[2]);
				$payload = $m[3];
				if ($encoding === 'Q') {
					$bin = quoted_printable_decode(str_replace('_', ' ', $payload));
				} else {
					$bin = base64_decode($payload, true);
					if ($bin === false) {
						return $m[0];
					}
				}
				if ($charset !== 'UTF-8' && $charset !== 'UTF8' && function_exists('mb_convert_encoding')) {
					$converted = @mb_convert_encoding($bin, 'UTF-8', $charset);
					if (is_string($converted) && $converted !== '') {
						return $converted;
					}
				}

				return $bin;
			},
			$raw,
		);
		if (!is_string($out) || $out === '' || self::stillEncoded($out)) {
			return null;
		}

		return $out;
	}
}

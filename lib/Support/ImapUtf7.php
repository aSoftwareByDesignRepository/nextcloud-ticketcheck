<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Support;

/**
 * IMAP mailbox Modified UTF-7 (RFC 3501) ↔ UTF-8.
 *
 * Wire LIST/SELECT names often encode non-ASCII (e.g. German umlauts) as
 * `&AOQ-` for `ä`. CRM stores human UTF-8 paths; encode before SELECT / php-imap.
 */
final class ImapUtf7
{
	/**
	 * Decode Modified UTF-7 mailbox name to UTF-8.
	 * Already-UTF-8 names (no `&…-` shifts) pass through unchanged.
	 */
	public static function toUtf8(string $mailbox): string
	{
		$mailbox = str_replace("\0", '', $mailbox);
		if ($mailbox === '' || !str_contains($mailbox, '&')) {
			return $mailbox;
		}

		$out = '';
		$len = strlen($mailbox);
		for ($i = 0; $i < $len; $i++) {
			if ($mailbox[$i] !== '&') {
				$out .= $mailbox[$i];
				continue;
			}
			$end = strpos($mailbox, '-', $i + 1);
			if ($end === false) {
				$out .= substr($mailbox, $i);
				break;
			}
			$chunk = substr($mailbox, $i + 1, $end - $i - 1);
			$i = $end;
			if ($chunk === '') {
				$out .= '&';
				continue;
			}
			$b64 = strtr($chunk, ',', '/');
			$pad = strlen($b64) % 4;
			if ($pad > 0) {
				$b64 .= str_repeat('=', 4 - $pad);
			}
			$raw = base64_decode($b64, true);
			if ($raw === false || $raw === '') {
				// Not valid Modified UTF-7 — keep the original shift literally.
				$out .= '&' . $chunk . '-';
				continue;
			}
			$decoded = mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
			if (!is_string($decoded) || $decoded === '') {
				$out .= '&' . $chunk . '-';
				continue;
			}
			$out .= $decoded;
		}

		return $out;
	}

	/**
	 * Encode UTF-8 mailbox name to Modified UTF-7 for IMAP SELECT / php-imap.
	 * Idempotent for wire forms: toWire(toUtf8(wire)) === canonical wire.
	 */
	public static function toWire(string $mailbox): string
	{
		$mailbox = self::toUtf8(str_replace("\0", '', $mailbox));
		if ($mailbox === '') {
			return '';
		}

		$out = '';
		$pending = '';
		$len = mb_strlen($mailbox, 'UTF-8');
		for ($i = 0; $i < $len; $i++) {
			$ch = mb_substr($mailbox, $i, 1, 'UTF-8');
			if ($ch === '&') {
				if ($pending !== '') {
					$out .= self::encodeShift($pending);
					$pending = '';
				}
				$out .= '&-';
				continue;
			}
			$ord = strlen($ch) === 1 ? ord($ch) : null;
			$asciiPrintable = $ord !== null && $ord >= 0x20 && $ord <= 0x7e;
			if ($asciiPrintable) {
				if ($pending !== '') {
					$out .= self::encodeShift($pending);
					$pending = '';
				}
				$out .= $ch;
				continue;
			}
			$pending .= $ch;
		}
		if ($pending !== '') {
			$out .= self::encodeShift($pending);
		}

		return $out;
	}

	/**
	 * Case-fold key for folder upsert / dedupe (UTF-8 aware).
	 */
	public static function folderKey(string $mailbox): string
	{
		$utf8 = self::toUtf8(trim($mailbox));
		if ($utf8 === '') {
			return '';
		}

		return mb_strtolower($utf8, 'UTF-8');
	}

	private static function encodeShift(string $utf8): string
	{
		$utf16 = mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8');
		if (!is_string($utf16) || $utf16 === '') {
			return '';
		}
		$b64 = base64_encode($utf16);
		$b64 = rtrim($b64, '=');
		$b64 = strtr($b64, '/', ',');

		return '&' . $b64 . '-';
	}
}

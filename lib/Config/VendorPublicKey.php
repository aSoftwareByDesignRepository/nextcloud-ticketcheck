<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Config;

/**
 * Embedded vendor Ed25519 public key (32 bytes) for TKC2 verification.
 *
 * The default is the production vendor public key (same key family as
 * the vendor licensing service). Customer servers need no configuration; the key is never
 * fetched remotely (N5).
 *
 * Env override `TKC_VENDOR_PUBLIC_KEY_B64` is allowed ONLY under PHPUnit (or
 * when `TKC_ALLOW_VENDOR_KEY_OVERRIDE=1` is set for harnesses). Production
 * processes always use the embedded default — a compromised env must not
 * swap the trust anchor.
 */
final class VendorPublicKey
{
	/**
	 * Production vendor public key — verifies licenses signed by the vendor licensing service.
	 */
	public const DEFAULT_PUBLIC_KEY_B64 = 'naLgi4THUgwJCRoUehq20QU4uJsLVHzuKV04NhkITn8';

	/**
	 * Deterministic PHPUnit fixture key (shared Check-family test seed for Ed25519).
	 * Seed: sha256("projectcheck-pc2-test-signing-v1") — historical shared harness seed;
	 * product payloads still must be `ticketcheck` / TKC2 (wrong product is rejected).
	 */
	public const TEST_PUBLIC_KEY_B64 = 'QLPCkorywY7kyNFmR961Euz2vwLMndfllF-3hmG6hnM';

	public static function publicKeyB64(): string
	{
		if (self::envOverrideAllowed()) {
			$fromEnv = getenv('TKC_VENDOR_PUBLIC_KEY_B64');
			if (is_string($fromEnv) && trim($fromEnv) !== '') {
				return trim($fromEnv);
			}
		}
		return self::DEFAULT_PUBLIC_KEY_B64;
	}

	/**
	 * True only in test harnesses — never for a normal Nextcloud PHP-FPM/CLI
	 * request that happens to inherit a forged env var.
	 */
	public static function envOverrideAllowed(): bool
	{
		if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('PHPUNIT_RUNNING')) {
			return true;
		}
		return getenv('TKC_ALLOW_VENDOR_KEY_OVERRIDE') === '1';
	}

	public static function bytes(): string
	{
		$decoded = self::base64urlDecode(self::publicKeyB64());
		if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
			throw new \RuntimeException('Invalid vendor public key configuration.');
		}
		return $decoded;
	}

	public static function base64urlDecode(string $data): string|false
	{
		$padded = strtr($data, '-_', '+/');
		$padLen = (4 - strlen($padded) % 4) % 4;
		return base64_decode($padded . str_repeat('=', $padLen), true);
	}

	public static function base64urlEncode(string $data): string
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}
}

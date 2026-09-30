<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\License;

use OCA\Ticketcheck\Config\VendorPublicKey;
use OCA\Ticketcheck\License\Tkc2Codec;
use PHPUnit\Framework\TestCase;

final class Tkc2CodecTest extends TestCase
{
	private string $secretKey;

	protected function setUp(): void
	{
		parent::setUp();
		// Shared Check-family test seed → VendorPublicKey::TEST_PUBLIC_KEY_B64
		$seed = hash('sha256', 'projectcheck-pc2-test-signing-v1', true);
		$kp = sodium_crypto_sign_seed_keypair($seed);
		$this->secretKey = sodium_crypto_sign_secretkey($kp);
		$pub = sodium_crypto_sign_publickey($kp);
		putenv('TKC_VENDOR_PUBLIC_KEY_B64=' . VendorPublicKey::base64urlEncode($pub));
		putenv('TKC_ALLOW_VENDOR_KEY_OVERRIDE=1');
		if (!defined('PHPUNIT_RUNNING')) {
			define('PHPUNIT_RUNNING', true);
		}
	}

	protected function tearDown(): void
	{
		putenv('TKC_VENDOR_PUBLIC_KEY_B64');
		putenv('TKC_ALLOW_VENDOR_KEY_OVERRIDE');
		parent::tearDown();
	}

	public function testValidKeyRoundTrip(): void
	{
		$wire = $this->mint(40, '2099-12-31');
		self::assertSame('', Tkc2Codec::classifyError($wire));
		$parsed = Tkc2Codec::parseAndVerify($wire);
		self::assertNotNull($parsed);
		self::assertSame('ticketcheck', $parsed['payload']['product']);
		self::assertSame(40, $parsed['payload']['mobileSeats']);
	}

	public function testTamperedPayloadFailsSignature(): void
	{
		$wire = $this->mint(10, '2099-01-01');
		$parts = explode('.', $wire);
		$payload = json_decode(VendorPublicKey::base64urlDecode($parts[1]), true);
		$payload['mobileSeats'] = 9999;
		$parts[1] = VendorPublicKey::base64urlEncode(Tkc2Codec::canonicalJson($payload));
		$tampered = implode('.', $parts);
		self::assertSame(Tkc2Codec::ERROR_INVALID_SIGNATURE, Tkc2Codec::classifyError($tampered));
	}

	public function testWrongProductRejected(): void
	{
		$payload = [
			'v' => 2,
			'product' => 'projectcheck',
			'customerId' => 'acme-corp',
			'issuedAt' => '2026-01-01',
			'validUntil' => '2099-01-01',
			'mobileSeats' => 5,
		];
		$bytes = Tkc2Codec::canonicalJson($payload);
		$sig = sodium_crypto_sign_detached($bytes, $this->secretKey);
		$wire = 'TKC2.' . VendorPublicKey::base64urlEncode($bytes) . '.' . VendorPublicKey::base64urlEncode($sig);
		self::assertSame(Tkc2Codec::ERROR_INVALID_PAYLOAD, Tkc2Codec::classifyError($wire));
	}

	public function testIsValidOnInclusive(): void
	{
		self::assertTrue(Tkc2Codec::isValidOn('2026-07-26', '2026-07-26'));
		self::assertFalse(Tkc2Codec::isValidOn('2026-07-25', '2026-07-26'));
	}

	private function mint(int $seats, string $until): string
	{
		$payload = [
			'v' => 2,
			'product' => 'ticketcheck',
			'customerId' => 'acme-corp',
			'issuedAt' => '2026-01-01',
			'validUntil' => $until,
			'mobileSeats' => $seats,
		];
		$bytes = Tkc2Codec::canonicalJson($payload);
		$sig = sodium_crypto_sign_detached($bytes, $this->secretKey);
		return 'TKC2.' . VendorPublicKey::base64urlEncode($bytes) . '.' . VendorPublicKey::base64urlEncode($sig);
	}
}

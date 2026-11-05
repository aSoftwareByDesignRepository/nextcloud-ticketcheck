<?php

declare(strict_types=1);

/**
 * Portal token primitives — bearer entropy, hashing, OTP shape (spec NN-T01/MH-T01).
 */

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\TokenService;
use PHPUnit\Framework\TestCase;

final class PortalTokenServiceTest extends TestCase
{
    public function testMintReturnsDistinctTokenAndHash(): void
    {
        $svc = new TokenService();
        [$token, $hash] = $svc->mint();
        self::assertNotSame('', $token);
        self::assertSame(64, strlen($hash)); // sha256 hex
        self::assertSame($hash, $svc->hash($token));
        self::assertTrue($svc->verify($token, $hash));
    }

    public function testMintIsUnpredictable(): void
    {
        $svc = new TokenService();
        $seen = [];
        for ($i = 0; $i < 32; $i++) {
            [$token] = $svc->mint();
            $seen[$token] = true;
        }
        self::assertCount(32, $seen); // 256-bit tokens never collide
    }

    public function testTokenIsUrlSafe(): void
    {
        $svc = new TokenService();
        for ($i = 0; $i < 50; $i++) {
            [$token] = $svc->mint();
            self::assertMatchesRegularExpression('/^[A-Za-z0-9\-_]+$/', $token);
        }
    }

    public function testVerifyRejectsWrongToken(): void
    {
        $svc = new TokenService();
        [, $hash] = $svc->mint();
        self::assertFalse($svc->verify('wrong-token', $hash));
        self::assertFalse($svc->verify('', $hash));
    }

    public function testOtpIsSixDigits(): void
    {
        $svc = new TokenService();
        for ($i = 0; $i < 200; $i++) {
            self::assertMatchesRegularExpression('/^\d{6}$/', $svc->mintOtp());
        }
    }
}

<?php

declare(strict_types=1);

/**
 * GuestAuthConfig — single authority for the S3 door, nc_fallback JSON,
 * and the S5 gate (fail closed always wins).
 */

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\GuestAuthConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class GuestAuthConfigTest extends TestCase
{
    /** @var array<string,string> */
    private array $store = [];

    private function config(): GuestAuthConfig
    {
        $this->store = [];
        $ic = $this->createMock(IConfig::class);
        $ic->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default) => $this->store[$key] ?? $default);
        $ic->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value): void {
                $this->store[$key] = $value;
            });
        return new GuestAuthConfig($ic);
    }

    public function testDefaultsAreLegacyCompatible(): void
    {
        $c = $this->config();
        self::assertSame('nc_account', $c->provisioning());
        self::assertFalse($c->isIdentityProvisioning());
        self::assertTrue($c->ncPortalLoginAllowed());
        self::assertFalse($c->s5GateArmed());
        self::assertSame(10, $c->otpTtlMinutes());
        self::assertSame(30, $c->sessionDays());
        self::assertSame([], $c->ncFallbackUids());
    }

    public function testS5GateFailsClosedOverDoorFlag(): void
    {
        $c = $this->config();
        $c->setNcPortalLoginAllowed(true);
        self::assertTrue($c->ncPortalLoginAllowed());
        $c->setS5GateArmed(true);
        self::assertFalse($c->ncPortalLoginAllowed()); // S5 refuses regardless
    }

    public function testDoorFlagRoundTrip(): void
    {
        $c = $this->config();
        $c->setNcPortalLoginAllowed(false);
        self::assertFalse($c->ncPortalLoginAllowed());
        $c->setNcPortalLoginAllowed(true);
        self::assertTrue($c->ncPortalLoginAllowed());
    }

    public function testNcFallbackRoundTripAndDedup(): void
    {
        $c = $this->config();
        self::assertTrue($c->setNcFallback('alice', true));
        self::assertFalse($c->setNcFallback('alice', true)); // already there
        self::assertTrue($c->isNcFallback('alice'));
        self::assertFalse($c->isNcFallback('bob'));
        self::assertTrue($c->setNcFallback('alice', false));
        self::assertFalse($c->isNcFallback('alice'));
    }

    public function testNcFallbackToleratesGarbageJson(): void
    {
        $this->store = ['guest_nc_fallback' => '{broken'];
        $ic = $this->createMock(IConfig::class);
        $ic->method('getAppValue')->willReturnCallback(
            fn (string $a, string $k, string $d) => $this->store[$k] ?? $d);
        $c = new GuestAuthConfig($ic);
        self::assertSame([], $c->ncFallbackUids());
        $this->store['guest_nc_fallback'] = '"notalist"';
        self::assertSame([], $c->ncFallbackUids());
        $this->store['guest_nc_fallback'] = '["alice","",{"x":1},"bob"]';
        self::assertSame(['alice', 'bob'], $c->ncFallbackUids());
    }

    public function testTtlClampsToBounds(): void
    {
        $c = $this->config();
        $this->store['guest_otp_ttl_min'] = '1';
        self::assertSame(5, $c->otpTtlMinutes()); // floor 5
        $this->store['guest_otp_ttl_min'] = '999';
        self::assertSame(30, $c->otpTtlMinutes()); // cap 30
    }

    public function testSessionDaysClampsToBounds(): void
    {
        $c = $this->config();
        $this->store['guest_portal_sess_days'] = '0';
        self::assertSame(1, $c->sessionDays()); // floor 1
        $this->store['guest_portal_sess_days'] = '999';
        self::assertSame(365, $c->sessionDays()); // cap 365
    }

    public function testInvalidProvisioningRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->config()->setProvisioning('bogus');
    }
}

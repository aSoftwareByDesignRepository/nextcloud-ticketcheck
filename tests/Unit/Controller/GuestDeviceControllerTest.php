<?php

declare(strict_types=1);

/**
 * GuestDeviceController — companion §10.7 client endpoints.
 * Pairing consumes a `pair` OTP (single-use, identity-bound); bootstrap
 * and revoke present the X-TicketCheck-Device bearer — never Authorization.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Tests\Unit\Controller;

use OCA\Ticketcheck\Controller\GuestDeviceController;
use OCA\Ticketcheck\Db\GuestDevice;
use OCA\Ticketcheck\Db\GuestIdentity;
use OCA\Ticketcheck\Db\GuestIdentityMapper;
use OCA\Ticketcheck\Service\GuestDeviceService;
use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Service\PortalOtpService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GuestDeviceControllerTest extends TestCase
{
    private IRequest&MockObject $request;
    private PortalOtpService&MockObject $otp;
    private GuestDeviceService&MockObject $devices;
    private GuestIdentityMapper&MockObject $identities;
    private GuestProjectAccessMapper&MockObject $access;
    private GuestDeviceController $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->otp = $this->createMock(PortalOtpService::class);
        $this->devices = $this->createMock(GuestDeviceService::class);
        $this->identities = $this->createMock(GuestIdentityMapper::class);
        $this->access = $this->createMock(GuestProjectAccessMapper::class);
        $this->controller = new GuestDeviceController(
            'ticketcheck', $this->request, $this->otp, $this->devices, $this->identities, $this->access
        );
    }

    private function identity(int $id, string $status = 'active'): GuestIdentity
    {
        $i = new GuestIdentity();
        $i->setId($id);
        $i->setEmail('g@example.com');
        $i->setDisplayName('Guest');
        $i->setStatus($status);
        return $i;
    }

    private function device(int $id, int $identityId): GuestDevice
    {
        $d = new GuestDevice();
        $d->setId($id);
        $d->setIdentityId($identityId);
        return $d;
    }

    // --- pair -------------------------------------------------------------

    public function testPairRejectsInvalidCode(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $k, $d = null) => ['email' => 'g@x.co', 'code' => '000000'][$k] ?? $d
        );
        $this->otp->method('verify')->willReturn(null);
        $this->devices->expects($this->never())->method('pair');
        $r = $this->controller->pair();
        self::assertSame(401, $r->getStatus());
        self::assertFalse($r->getData()['ok']);
        self::assertSame('INVALID_CODE', $r->getData()['error']['code']);
    }

    public function testPairRejectsDisabledIdentity(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $k, $d = null) => ['email' => 'g@x.co', 'code' => '123456'][$k] ?? $d
        );
        $this->otp->method('verify')->willReturn(7);
        $this->identities->method('find')->with(7)->willReturn($this->identity(7, 'disabled'));
        $this->devices->expects($this->never())->method('pair');
        $r = $this->controller->pair();
        self::assertSame(401, $r->getStatus());
    }

    public function testPairMintsDeviceToken(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $k, $d = null) => ['email' => 'g@x.co', 'code' => '123456', 'label' => 'Phone'][$k] ?? $d
        );
        $this->otp->method('verify')->with('g@x.co', '123456', PortalOtpService::PURPOSE_PAIR)->willReturn(7);
        $this->identities->method('find')->willReturn($this->identity(7));
        $this->devices->expects($this->once())->method('pair')
            ->with(7, 'Phone')
            ->willReturn(['device-token-xyz', $this->device(1, 7)]);
        $r = $this->controller->pair();
        self::assertSame(201, $r->getStatus());
        self::assertTrue($r->getData()['ok']);
        self::assertSame('device-token-xyz', $r->getData()['device_token']);
        self::assertSame('client', $r->getData()['role']);
    }

    public function testPairNormalizesEmailAndCode(): void
    {
        // Email lowered, non-digits stripped from code — same normalization
        // as the web login path.
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $k, $d = null) => ['email' => '  G@X.CO ', 'code' => '123-456'][$k] ?? $d
        );
        $this->otp->expects($this->once())->method('verify')
            ->with('g@x.co', '123456', PortalOtpService::PURPOSE_PAIR)
            ->willReturn(7);
        $this->identities->method('find')->willReturn($this->identity(7));
        $this->devices->method('pair')->willReturn(['tok', $this->device(1, 7)]);
        $r = $this->controller->pair();
        self::assertSame(201, $r->getStatus());
    }

    // --- bootstrap --------------------------------------------------------

    public function testBootstrapRejectsMissingDeviceHeader(): void
    {
        $this->request->method('getHeader')->willReturn('');
        $this->devices->expects($this->never())->method('resolve');
        $r = $this->controller->bootstrap();
        self::assertSame(401, $r->getStatus());
        self::assertSame('UNAUTHORIZED', $r->getData()['error']['code']);
    }

    public function testBootstrapRejectsUnknownDevice(): void
    {
        $this->request->method('getHeader')->willReturn('bad-token');
        $this->devices->method('resolve')->willReturn(null);
        $r = $this->controller->bootstrap();
        self::assertSame(401, $r->getStatus());
    }

    public function testBootstrapRejectsInactiveIdentity(): void
    {
        $this->request->method('getHeader')->willReturn('tok');
        $this->devices->method('resolve')->willReturn($this->device(1, 7));
        $this->identities->method('find')->willReturn($this->identity(7, 'erased'));
        $r = $this->controller->bootstrap();
        self::assertSame(403, $r->getStatus());
        self::assertSame('ROLE_DENIED', $r->getData()['error']['code']);
    }

    public function testBootstrapReturnsClientPayload(): void
    {
        $this->request->method('getHeader')->willReturn('tok');
        $this->devices->method('resolve')->willReturn($this->device(1, 7));
        $this->identities->method('find')->willReturn($this->identity(7));
        $this->access->method('getProjectsForUser')->with('portal:7')->willReturn([1, 2]);
        $r = $this->controller->bootstrap();
        self::assertSame(200, $r->getStatus());
        $d = $r->getData();
        self::assertTrue($d['ok']);
        self::assertSame('client', $d['role']);
        self::assertSame('Guest', $d['display_name']);
        self::assertSame([1, 2], $d['project_ids']);
    }

    // --- revoke -----------------------------------------------------------

    public function testRevokeIsIdempotent(): void
    {
        // No device header / unknown token → still 200 (never oracles).
        $this->request->method('getHeader')->willReturn('');
        $this->devices->expects($this->never())->method('revokeById');
        $r = $this->controller->revoke();
        self::assertTrue($r->getData()['ok']);
    }

    public function testRevokeUnregistersDevice(): void
    {
        $this->request->method('getHeader')->willReturn('tok');
        $this->devices->method('resolve')->willReturn($this->device(3, 7));
        $this->devices->expects($this->once())->method('revokeById')->with(3, 7);
        $r = $this->controller->revoke();
        self::assertTrue($r->getData()['ok']);
    }
}

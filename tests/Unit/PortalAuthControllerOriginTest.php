<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\PortalAuthController;
use OCA\Ticketcheck\Db\GuestAuditMapper;
use OCA\Ticketcheck\Db\GuestIdentity;
use OCA\Ticketcheck\Db\GuestIdentityMapper;
use OCA\Ticketcheck\Service\GuestAuthConfig;
use OCA\Ticketcheck\Service\GuestMailOutboxService;
use OCA\Ticketcheck\Service\GuestPortalPageService;
use OCA\Ticketcheck\Service\PortalActorResolver;
use OCA\Ticketcheck\Service\PortalOtpService;
use OCA\Ticketcheck\Service\PortalSessionService;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Regression: originOk() compared the Origin hostname against
 * getServerHost() verbatim, which carries the port on non-default-port
 * deployments ("localhost:8081"). Every browser send therefore failed the
 * check and sendOtp silently no-opped behind the uniform 202.
 */
class PortalAuthControllerOriginTest extends TestCase
{
	/** @var IRequest&MockObject */
	private IRequest $request;
	/** @var PortalOtpService&MockObject */
	private PortalOtpService $otp;
	/** @var GuestIdentityMapper&MockObject */
	private GuestIdentityMapper $identities;
	/** @var GuestMailOutboxService&MockObject */
	private GuestMailOutboxService $outbox;
	private PortalAuthController $controller;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->otp = $this->createMock(PortalOtpService::class);
		$this->identities = $this->createMock(GuestIdentityMapper::class);
		$this->outbox = $this->createMock(GuestMailOutboxService::class);
		$throttler = $this->createMock(IThrottler::class);

		$identity = new GuestIdentity();
		$identity->setId(42);
		$identity->setStatus('active');
		$this->identities->method('findByEmail')->willReturn($identity);
		$this->otp->method('issue')->willReturn(['123456', 'magictoken', 7]);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $k, $d = null) => $k === 'email' ? 'guest@example.com' : $d
		);
		$this->request->method('getRemoteAddress')->willReturn('10.0.0.1');

		$this->controller = new PortalAuthController(
			'ticketcheck',
			$this->request,
			$this->otp,
			$this->createMock(PortalSessionService::class),
			$this->createMock(PortalActorResolver::class),
			$this->identities,
			$this->outbox,
			$this->createMock(GuestAuthConfig::class),
			$this->createMock(GuestAuditMapper::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(IFactory::class),
			$throttler,
			$this->createMock(GuestPortalPageService::class),
		);
	}

	private function withHeaders(string $origin, string $serverHost): void
	{
		$this->request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => $name === 'Origin' ? $origin : ''
		);
		$this->request->method('getServerHost')->willReturn($serverHost);
	}

	public function testOtpIssuedWhenServerHostCarriesNonDefaultPort(): void
	{
		$this->withHeaders('http://localhost:8081', 'localhost:8081');
		$this->otp->expects($this->once())->method('issue');
		$resp = $this->controller->sendOtp();
		$this->assertSame(202, $resp->getStatus());
	}

	public function testOtpIssuedOnDefaultPortHost(): void
	{
		$this->withHeaders('https://portal.example.com', 'portal.example.com');
		$this->otp->expects($this->once())->method('issue');
		$this->controller->sendOtp();
	}

	public function testOtpIssuedForBracketedIpv6HostWithPort(): void
	{
		$this->withHeaders('http://[::1]:8080', '[::1]:8080');
		$this->otp->expects($this->once())->method('issue');
		$this->controller->sendOtp();
	}

	public function testForeignOriginStillBlocked(): void
	{
		$this->withHeaders('https://evil.example.com', 'localhost:8081');
		$this->otp->expects($this->never())->method('issue');
		$resp = $this->controller->sendOtp();
		$this->assertSame(202, $resp->getStatus());
	}

	public function testSubdomainOriginStillBlocked(): void
	{
		$this->withHeaders('https://portal.example.com.evil.com', 'portal.example.com');
		$this->otp->expects($this->never())->method('issue');
		$this->controller->sendOtp();
	}

	public function testNoOriginStillAllowed(): void
	{
		$this->withHeaders('', 'localhost:8081');
		$this->otp->expects($this->once())->method('issue');
		$this->controller->sendOtp();
	}
}

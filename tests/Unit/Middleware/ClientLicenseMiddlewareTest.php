<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Middleware;

use OCA\Ticketcheck\Exception\CompanionUnauthorizedException;
use OCA\Ticketcheck\Exception\PaymentRequiredException;
use OCA\Ticketcheck\Exception\RoleDeniedException;
use OCA\Ticketcheck\Middleware\ClientLicenseMiddleware;
use OCA\Ticketcheck\Service\CompanionGateService;
use OCA\Ticketcheck\Service\LicenseService;
use OCA\Ticketcheck\Exception\MobileGateException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ClientLicenseMiddlewareTest extends TestCase
{
	private function makeMw(
		IRequest $request,
		IUserSession $userSession,
		LicenseService $license,
		CompanionGateService $companionGate,
		?ISession $ncSession = null,
	): ClientLicenseMiddleware {
		$session = $ncSession ?? $this->createMock(ISession::class);
		if ($ncSession === null) {
			$session->method('get')->with('app_password')->willReturn('tk-app-password-token');
		}
		return new ClientLicenseMiddleware(
			$request,
			$userSession,
			$session,
			$license,
			$companionGate,
			new NullLogger(),
		);
	}

	private function basicAuth(string $user = 'alice', string $password = 'secret'): string
	{
		return 'Basic ' . base64_encode($user . ':' . $password);
	}

	public function testBootstrapNotLicenseGatedWithBasicAuth(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn($this->basicAuth());
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/companion/api/v1/bootstrap');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$companionGate = $this->createMock(CompanionGateService::class);
		$companionGate->method('canAccessCompanion')->with('alice')->willReturn(true);

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('isMobilePlanActive');
		$license->expects($this->never())->method('assertMobileAccess');

		$mw = $this->makeMw($request, $userSession, $license, $companionGate);
		$mw->beforeController(new \stdClass(), 'bootstrap');
	}

	public function testNonCompanionPathIsNoOp(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn($this->basicAuth());
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/api/tickets');

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('isMobilePlanActive');

		$companionGate = $this->createMock(CompanionGateService::class);
		$companionGate->expects($this->never())->method('canAccessCompanion');

		$mw = $this->makeMw(
			$request,
			$this->createMock(IUserSession::class),
			$license,
			$companionGate,
		);
		$mw->beforeController(new \stdClass(), 'index');
	}

	public function testSeatMissingReturns402(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn($this->basicAuth());
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/companion/api/v1/inbox');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$companionGate = $this->createMock(CompanionGateService::class);
		$companionGate->method('canAccessCompanion')->willReturn(true);

		$license = $this->createMock(LicenseService::class);
		$license->method('isMobilePlanActive')->willReturn(true);
		$license->method('assertMobileAccess')
			->willThrowException(new MobileGateException('seat_required'));

		$mw = $this->makeMw($request, $userSession, $license, $companionGate);
		$this->expectException(PaymentRequiredException::class);
		$this->expectExceptionMessage('NO_MOBILE_SEAT');
		$mw->beforeController(new \stdClass(), 'inbox');
	}

	public function testBearerAuthAlsoSeatGated(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('Bearer tok');
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/companion/api/v1/inbox');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$companionGate = $this->createMock(CompanionGateService::class);
		$companionGate->method('canAccessCompanion')->willReturn(true);

		$license = $this->createMock(LicenseService::class);
		$license->method('isMobilePlanActive')->willReturn(true);
		$license->method('assertMobileAccess')
			->willThrowException(new MobileGateException('seat_required'));

		$mw = $this->makeMw($request, $userSession, $license, $companionGate);
		$this->expectException(PaymentRequiredException::class);
		$this->expectExceptionMessage('NO_MOBILE_SEAT');
		$mw->beforeController(new \stdClass(), 'inbox');
	}

	public function testIndexPhpCompanionPathAlsoSeatGated(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn($this->basicAuth());
		$request->method('getPathInfo')->willReturn('/index.php/apps/ticketcheck/companion/api/v1/inbox');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$companionGate = $this->createMock(CompanionGateService::class);
		$companionGate->method('canAccessCompanion')->willReturn(true);

		$license = $this->createMock(LicenseService::class);
		$license->method('isMobilePlanActive')->willReturn(true);
		$license->method('assertMobileAccess')
			->willThrowException(new MobileGateException('seat_required'));

		$mw = $this->makeMw($request, $userSession, $license, $companionGate);
		$this->expectException(PaymentRequiredException::class);
		$this->expectExceptionMessage('NO_MOBILE_SEAT');
		$mw->beforeController(new \stdClass(), 'inbox');
	}

	public function testCookieOnlyCompanionRejected(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('');
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/companion/api/v1/inbox');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('isMobilePlanActive');

		$companionGate = $this->createMock(CompanionGateService::class);
		$companionGate->expects($this->never())->method('canAccessCompanion');

		$mw = $this->makeMw($request, $userSession, $license, $companionGate);
		$this->expectException(CompanionUnauthorizedException::class);
		$this->expectExceptionMessage('NOT_AUTHENTICATED');
		$mw->beforeController(new \stdClass(), 'inbox');
	}

	public function testEmptyBasicHeaderRejected(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('Basic ');
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/companion/api/v1/inbox');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('isMobilePlanActive');

		$mw = $this->makeMw(
			$request,
			$userSession,
			$license,
			$this->createMock(CompanionGateService::class),
		);
		$this->expectException(CompanionUnauthorizedException::class);
		$mw->beforeController(new \stdClass(), 'inbox');
	}

	public function testForgedBasicEmptyPasswordWithoutAppPasswordRejected(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn($this->basicAuth('alice', ''));
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/companion/api/v1/inbox');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$ncSession = $this->createMock(ISession::class);
		$ncSession->method('get')->with('app_password')->willReturn(null);

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('isMobilePlanActive');

		$mw = $this->makeMw(
			$request,
			$userSession,
			$license,
			$this->createMock(CompanionGateService::class),
			$ncSession,
		);
		$this->expectException(CompanionUnauthorizedException::class);
		$mw->beforeController(new \stdClass(), 'inbox');
	}

	public function testForgedBearerWithoutAppPasswordSessionRejected(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('Bearer garbage-token');
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/companion/api/v1/inbox');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$ncSession = $this->createMock(ISession::class);
		$ncSession->method('get')->with('app_password')->willReturn(null);

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('isMobilePlanActive');

		$mw = $this->makeMw(
			$request,
			$userSession,
			$license,
			$this->createMock(CompanionGateService::class),
			$ncSession,
		);
		$this->expectException(CompanionUnauthorizedException::class);
		$mw->beforeController(new \stdClass(), 'inbox');
	}

	public function testPathTraversalStillSeatGated(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn($this->basicAuth());
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/portal/../companion/api/v1/inbox');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$companionGate = $this->createMock(CompanionGateService::class);
		$companionGate->method('canAccessCompanion')->willReturn(true);

		$license = $this->createMock(LicenseService::class);
		$license->method('isMobilePlanActive')->willReturn(true);
		$license->method('assertMobileAccess')
			->willThrowException(new MobileGateException('seat_required'));

		$mw = $this->makeMw($request, $userSession, $license, $companionGate);
		$this->expectException(PaymentRequiredException::class);
		$this->expectExceptionMessage('NO_MOBILE_SEAT');
		$mw->beforeController(new \stdClass(), 'inbox');
	}

	public function testExpiredMapsToExpiredWireCode(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn($this->basicAuth());
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/companion/api/v1/inbox');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$companionGate = $this->createMock(CompanionGateService::class);
		$companionGate->method('canAccessCompanion')->willReturn(true);

		$license = $this->createMock(LicenseService::class);
		$license->method('isMobilePlanActive')->willReturn(true);
		$license->method('assertMobileAccess')
			->willThrowException(new MobileGateException('license_expired'));

		$mw = $this->makeMw($request, $userSession, $license, $companionGate);
		$this->expectException(PaymentRequiredException::class);
		$this->expectExceptionMessage('EXPIRED');
		$mw->beforeController(new \stdClass(), 'inbox');
	}

	public function testWebStaffApiNotGatedEvenWithBasicAuth(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn($this->basicAuth());
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/api/tickets/1');

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('isMobilePlanActive');
		$license->expects($this->never())->method('assertMobileAccess');

		$companionGate = $this->createMock(CompanionGateService::class);
		$companionGate->expects($this->never())->method('canAccessCompanion');

		$mw = $this->makeMw(
			$request,
			$this->createMock(IUserSession::class),
			$license,
			$companionGate,
		);
		$mw->beforeController(new \stdClass(), 'show');
	}

	public function testGuestBootstrapAllowedSoClientCanShowRoleDenied(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn($this->basicAuth());
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/companion/api/v1/bootstrap');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('guest-1');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$companionGate = $this->createMock(CompanionGateService::class);
		$companionGate->expects($this->never())->method('canAccessCompanion');

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('isMobilePlanActive');

		$mw = $this->makeMw($request, $userSession, $license, $companionGate);
		$mw->beforeController(new \stdClass(), 'bootstrap');
	}

	public function testGuestDeniedOnCompanionInbox(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn($this->basicAuth());
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/companion/api/v1/inbox');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('guest-1');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$companionGate = $this->createMock(CompanionGateService::class);
		$companionGate->method('canAccessCompanion')->with('guest-1')->willReturn(false);

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('isMobilePlanActive');

		$mw = $this->makeMw($request, $userSession, $license, $companionGate);
		$this->expectException(RoleDeniedException::class);
		$mw->beforeController(new \stdClass(), 'inbox');
	}

	public function testAfterExceptionUnauthorizedEnvelope(): void
	{
		$request = $this->createMock(IRequest::class);
		$mw = $this->makeMw(
			$request,
			$this->createMock(IUserSession::class),
			$this->createMock(LicenseService::class),
			$this->createMock(CompanionGateService::class),
		);
		$response = $mw->afterException(new \stdClass(), 'inbox', new CompanionUnauthorizedException());
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('NOT_AUTHENTICATED', $data['error']['code']);
	}

	public function testAfterExceptionMapsMaxDelayReachedToJson429(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/companion/api/v1/bootstrap');
		$mw = $this->makeMw(
			$request,
			$this->createMock(IUserSession::class),
			$this->createMock(LicenseService::class),
			$this->createMock(CompanionGateService::class),
		);
		$response = $mw->afterException(
			new \stdClass(),
			'bootstrap',
			new \OCP\Security\Bruteforce\MaxDelayReached(),
		);
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('RATE_LIMITED', $data['error']['code']);
		self::assertSame('rate_limited', $data['error']['type']);
	}

	public function testAfterControllerRewritesHtml429ToJson(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/companion/api/v1/inbox');
		$mw = $this->makeMw(
			$request,
			$this->createMock(IUserSession::class),
			$this->createMock(LicenseService::class),
			$this->createMock(CompanionGateService::class),
		);
		$html = new \OCP\AppFramework\Http\TooManyRequestsResponse();
		$out = $mw->afterController(new \stdClass(), 'inbox', $html);
		self::assertInstanceOf(JSONResponse::class, $out);
		self::assertSame(Http::STATUS_TOO_MANY_REQUESTS, $out->getStatus());
		self::assertSame('RATE_LIMITED', $out->getData()['error']['code']);
	}
}

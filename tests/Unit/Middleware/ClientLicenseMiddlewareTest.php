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
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\Authentication\Token\IProvider as ITokenProvider;
use OCP\Authentication\Token\IToken;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
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
		?ITokenProvider $tokenProvider = null,
		?IUserManager $userManager = null,
		?IThrottler $throttler = null,
	): ClientLicenseMiddleware {
		$session = $ncSession ?? $this->createMock(ISession::class);
		if ($ncSession === null) {
			$session->method('get')->with('app_password')->willReturn('tk-app-password-token');
		}
		if ($tokenProvider === null) {
			$tokenProvider = $this->createStub(ITokenProvider::class);
			$tokenProvider->method('getToken')
				->willThrowException(new InvalidTokenException());
		}
		$userManager ??= $this->createStub(IUserManager::class);
		$throttler ??= $this->createStub(IThrottler::class);
		return new ClientLicenseMiddleware(
			$request,
			$userSession,
			$session,
			$license,
			$companionGate,
			new NullLogger(),
			$tokenProvider,
			$userManager,
			$throttler,
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
		// Basic identity must match the session identity (explicit credential wins).
		$request->method('getHeader')->with('Authorization')->willReturn($this->basicAuth('guest-1'));
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
		// Basic identity must match the session identity (explicit credential wins).
		$request->method('getHeader')->with('Authorization')->willReturn($this->basicAuth('guest-1'));
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

	/**
	 * tkc-avd-cookie-session-shadows-basic-auth: a stale session cookie must not
	 * shadow a valid `Authorization: Basic` credential on companion routes.
	 * Core tryTokenLogin() resolves the cookie session before tryBasicAuthLogin()
	 * ever runs; the middleware must re-authenticate with the explicit
	 * credential so the request resolves as the Basic user (e2e_agent), not the
	 * cookie user (e2e_guest).
	 */
	public function testForeignSessionCookieDoesNotShadowBasicIdentity(): void
	{
		$agentToken = 'e2e-agent-app-password-token';

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')
			->willReturn($this->basicAuth('e2e_agent', $agentToken));
		$request->method('getPathInfo')
			->willReturn('/apps/ticketcheck/companion/api/v1/bootstrap');
		$request->method('getRemoteAddress')->willReturn('172.26.0.1');

		$guest = $this->createMock(IUser::class);
		$guest->method('getUID')->willReturn('e2e_guest');
		$agent = $this->createMock(IUser::class);
		$agent->method('getUID')->willReturn('e2e_agent');

		// Ambient session resolves the cookie user first; after the explicit
		// re-login the same session object must yield the Basic credential user.
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')
			->willReturnOnConsecutiveCalls($guest, $agent, $agent, $agent);
		$userSession->expects($this->once())
			->method('login')
			->with('e2e_agent', $agentToken)
			->willReturn(true);

		$token = $this->createMock(IToken::class);
		$token->method('getUID')->willReturn('e2e_agent');
		$tokenProvider = $this->createMock(ITokenProvider::class);
		$tokenProvider->method('getToken')->with($agentToken)->willReturn($token);

		$ncSession = $this->createMock(ISession::class);
		$ncSession->method('get')->with('app_password')->willReturn('e2e-guest-app-password');
		// Core logClientIn() semantics: the session is re-pinned to the
		// *presented* app password so validateSession() checks it later.
		$ncSession->expects($this->once())
			->method('set')
			->with('app_password', $agentToken);

		$userManager = $this->createMock(IUserManager::class);
		$throttler = $this->createMock(IThrottler::class);
		$throttler->expects($this->once())
			->method('sleepDelayOrThrowOnMax')
			->with('172.26.0.1', 'login');
		$throttler->expects($this->never())->method('registerAttempt');

		$companionGate = $this->createMock(CompanionGateService::class);
		$license = $this->createMock(LicenseService::class);

		$mw = $this->makeMw(
			$request,
			$userSession,
			$license,
			$companionGate,
			$ncSession,
			$tokenProvider,
			$userManager,
			$throttler,
		);
		// Bootstrap is role-reporting only — must not throw for the agent.
		$mw->beforeController(new \stdClass(), 'bootstrap');
	}

	public function testForeignSessionCookieResolvesBasicUserForGateCheck(): void
	{
		$agentToken = 'e2e-agent-app-password-token';

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')
			->willReturn($this->basicAuth('e2e_agent', $agentToken));
		$request->method('getPathInfo')
			->willReturn('/apps/ticketcheck/companion/api/v1/inbox');
		$request->method('getRemoteAddress')->willReturn('172.26.0.1');

		$guest = $this->createMock(IUser::class);
		$guest->method('getUID')->willReturn('e2e_guest');
		$agent = $this->createMock(IUser::class);
		$agent->method('getUID')->willReturn('e2e_agent');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')
			->willReturnOnConsecutiveCalls($guest, $agent, $agent, $agent);
		$userSession->method('login')->willReturn(true);

		$token = $this->createMock(IToken::class);
		$token->method('getUID')->willReturn('e2e_agent');
		$tokenProvider = $this->createMock(ITokenProvider::class);
		$tokenProvider->method('getToken')->willReturn($token);

		$ncSession = $this->createMock(ISession::class);
		$ncSession->method('get')->with('app_password')->willReturn('e2e-guest-app-password');

		// The companion gate must see the *Basic* user, not the cookie user.
		$companionGate = $this->createMock(CompanionGateService::class);
		$companionGate->expects($this->once())
			->method('canAccessCompanion')
			->with('e2e_agent')
			->willReturn(true);

		$license = $this->createMock(LicenseService::class);
		$license->method('isMobilePlanActive')->willReturn(true);
		$license->expects($this->once())->method('assertMobileAccess')->with('e2e_agent');

		$mw = $this->makeMw(
			$request,
			$userSession,
			$license,
			$companionGate,
			$ncSession,
			$tokenProvider,
			$this->createMock(IUserManager::class),
			$this->createMock(IThrottler::class),
		);
		$mw->beforeController(new \stdClass(), 'inbox');
	}

	public function testMatchingSessionIdentityDoesNotRelogin(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')
			->willReturn($this->basicAuth('alice', 'secret'));
		$request->method('getPathInfo')
			->willReturn('/apps/ticketcheck/companion/api/v1/bootstrap');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		// Ambient session already presents the claimed identity — no re-auth.
		$userSession->expects($this->never())->method('login');

		$license = $this->createMock(LicenseService::class);
		$companionGate = $this->createMock(CompanionGateService::class);

		$mw = $this->makeMw($request, $userSession, $license, $companionGate);
		$mw->beforeController(new \stdClass(), 'bootstrap');
	}

	public function testInvalidBasicWithForeignSessionIsRejectedAndThrottled(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')
			->willReturn($this->basicAuth('e2e_agent', 'wrong-password'));
		$request->method('getPathInfo')
			->willReturn('/apps/ticketcheck/companion/api/v1/bootstrap');
		$request->method('getRemoteAddress')->willReturn('172.26.0.1');

		$guest = $this->createMock(IUser::class);
		$guest->method('getUID')->willReturn('e2e_guest');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($guest);
		$userSession->method('login')->willReturn(false);

		$tokenProvider = $this->createMock(ITokenProvider::class);
		$tokenProvider->method('getToken')
			->willThrowException(new InvalidTokenException());

		$agent = $this->createMock(IUser::class);
		$agent->method('getUID')->willReturn('e2e_agent');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('e2e_agent')->willReturn($agent);
		$userManager->method('getByEmail')->willReturn([]);

		$throttler = $this->createMock(IThrottler::class);
		$throttler->expects($this->once())
			->method('registerAttempt')
			->with('login', '172.26.0.1', ['user' => 'e2e_agent']);

		$mw = $this->makeMw(
			$request,
			$userSession,
			$this->createMock(LicenseService::class),
			$this->createMock(CompanionGateService::class),
			null,
			$tokenProvider,
			$userManager,
			$throttler,
		);
		$this->expectException(CompanionUnauthorizedException::class);
		$this->expectExceptionMessage('NOT_AUTHENTICATED');
		$mw->beforeController(new \stdClass(), 'bootstrap');
	}

	public function testForeignSessionCookieOnWebPathIsUntouched(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')
			->willReturn($this->basicAuth('e2e_agent', 'e2e-agent-token'));
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/api/tickets');

		// Cookie user stays authoritative on non-companion routes: never re-login.
		$userSession = $this->createMock(IUserSession::class);
		$userSession->expects($this->never())->method('getUser');
		$userSession->expects($this->never())->method('login');

		$mw = $this->makeMw(
			$request,
			$userSession,
			$this->createMock(LicenseService::class),
			$this->createMock(CompanionGateService::class),
		);
		$mw->beforeController(new \stdClass(), 'index');
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

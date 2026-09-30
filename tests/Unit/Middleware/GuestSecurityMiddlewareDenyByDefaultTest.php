<?php

declare(strict_types=1);

/**
 * GuestSecurityMiddleware must deny any non-allowlisted path for guests (no fail-open).
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Tests\Unit\Middleware;

use OCA\Ticketcheck\Exception\AppAccessDeniedException;
use OCA\Ticketcheck\Middleware\GuestSecurityMiddleware;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GuestSecurityMiddlewareDenyByDefaultTest extends TestCase
{
	private function makeL10nFactory(): IFactory
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $key): string => $key);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		return $factory;
	}

	public function testGuestNonAllowlistedSettingsAdminIsBlocked(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('guest1');
		$user->method('getEMailAddress')->willReturn('guest@example.com');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')
			->with('guest1', 'helpdesk_customers')
			->willReturn(true);

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('canAccessApp')->willReturn(true);

		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/settings/admin');
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$request->method('getHeader')->willReturn('phpunit');

		$middleware = new GuestSecurityMiddleware(
			$userSession,
			$groupManager,
			$this->createMock(IURLGenerator::class),
			$request,
			$this->createMock(LoggerInterface::class),
			$permissionService,
			$this->makeL10nFactory()
		);

		$this->expectException(AppAccessDeniedException::class);
		$middleware->beforeController(new \stdClass(), 'index');
	}

	public function testGuestAllowlistedPortalPasses(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('guest1');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')
			->with('guest1', 'helpdesk_customers')
			->willReturn(true);

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('canAccessApp')->willReturn(true);

		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/portal');

		$middleware = new GuestSecurityMiddleware(
			$userSession,
			$groupManager,
			$this->createMock(IURLGenerator::class),
			$request,
			$this->createMock(LoggerInterface::class),
			$permissionService,
			$this->makeL10nFactory()
		);

		$middleware->beforeController(new \stdClass(), 'index');
		$this->addToAssertionCount(1);
	}

	public function testGuestSettingsAppRootIsBlocked(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('guest1');
		$user->method('getEMailAddress')->willReturn('guest@example.com');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(true);

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('canAccessApp')->willReturn(true);

		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/settings');
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$request->method('getHeader')->willReturn('phpunit');

		$middleware = new GuestSecurityMiddleware(
			$userSession,
			$groupManager,
			$this->createMock(IURLGenerator::class),
			$request,
			$this->createMock(LoggerInterface::class),
			$permissionService,
			$this->makeL10nFactory()
		);

		$this->expectException(AppAccessDeniedException::class);
		$middleware->beforeController(new \stdClass(), 'index');
	}

	public function testAfterExceptionDoesNotRedirectLoopWhenGuestDeniedOnPortal(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('guest1');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->with('guest1', 'helpdesk_customers')->willReturn(true);

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('canAccessApp')->willReturn(false);

		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/portal');
		$request->method('getMethod')->willReturn('GET');

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(static function (string $route): string {
			self::assertNotSame('ticketcheck.customerPortal.index', $route);
			if ($route === 'core.login.logout') {
				return '/logout';
			}
			return '/' . $route;
		});

		$middleware = new GuestSecurityMiddleware(
			$userSession,
			$groupManager,
			$urlGenerator,
			$request,
			$this->createMock(LoggerInterface::class),
			$permissionService,
			$this->makeL10nFactory()
		);

		$response = $middleware->afterException(
			new \stdClass(),
			'index',
			new AppAccessDeniedException('You are not allowed to access Ticketcheck.')
		);

		self::assertInstanceOf(TemplateResponse::class, $response);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(TemplateResponse::RENDER_AS_GUEST, $response->getRenderAs());
		$params = $response->getParams();
		self::assertSame('/logout', $params['homeUrl'] ?? null);
		self::assertSame('logout', $params['homeLabel'] ?? null);
		self::assertSame('', $params['portalUrl'] ?? null);
	}

	public function testAfterExceptionStaffAppAccessDenyIsTerminal403NotHomeRedirect(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('agent1');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->with('agent1', 'helpdesk_customers')->willReturn(false);

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('canAccessApp')->willReturn(false);

		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/tickets');
		$request->method('getMethod')->willReturn('GET');

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToDefaultPageUrl')->willReturn('/apps/files');
		$urlGenerator->method('linkToRoute')->willReturnCallback(static function (string $route): string {
			self::assertNotSame('ticketcheck.customerPortal.index', $route);
			return '/' . $route;
		});

		$middleware = new GuestSecurityMiddleware(
			$userSession,
			$groupManager,
			$urlGenerator,
			$request,
			$this->createMock(LoggerInterface::class),
			$permissionService,
			$this->makeL10nFactory()
		);

		$response = $middleware->afterException(
			new \stdClass(),
			'index',
			new AppAccessDeniedException('You are not allowed to access Ticketcheck.')
		);

		self::assertInstanceOf(TemplateResponse::class, $response);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(TemplateResponse::RENDER_AS_USER, $response->getRenderAs());
		$params = $response->getParams();
		self::assertSame('/apps/files', $params['homeUrl'] ?? null);
		self::assertSame('back_to_nextcloud', $params['homeLabel'] ?? null);
	}

	public function testAfterExceptionRedirectsGuestToPortalWhenOffAllowlistWithAppAccess(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('guest1');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->with('guest1', 'helpdesk_customers')->willReturn(true);

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('canAccessApp')->willReturn(true);

		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/files');
		$request->method('getMethod')->willReturn('GET');

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')
			->with('ticketcheck.customerPortal.index')
			->willReturn('/apps/ticketcheck/portal');

		$middleware = new GuestSecurityMiddleware(
			$userSession,
			$groupManager,
			$urlGenerator,
			$request,
			$this->createMock(LoggerInterface::class),
			$permissionService,
			$this->makeL10nFactory()
		);

		$response = $middleware->afterException(
			new \stdClass(),
			'index',
			new AppAccessDeniedException('Guest users can only access the helpdesk portal.')
		);

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/apps/ticketcheck/portal', $response->getRedirectUrl());
	}

	public function testGuestCompanionBootstrapPathPassesIsolation(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('guest1');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')
			->with('guest1', 'helpdesk_customers')
			->willReturn(true);

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('canAccessApp')->willReturn(true);

		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/companion/api/v1/bootstrap');

		$middleware = new GuestSecurityMiddleware(
			$userSession,
			$groupManager,
			$this->createMock(IURLGenerator::class),
			$request,
			$this->createMock(LoggerInterface::class),
			$permissionService,
			$this->makeL10nFactory()
		);

		$middleware->beforeController(new \stdClass(), 'bootstrap');
		$this->addToAssertionCount(1);
	}

	public function testCompanionAppAccessDenyReturnsCompanionEnvelope(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(false);

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('canAccessApp')->willReturn(false);

		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/companion/api/v1/inbox');

		$middleware = new GuestSecurityMiddleware(
			$userSession,
			$groupManager,
			$this->createMock(IURLGenerator::class),
			$request,
			$this->createMock(LoggerInterface::class),
			$permissionService,
			$this->makeL10nFactory()
		);

		$response = $middleware->afterException(
			new \stdClass(),
			'inbox',
			new AppAccessDeniedException('You are not allowed to access Ticketcheck.')
		);

		self::assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $response);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('APP_ACCESS_DENIED', $data['error']['code']);
	}
}

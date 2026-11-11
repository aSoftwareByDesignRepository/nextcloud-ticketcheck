<?php

declare(strict_types=1);

/**
 * My-requests surface gates (spec D-R10/US-R04).
 *
 * The surface exists only for dual-role staff (requester-group member WITH
 * staff scope). Non-members, contained guests, and pure requesters are
 * redirected to their canonical surfaces — never the list.
 */

namespace OCA\Ticketcheck\Tests\Unit\Controller;

use OCA\Ticketcheck\Controller\MyRequestsController;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\PortalTicketListFilterService;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

final class MyRequestsControllerGateTest extends TestCase
{
	private function controller(PermissionService $perm): MyRequestsController
	{
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(
			static fn (string $route): string => '/apps/ticketcheck/' . $route
		);
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);

		return new MyRequestsController(
			'ticketcheck',
			$this->createMock(IRequest::class),
			$perm,
			$this->createMock(TicketMapper::class),
			$urlGenerator,
			$l10nFactory,
			$this->createMock(LocaleFormatService::class),
			$this->createMock(NavigationContextService::class),
			$this->createMock(FrontEndAssetService::class),
			new PortalTicketListFilterService(),
		);
	}

	private function permission(
		string $uid,
		bool $requesterMember,
		bool $guest,
		bool $requester,
	): PermissionService {
		$perm = $this->createMock(PermissionService::class);
		$perm->method('getCurrentUserId')->willReturn($uid);
		$perm->method('isRequesterGroupMember')->willReturn($requesterMember);
		$perm->method('isGuest')->willReturn($guest);
		$perm->method('isRequester')->willReturn($requester);
		return $perm;
	}

	public function testNonRequesterUserRedirectsToEntryPoint(): void
	{
		$perm = $this->permission('plain1', false, false, false);
		$response = $this->controller($perm)->index();
		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertStringContainsString('page.index', $response->getRedirectURL());
	}

	public function testGuestContainedUserRedirectsToPortal(): void
	{
		// Dual-member guest+requester: guest containment wins — the portal,
		// never the staff surface.
		$perm = $this->permission('guest1', true, true, false);
		$response = $this->controller($perm)->index();
		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertStringContainsString('customerPortal.index', $response->getRedirectURL());
	}

	public function testPureRequesterRedirectsToPortal(): void
	{
		// Pure requesters live on the portal — this surface is dual-role only.
		$perm = $this->permission('req1', true, false, true);
		$response = $this->controller($perm)->index();
		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertStringContainsString('customerPortal.index', $response->getRedirectURL());
	}

	public function testUnauthenticatedRequestRedirectsToEntryPoint(): void
	{
		$perm = $this->permission('', false, false, false);
		$response = $this->controller($perm)->index();
		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertStringContainsString('page.index', $response->getRedirectURL());
	}
}

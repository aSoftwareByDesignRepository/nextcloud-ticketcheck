<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\TicketController;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\ActivityEventService;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\DeletionService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\MergeService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCA\Ticketcheck\Service\SplitService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWatcherService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * removeLink must authz before any link lookup. Missing → 404 ticket_not_found;
 * AuthZ deny → explicit 403 access_denied (sealed contract, never 404-as-deny).
 */
final class TicketControllerRemoveLinkOracleTest extends TestCase
{
	/** @var TicketService&MockObject */
	private TicketService $ticketService;
	/** @var PermissionService&MockObject */
	private PermissionService $permissionService;
	/** @var TicketLinkService&MockObject */
	private TicketLinkService $ticketLinkService;
	/** @var IRequest&MockObject */
	private IRequest $request;
	private TicketController $controller;

	protected function setUp(): void
	{
		parent::setUp();
		$this->ticketService = $this->createMock(TicketService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->ticketLinkService = $this->createMock(TicketLinkService::class);
		$this->request = $this->createMock(IRequest::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $key): string => $key);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);

		$this->controller = new TicketController(
			'ticketcheck',
			$this->request,
			$this->ticketService,
			$this->permissionService,
			$this->createMock(MergeService::class),
			$this->createMock(EmailService::class),
			$this->createMock(ProjectService::class),
			$this->createMock(DeletionService::class),
			$this->createMock(TicketMapper::class),
			$this->createMock(AttachmentMapper::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(IUserSession::class),
			$this->createMock(SafeFilenameService::class),
			$l10nFactory,
			$this->createMock(IConfig::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
			$this->ticketLinkService,
			$this->createMock(TicketWatcherService::class),
			$this->createMock(SplitService::class),
			$this->createMock(ActivityEventService::class),
			$this->createMock(AttachmentUploadService::class),
			$this->createMock(LocaleFormatService::class),
			$this->createMock(NavigationContextService::class),
			$this->createMock(FrontEndAssetService::class),
			$this->createMock(AttachmentDeliveryService::class),
		);
	}

	public function testRemoveLinkMissingTicketIs404NotFound(): void
	{
		$this->request->method('getParam')->willReturnCallback(static fn (string $k, $d = null) => match ($k) {
			'link_id' => 9,
			default => $d,
		});
		$this->ticketService->method('getActiveTicket')
			->willThrowException(new DoesNotExistException('missing'));
		$this->ticketLinkService->expects(self::never())->method('removeLinkForTicket');

		$response = $this->controller->removeLink(77);
		self::assertSame(404, $response->getStatus());
		self::assertSame('ticket_not_found', $response->getData()['message'] ?? null);
	}

	public function testRemoveLinkForeignTicketIs403DeniedWithoutLinkLookup(): void
	{
		$ticket = new Ticket();
		$ticket->setId(77);
		$this->request->method('getParam')->willReturnCallback(static fn (string $k, $d = null) => match ($k) {
			'link_id' => 9,
			default => $d,
		});
		$this->ticketService->method('getActiveTicket')->with(77)->willReturn($ticket);
		$this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(false);
		$this->ticketLinkService->expects(self::never())->method('removeLinkForTicket');

		$response = $this->controller->removeLink(77);
		self::assertSame(403, $response->getStatus());
		self::assertSame('access_denied', $response->getData()['message'] ?? null);
	}

	public function testRemoveLinkMissingLinkAfterAuthzIsInvalidParameters(): void
	{
		$ticket = new Ticket();
		$ticket->setId(77);
		$this->request->method('getParam')->willReturnCallback(static fn (string $k, $d = null) => match ($k) {
			'link_id' => 9,
			default => $d,
		});
		$this->ticketService->method('getActiveTicket')->willReturn($ticket);
		$this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(true);
		$this->permissionService->method('canEditTicket')->willReturn(true);
		$this->ticketLinkService->method('removeLinkForTicket')
			->willThrowException(new DoesNotExistException('no link'));

		$response = $this->controller->removeLink(77);
		self::assertSame(400, $response->getStatus());
		self::assertSame('invalid_parameters', $response->getData()['message'] ?? null);
	}

	public function testRemoveLinkByPairMissingLinkedIs404NotFound(): void
	{
		$route = new Ticket();
		$route->setId(10);
		$this->request->method('getParam')->willReturnCallback(static fn (string $k, $d = null) => match ($k) {
			'linked_ticket_id' => 999,
			'link_type' => 'related',
			default => $d,
		});
		$this->ticketService->method('getActiveTicket')->willReturnCallback(
			static function (int $id) use ($route) {
				if ($id === 10) {
					return $route;
				}
				throw new DoesNotExistException('missing linked');
			}
		);
		$this->permissionService->method('canViewTicketWithProjectAccess')->with($route)->willReturn(true);
		$this->permissionService->method('canEditTicket')->with($route)->willReturn(true);
		$this->ticketLinkService->expects(self::never())->method('removeLinkByPair');

		$missing = $this->controller->removeLink(10);
		self::assertSame(404, $missing->getStatus());
		self::assertSame('ticket_not_found', $missing->getData()['message'] ?? null);
	}

	public function testRemoveLinkByPairForeignLinkedIs403WithoutServiceCall(): void
	{
		$route = new Ticket();
		$route->setId(10);
		$foreign = new Ticket();
		$foreign->setId(999);
		$this->request->method('getParam')->willReturnCallback(static fn (string $k, $d = null) => match ($k) {
			'linked_ticket_id' => 999,
			'link_type' => 'related',
			default => $d,
		});
		$this->ticketService->method('getActiveTicket')->willReturnCallback(
			static function (int $id) use ($route, $foreign) {
				return $id === 10 ? $route : $foreign;
			}
		);
		$this->permissionService->method('canViewTicketWithProjectAccess')->willReturnCallback(
			static function ($ticket) use ($route) {
				return $ticket === $route;
			}
		);
		$this->permissionService->method('canEditTicket')->with($route)->willReturn(true);
		$this->ticketLinkService->expects(self::never())->method('removeLinkByPair');

		$response = $this->controller->removeLink(10);
		self::assertSame(403, $response->getStatus());
		self::assertSame('access_denied', $response->getData()['message'] ?? null);
	}
}

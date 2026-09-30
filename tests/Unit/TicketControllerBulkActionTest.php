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
 * Bulk actions must go through TicketService (merge-safe, cascading delete).
 */
class TicketControllerBulkActionTest extends TestCase
{
	/** @var IRequest&MockObject */
	private IRequest $request;
	/** @var TicketService&MockObject */
	private TicketService $ticketService;
	/** @var PermissionService&MockObject */
	private PermissionService $permissionService;
	/** @var TicketMapper&MockObject */
	private TicketMapper $ticketMapper;
	private TicketController $controller;

	protected function setUp(): void
	{
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->ticketService = $this->createMock(TicketService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->ticketMapper = $this->createMock(TicketMapper::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $key): string => $key);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);

		$this->permissionService->method('isGuest')->willReturn(false);
		$this->permissionService->method('canViewHelpdeskOverview')->willReturn(true);
		$this->permissionService->method('getCurrentUserId')->willReturn('agent1');

		$this->controller = new TicketController(
			'ticketcheck',
			$this->request,
			$this->ticketService,
			$this->permissionService,
			$this->createMock(MergeService::class),
			$this->createMock(EmailService::class),
			$this->createMock(ProjectService::class),
			$this->createMock(DeletionService::class),
			$this->ticketMapper,
			$this->createMock(AttachmentMapper::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(IUserSession::class),
			$this->createMock(SafeFilenameService::class),
			$l10nFactory,
			$this->createMock(IConfig::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(TicketLinkService::class),
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

	public function testBulkDeleteUsesTicketServiceCascade(): void
	{
		$ticket = new Ticket();
		$ticket->setId(5);
		$ticket->setStatus(Ticket::STATUS_NEW);

		$this->request->method('getParam')->willReturnCallback(static function (string $key, $default = null) {
			return match ($key) {
				'action' => 'delete',
				'ticket_ids' => [5],
				'data' => [],
				default => $default,
			};
		});
		$this->ticketMapper->method('find')->with(5)->willReturn($ticket);
		$this->ticketService->method('getActiveTicket')->with(5)->willReturn($ticket);
		$this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(true);
		$this->permissionService->method('canEditTicket')->with($ticket)->willReturn(true);
		$this->permissionService->method('canDeleteTicket')->with($ticket)->willReturn(true);
		$this->ticketService->expects(self::once())->method('deleteTicket')->with(5);
		$this->ticketMapper->expects(self::never())->method('delete');

		$response = $this->controller->bulkAction();

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertSame(1, $data['succeeded'] ?? null);
		self::assertTrue($data['results'][0]['success'] ?? false);
	}

	public function testBulkStatusUsesChangeStatusService(): void
	{
		$ticket = new Ticket();
		$ticket->setId(8);
		$ticket->setStatus(Ticket::STATUS_NEW);

		$this->request->method('getParam')->willReturnCallback(static function (string $key, $default = null) {
			return match ($key) {
				'action' => 'status',
				'ticket_ids' => [8],
				'data' => ['status' => 'resolved'],
				default => $default,
			};
		});
		$this->ticketMapper->method('find')->with(8)->willReturn($ticket);
		$this->ticketService->method('getActiveTicket')->with(8)->willReturn($ticket);
		$this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(true);
		$this->permissionService->method('canEditTicket')->with($ticket)->willReturn(true);
		$this->ticketService->expects(self::once())->method('changeStatus')->with(8, Ticket::STATUS_DONE)->willReturn($ticket);
		$this->ticketMapper->expects(self::never())->method('update');

		$response = $this->controller->bulkAction();

		self::assertSame(200, $response->getStatus());
		self::assertSame(1, $response->getData()['succeeded'] ?? null);
	}

	public function testBulkRejectsOversizedSelection(): void
	{
		$ids = range(1, 101);
		$this->request->method('getParam')->willReturnCallback(static function (string $key, $default = null) use ($ids) {
			return match ($key) {
				'action' => 'status',
				'ticket_ids' => $ids,
				'data' => ['status' => 'done'],
				default => $default,
			};
		});

		$response = $this->controller->bulkAction();

		self::assertSame(400, $response->getStatus());
		self::assertSame('too_many_requests', $response->getData()['error'] ?? null);
	}

	public function testBulkRejectsGuests(): void
	{
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->permissionService->method('isGuest')->willReturn(true);
		// Rebuild controller with guest permission mock
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
			$this->ticketMapper,
			$this->createMock(AttachmentMapper::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(IUserSession::class),
			$this->createMock(SafeFilenameService::class),
			$l10nFactory,
			$this->createMock(IConfig::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(TicketLinkService::class),
			$this->createMock(TicketWatcherService::class),
			$this->createMock(SplitService::class),
			$this->createMock(ActivityEventService::class),
			$this->createMock(AttachmentUploadService::class),
			$this->createMock(LocaleFormatService::class),
			$this->createMock(NavigationContextService::class),
			$this->createMock(FrontEndAssetService::class),
			$this->createMock(AttachmentDeliveryService::class),
		);

		$this->request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => match ($key) {
			'action' => 'status',
			'ticket_ids' => [1],
			'data' => ['status' => 'done'],
			default => $default,
		});
		$this->ticketService->expects(self::never())->method('getActiveTicket');

		$response = $this->controller->bulkAction();
		self::assertSame(403, $response->getStatus());
		self::assertSame('access_denied_use_customer_portal', $response->getData()['error'] ?? null);
	}

	public function testBulkMissingAndForeignShareTicketNotFound(): void
	{
		$this->request->method('getParam')->willReturnCallback(static function (string $key, $default = null) {
			return match ($key) {
				'action' => 'status',
				'ticket_ids' => [101, 202],
				'data' => ['status' => 'done'],
				default => $default,
			};
		});

		$foreign = new Ticket();
		$foreign->setId(202);
		$this->ticketService->method('getActiveTicket')->willReturnCallback(
			function (int $id) use ($foreign) {
				if ($id === 101) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('missing');
				}
				return $foreign;
			}
		);
		$this->permissionService->method('canViewTicketWithProjectAccess')->with($foreign)->willReturn(false);
		$this->ticketService->expects(self::never())->method('changeStatus');

		$response = $this->controller->bulkAction();
		self::assertSame(200, $response->getStatus());
		$results = $response->getData()['results'] ?? [];
		self::assertCount(2, $results);
		self::assertSame('ticket_not_found', $results[0]['error'] ?? null);
		self::assertSame('ticket_not_found', $results[1]['error'] ?? null);
		self::assertFalse($results[0]['success']);
		self::assertFalse($results[1]['success']);
	}
}

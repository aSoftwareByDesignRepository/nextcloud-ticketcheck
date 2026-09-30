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
 * Staff mutate APIs: missing ticket → 404 ticket_not_found; AuthZ deny
 * (foreign project / no view access) → explicit 403 access_denied — the sealed
 * contract (AtlasApiEndpointHappyAuthzTest, never 404-as-deny). Capability deny
 * on a viewable ticket is likewise 403. Denies must never reach services.
 */
final class TicketControllerStaffMutateOracleTest extends TestCase
{
	/** @var TicketService&MockObject */
	private TicketService $ticketService;
	/** @var PermissionService&MockObject */
	private PermissionService $permissionService;
	/** @var IRequest&MockObject */
	private IRequest $request;
	private TicketController $controller;

	protected function setUp(): void
	{
		parent::setUp();
		$this->ticketService = $this->createMock(TicketService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->request = $this->createMock(IRequest::class);

		$this->permissionService->method('isGuest')->willReturn(false);
		$this->permissionService->method('canViewHelpdeskOverview')->willReturn(true);
		$this->permissionService->method('getCurrentUserId')->willReturn('member1');

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

	private function foreignTicket(): Ticket
	{
		$ticket = new Ticket();
		$ticket->setId(77);
		$ticket->setProjectId(999);
		return $ticket;
	}

	public function testMissingTicketUpdateIs404NotFound(): void
	{
		$this->ticketService->method('getActiveTicket')
			->willThrowException(new DoesNotExistException('missing'));
		$this->ticketService->expects(self::never())->method('updateTicket');

		$response = $this->controller->update(77);
		self::assertSame(404, $response->getStatus());
		self::assertSame('ticket_not_found', $response->getData()['message'] ?? null);
	}

	public function testForeignProjectUpdateIs403Denied(): void
	{
		$ticket = $this->foreignTicket();
		$this->ticketService->method('getActiveTicket')->with(77)->willReturn($ticket);
		$this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(false);
		$this->permissionService->expects(self::never())->method('canEditTicket');
		$this->ticketService->expects(self::never())->method('updateTicket');

		$response = $this->controller->update(77);
		self::assertSame(403, $response->getStatus());
		self::assertSame('access_denied', $response->getData()['message'] ?? null);
	}

	public function testForeignProjectIs403DeniedOnMutateEndpoints(): void
	{
		$ticket = $this->foreignTicket();
		$this->ticketService->method('getActiveTicket')->willReturn($ticket);
		$this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(false);

		foreach ([
			fn () => $this->controller->update(77),
			fn () => $this->controller->updatePost(77),
			fn () => $this->controller->delete(77),
			fn () => $this->controller->changeStatus(77),
			fn () => $this->controller->assign(77),
			fn () => $this->controller->addComment(77),
			fn () => $this->controller->uploadAttachment(77),
			fn () => $this->controller->deleteAttachment(77, 1),
			fn () => $this->controller->searchAssignableUsers(77),
		] as $call) {
			$response = $call();
			self::assertSame(403, $response->getStatus(), (string) json_encode($response->getData()));
			self::assertSame('access_denied', $response->getData()['message'] ?? null);
		}
	}

	public function testViewableWithoutEditStill403OnUpdate(): void
	{
		$ticket = $this->foreignTicket();
		$ticket->setProjectId(12);
		$this->ticketService->method('getActiveTicket')->with(77)->willReturn($ticket);
		$this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(true);
		$this->permissionService->method('canEditTicket')->with($ticket)->willReturn(false);
		$this->ticketService->expects(self::never())->method('updateTicket');

		$response = $this->controller->update(77);
		self::assertSame(403, $response->getStatus());
		self::assertSame('access_denied', $response->getData()['message'] ?? null);
	}
}

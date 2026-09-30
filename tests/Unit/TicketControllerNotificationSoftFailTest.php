<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\TicketController;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Comment;
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
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Successful mutations must stay HTTP 200 when email/activity throw (no retry duplicates).
 */
final class TicketControllerNotificationSoftFailTest extends TestCase
{
	/** @var IRequest&MockObject */
	private IRequest $request;
	/** @var TicketService&MockObject */
	private TicketService $ticketService;
	/** @var PermissionService&MockObject */
	private PermissionService $permissionService;
	/** @var EmailService&MockObject */
	private EmailService $emailService;
	/** @var ActivityEventService&MockObject */
	private ActivityEventService $activityEventService;
	/** @var IUserSession&MockObject */
	private IUserSession $userSession;
	private TicketController $controller;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->ticketService = $this->createMock(TicketService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->emailService = $this->createMock(EmailService::class);
		$this->activityEventService = $this->createMock(ActivityEventService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $key): string => $key);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);

		$this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(true);
		$this->permissionService->method('canEditTicket')->willReturn(true);
		$this->permissionService->method('canCommentOnTicket')->willReturn(true);
		$this->permissionService->method('canCreateInternalNotes')->willReturn(false);
		$this->permissionService->method('getCurrentUserDisplayName')->willReturn('Agent');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('agent1');
		$user->method('getDisplayName')->willReturn('Agent');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new TicketController(
			'ticketcheck',
			$this->request,
			$this->ticketService,
			$this->permissionService,
			$this->createMock(MergeService::class),
			$this->emailService,
			$this->createMock(ProjectService::class),
			$this->createMock(DeletionService::class),
			$this->createMock(TicketMapper::class),
			$this->createMock(AttachmentMapper::class),
			$this->createMock(IURLGenerator::class),
			$this->userSession,
			$this->createMock(SafeFilenameService::class),
			$l10nFactory,
			$this->createMock(IConfig::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(TicketLinkService::class),
			$this->createMock(TicketWatcherService::class),
			$this->createMock(SplitService::class),
			$this->activityEventService,
			$this->createMock(AttachmentUploadService::class),
			$this->createMock(LocaleFormatService::class),
			$this->createMock(NavigationContextService::class),
			$this->createMock(FrontEndAssetService::class),
			$this->createMock(AttachmentDeliveryService::class),
		);
	}

	public function testAddCommentSucceedsWhenEmailThrows(): void
	{
		$ticket = new Ticket();
		$ticket->setId(5);
		$this->ticketService->method('getActiveTicket')->willReturn($ticket);

		$comment = new Comment();
		$comment->setId(3);
		$comment->setAuthorName('Agent');
		$comment->setContent('Hello');
		$comment->setCreatedAt(new \DateTime('2026-07-24 12:00:00'));
		$this->ticketService->expects(self::once())->method('addComment')->willReturn($comment);

		$this->request->method('getParam')->willReturnCallback(static fn (string $k, $d = null) => match ($k) {
			'content' => 'Hello',
			'is_internal' => false,
			default => $d,
		});

		$this->emailService->method('sendNewCommentNotification')
			->willThrowException(new \RuntimeException('smtp down'));
		$this->activityEventService->expects(self::once())->method('logTicketCommentAdded');

		$response = $this->controller->addComment(5);
		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['success'] ?? false);
	}

	public function testChangeStatusSucceedsWhenEmailThrows(): void
	{
		$ticket = new Ticket();
		$ticket->setId(5);
		$ticket->setStatus(Ticket::STATUS_NEW);
		$this->ticketService->method('getActiveTicket')->willReturn($ticket);

		$updated = new Ticket();
		$updated->setId(5);
		$updated->setStatus(Ticket::STATUS_IN_PROGRESS);
		$this->ticketService->expects(self::once())->method('changeStatus')->willReturn($updated);

		$this->request->method('getParam')->willReturnCallback(static fn (string $k, $d = null) => match ($k) {
			'status' => 'in_progress',
			default => $d,
		});

		$this->emailService->method('sendStatusChangedNotification')
			->willThrowException(new \RuntimeException('smtp down'));
		$this->activityEventService->expects(self::once())->method('logTicketStatusChanged');

		$response = $this->controller->changeStatus(5);
		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['success'] ?? false);
	}

	public function testAssignSucceedsWhenEmailThrowsError(): void
	{
		$ticket = new Ticket();
		$ticket->setId(5);
		$this->ticketService->method('getActiveTicket')->willReturn($ticket);
		$updated = new Ticket();
		$updated->setId(5);
		$this->ticketService->expects(self::once())->method('assignTicket')->willReturn($updated);

		$this->request->method('getParam')->willReturnCallback(static fn (string $k, $d = null) => match ($k) {
			'user_id' => 'agent2',
			default => $d,
		});

		$this->emailService->method('sendTicketAssignedNotification')
			->willThrowException(new \Error('engine fatal in mailer'));
		$this->activityEventService->expects(self::once())->method('logTicketAssigned');

		$response = $this->controller->assign(5);
		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['success'] ?? false);
	}
}

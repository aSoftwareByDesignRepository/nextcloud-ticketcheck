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
 * Reply / comment API security: private-note privilege and attachment authz alignment.
 */
class TicketControllerAddCommentSecurityTest extends TestCase
{
	/** @var IRequest&MockObject */
	private $request;
	/** @var TicketService&MockObject */
	private $ticketService;
	/** @var PermissionService&MockObject */
	private $permissionService;
	/** @var TicketMapper&MockObject */
	private $ticketMapper;
	/** @var EmailService&MockObject */
	private $emailService;
	/** @var ActivityEventService&MockObject */
	private $activityEventService;
	/** @var IUserSession&MockObject */
	private $userSession;
	private TicketController $controller;

	protected function setUp(): void
	{
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->ticketService = $this->createMock(TicketService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->ticketMapper = $this->createMock(TicketMapper::class);
		$this->emailService = $this->createMock(EmailService::class);
		$this->activityEventService = $this->createMock(ActivityEventService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $key): string => $key);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->with('ticketcheck')->willReturn($l10n);

		$this->controller = new TicketController(
			'ticketcheck',
			$this->request,
			$this->ticketService,
			$this->permissionService,
			$this->createMock(MergeService::class),
			$this->emailService,
			$this->createMock(ProjectService::class),
			$this->createMock(DeletionService::class),
			$this->ticketMapper,
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
			new AttachmentDeliveryService($this->createMock(IConfig::class), new SafeFilenameService()),
		);
	}

	public function testAddCommentStripsInternalFlagWithoutPrivilege(): void
	{
		$ticket = new Ticket();
		$ticket->setId(39);
		$this->ticketService->method('getActiveTicket')->with(39)->willReturn($ticket);
		$this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(true);
		$this->permissionService->method('canCommentOnTicket')->with($ticket)->willReturn(true);
		$this->permissionService->method('canCreateInternalNotes')->willReturn(false);
		$this->permissionService->method('getCurrentUserDisplayName')->willReturn('Member');

		$this->request->method('getParam')->willReturnCallback(static function (string $key, $default = null) {
			return match ($key) {
				'content' => 'Visible reply',
				'is_internal' => true,
				default => $default,
			};
		});

		$saved = new Comment();
		$saved->setId(7);
		$saved->setAuthorName('Member');
		$saved->setContent('Visible reply');
		$saved->setCreatedAt(new \DateTime('2026-07-22 12:00:00'));

		$this->ticketService->expects(self::once())
			->method('addComment')
			->with(39, 'Visible reply', false)
			->willReturn($saved);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('member1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->emailService->expects(self::once())->method('sendNewCommentNotification');
		$this->activityEventService->expects(self::once())
			->method('logTicketCommentAdded')
			->with('member1', $ticket, false);

		$response = $this->controller->addComment(39);
		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testAddCommentAllowsInternalNotesForAgents(): void
	{
		$ticket = new Ticket();
		$ticket->setId(39);
		$this->ticketService->method('getActiveTicket')->with(39)->willReturn($ticket);
		$this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(true);
		$this->permissionService->method('canCommentOnTicket')->with($ticket)->willReturn(true);
		$this->permissionService->method('canCreateInternalNotes')->willReturn(true);

		$this->request->method('getParam')->willReturnCallback(static function (string $key, $default = null) {
			return match ($key) {
				'content' => 'Private note',
				'is_internal' => '1',
				default => $default,
			};
		});

		$saved = new Comment();
		$saved->setId(8);
		$saved->setAuthorName('Agent');
		$saved->setContent('Private note');
		$saved->setCreatedAt(new \DateTime('2026-07-22 12:00:00'));

		$this->ticketService->expects(self::once())
			->method('addComment')
			->with(39, 'Private note', true)
			->willReturn($saved);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('agent1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->emailService->expects(self::never())->method('sendNewCommentNotification');
		$this->activityEventService->expects(self::once())
			->method('logTicketCommentAdded')
			->with('agent1', $ticket, true);

		$response = $this->controller->addComment(39);
		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testAddCommentAuthorizesSurvivorWhenSourceWasMerged(): void
	{
		$source = new Ticket();
		$source->setId(10);
		$source->setMergedIntoId(20);

		$survivor = new Ticket();
		$survivor->setId(20);

		$this->ticketService->method('getActiveTicket')->with(10)->willReturn($survivor);
		$this->permissionService->method('canViewTicketWithProjectAccess')->with($survivor)->willReturn(true);
		// Authz must run on survivor (20), not the merged source (10).
		$this->permissionService->expects(self::once())
			->method('canCommentOnTicket')
			->with($survivor)
			->willReturn(true);
		$this->permissionService->method('canCreateInternalNotes')->willReturn(false);
		$this->permissionService->method('getCurrentUserDisplayName')->willReturn('Member');

		$this->request->method('getParam')->willReturnCallback(static function (string $key, $default = null) {
			return match ($key) {
				'content' => 'Follow-up on merged thread',
				'is_internal' => false,
				default => $default,
			};
		});

		$saved = new Comment();
		$saved->setId(9);
		$saved->setAuthorName('Member');
		$saved->setContent('Follow-up on merged thread');
		$saved->setCreatedAt(new \DateTime('2026-07-22 12:00:00'));

		$this->ticketService->expects(self::once())
			->method('addComment')
			->with(20, 'Follow-up on merged thread', false)
			->willReturn($saved);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('member1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->emailService->expects(self::once())
			->method('sendNewCommentNotification')
			->with($survivor, 'Member', 'Follow-up on merged thread', 'member1');
		$this->activityEventService->expects(self::once())
			->method('logTicketCommentAdded')
			->with('member1', $survivor, false);

		$response = $this->controller->addComment(10);
		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(10, $data['redirected_from']);
		$this->assertSame(20, $data['ticket_id']);
	}

	public function testAddCommentDeniedWhenCannotCommentOnSurvivor(): void
	{
		$survivor = new Ticket();
		$survivor->setId(20);
		$this->ticketService->method('getActiveTicket')->with(10)->willReturn($survivor);
		// Viewable survivor, but comment capability denied (defense-in-depth).
		$this->permissionService->method('canViewTicketWithProjectAccess')->with($survivor)->willReturn(true);
		$this->permissionService->method('canCommentOnTicket')->with($survivor)->willReturn(false);
		$this->ticketService->expects(self::never())->method('addComment');

		$response = $this->controller->addComment(10);
		$this->assertSame(403, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testUploadAttachmentDeniedWhenCannotComment(): void
	{
		$ticket = new Ticket();
		$ticket->setId(39);
		$this->ticketService->method('getActiveTicket')->with(39)->willReturn($ticket);
		$this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(true);
		$this->permissionService->method('canCommentOnTicket')->with($ticket)->willReturn(false);

		$response = $this->controller->uploadAttachment(39);
		$this->assertSame(403, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertSame('access_denied', $response->getData()['message']);
	}
}

<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Theming\Service\ThemesService;
use OCA\Ticketcheck\Controller\CustomerPortalController;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\KBArticleMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\GuestLayoutParamsProvider;
use OCA\Ticketcheck\Service\GuestPasswordPolicyService;
use OCA\Ticketcheck\Service\GuestPortalPageService;
use OCA\Ticketcheck\Service\HtmlSanitizerService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\PortalTicketListFilterService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCA\Ticketcheck\Service\SurveyService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCA\Ticketcheck\Service\TicketService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Live portal create/reply must delete the new ticket/comment when attach throws Error.
 */
final class CustomerPortalControllerAttachErrorCompensationTest extends TestCase
{
	/** @var TicketService&MockObject */
	private TicketService $ticketService;
	/** @var PermissionService&MockObject */
	private PermissionService $permissionService;
	/** @var AttachmentUploadService&MockObject */
	private AttachmentUploadService $attachmentUploadService;
	/** @var TicketMapper&MockObject */
	private TicketMapper $ticketMapper;
	/** @var NavigationContextService&MockObject */
	private NavigationContextService $navigationContext;

	protected function setUp(): void
	{
		parent::setUp();
		$this->ticketService = $this->createMock(TicketService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->attachmentUploadService = $this->createMock(AttachmentUploadService::class);
		$this->ticketMapper = $this->createMock(TicketMapper::class);
		$this->navigationContext = $this->createMock(NavigationContextService::class);

		$this->permissionService->method('getCurrentUserId')->willReturn('guest1');
		$this->permissionService->method('isGuest')->willReturn(true);
		$this->permissionService->method('checkRateLimit')->willReturn(true);
		$this->permissionService->method('getAccessibleProjectIds')->willReturn([12]);
		$this->permissionService->method('canAccessProject')->with(12)->willReturn(true);
		$this->permissionService->method('getCurrentUserDisplayName')->willReturn('Guest');
		$this->permissionService->method('getCurrentUserEmail')->willReturn('g@example.com');
		$this->navigationContext->method('canGuestCreateTicket')->willReturn(true);
		$this->ticketMapper->method('countRecentByUser')->willReturn(0);
		$this->ticketService->method('countRecentPortalActionsByUser')->willReturn(0);
		$this->ticketService->method('withGuestActivityGate')->willReturnCallback(
			static function (string $userId, callable $callback) {
				return $callback();
			}
		);
	}

	private function buildController(IRequest $request): CustomerPortalController
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $key): string => $key);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/portal/tickets/9');

		return new CustomerPortalController(
			'ticketcheck',
			$request,
			$this->ticketService,
			$this->permissionService,
			$this->createMock(ProjectService::class),
			$this->createMock(EmailService::class),
			$this->ticketMapper,
			$this->createMock(KBArticleMapper::class),
			$this->createMock(AttachmentMapper::class),
			$urlGenerator,
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ThemesService::class),
			$this->createMock(\OCP\ISession::class),
			$this->createMock(EmailPreferencesService::class),
			$this->createMock(SafeFilenameService::class),
			$this->createMock(IConfig::class),
			$l10nFactory,
			$this->createMock(HtmlSanitizerService::class),
			$this->createMock(IMailer::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(GuestLayoutParamsProvider::class),
			$this->createMock(SurveyService::class),
			$this->createMock(TicketLinkService::class),
			$this->attachmentUploadService,
			$this->navigationContext,
			$this->createMock(GuestPortalPageService::class),
			new GuestPasswordPolicyService(),
			new PortalTicketListFilterService(),
			new AttachmentDeliveryService($this->createMock(IConfig::class), new SafeFilenameService()),
		);
	}

	public function testStoreTicketDeletesTicketWhenAttachThrowsError(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $name, $default = null) => match ($name) {
			'title' => 'Help',
			'description' => 'Please help',
			'priority' => 'normal',
			'category' => 'general',
			'project_id' => '12',
			default => $default,
		});

		$ticket = new Ticket();
		$ticket->setId(9);
		$this->ticketService->method('createTicket')->willReturn($ticket);
		$this->ticketService->method('addAttachmentFromUploadedFile')
			->willThrowException(new \Error('engine fatal during portal attach'));
		$this->ticketService->expects(self::once())->method('deleteTicket')->with(9);

		$this->attachmentUploadService->method('validateUploadedFilesBatch')->willReturn([
			'success' => true,
			'fileCount' => 1,
		]);
		$this->attachmentUploadService->method('validateUploadedFile')->willReturn([
			'success' => true,
			'mimeType' => 'application/pdf',
			'originalName' => 'a.pdf',
		]);

		$_FILES['attachments'] = [
			'name' => ['a.pdf'],
			'type' => ['application/pdf'],
			'tmp_name' => [__FILE__],
			'error' => [UPLOAD_ERR_OK],
			'size' => [100],
		];

		try {
			$response = $this->buildController($request)->storeTicket();
			self::assertSame(400, $response->getStatus());
			self::assertSame('an_error_occurred', $response->getData()['error'] ?? null);
		} finally {
			unset($_FILES['attachments']);
		}
	}

	public function testAddReplyDeletesCommentWhenAttachThrowsError(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $name, $default = null) => match ($name) {
			'comment' => 'Follow-up with file',
			default => $default,
		});

		$ticket = new Ticket();
		$ticket->setId(7);
		$ticket->setStatus(Ticket::STATUS_IN_PROGRESS);
		$this->ticketMapper->method('find')->with(7)->willReturn($ticket);
		$this->ticketService->method('getActiveTicket')->with(7)->willReturn($ticket);
		$this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(true);

		$comment = new Comment();
		$comment->setId(44);
		$this->ticketService->method('addComment')->willReturn($comment);
		$this->ticketService->method('addAttachmentFromUploadedFile')
			->willThrowException(new \Error('engine fatal during portal reply attach'));
		$this->ticketService->expects(self::once())->method('deleteComment')->with(7, 44);

		$this->attachmentUploadService->method('validateUploadedFilesBatch')->willReturn([
			'success' => true,
			'fileCount' => 1,
		]);
		$this->attachmentUploadService->method('validateUploadedFile')->willReturn([
			'success' => true,
			'mimeType' => 'application/pdf',
			'originalName' => 'b.pdf',
		]);

		$_FILES['attachments'] = [
			'name' => ['b.pdf'],
			'type' => ['application/pdf'],
			'tmp_name' => [__FILE__],
			'error' => [UPLOAD_ERR_OK],
			'size' => [100],
		];

		try {
			$response = $this->buildController($request)->addReply(7);
			self::assertSame(400, $response->getStatus());
			// Error bubbles (not wrapped as upload failure) so outer Throwable compensate runs.
			self::assertSame('an_error_occurred', $response->getData()['error'] ?? null);
		} finally {
			unset($_FILES['attachments']);
		}
	}
}

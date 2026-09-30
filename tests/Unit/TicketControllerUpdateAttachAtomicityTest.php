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
 * Staff multipart update must attach-first and leave ticket fields untouched
 * when the attach batch fails (no partial "fields saved / files lost" state).
 */
class TicketControllerUpdateAttachAtomicityTest extends TestCase
{
	/** @var IRequest&MockObject */
	private IRequest $request;
	/** @var TicketService&MockObject */
	private TicketService $ticketService;
	/** @var PermissionService&MockObject */
	private PermissionService $permissionService;
	/** @var AttachmentUploadService&MockObject */
	private AttachmentUploadService $attachmentUploadService;
	/** @var EmailService&MockObject */
	private EmailService $emailService;
	private TicketController $controller;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->ticketService = $this->createMock(TicketService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->attachmentUploadService = $this->createMock(AttachmentUploadService::class);
		$this->emailService = $this->createMock(EmailService::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $key): string => $key);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);

		$this->permissionService->method('isGuest')->willReturn(false);
		$this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(true);
		$this->permissionService->method('canEditTicket')->willReturn(true);
		$this->permissionService->method('canMoveTicketToProject')->willReturn(true);
		$this->permissionService->method('getCurrentUserId')->willReturn('agent1');

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
			$this->attachmentUploadService,
			$this->createMock(LocaleFormatService::class),
			$this->createMock(NavigationContextService::class),
			$this->createMock(FrontEndAssetService::class),
			$this->createMock(AttachmentDeliveryService::class),
		);
	}

	public function testUpdatePostDoesNotMutateFieldsWhenAttachBatchFails(): void
	{
		$ticket = new Ticket();
		$ticket->setId(42);
		$ticket->setTitle('Old');
		$ticket->setProjectId(null);

		$this->request->method('getParam')->willReturnMap([
			['project_id', null, null],
			['title', null, 'New title'],
			['description', null, 'New body'],
			['category', null, Ticket::CATEGORY_GENERAL],
			['priority', null, Ticket::PRIORITY_NORMAL],
			['status', null, null],
			['assigned_to', null, null],
		]);

		$_FILES['attachments'] = [
			'name' => ['a.pdf'],
			'type' => ['application/pdf'],
			'tmp_name' => ['/tmp/x'],
			'error' => [UPLOAD_ERR_OK],
			'size' => [100],
		];

		$this->attachmentUploadService->method('validateUploadedFilesBatch')->willReturn([
			'success' => true,
			'fileCount' => 1,
		]);
		$this->attachmentUploadService->method('validateUploadedFile')->willReturn([
			'success' => false,
			'message' => 'bad',
		]);

		$this->ticketService->expects(self::once())->method('getActiveTicket')->with(42)->willReturn($ticket);
		$this->ticketService->expects(self::never())->method('updateTicket');
		$this->ticketService->expects(self::never())->method('deleteAttachmentWherever');
		$this->emailService->expects(self::never())->method('sendTicketUpdatedNotification');

		$response = $this->controller->updatePost(42);
		self::assertSame(400, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success'] ?? true);
		self::assertSame('file_upload_failed_try_again', $data['message'] ?? null);
		self::assertSame(42, $data['ticket_id'] ?? null);

		unset($_FILES['attachments']);
	}

	public function testUpdatePostWithoutFilesStillUpdatesFields(): void
	{
		$ticket = new Ticket();
		$ticket->setId(9);
		$ticket->setTitle('Old');
		$ticket->setProjectId(null);

		$updated = new Ticket();
		$updated->setId(9);
		$updated->setTitle('New title');

		$this->request->method('getParam')->willReturnMap([
			['project_id', null, null],
			['title', null, 'New title'],
			['description', null, 'Body'],
			['category', null, Ticket::CATEGORY_GENERAL],
			['priority', null, Ticket::PRIORITY_NORMAL],
			['status', null, null],
			['assigned_to', null, null],
		]);
		unset($_FILES['attachments']);

		$this->attachmentUploadService->method('validateUploadedFilesBatch')->willReturn([
			'success' => true,
			'fileCount' => 0,
		]);

		$this->ticketService->expects(self::once())->method('getActiveTicket')->with(9)->willReturn($ticket);
		$this->ticketService->expects(self::once())->method('updateTicket')->willReturn($updated);
		$this->emailService->method('sendTicketUpdatedNotification')->willThrowException(new \RuntimeException('smtp down'));

		$response = $this->controller->updatePost(9);
		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success'] ?? false);
		self::assertSame(9, $data['ticket_id'] ?? null);
	}

	public function testUpdatePostCompensatesAttachmentsWhenFieldUpdateFails(): void
	{
		$ticket = new Ticket();
		$ticket->setId(55);
		$ticket->setTitle('Old');
		$ticket->setProjectId(null);

		$this->request->method('getParam')->willReturnMap([
			['project_id', null, null],
			['title', null, 'New title'],
			['description', null, 'Body'],
			['category', null, Ticket::CATEGORY_GENERAL],
			['priority', null, Ticket::PRIORITY_NORMAL],
			['status', null, null],
			['assigned_to', null, null],
		]);

		$_FILES['attachments'] = [
			'name' => ['ok.pdf'],
			'type' => ['application/pdf'],
			'tmp_name' => ['/tmp/ok'],
			'error' => [UPLOAD_ERR_OK],
			'size' => [50],
		];

		$this->attachmentUploadService->method('validateUploadedFilesBatch')->willReturn([
			'success' => true,
			'fileCount' => 1,
		]);
		$this->attachmentUploadService->method('validateUploadedFile')->willReturn([
			'success' => true,
			'mimeType' => 'application/pdf',
			'originalName' => 'ok.pdf',
		]);

		$attachment = new \OCA\Ticketcheck\Db\Attachment();
		$attachment->setId(777);
		$attachment->setFileName('ok.pdf');
		$attachment->setFileSize(50);

		$this->ticketService->expects(self::once())->method('getActiveTicket')->with(55)->willReturn($ticket);
		$this->ticketService->expects(self::once())->method('addAttachmentFromUploadedFile')->willReturn($attachment);
		$this->ticketService->expects(self::once())->method('updateTicket')
			->willThrowException(new \RuntimeException('db write failed'));
		$this->ticketService->expects(self::once())->method('deleteAttachmentWherever')->with(777);

		$response = $this->controller->updatePost(55);
		self::assertSame(400, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success'] ?? true);

		unset($_FILES['attachments']);
	}

	public function testUpdatePostCompensatesPartialBatchWhenSecondFileFails(): void
	{
		$ticket = new Ticket();
		$ticket->setId(61);
		$ticket->setTitle('Old');
		$ticket->setProjectId(null);

		$this->request->method('getParam')->willReturnMap([
			['project_id', null, null],
			['title', null, 'New title'],
			['description', null, 'Body'],
			['category', null, Ticket::CATEGORY_GENERAL],
			['priority', null, Ticket::PRIORITY_NORMAL],
			['status', null, null],
			['assigned_to', null, null],
		]);

		$_FILES['attachments'] = [
			'name' => ['ok.pdf', 'bad.pdf'],
			'type' => ['application/pdf', 'application/pdf'],
			'tmp_name' => ['/tmp/ok', '/tmp/bad'],
			'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
			'size' => [50, 50],
		];

		$this->attachmentUploadService->method('validateUploadedFilesBatch')->willReturn([
			'success' => true,
			'fileCount' => 2,
		]);
		$this->attachmentUploadService->method('validateUploadedFile')->willReturnOnConsecutiveCalls(
			[
				'success' => true,
				'mimeType' => 'application/pdf',
				'originalName' => 'ok.pdf',
			],
			[
				'success' => false,
				'message' => 'bad',
			],
		);

		$attachment = new \OCA\Ticketcheck\Db\Attachment();
		$attachment->setId(888);
		$attachment->setFileName('ok.pdf');
		$attachment->setFileSize(50);

		$this->ticketService->expects(self::once())->method('getActiveTicket')->with(61)->willReturn($ticket);
		$this->ticketService->expects(self::once())->method('addAttachmentFromUploadedFile')->willReturn($attachment);
		$this->ticketService->expects(self::once())->method('deleteAttachmentWherever')->with(888);
		$this->ticketService->expects(self::never())->method('updateTicket');

		$response = $this->controller->updatePost(61);
		self::assertSame(400, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success'] ?? true);
		self::assertSame('file_upload_failed_try_again', $data['message'] ?? null);

		unset($_FILES['attachments']);
	}
}

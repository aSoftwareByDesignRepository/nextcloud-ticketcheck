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
 * Staff create must compensate-delete the ticket when a bundled attach batch fails.
 */
class TicketControllerCreateAttachCompensationTest extends TestCase
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
		$this->permissionService->method('canViewHelpdeskOverview')->willReturn(true);
		$this->permissionService->method('getCurrentUserId')->willReturn('agent1');
		$this->permissionService->method('getCurrentUserEmail')->willReturn('a@example.com');
		$this->permissionService->method('getCurrentUserDisplayName')->willReturn('Agent');

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

	public function testStoreCompensatesWhenUploadBatchFails(): void
	{
		$ticket = new Ticket();
		$ticket->setId(42);
		$ticket->setTicketNumber('T-42');

		$this->request->method('getParam')->willReturnMap([
			['title', null, 'Hello'],
			['description', null, 'World'],
			['customer_id', null, null],
			['customer_email', null, 'c@example.com'],
			['customer_name', null, 'Cust'],
			['project_id', null, null],
			['category', Ticket::CATEGORY_GENERAL, Ticket::CATEGORY_GENERAL],
			['priority', Ticket::PRIORITY_NORMAL, Ticket::PRIORITY_NORMAL],
			['assigned_to', null, null],
			['status', 'new', 'new'],
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

		$this->ticketService->expects(self::once())->method('createTicket')->willReturn($ticket);
		$this->ticketService->expects(self::once())->method('deleteTicket')->with(42);
		$this->emailService->expects(self::never())->method('sendTicketCreatedNotification');

		$response = $this->controller->store();
		self::assertSame(400, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success'] ?? true);
		self::assertSame('file_upload_failed_try_again', $data['message'] ?? null);

		unset($_FILES['attachments']);
	}

	public function testStoreDeletesTicketWhenAttachThrowsError(): void
	{
		$ticket = new Ticket();
		$ticket->setId(55);
		$ticket->setTicketNumber('T-55');

		$this->request->method('getParam')->willReturnMap([
			['title', null, 'Hello'],
			['description', null, 'World'],
			['customer_id', null, null],
			['customer_email', null, 'c@example.com'],
			['customer_name', null, 'Cust'],
			['project_id', null, null],
			['category', Ticket::CATEGORY_GENERAL, Ticket::CATEGORY_GENERAL],
			['priority', Ticket::PRIORITY_NORMAL, Ticket::PRIORITY_NORMAL],
			['assigned_to', null, null],
			['status', 'new', 'new'],
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
			'success' => true,
			'mimeType' => 'application/pdf',
			'originalName' => 'a.pdf',
		]);

		$this->ticketService->expects(self::once())->method('createTicket')->willReturn($ticket);
		$this->ticketService->method('addAttachmentFromUploadedFile')
			->willThrowException(new \Error('engine fatal during attach'));
		$this->ticketService->expects(self::once())->method('deleteTicket')->with(55);
		$this->emailService->expects(self::never())->method('sendTicketCreatedNotification');

		$response = $this->controller->store();
		self::assertSame(400, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success'] ?? true);
		self::assertSame('server_error_try_again_later', $data['message'] ?? null);

		unset($_FILES['attachments']);
	}

	public function testStoreSucceedsWhenEmailThrows(): void
	{
		$ticket = new Ticket();
		$ticket->setId(7);
		$ticket->setTicketNumber('T-7');

		$this->request->method('getParam')->willReturnMap([
			['title', null, 'Hello'],
			['description', null, 'World'],
			['customer_id', null, null],
			['customer_email', null, 'c@example.com'],
			['customer_name', null, 'Cust'],
			['project_id', null, null],
			['category', Ticket::CATEGORY_GENERAL, Ticket::CATEGORY_GENERAL],
			['priority', Ticket::PRIORITY_NORMAL, Ticket::PRIORITY_NORMAL],
			['assigned_to', null, null],
			['status', 'new', 'new'],
		]);
		unset($_FILES['attachments']);

		$this->attachmentUploadService->method('validateUploadedFilesBatch')->willReturn([
			'success' => true,
			'fileCount' => 0,
		]);

		$this->ticketService->expects(self::once())->method('createTicket')->willReturn($ticket);
		$this->ticketService->expects(self::never())->method('deleteTicket');
		$this->emailService->method('sendTicketCreatedNotification')->willThrowException(new \RuntimeException('smtp down'));

		$response = $this->controller->store();
		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success'] ?? false);
		self::assertSame(7, $data['ticket']['id'] ?? null);
	}

	public function testHandleMultipleFileUploadsCompensatesOnErrorAfterPartialSuccess(): void
	{
		$ok = new \OCA\Ticketcheck\Db\Attachment();
		$ok->setId(91);
		$ok->setFileName('first.pdf');
		$ok->setFileSize(100);

		$tmp = tempnam(sys_get_temp_dir(), 'tcattach');
		self::assertNotFalse($tmp);
		file_put_contents($tmp, 'x');

		$_FILES['attachments'] = [
			'name' => ['first.pdf', 'second.pdf'],
			'type' => ['application/pdf', 'application/pdf'],
			'tmp_name' => [$tmp, $tmp],
			'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
			'size' => [100, 100],
		];

		$this->attachmentUploadService->method('validateUploadedFile')->willReturn([
			'success' => true,
			'mimeType' => 'application/pdf',
			'originalName' => 'first.pdf',
		]);

		$this->ticketService->expects(self::exactly(2))
			->method('addAttachmentFromUploadedFile')
			->willReturnOnConsecutiveCalls(
				$ok,
				$this->throwException(new \Error('engine fatal after partial upload'))
			);
		$this->ticketService->expects(self::once())
			->method('deleteAttachmentWherever')
			->with(91);

		$method = new \ReflectionMethod(TicketController::class, 'handleMultipleFileUploads');
		$method->setAccessible(true);

		try {
			$this->expectException(\Error::class);
			$this->expectExceptionMessage('engine fatal after partial upload');
			$method->invoke($this->controller, 42, 2);
		} finally {
			unset($_FILES['attachments']);
			@unlink($tmp);
		}
	}
}

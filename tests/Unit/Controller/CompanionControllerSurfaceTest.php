<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Controller;

use OCA\Ticketcheck\Controller\CompanionController;
use OCA\Ticketcheck\Exception\CompanionConflictException;
use OCA\Ticketcheck\Exception\CompanionNotFoundException;
use OCA\Ticketcheck\Exception\CompanionValidationException;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\CompanionGateService;
use OCA\Ticketcheck\Service\CompanionTicketService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CompanionControllerSurfaceTest extends TestCase
{
	/** @var IRequest&MockObject */
	private IRequest $request;
	/** @var CompanionTicketService&MockObject */
	private CompanionTicketService $tickets;
	/** @var AttachmentUploadService&MockObject */
	private AttachmentUploadService $upload;
	/** @var AttachmentDeliveryService&MockObject */
	private AttachmentDeliveryService $delivery;
	/** @var IConfig&MockObject */
	private IConfig $config;

	private CompanionController $controller;

	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice Agent');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')
			->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);

		$this->tickets = $this->createMock(CompanionTicketService::class);
		$this->upload = $this->createMock(AttachmentUploadService::class);
		$this->delivery = $this->createMock(AttachmentDeliveryService::class);
		$this->config = $this->createMock(IConfig::class);

		$this->controller = new CompanionController(
			'ticketcheck',
			$this->request,
			$session,
			$this->createMock(CompanionGateService::class),
			$this->tickets,
			$this->config,
			$this->upload,
			$this->delivery,
		);
	}

	protected function tearDown(): void
	{
		unset($_FILES['file']);
	}

	/**
	 * @param JSONResponse $response
	 * @return array<string, mixed>
	 */
	private static function envelope($response): array
	{
		self::assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		self::assertIsArray($data);
		return $data;
	}

	public function testInboxValidationErrorIs422Envelope(): void
	{
		$this->params = ['queue' => 'open', 'priority' => 'catastrophic'];
		$this->tickets->method('inbox')
			->willThrowException(new CompanionValidationException('invalid_priority'));

		$response = $this->controller->inbox();
		$data = self::envelope($response);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertFalse($data['ok']);
		self::assertSame('invalid_priority', $data['error']['code']);
	}

	public function testInboxRejectsNonNumericProjectId(): void
	{
		$this->params = ['queue' => 'open', 'projectId' => 'abc'];

		$response = $this->controller->inbox();
		$data = self::envelope($response);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('invalid_projectid', $data['error']['code']);
	}

	public function testShowNotFoundIs404(): void
	{
		$this->tickets->method('detail')->willThrowException(new CompanionNotFoundException());

		$response = $this->controller->show(9);
		$data = self::envelope($response);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('ticket_not_found', $data['error']['code']);
	}

	public function testAddCommentConflictIs409(): void
	{
		$this->params = ['body' => 'x', 'visibility' => 'public', 'version' => 5];
		$this->tickets->method('addComment')->willThrowException(new CompanionConflictException());

		$response = $this->controller->addComment(1);
		$data = self::envelope($response);

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame('CONFLICT', $data['error']['code']);
	}

	public function testAddCommentRequiresVersion(): void
	{
		$this->params = ['body' => 'x', 'visibility' => 'public'];

		$response = $this->controller->addComment(1);
		$data = self::envelope($response);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('version_required', $data['error']['code']);
	}

	public function testCreateReturns201(): void
	{
		$this->params = ['title' => 'T', 'description' => 'D', 'priority' => 'high', 'projectId' => 3];
		$this->tickets->method('create')->with('alice', 3, 'T', 'D', 'high')
			->willReturn(['ticket' => ['id' => 5]]);

		$response = $this->controller->create();
		$data = self::envelope($response);

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertTrue($data['ok']);
		self::assertSame(5, $data['ticket']['id']);
	}

	public function testBulkStatusRejectsNonArrayIds(): void
	{
		$this->params = ['ticketIds' => 'oops', 'status' => 'done'];

		$response = $this->controller->bulkStatus();
		$data = self::envelope($response);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('invalid_ticket_ids', $data['error']['code']);
	}

	public function testBulkStatusForwardsResults(): void
	{
		$this->params = ['ticketIds' => [1, 2], 'status' => 'done', 'versions' => ['1' => 10, '2' => 20]];
		$this->tickets->method('bulkStatus')->with('alice', [1, 2], 'done', [1 => 10, 2 => 20])
			->willReturn(['results' => [['id' => 1, 'ok' => true], ['id' => 2, 'ok' => false, 'error' => 'ticket_not_found']]]);

		$response = $this->controller->bulkStatus();
		$data = self::envelope($response);

		self::assertTrue($data['ok']);
		self::assertCount(2, $data['results']);
	}

	public function testWatchParsesBooleanFalse(): void
	{
		$this->params = ['watching' => 'false', 'version' => 1782900000];
		$this->tickets->expects(self::once())->method('setWatching')->with(3, 'alice', false, 1782900000)
			->willReturn(['watching' => false, 'ticket' => ['id' => 3, 'version' => 1782900000]]);

		$response = $this->controller->watch(3);
		$data = self::envelope($response);

		self::assertTrue($data['ok']);
		self::assertFalse($data['watching']);
	}

	public function testUploadWithoutFileIs422(): void
	{
		unset($_FILES['file']);

		$response = $this->controller->uploadAttachment(1);
		$data = self::envelope($response);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('no_file_uploaded', $data['error']['code']);
	}

	public function testUploadTooLargeIs413(): void
	{
		$_FILES['file'] = [
			'name' => 'big.png',
			'tmp_name' => '/tmp/big',
			'size' => 11 * 1024 * 1024,
			'error' => UPLOAD_ERR_OK,
		];
		$this->config->method('getAppValue')->willReturn((string)(10 * 1024 * 1024));

		$response = $this->controller->uploadAttachment(1);
		$data = self::envelope($response);

		self::assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());
		self::assertSame('attachment_too_large', $data['error']['code']);
	}

	public function testUploadInvalidFileIs422(): void
	{
		$_FILES['file'] = [
			'name' => 'evil.php',
			'tmp_name' => '/tmp/evil',
			'size' => 100,
			'error' => UPLOAD_ERR_OK,
		];
		$this->config->method('getAppValue')->willReturn((string)(10 * 1024 * 1024));
		$this->upload->method('validateUploadedFile')->willReturn(['success' => false, 'message' => 'nope']);

		$response = $this->controller->uploadAttachment(1);
		$data = self::envelope($response);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('invalid_attachment', $data['error']['code']);
	}

	public function testUploadHappyPathIs201(): void
	{
		$_FILES['file'] = [
			'name' => 'shot.png',
			'tmp_name' => '/tmp/shot',
			'size' => 2048,
			'error' => UPLOAD_ERR_OK,
		];
		$this->params = ['version' => 41];
		$this->config->method('getAppValue')->willReturn((string)(10 * 1024 * 1024));
		$this->upload->method('validateUploadedFile')
			->willReturn(['success' => true, 'mimeType' => 'image/png', 'originalName' => 'shot.png']);
		$this->tickets->expects(self::once())
			->method('addUploadedAttachment')
			->with(1, '/tmp/shot', 'shot.png', 2048, 'image/png', 41)
			->willReturn(['attachment' => ['id' => 8], 'ticket' => ['id' => 1, 'version' => 42]]);

		$response = $this->controller->uploadAttachment(1);
		$data = self::envelope($response);

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(8, $data['attachment']['id']);
		self::assertSame(42, $data['ticket']['version']);
	}

	public function testUploadRequiresVersion(): void
	{
		$_FILES['file'] = [
			'name' => 'shot.png',
			'tmp_name' => '/tmp/shot',
			'size' => 2048,
			'error' => UPLOAD_ERR_OK,
		];
		$this->params = [];
		$this->config->method('getAppValue')->willReturn((string)(10 * 1024 * 1024));
		$this->upload->method('validateUploadedFile')
			->willReturn(['success' => true, 'mimeType' => 'image/png', 'originalName' => 'shot.png']);
		$this->tickets->expects(self::never())->method('addUploadedAttachment');

		$response = $this->controller->uploadAttachment(1);
		$data = self::envelope($response);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('version_required', $data['error']['code']);
	}

	public function testDownloadNotFoundIs404(): void
	{
		$this->tickets->method('attachmentForDownload')
			->willThrowException(new CompanionNotFoundException());

		$response = $this->controller->downloadAttachment(1, 99);
		$data = self::envelope($response);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('ticket_not_found', $data['error']['code']);
	}

	public function testQueueCountsForwarded(): void
	{
		$this->tickets->method('queueCounts')->with('alice')
			->willReturn(['open' => 3, 'mine' => 1, 'waiting' => 2, 'watching' => 0]);

		$response = $this->controller->queueCounts();
		$data = self::envelope($response);

		self::assertTrue($data['ok']);
		self::assertSame(3, $data['counts']['open']);
		self::assertSame(0, $data['counts']['watching']);
	}

	public function testFilterOptionsForwarded(): void
	{
		$this->tickets->method('filterOptions')->willReturn([
			'projects' => [['id' => 1, 'name' => 'Acme']],
			'priorities' => ['low', 'normal', 'high', 'urgent'],
			'statuses' => ['new', 'in_progress', 'waiting', 'done'],
		]);

		$response = $this->controller->filterOptions();
		$data = self::envelope($response);

		self::assertTrue($data['ok']);
		self::assertSame('Acme', $data['projects'][0]['name']);
	}

	public function testAssignAcceptsUserIdAlias(): void
	{
		$this->params = ['userId' => 'bob', 'version' => 7];
		$this->tickets->expects(self::once())
			->method('assign')
			->with(5, 'alice', 'bob', 7)
			->willReturn(['ticket' => ['id' => 5, 'assignedTo' => 'bob', 'version' => 8]]);

		$response = $this->controller->assign(5);
		$data = self::envelope($response);

		self::assertTrue($data['ok']);
		self::assertSame('bob', $data['ticket']['assignedTo']);
	}

	public function testOptionalVersionHelperRemovedRequireVersionIsSoleCasGate(): void
	{
		$ref = new \ReflectionClass(CompanionController::class);
		self::assertFalse(
			$ref->hasMethod('optionalVersion'),
			'optionalVersion must stay deleted — it advertised a no-CAS path',
		);
		self::assertTrue($ref->hasMethod('requireVersion'));
		$require = $ref->getMethod('requireVersion');
		self::assertTrue($require->isPrivate());
	}
}

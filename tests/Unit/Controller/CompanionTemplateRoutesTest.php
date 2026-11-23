<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Controller;

use OCA\Ticketcheck\Controller\CompanionController;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Exception\CompanionNotFoundException;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\CompanionGateService;
use OCA\Ticketcheck\Service\CompanionPushService;
use OCA\Ticketcheck\Service\CompanionTicketService;
use OCA\Ticketcheck\Service\IdempotencyService;
use OCA\Ticketcheck\Service\KnowledgeBaseService;
use OCA\Ticketcheck\Service\TemplateService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * TC-COMP-QUICKACT §9.3 — canned-reply companion routes.
 */
final class CompanionTemplateRoutesTest extends TestCase
{
	private IRequest $request;
	/** @var TemplateService&MockObject */
	private TemplateService $templates;
	/** @var CompanionTicketService&MockObject */
	private CompanionTicketService $tickets;
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

		$this->templates = $this->createMock(TemplateService::class);
		$this->tickets = $this->createMock(CompanionTicketService::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $key, ...$args) => 'L:' . $key
		);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);

		$this->controller = new CompanionController(
			'ticketcheck',
			$this->request,
			$session,
			$this->createMock(CompanionGateService::class),
			$this->tickets,
			$this->createMock(IConfig::class),
			$this->createMock(AttachmentUploadService::class),
			$this->createMock(AttachmentDeliveryService::class),
			$this->createMock(IdempotencyService::class),
			$this->createMock(CompanionPushService::class),
			$this->createMock(KnowledgeBaseService::class),
			null,
			$this->templates,
			$l10nFactory,
		);
	}

	/** @return array<string, mixed> */
	private static function body(JSONResponse $response): array
	{
		$data = $response->getData();
		self::assertIsArray($data);
		return $data;
	}

	public function testListReturnsMetadataOnlySortedWithoutContent(): void
	{
		$this->templates->method('getAllTemplates')->willReturn([
			['id' => 7, 'name' => 'Apology', 'category' => 'Service', 'content' => 'SECRET BODY'],
			['id' => 3, 'name' => 'Escalate', 'category' => null, 'content' => 'OTHER'],
			['id' => 9, 'name' => 'Blank', 'category' => '   ', 'content' => 'X'],
		]);

		$data = self::body($this->controller->templates());
		self::assertTrue($data['ok']);
		self::assertCount(3, $data['items']);
		foreach ($data['items'] as $item) {
			// MH-06: metadata only — body stays server-side until /process.
			self::assertSame(['id', 'name', 'category'], array_keys($item));
		}
		// Null/whitespace-only categories serialize to null (E-12 client "Other").
		self::assertSame('Service', $data['items'][0]['category']);
		self::assertNull($data['items'][1]['category']);
		self::assertNull($data['items'][2]['category']);
	}

	public function testProcessDerivesVariablesServerSideAndIgnoresCallerMap(): void
	{
		$this->params = [
			'ticket_id' => 41,
			// NN-07: a hostile caller tries to spoof the render context —
			// the controller must never read `variables`.
			'variables' => ['customer_name' => 'MALLORY', 'agent_name' => 'ROOT'],
		];
		$this->templates->method('getTemplate')->with(5)->willReturn([
			'id' => 5, 'name' => 'T', 'is_active' => 1,
		]);
		$ticket = new Ticket();
		$ticket->setTicketNumber('TC-41');
		$ticket->setTitle('Broken thing');
		$ticket->setCustomerName('Customer One');
		$ticket->setCustomerEmail('cust@example.com');
		$ticket->setStatus(Ticket::STATUS_IN_PROGRESS);
		$this->tickets->method('requireTicketForRead')->with(41)->willReturn($ticket);

		$captured = null;
		$this->templates->method('processTemplatePlain')
			->willReturnCallback(function (int $id, array $vars) use (&$captured) {
				$captured = $vars;
				return 'rendered';
			});

		$data = self::body($this->controller->processTemplate(5));
		self::assertTrue($data['ok']);
		self::assertSame('rendered', $data['content']);
		self::assertIsArray($captured);
		self::assertSame('TC-41', $captured['ticket_number']);
		self::assertSame('Broken thing', $captured['title']);
		self::assertSame('Customer One', $captured['customer_name']);
		self::assertSame('cust@example.com', $captured['customer_email']);
		self::assertSame('Alice Agent', $captured['agent_name']);
		self::assertSame('L:status_in_progress', $captured['status']);
		// Every supplied spoof is absent from the derived map.
		self::assertNotContains('MALLORY', $captured);
		self::assertNotContains('ROOT', $captured);
	}

	public function testProcessAcceptsDigitStringTicketId(): void
	{
		$this->params = ['ticket_id' => '41'];
		$this->templates->method('getTemplate')->willReturn(['id' => 5, 'is_active' => 1]);
		$ticket = new Ticket();
		$ticket->setTicketNumber('TC-41');
		$ticket->setTitle('t');
		$ticket->setCustomerName('');
		$ticket->setCustomerEmail('c@e.com');
		$ticket->setStatus(Ticket::STATUS_NEW);
		$this->tickets->method('requireTicketForRead')->with(41)->willReturn($ticket);
		$this->templates->method('processTemplatePlain')->willReturn('ok-body');
		$data = self::body($this->controller->processTemplate(5));
		self::assertTrue($data['ok']);
	}

	public function testProcessRejectsMalformedTicketId(): void
	{
		foreach ([null, 0, -3, 'abc', 1.5] as $bad) {
			$this->params = ['ticket_id' => $bad];
			$this->templates->method('getTemplate')->willReturn(['id' => 5, 'is_active' => 1]);
			$resp = $this->controller->processTemplate(5);
			self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $resp->getStatus());
			self::assertSame('invalid_ticket_id', self::body($resp)['error']['code']);
		}
	}

	public function testProcessCollapsesUnknownTemplateAndInvisibleTicketToSameBody(): void
	{
		$this->params = ['ticket_id' => 41];
		// Template 77 does not exist → uniform 404.
		$this->templates->method('getTemplate')->willReturnCallback(
			static fn (int $id) => $id === 5 ? ['id' => 5, 'is_active' => 1] : null
		);
		$unknown = $this->controller->processTemplate(77);
		self::assertSame(Http::STATUS_NOT_FOUND, $unknown->getStatus());

		// Template 5 exists but the ticket is invisible → TH-01 uniform-404:
		// same status AND same error body, no bit differs (no existence oracle).
		$this->tickets->method('requireTicketForRead')
			->willThrowException(new CompanionNotFoundException('ticket_not_found'));
		$invisible = $this->controller->processTemplate(5);

		self::assertSame($unknown->getStatus(), $invisible->getStatus());
		self::assertSame($unknown->getData(), $invisible->getData());
	}

	public function testProcessUniform404ForInactiveTemplate(): void
	{
		$this->params = ['ticket_id' => 41];
		$this->templates->method('getTemplate')->willReturn(['id' => 5, 'is_active' => 0]);
		$resp = $this->controller->processTemplate(5);
		self::assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		self::assertSame('template_not_found', self::body($resp)['error']['code']);
	}

	public function testProcessUniform404WhenRowDeletedBeforeRender(): void
	{
		$this->params = ['ticket_id' => 41];
		$this->templates->method('getTemplate')->willReturn(['id' => 5, 'is_active' => 1]);
		$ticket = new Ticket();
		$ticket->setTicketNumber('TC-41');
		$ticket->setTitle('t');
		$ticket->setCustomerName('c');
		$ticket->setCustomerEmail('c@e.com');
		$ticket->setStatus(Ticket::STATUS_NEW);
		$this->tickets->method('requireTicketForRead')->willReturn($ticket);
		$this->templates->method('processTemplatePlain')
			->willThrowException(new \Exception('Template not found'));
		$resp = $this->controller->processTemplate(5);
		self::assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		self::assertSame('template_not_found', self::body($resp)['error']['code']);
	}

	public function testProcessUsesPlainRenderingForThePlainTextComposer(): void
	{
		$this->params = ['ticket_id' => 41];
		$this->templates->method('getTemplate')->willReturn(['id' => 5, 'is_active' => 1]);
		$ticket = new Ticket();
		$ticket->setTicketNumber('TC-41');
		$ticket->setTitle('t');
		$ticket->setCustomerName("O'Brien & Co");
		$ticket->setCustomerEmail('c@e.com');
		$ticket->setStatus(Ticket::STATUS_NEW);
		$this->tickets->method('requireTicketForRead')->willReturn($ticket);
		// The HTML-escaping render path is the wrong consumer contract here:
		// entity-escaped values land literally in the stored comment and get
		// escaped AGAIN at render (p()) — visible entity garbage for the
		// customer. The companion must request plain substitution.
		$this->templates->expects($this->never())->method('processTemplate');
		$this->templates->expects($this->once())
			->method('processTemplatePlain')
			->with(5, self::callback(static fn (array $vars): bool => ($vars['customer_name'] ?? null) === "O'Brien & Co"))
			->willReturn('ok');
		self::assertTrue(self::body($this->controller->processTemplate(5))['ok']);
	}
}

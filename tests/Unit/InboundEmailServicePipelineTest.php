<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\HelpdeskCustomerMapper;
use OCA\Ticketcheck\Db\HelpdeskProjectMapper;
use OCA\Ticketcheck\Db\InboundMessage;
use OCA\Ticketcheck\Db\InboundMessageMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\InboundEmailService;
use OCA\Ticketcheck\Service\InboundMailConfigService;
use OCA\Ticketcheck\Service\ReplyTokenService;
use OCA\Ticketcheck\Service\TicketService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory as L10NFactory;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * End-to-end pipeline through the mocked dedupe mapper: claim semantics,
 * the action state machine, caps, routing, and terminal outcomes.
 */
class InboundEmailServicePipelineTest extends TestCase
{
	/** @var array<string, string> */
	private array $store = [];
	private TicketService $ticketService;
	private InboundMessageMapper $inboundMapper;
	private EmailService $emailService;
	private HelpdeskProjectMapper $projectMapper;
	private InboundEmailService $service;
	private ?string $finishedAction = null;
	private int $senderCreates = 0;
	private int $globalCreates = 0;

	protected function setUp(): void
	{
		parent::setUp();
		$this->store = ['inbound_imap_address' => 'support@example.com'];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default);
		$appConfig->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			});
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(fn (string $k, $d = '') => $k === 'secret' ? 'test-secret' : $d);
		$crypto = $this->createMock(ICrypto::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $k) => $k);
		$mailboxConfig = new InboundMailConfigService($appConfig, $config, $crypto, $l);

		$this->ticketService = $this->createMock(TicketService::class);
		$this->emailService = $this->createMock(EmailService::class);
		$this->projectMapper = $this->createMock(HelpdeskProjectMapper::class);
		$this->inboundMapper = $this->createMock(InboundMessageMapper::class);
		$this->inboundMapper->method('claim')
			->willReturnCallback(static fn (InboundMessage $row) => ['row' => $row, 'status' => 'claimed']);
		$this->inboundMapper->method('finish')
			->willReturnCallback(function (InboundMessage $row, string $action) {
				$this->finishedAction = $action;
				return $row;
			});
		$this->inboundMapper->method('countTicketCreationsBySender')
			->willReturnCallback(fn () => $this->senderCreates);
		$this->inboundMapper->method('countTicketCreationsSince')
			->willReturnCallback(fn () => $this->globalCreates);
		$l10nFactory = $this->createMock(L10NFactory::class);
		$l10nFactory->method('get')->willReturn($l);

		$this->service = new InboundEmailService(
			$this->ticketService,
			$this->createMock(LoggerInterface::class),
			$mailboxConfig,
			$this->inboundMapper,
			new ReplyTokenService($config),
			$this->emailService,
			$this->createMock(HelpdeskCustomerMapper::class),
			$this->projectMapper,
			$l10nFactory,
		);
	}

	private function msg(array $overrides = []): array
	{
		return array_merge([
			'from' => 'customer@example.com',
			'fromName' => 'Customer',
			'to' => ['support@example.com'],
			'cc' => [],
			'subject' => 'Need help',
			'text' => 'Please help me with this.',
			'html' => '',
			'messageId' => '<m' . bin2hex(random_bytes(6)) . '@x>',
		], $overrides);
	}

	// ---------- create path ----------

	public function testCreatesTicketForUnknownSender(): void
	{
		$ticket = new Ticket();
		$ticket->setId(55);
		$this->ticketService->expects(self::once())
			->method('createTicketFromEmail')
			->with(self::callback(fn (array $d) =>
				$d['customer_email'] === 'customer@example.com'
				&& $d['project_id'] === null
				&& str_contains($d['description'], 'Please help me')
			))
			->willReturn($ticket);
		$this->emailService->expects(self::once())->method('sendTicketCreatedNotification');

		$r = $this->service->processInboundMessage($this->msg(), 'imap', 10, 1);
		self::assertSame(InboundMessage::ACTION_TICKET_CREATED, $r['action']);
		self::assertSame(55, $r['ticket_id']);
		self::assertSame(InboundMessage::ACTION_TICKET_CREATED, $this->finishedAction);
	}

	public function testUnknownSenderIgnorePolicy(): void
	{
		$this->store['inbound_imap_unknown_sender_policy'] = 'ignore';
		$this->ticketService->expects(self::never())->method('createTicketFromEmail');
		$r = $this->service->processInboundMessage($this->msg(), 'imap', 10, 1);
		self::assertSame(InboundMessage::ACTION_IGNORED_POLICY, $r['action']);
	}

	public function testAutomatedMailIgnored(): void
	{
		$this->ticketService->expects(self::never())->method('createTicketFromEmail');
		$r = $this->service->processInboundMessage($this->msg([
			'from' => 'mailer-daemon@example.com',
		]), 'imap', 10, 1);
		self::assertSame(InboundMessage::ACTION_IGNORED_AUTO, $r['action']);
	}

	public function testEmptyBodyIgnored(): void
	{
		$r = $this->service->processInboundMessage($this->msg(['text' => '', 'html' => '']), 'imap', 10, 1);
		self::assertSame(InboundMessage::ACTION_IGNORED_EMPTY, $r['action']);
	}

	public function testInvalidSenderIgnored(): void
	{
		$r = $this->service->processInboundMessage($this->msg(['from' => 'not-an-email']), 'imap', 10, 1);
		self::assertSame(InboundMessage::ACTION_IGNORED_EMPTY, $r['action']);
	}

	public function testSenderCapIgnored(): void
	{
		$this->senderCreates = 99;
		$r = $this->service->processInboundMessage($this->msg(), 'imap', 10, 1);
		self::assertSame(InboundMessage::ACTION_IGNORED_CAP, $r['action']);
		self::assertSame(InboundMessage::ACTION_IGNORED_CAP, $this->finishedAction);
	}

	public function testGlobalCapIgnored(): void
	{
		$this->globalCreates = 999;
		$r = $this->service->processInboundMessage($this->msg(), 'imap', 10, 1);
		self::assertSame(InboundMessage::ACTION_IGNORED_CAP, $r['action']);
	}

	// ---------- routing ----------

	public function testRoutingPlusTagToProject(): void
	{
		$this->store['inbound_imap_routing_json'] = json_encode([
			'billing' => ['project_id' => 7, 'category' => 'finance'],
		]);
		$project = $this->createMock(\OCA\Ticketcheck\Db\HelpdeskProject::class);
		$this->projectMapper->method('find')->willReturn($project);
		$ticket = new Ticket();
		$ticket->setId(1);
		$this->ticketService->expects(self::once())
			->method('createTicketFromEmail')
			->with(self::callback(fn (array $d) => $d['project_id'] === 7 && $d['category'] === 'finance'))
			->willReturn($ticket);

		$r = $this->service->processInboundMessage($this->msg([
			'to' => ['support+billing@example.com'],
		]), 'imap', 10, 1);
		self::assertSame(InboundMessage::ACTION_TICKET_CREATED, $r['action']);
	}

	public function testRoutingToMissingProjectFallsBack(): void
	{
		$this->store['inbound_imap_routing_json'] = json_encode([
			'gone' => ['project_id' => 999, 'category' => 'x'],
		]);
		$this->projectMapper->method('find')->willThrowException(new \RuntimeException('not found'));
		$ticket = new Ticket();
		$ticket->setId(1);
		$this->ticketService->expects(self::once())
			->method('createTicketFromEmail')
			->with(self::callback(fn (array $d) => $d['project_id'] === null && $d['category'] === Ticket::CATEGORY_GENERAL))
			->willReturn($ticket);

		$r = $this->service->processInboundMessage($this->msg([
			'to' => ['support+gone@example.com'],
		]), 'imap', 10, 1);
		self::assertSame(InboundMessage::ACTION_TICKET_CREATED, $r['action']);
	}

	// ---------- reply path ----------

	public function testTokenizedReplyCreatesComment(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(fn (string $k, $d = '') => $k === 'secret' ? 'test-secret' : $d);
		$token = (new ReplyTokenService($config))->token(42, 'customer@example.com');

		$ticket = new Ticket();
		$ticket->setId(42);
		$ticket->setCustomerEmail('customer@example.com');
		$comment = new Comment();
		$comment->setId(9);
		$this->ticketService->method('getActiveTicket')->with(42)->willReturn($ticket);
		$this->ticketService->expects(self::once())->method('addCommentFromEmail')->willReturn($comment);

		$r = $this->service->processInboundMessage($this->msg([
			'to' => ["support+42-{$token}@example.com"],
		]), 'imap', 10, 1);
		self::assertSame(InboundMessage::ACTION_COMMENT, $r['action']);
		self::assertSame(9, $r['comment_id']);
	}

	public function testReplyToMissingTicketIgnored(): void
	{
		$this->ticketService->method('getActiveTicket')->willThrowException(new \RuntimeException('gone'));
		$r = $this->service->processInboundMessage($this->msg([
			'to' => ['support+77@example.com'],
		]), 'imap', 10, 1);
		self::assertSame(InboundMessage::ACTION_IGNORED_NO_TICKET, $r['action']);
	}

	public function testSubjectFallbackReply(): void
	{
		$ticket = new Ticket();
		$ticket->setId(33);
		$ticket->setCustomerEmail('customer@example.com');
		$comment = new Comment();
		$comment->setId(1);
		$this->ticketService->method('getActiveTicket')->with(33)->willReturn($ticket);
		$this->ticketService->expects(self::once())->method('addCommentFromEmail')->willReturn($comment);

		$r = $this->service->processInboundMessage($this->msg([
			'subject' => 'Re: ticket #33 update',
		]), 'imap', 10, 1);
		self::assertSame(InboundMessage::ACTION_COMMENT, $r['action']);
	}

	public function testSubjectFallbackDisabledWhenTokenRequired(): void
	{
		$this->store['inbound_imap_require_reply_token'] = 'yes';
		$this->ticketService->expects(self::never())->method('getActiveTicket');
		// No address target + token required + only a #subject → create path.
		$ticket = new Ticket();
		$ticket->setId(1);
		$this->ticketService->method('createTicketFromEmail')->willReturn($ticket);
		$r = $this->service->processInboundMessage($this->msg([
			'subject' => 'Re: ticket #33 update',
		]), 'imap', 10, 1);
		self::assertSame(InboundMessage::ACTION_TICKET_CREATED, $r['action']);
	}

	// ---------- dedupe / claim semantics ----------

	public function testDuplicateTerminalRowReturnsStoredResult(): void
	{
		$existing = new InboundMessage();
		$existing->setAction(InboundMessage::ACTION_TICKET_CREATED);
		$existing->setTicketId(88);
		$this->inboundMapper = $this->createMock(InboundMessageMapper::class);
		$this->inboundMapper->method('claim')
			->willReturn(['row' => $existing, 'status' => 'duplicate']);
		$service = $this->rebuildService();

		$r = $service->processInboundMessage($this->msg(), 'imap', 10, 1);
		self::assertTrue($r['duplicate']);
		self::assertSame(InboundMessage::ACTION_TICKET_CREATED, $r['action']);
		self::assertSame(88, $r['ticket_id']);
	}

	public function testDuplicateActiveProcessingRowIsRetryable(): void
	{
		$existing = new InboundMessage();
		$existing->setAction(InboundMessage::ACTION_PROCESSING);
		$this->inboundMapper = $this->createMock(InboundMessageMapper::class);
		$this->inboundMapper->method('claim')
			->willReturn(['row' => $existing, 'status' => 'duplicate']);
		$service = $this->rebuildService();

		$r = $service->processInboundMessage($this->msg(), 'imap', 10, 1);
		self::assertSame(InboundMessage::ACTION_ERROR_RETRYABLE, $r['action']);
		self::assertArrayNotHasKey('duplicate', $r);
	}

	public function testDuplicateRetryableRowIsRetryableNotSuccess(): void
	{
		// A redelivery hitting an error_retryable row must NOT report
		// success — that would kill the provider retry AND never reprocess.
		$existing = new InboundMessage();
		$existing->setAction(InboundMessage::ACTION_ERROR_RETRYABLE);
		$this->inboundMapper = $this->createMock(InboundMessageMapper::class);
		$this->inboundMapper->method('claim')
			->willReturn(['row' => $existing, 'status' => 'duplicate']);
		$service = $this->rebuildService();

		$r = $service->processInboundMessage($this->msg(), 'imap', 10, 1);
		self::assertSame(InboundMessage::ACTION_ERROR_RETRYABLE, $r['action']);
		self::assertArrayNotHasKey('duplicate', $r);
	}

	public function testAttemptsExceededPoisonsOut(): void
	{
		$existing = new InboundMessage();
		$existing->setAction(InboundMessage::ACTION_PROCESSING);
		$existing->setAttempts(4);
		$this->inboundMapper = $this->createMock(InboundMessageMapper::class);
		$this->inboundMapper->method('claim')
			->willReturn(['row' => $existing, 'status' => 'reclaim']);
		$this->inboundMapper->method('finish')
			->willReturnCallback(function (InboundMessage $row, string $action) {
				$this->finishedAction = $action;
				return $row;
			});
		$service = $this->rebuildService();

		$r = $service->processInboundMessage($this->msg(), 'imap', 10, 1);
		self::assertSame(InboundMessage::ACTION_ERROR_PERMANENT, $r['action']);
		self::assertSame(InboundMessage::ACTION_ERROR_PERMANENT, $this->finishedAction);
	}

	public function testProcessingExceptionIsRetryableThenPermanent(): void
	{
		$this->ticketService->method('createTicketFromEmail')
			->willThrowException(new \RuntimeException('db gone'));
		$row = new InboundMessage();
		$row->setAttempts(1);
		$this->inboundMapper = $this->createMock(InboundMessageMapper::class);
		$this->inboundMapper->method('claim')->willReturn(['row' => $row, 'status' => 'claimed']);
		$this->inboundMapper->method('finish')
			->willReturnCallback(function (InboundMessage $r, string $action) {
				$this->finishedAction = $action;
				return $r;
			});
		$service = $this->rebuildService();

		$r = $service->processInboundMessage($this->msg(), 'imap', 10, 1);
		self::assertSame(InboundMessage::ACTION_ERROR_RETRYABLE, $r['action']);
	}

	private function rebuildService(): InboundEmailService
	{
		// Rebuild with the current mapper mock (tests swap it per-case).
		$ref = new \ReflectionClass($this->service);
		$prop = $ref->getProperty('inboundMapper');
		$prop->setAccessible(true);
		$prop->setValue($this->service, $this->inboundMapper);
		return $this->service;
	}
}

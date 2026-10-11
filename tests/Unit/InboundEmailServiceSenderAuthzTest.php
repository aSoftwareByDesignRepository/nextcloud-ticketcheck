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
 * Sender authorization for email replies: only the ticket's own customer
 * email may add comments (a bare From is spoofable). The dedupe-aware
 * contract returns success:true for terminal "ignored_*" outcomes so
 * webhook providers don't storm retries — the internal action carries
 * the real decision.
 */
class InboundEmailServiceSenderAuthzTest extends TestCase
{
	private TicketService $ticketService;
	private InboundMessageMapper $inboundMapper;
	private EmailService $emailService;
	private ?string $finishedAction = null;
	private InboundEmailService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$store = [
			'inbound_imap_address' => 'support@example.com',
		];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(fn (string $app, string $key, string $default = '') => $store[$key] ?? $default);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(fn (string $k, $d = '') => $k === 'secret' ? 'test-secret' : $d);
		$crypto = $this->createMock(ICrypto::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $k) => $k);
		$mailboxConfig = new InboundMailConfigService($appConfig, $config, $crypto, $l);
		$replyTokens = new ReplyTokenService($config);

		$this->ticketService = $this->createMock(TicketService::class);
		$this->emailService = $this->createMock(EmailService::class);
		$this->inboundMapper = $this->createMock(InboundMessageMapper::class);
		$this->inboundMapper->method('claim')
			->willReturnCallback(static fn (InboundMessage $row) => ['row' => $row, 'status' => 'claimed']);
		$this->inboundMapper->method('finish')
			->willReturnCallback(function (InboundMessage $row, string $action) {
				$this->finishedAction = $action;
				return $row;
			});
		$l10nFactory = $this->createMock(L10NFactory::class);
		$l10nFactory->method('get')->willReturn($l);

		$this->service = new InboundEmailService(
			$this->ticketService,
			$this->createMock(LoggerInterface::class),
			$mailboxConfig,
			$this->inboundMapper,
			$replyTokens,
			$this->emailService,
			$this->createMock(HelpdeskCustomerMapper::class),
			$this->createMock(HelpdeskProjectMapper::class),
			$l10nFactory,
		);
	}

	private function service(): InboundEmailService
	{
		return $this->service;
	}

	public function testRejectsNonCustomerSender(): void
	{
		$ticket = new Ticket();
		$ticket->setId(10);
		$ticket->setCustomerEmail('owner@example.com');
		$ticket->setProjectId(5);

		$this->ticketService->method('getActiveTicket')->with(10)->willReturn($ticket);
		$this->ticketService->expects(self::never())->method('addCommentFromEmail');

		$result = $this->service()->processWebhook([
			'to' => 'support+10@example.com',
			'from' => 'other-guest@example.com',
			'subject' => 'Re: #10',
			'text' => 'Trying to inject a comment',
		]);

		self::assertTrue($result['success']);
		self::assertSame(InboundMessage::ACTION_IGNORED_UNAUTHORIZED, $this->finishedAction);
	}

	public function testRejectsForgedToken(): void
	{
		$ticket = new Ticket();
		$ticket->setId(10);
		$ticket->setCustomerEmail('owner@example.com');

		$this->ticketService->method('getActiveTicket')->willReturn($ticket);
		$this->ticketService->expects(self::never())->method('addCommentFromEmail');

		$result = $this->service()->processWebhook([
			'to' => 'support+10-aaaaaaaaaa@example.com', // valid shape, wrong HMAC
			'from' => 'owner@example.com',
			'subject' => 'Re: #10',
			'text' => 'Forged token attempt',
		]);

		self::assertTrue($result['success']);
		self::assertSame(InboundMessage::ACTION_IGNORED_BAD_TOKEN, $this->finishedAction);
	}

	public function testAcceptsMatchingCustomerEmail(): void
	{
		$ticket = new Ticket();
		$ticket->setId(10);
		$ticket->setCustomerEmail('owner@example.com');
		$ticket->setProjectId(5);
		$comment = new Comment();
		$comment->setId(77);

		$this->ticketService->method('getActiveTicket')->with(10)->willReturn($ticket);
		$this->ticketService->expects(self::once())
			->method('addCommentFromEmail')
			->with(10, 'Hello from customer', 'owner@example.com', 'owner@example.com')
			->willReturn($comment);

		$result = $this->service()->processWebhook([
			'to' => 'support+10@example.com',
			'from' => 'owner@example.com',
			'subject' => 'Re: #10',
			'text' => 'Hello from customer',
		]);

		self::assertTrue($result['success']);
		self::assertSame(10, $result['ticket_id']);
		self::assertSame(InboundMessage::ACTION_COMMENT, $this->finishedAction);
	}

	public function testLockedWriteRejectionIsTerminalNotRetryable(): void
	{
		$ticket = new Ticket();
		$ticket->setId(10);
		$ticket->setCustomerEmail('owner@example.com');

		$this->ticketService->method('getActiveTicket')->willReturn($ticket);
		$this->ticketService->method('addCommentFromEmail')
			->willThrowException(new \Exception('Sender not authorized for this ticket'));

		$result = $this->service()->processWebhook([
			'to' => 'support+10@example.com',
			'from' => 'owner@example.com',
			'subject' => 'Re: #10',
			'text' => 'Race after merge to other customer',
		]);

		// A locked-write authz failure is a terminal decision, not a retry
		// candidate — retrying can never change the merge outcome.
		self::assertTrue($result['success']);
		self::assertSame(InboundMessage::ACTION_IGNORED_UNAUTHORIZED, $this->finishedAction);
	}
}

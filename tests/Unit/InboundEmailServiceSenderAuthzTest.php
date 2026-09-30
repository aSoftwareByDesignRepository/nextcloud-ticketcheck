<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Service\InboundEmailService;
use OCA\Ticketcheck\Service\TicketService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class InboundEmailServiceSenderAuthzTest extends TestCase
{
	public function testRejectsProjectGuestWhoIsNotTicketCustomer(): void
	{
		$ticket = new Ticket();
		$ticket->setId(10);
		$ticket->setCustomerEmail('owner@example.com');
		$ticket->setProjectId(5);

		$ticketService = $this->createMock(TicketService::class);
		$ticketService->expects(self::once())
			->method('getActiveTicket')
			->with(10)
			->willReturn($ticket);
		$ticketService->expects(self::never())->method('addCommentFromEmail');

		$service = new InboundEmailService(
			$ticketService,
			$this->createMock(LoggerInterface::class),
		);

		$result = $service->processWebhook([
			'to' => 'support+10@example.com',
			'from' => 'other-guest@example.com',
			'subject' => 'Re: #10',
			'text' => 'Trying to inject a comment',
		]);

		self::assertFalse($result['success']);
		self::assertSame('Sender not authorized for this ticket', $result['error']);
	}

	public function testAcceptsMatchingCustomerEmail(): void
	{
		$ticket = new Ticket();
		$ticket->setId(10);
		$ticket->setCustomerEmail('owner@example.com');
		$ticket->setProjectId(5);

		$ticketService = $this->createMock(TicketService::class);
		$ticketService->expects(self::once())
			->method('getActiveTicket')
			->with(10)
			->willReturn($ticket);
		$ticketService->expects(self::once())
			->method('addCommentFromEmail')
			->with(10, 'Hello from customer', 'owner@example.com', 'owner@example.com');

		$service = new InboundEmailService(
			$ticketService,
			$this->createMock(LoggerInterface::class),
		);

		$result = $service->processWebhook([
			'to' => 'support+10@example.com',
			'from' => 'owner@example.com',
			'subject' => 'Re: #10',
			'text' => 'Hello from customer',
		]);

		self::assertTrue($result['success']);
		self::assertSame(10, $result['ticket_id']);
	}

	public function testPropagatesSenderUnauthorizedFromLockedWrite(): void
	{
		$ticket = new Ticket();
		$ticket->setId(10);
		$ticket->setCustomerEmail('owner@example.com');

		$ticketService = $this->createMock(TicketService::class);
		$ticketService->expects(self::once())
			->method('getActiveTicket')
			->with(10)
			->willReturn($ticket);
		$ticketService->expects(self::once())
			->method('addCommentFromEmail')
			->willThrowException(new \Exception('Sender not authorized for this ticket'));

		$service = new InboundEmailService(
			$ticketService,
			$this->createMock(LoggerInterface::class),
		);

		$result = $service->processWebhook([
			'to' => 'support+10@example.com',
			'from' => 'owner@example.com',
			'subject' => 'Re: #10',
			'text' => 'Race after merge to other customer',
		]);

		self::assertFalse($result['success']);
		self::assertSame('Sender not authorized for this ticket', $result['error']);
	}
}

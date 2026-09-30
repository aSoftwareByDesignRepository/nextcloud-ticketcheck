<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\SplitService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SplitServiceOracleTest extends TestCase
{
	public function testMissingSourceThrowsTicketNotFoundNotRawDoesNotExist(): void
	{
		$mapper = $this->createMock(TicketMapper::class);
		$mapper->method('find')->willThrowException(new DoesNotExistException('nope'));

		$service = new SplitService(
			$mapper,
			$this->createMock(TicketService::class),
			$this->createMock(PermissionService::class),
			$this->createMock(TicketLinkService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(TicketWorkflowLock::class),
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('ticket_not_found');
		$service->splitTicket(99, [
			['title' => 'A', 'description' => 'a'],
			['title' => 'B', 'description' => 'b'],
		]);
	}

	public function testForeignProjectThrowsTicketNotFound(): void
	{
		$ticket = new Ticket();
		$ticket->setId(99);
		$mapper = $this->createMock(TicketMapper::class);
		$mapper->method('find')->willReturn($ticket);
		$permission = $this->createMock(PermissionService::class);
		$permission->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(false);
		$permission->expects(self::never())->method('canEditTicket');

		$service = new SplitService(
			$mapper,
			$this->createMock(TicketService::class),
			$permission,
			$this->createMock(TicketLinkService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(TicketWorkflowLock::class),
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('ticket_not_found');
		$service->splitTicket(99, [
			['title' => 'A', 'description' => 'a'],
			['title' => 'B', 'description' => 'b'],
		]);
	}
}

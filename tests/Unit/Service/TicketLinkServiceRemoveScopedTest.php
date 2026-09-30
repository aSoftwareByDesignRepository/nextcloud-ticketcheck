<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketLink;
use OCA\Ticketcheck\Db\TicketLinkMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TicketLinkServiceRemoveScopedTest extends TestCase
{
	public function testRemoveLinkForTicketRejectsLinkOnOtherTicket(): void
	{
		$link = new TicketLink();
		$link->setId(9);
		$link->setTicketId(100);
		$link->setLinkedTicketId(200);

		$routeTicket = new Ticket();
		$routeTicket->setId(50);

		$linkMapper = $this->createMock(TicketLinkMapper::class);
		$linkMapper->method('find')->with(9)->willReturn($link);
		$linkMapper->expects(self::never())->method('deleteById');

		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->method('find')->with(50)->willReturn($routeTicket);

		$permission = $this->createMock(PermissionService::class);
		$permission->method('canEditTicket')->with($routeTicket)->willReturn(true);

		$service = new TicketLinkService(
			$linkMapper,
			$ticketMapper,
			$permission,
			$this->createMock(TicketWorkflowLock::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Link does not belong to this ticket');
		$service->removeLinkForTicket(50, 9);
	}

	public function testRemoveLinkForTicketDoesNotProbeLinkWhenCannotEdit(): void
	{
		$ticket = new Ticket();
		$ticket->setId(50);

		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->method('find')->with(50)->willReturn($ticket);

		$permission = $this->createMock(PermissionService::class);
		$permission->method('canEditTicket')->with($ticket)->willReturn(false);

		$linkMapper = $this->createMock(TicketLinkMapper::class);
		$linkMapper->expects(self::never())->method('find');

		$service = new TicketLinkService(
			$linkMapper,
			$ticketMapper,
			$permission,
			$this->createMock(TicketWorkflowLock::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('ticket_not_found');
		$service->removeLinkForTicket(50, 9);
	}

	public function testRemoveLinkForTicketAllowsWhenRouteTicketIsEitherEnd(): void
	{
		$link = new TicketLink();
		$link->setId(9);
		$link->setTicketId(100);
		$link->setLinkedTicketId(200);

		$ticket = new Ticket();
		$ticket->setId(100);
		$linked = new Ticket();
		$linked->setId(200);

		$linkMapper = $this->createMock(TicketLinkMapper::class);
		$linkMapper->method('find')->with(9)->willReturn($link);
		$linkMapper->expects(self::once())->method('deleteById')->with(9);

		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->method('find')->willReturnCallback(
			static function (int $id) use ($ticket, $linked): Ticket {
				return $id === 100 ? $ticket : $linked;
			}
		);

		$permission = $this->createMock(PermissionService::class);
		$permission->method('canEditTicket')->willReturn(true);

		$lock = $this->createMock(TicketWorkflowLock::class);
		$lock->method('withTicketLocks')->willReturnCallback(
			static fn (array $ids, callable $cb) => $cb()
		);

		$service = new TicketLinkService(
			$linkMapper,
			$ticketMapper,
			$permission,
			$lock,
			$this->createMock(LoggerInterface::class),
		);

		$service->removeLinkForTicket(200, 9);
	}

	public function testRemoveLinkByPairDoesNotLockWhenCannotEditLinked(): void
	{
		$ticket = new Ticket();
		$ticket->setId(10);
		$linked = new Ticket();
		$linked->setId(20);

		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->method('find')->willReturnCallback(
			static function (int $id) use ($ticket, $linked): Ticket {
				return $id === 10 ? $ticket : $linked;
			}
		);

		$permission = $this->createMock(PermissionService::class);
		$permission->method('canEditTicket')->willReturnCallback(
			static function (Ticket $t) use ($ticket): bool {
				return $t === $ticket;
			}
		);

		$lock = $this->createMock(TicketWorkflowLock::class);
		$lock->expects(self::never())->method('withTicketLocks');

		$service = new TicketLinkService(
			$this->createMock(TicketLinkMapper::class),
			$ticketMapper,
			$permission,
			$lock,
			$this->createMock(LoggerInterface::class),
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('ticket_not_found');
		$service->removeLinkByPair(10, 20, 'related');
	}

	public function testRemoveLinkByPairMissingLinkedIsTicketNotFound(): void
	{
		$ticket = new Ticket();
		$ticket->setId(10);
		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->method('find')->willReturnCallback(
			static function (int $id) use ($ticket): Ticket {
				if ($id === 10) {
					return $ticket;
				}
				throw new \OCP\AppFramework\Db\DoesNotExistException('missing');
			}
		);
		$permission = $this->createMock(PermissionService::class);
		$permission->method('canEditTicket')->with($ticket)->willReturn(true);

		$lock = $this->createMock(TicketWorkflowLock::class);
		$lock->expects(self::never())->method('withTicketLocks');

		$service = new TicketLinkService(
			$this->createMock(TicketLinkMapper::class),
			$ticketMapper,
			$permission,
			$lock,
			$this->createMock(LoggerInterface::class),
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('ticket_not_found');
		$service->removeLinkByPair(10, 20, 'related');
	}
}

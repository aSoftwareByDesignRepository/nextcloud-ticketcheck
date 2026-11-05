<?php

declare(strict_types=1);

/**
 * TicketLinkService::addLink — check-then-act race pin.
 *
 * exists() → insert() can lose the (ticket_id,linked_ticket_id,link_type)
 * unique-index race to a concurrent link: the loser must surface the same
 * 'Link already exists' the pre-check throws, never the raw DB exception.
 */

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketLink;
use OCA\Ticketcheck\Db\TicketLinkMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCP\DB\Exception as DbException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TicketLinkServiceConflictTest extends TestCase
{
	private function service(TicketLinkMapper $linkMapper): TicketLinkService
	{
		$t1 = new Ticket();
		$t1->setId(1);
		$t2 = new Ticket();
		$t2->setId(2);

		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->method('find')->willReturnCallback(
			static fn (int $id): Ticket => $id === 1 ? $t1 : $t2
		);

		$permission = $this->createMock(PermissionService::class);
		$permission->method('canEditTicket')->willReturn(true);

		$lock = $this->createMock(TicketWorkflowLock::class);
		$lock->method('withTicketLocks')->willReturnCallback(
			static fn (array $ids, callable $cb) => $cb()
		);

		return new TicketLinkService($linkMapper, $ticketMapper, $permission, $lock, new NullLogger());
	}

	public function testUniqueViolationMapsToLinkAlreadyExists(): void
	{
		$ex = $this->createMock(DbException::class);
		$ex->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);

		$linkMapper = $this->createMock(TicketLinkMapper::class);
		$linkMapper->method('exists')->willReturn(false);
		$linkMapper->method('insert')->willThrowException($ex);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Link already exists');
		$this->service($linkMapper)->addLink(1, 2, TicketLink::TYPE_RELATED);
	}

	public function testNonUniqueDbExceptionPropagatesUnmasked(): void
	{
		$ex = $this->createMock(DbException::class);
		$ex->method('getReason')->willReturn(DbException::REASON_SERVER);

		$linkMapper = $this->createMock(TicketLinkMapper::class);
		$linkMapper->method('exists')->willReturn(false);
		$linkMapper->method('insert')->willThrowException($ex);

		$this->expectException(DbException::class);
		$this->service($linkMapper)->addLink(1, 2, TicketLink::TYPE_RELATED);
	}
}

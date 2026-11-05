<?php

declare(strict_types=1);

/**
 * TicketWatcherMapper::moveToTicket — check-then-act race pin.
 *
 * isWatching() → update() can lose the (ticket_id,user_id) unique race when a
 * concurrent add lands the same user on the target: the move must drop the
 * redundant source row (same outcome as the pre-check), never surface the
 * raw DB exception.
 */

namespace OCA\Ticketcheck\Tests\Unit\Db;

use OCA\Ticketcheck\Db\TicketWatcher;
use OCA\Ticketcheck\Db\TicketWatcherMapper;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class TicketWatcherMapperMoveConflictTest extends TestCase
{
	private function watcher(int $id, int $ticketId, string $userId): TicketWatcher
	{
		$w = new TicketWatcher();
		$w->setId($id);
		$w->setTicketId($ticketId);
		$w->setUserId($userId);
		return $w;
	}

	public function testUniqueViolationOnMoveCountsAsDrop(): void
	{
		$watcher = $this->watcher(9, 1, 'bob');
		$ex = $this->createMock(DbException::class);
		$ex->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);

		$mapper = $this->getMockBuilder(TicketWatcherMapper::class)
			->setConstructorArgs([$this->createMock(IDBConnection::class)])
			->onlyMethods(['findByTicketId', 'isWatching', 'update', 'delete'])
			->getMock();
		$mapper->method('findByTicketId')->with(1)->willReturn([$watcher]);
		$mapper->method('isWatching')->with(2, 'bob')->willReturn(false);
		$mapper->method('update')->with($watcher)->willThrowException($ex);
		$mapper->expects($this->once())->method('delete')->with($watcher);

		self::assertSame(['moved' => 0, 'dropped' => 1], $mapper->moveToTicket(1, 2));
	}

	public function testNonUniqueDbExceptionPropagatesUnmasked(): void
	{
		$watcher = $this->watcher(9, 1, 'bob');
		$ex = $this->createMock(DbException::class);
		$ex->method('getReason')->willReturn(DbException::REASON_SERVER);

		$mapper = $this->getMockBuilder(TicketWatcherMapper::class)
			->setConstructorArgs([$this->createMock(IDBConnection::class)])
			->onlyMethods(['findByTicketId', 'isWatching', 'update', 'delete'])
			->getMock();
		$mapper->method('findByTicketId')->with(1)->willReturn([$watcher]);
		$mapper->method('isWatching')->willReturn(false);
		$mapper->method('update')->willThrowException($ex);
		$mapper->expects($this->never())->method('delete');

		$this->expectException(DbException::class);
		$mapper->moveToTicket(1, 2);
	}
}

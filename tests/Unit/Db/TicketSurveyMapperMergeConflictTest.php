<?php

declare(strict_types=1);

/**
 * TicketSurveyMapper::moveOrDropOnMerge — check-then-act race pin.
 *
 * findByTicket() → update() can lose the UNIQUE(ticket_id) race when a survey
 * lands on the target between check and write: the source row is then
 * redundant and must be dropped, never a raw DB exception.
 */

namespace OCA\Ticketcheck\Tests\Unit\Db;

use OCA\Ticketcheck\Db\TicketSurvey;
use OCA\Ticketcheck\Db\TicketSurveyMapper;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class TicketSurveyMapperMergeConflictTest extends TestCase
{
	public function testUniqueViolationOnMoveDropsSourceSurvey(): void
	{
		$survey = new TicketSurvey();
		$survey->setId(7);
		$survey->setTicketId(1);

		$ex = $this->createMock(DbException::class);
		$ex->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);

		$mapper = $this->getMockBuilder(TicketSurveyMapper::class)
			->setConstructorArgs([$this->createMock(IDBConnection::class)])
			->onlyMethods(['findByTicket', 'update', 'delete'])
			->getMock();
		$mapper->method('findByTicket')->willReturnCallback(
			static fn (int $id): ?TicketSurvey => $id === 1 ? $survey : null
		);
		$mapper->method('update')->with($survey)->willThrowException($ex);
		$mapper->expects($this->once())->method('delete')->with($survey);

		self::assertSame(['moved' => 0, 'dropped' => 1], $mapper->moveOrDropOnMerge(1, 2));
	}

	public function testNonUniqueDbExceptionPropagatesUnmasked(): void
	{
		$survey = new TicketSurvey();
		$survey->setId(7);
		$survey->setTicketId(1);

		$ex = $this->createMock(DbException::class);
		$ex->method('getReason')->willReturn(DbException::REASON_SERVER);

		$mapper = $this->getMockBuilder(TicketSurveyMapper::class)
			->setConstructorArgs([$this->createMock(IDBConnection::class)])
			->onlyMethods(['findByTicket', 'update', 'delete'])
			->getMock();
		$mapper->method('findByTicket')->willReturnCallback(
			static fn (int $id): ?TicketSurvey => $id === 1 ? $survey : null
		);
		$mapper->method('update')->willThrowException($ex);
		$mapper->expects($this->never())->method('delete');

		$this->expectException(DbException::class);
		$mapper->moveOrDropOnMerge(1, 2);
	}
}

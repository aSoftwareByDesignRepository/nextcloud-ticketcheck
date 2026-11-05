<?php

declare(strict_types=1);

/**
 * TicketLinkMapper::repointTicketOnMerge — check-then-act race pin.
 *
 * exists() → update() can lose the (ticket_id,linked_ticket_id,link_type)
 * unique race when a concurrent equivalent link lands on the target: the
 * source row is then redundant and must count as a drop, never a raw DB
 * exception.
 */

namespace OCA\Ticketcheck\Tests\Unit\Db;

use OCA\Ticketcheck\Db\TicketLink;
use OCA\Ticketcheck\Db\TicketLinkMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class TicketLinkMapperRepointConflictTest extends TestCase
{
	private function link(int $ticketId, int $linkedId, string $type): TicketLink
	{
		$l = new TicketLink();
		$l->setTicketId($ticketId);
		$l->setLinkedTicketId($linkedId);
		$l->setLinkType($type);
		return $l;
	}

	public function testUniqueViolationOnRepointCountsAsDrop(): void
	{
		$link = $this->link(1, 3, TicketLink::TYPE_RELATED);
		$ex = $this->createMock(DbException::class);
		$ex->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('orX')->willReturn($this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class));
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('param');
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = $this->getMockBuilder(TicketLinkMapper::class)
			->setConstructorArgs([$db])
			->onlyMethods(['findEntities', 'exists', 'update', 'delete'])
			->getMock();
		$mapper->method('findEntities')->willReturn([$link]);
		$mapper->method('exists')->with(2, 3, TicketLink::TYPE_RELATED)->willReturn(false);
		$mapper->method('update')->with($link)->willThrowException($ex);
		$mapper->expects($this->once())->method('delete')->with($link);

		self::assertSame(['moved' => 0, 'dropped' => 1], $mapper->repointTicketOnMerge(1, 2));
	}

	public function testNonUniqueDbExceptionPropagatesUnmasked(): void
	{
		$link = $this->link(1, 3, TicketLink::TYPE_RELATED);
		$ex = $this->createMock(DbException::class);
		$ex->method('getReason')->willReturn(DbException::REASON_SERVER);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('orX')->willReturn($this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class));
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('param');
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = $this->getMockBuilder(TicketLinkMapper::class)
			->setConstructorArgs([$db])
			->onlyMethods(['findEntities', 'exists', 'update', 'delete'])
			->getMock();
		$mapper->method('findEntities')->willReturn([$link]);
		$mapper->method('exists')->willReturn(false);
		$mapper->method('update')->willThrowException($ex);
		$mapper->expects($this->never())->method('delete');

		$this->expectException(DbException::class);
		$mapper->repointTicketOnMerge(1, 2);
	}
}

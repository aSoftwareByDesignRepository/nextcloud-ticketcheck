<?php

declare(strict_types=1);

/**
 * GuestProjectAccessMapper::grantAccess — check-then-act race pin.
 *
 * hasAccess() → insert() can lose the (user_id,project_id) unique-index race
 * to a concurrent grant: the loser must surface the same
 * 'User already has access to this project' the pre-check throws.
 */

namespace OCA\Ticketcheck\Tests\Unit\Db;

use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class GuestProjectAccessMapperConflictTest extends TestCase
{
	public function testUniqueViolationMapsToAlreadyHasAccess(): void
	{
		$ex = $this->createMock(DbException::class);
		$ex->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);

		$mapper = $this->getMockBuilder(GuestProjectAccessMapper::class)
			->setConstructorArgs([$this->createMock(IDBConnection::class)])
			->onlyMethods(['hasAccess', 'insert'])
			->getMock();
		$mapper->method('hasAccess')->with('bob', 3)->willReturn(false);
		$mapper->method('insert')->willThrowException($ex);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('User already has access to this project');
		$mapper->grantAccess('bob', 3, null, 'alice');
	}

	public function testNonUniqueDbExceptionPropagatesUnmasked(): void
	{
		$ex = $this->createMock(DbException::class);
		$ex->method('getReason')->willReturn(DbException::REASON_SERVER);

		$mapper = $this->getMockBuilder(GuestProjectAccessMapper::class)
			->setConstructorArgs([$this->createMock(IDBConnection::class)])
			->onlyMethods(['hasAccess', 'insert'])
			->getMock();
		$mapper->method('hasAccess')->willReturn(false);
		$mapper->method('insert')->willThrowException($ex);

		$this->expectException(DbException::class);
		$mapper->grantAccess('bob', 3, null, 'alice');
	}
}

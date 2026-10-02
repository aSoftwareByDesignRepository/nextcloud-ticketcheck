<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Exception\CompanionConflictException;
use OCA\Ticketcheck\Exception\CompanionValidationException;
use OCA\Ticketcheck\Service\IdempotencyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

final class IdempotencyServiceTest extends TestCase
{
	public function testNormalizeKeyRejectsTooShortAndIllegalChars(): void
	{
		$svc = $this->service();
		$this->assertNull($svc->normalizeKey(null));
		$this->assertNull($svc->normalizeKey(''));
		$this->assertNull($svc->normalizeKey('   '));

		try {
			$svc->normalizeKey('short');
			$this->fail('expected CompanionValidationException');
		} catch (CompanionValidationException $e) {
			$this->assertSame('invalid_idempotency_key', $e->getErrorCode());
		}

		try {
			$svc->normalizeKey('bad key with spaces!!');
			$this->fail('expected CompanionValidationException');
		} catch (CompanionValidationException $e) {
			$this->assertSame('invalid_idempotency_key', $e->getErrorCode());
		}

		$this->assertSame('tc-abc12345', $svc->normalizeKey('  tc-abc12345  '));
	}

	public function testRunWithoutKeyBypassesCache(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->never())->method('acquireLock');

		$svc = new IdempotencyService($db, $this->time(1_700_000_000), $locking);
		$calls = 0;
		$out = $svc->run('alice', 'companion.ticket.create.1', null, function () use (&$calls): array {
			$calls++;
			return ['ticket' => ['id' => 7]];
		});
		$this->assertSame(1, $calls);
		$this->assertSame(['ticket' => ['id' => 7]], $out);
	}

	public function testRunReturnsCachedResponseWithoutCallingOperation(): void
	{
		$now = 1_700_000_000;
		$cached = ['ticket' => ['id' => 42]];
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $c, $p) => $c . '=');
		$expr->method('gt')->willReturnCallback(static fn (string $c, $p) => $c . '>');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$stmt = $this->createMock(IResult::class);
		$stmt->method('fetch')->willReturn([
			'response_json' => json_encode($cached, JSON_THROW_ON_ERROR),
		]);
		$qb->method('executeQuery')->willReturn($stmt);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->never())->method('acquireLock');

		$svc = new IdempotencyService($db, $this->time($now), $locking);
		$calls = 0;
		$out = $svc->run('alice', 'companion.ticket.create.1', 'tc-stable-key-01', function () use (&$calls): array {
			$calls++;
			return ['ticket' => ['id' => 999]];
		});
		$this->assertSame(0, $calls);
		$this->assertSame($cached, $out);
	}

	public function testRunStoresOnFirstSuccess(): void
	{
		$now = 1_700_000_000;
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $c, $p) => $c . '=');
		$expr->method('gt')->willReturnCallback(static fn (string $c, $p) => $c . '>');

		$selectMiss = $this->createMock(IQueryBuilder::class);
		$selectMiss->method('expr')->willReturn($expr);
		$selectMiss->method('createNamedParameter')->willReturnArgument(0);
		$selectMiss->method('select')->willReturnSelf();
		$selectMiss->method('from')->willReturnSelf();
		$selectMiss->method('where')->willReturnSelf();
		$selectMiss->method('andWhere')->willReturnSelf();
		$selectMiss->method('setMaxResults')->willReturnSelf();
		$missStmt = $this->createMock(IResult::class);
		$missStmt->method('fetch')->willReturn(false);
		$selectMiss->method('executeQuery')->willReturn($missStmt);

		$insert = $this->createMock(IQueryBuilder::class);
		$insert->method('createNamedParameter')->willReturnArgument(0);
		$insert->method('insert')->willReturnSelf();
		$insert->method('values')->willReturnSelf();
		$insert->expects($this->once())->method('executeStatement')->willReturn(1);

		$selectAfter = $this->createMock(IQueryBuilder::class);
		$selectAfter->method('expr')->willReturn($expr);
		$selectAfter->method('createNamedParameter')->willReturnArgument(0);
		$selectAfter->method('select')->willReturnSelf();
		$selectAfter->method('from')->willReturnSelf();
		$selectAfter->method('where')->willReturnSelf();
		$selectAfter->method('andWhere')->willReturnSelf();
		$selectAfter->method('setMaxResults')->willReturnSelf();
		$hitStmt = $this->createMock(IResult::class);
		$hitStmt->method('fetch')->willReturn([
			'response_json' => '{"ticket":{"id":42}}',
		]);
		$selectAfter->method('executeQuery')->willReturn($hitStmt);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$selectMiss, // pre-lock lookup
			$selectMiss, // post-lock lookup
			$insert,
			$selectAfter, // prefer stored
		);

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock');
		$locking->expects($this->once())->method('releaseLock');

		$svc = new IdempotencyService($db, $this->time($now), $locking);
		$out = $svc->run('alice', 'companion.ticket.create.3', 'tc-first-success-key', static fn (): array => [
			'ticket' => ['id' => 42],
		]);
		$this->assertSame(['ticket' => ['id' => 42]], $out);
	}

	public function testBusyLockSurfacesConflict(): void
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $c, $p) => $c . '=');
		$expr->method('gt')->willReturnCallback(static fn (string $c, $p) => $c . '>');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$stmt = $this->createMock(IResult::class);
		$stmt->method('fetch')->willReturn(false);
		$qb->method('executeQuery')->willReturn($stmt);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')->willThrowException(new LockedException('busy'));

		$svc = new IdempotencyService($db, $this->time(1_700_000_000), $locking);
		try {
			$svc->run('alice', 'companion.ticket.create.1', 'tc-busy-key-01', static fn (): array => ['x' => 1]);
			$this->fail('expected CompanionConflictException');
		} catch (CompanionConflictException $e) {
			$this->assertSame('idempotency_busy', $e->getErrorCode());
			$this->assertSame(409, $e->getCode());
		}
	}

	public function testInvalidScopeRejected(): void
	{
		$svc = $this->service();
		$this->expectException(CompanionValidationException::class);
		$svc->run('', 'companion.ticket.create.1', 'tc-valid-key-01', static fn (): array => []);
	}

	private function service(): IdempotencyService
	{
		return new IdempotencyService(
			$this->createMock(IDBConnection::class),
			$this->time(1_700_000_000),
			$this->createMock(ILockingProvider::class),
		);
	}

	private function time(int $epoch): ITimeFactory
	{
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($epoch);
		return $time;
	}
}

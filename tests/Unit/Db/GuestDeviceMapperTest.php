<?php

declare(strict_types=1);

/**
 * GuestDeviceMapper::findByTokenHash — the revoked/expired SQL fence.
 * Asserted at the QB level: isNull(revoked_at) AND gte(expires_at, now)
 * must both reach the statement; removing either un-fences dead tokens.
 */

namespace OCA\Ticketcheck\Tests\Unit\Db;

use OCA\Ticketcheck\Db\GuestDeviceMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class GuestDeviceMapperTest extends TestCase
{
    public function testFindByTokenHashFencesRevokedAndExpired(): void
    {
        $clauses = [];
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('eq');
        $expr->method('isNull')->willReturnCallback(function ($col) use (&$clauses) {
            $clauses[] = 'null:' . $col;
            return 'isNull';
        });
        $expr->method('gte')->willReturnCallback(function ($col) use (&$clauses) {
            $clauses[] = 'gte:' . $col;
            return 'gte';
        });
        $stmt = $this->createMock(\OCP\DB\IResult::class);
        $stmt->method('fetchAll')->willReturn([]); // no row → DoesNotExistException
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        foreach (['select','from','where','andWhere','setMaxResults'] as $m) {
            $qb->method($m)->willReturnSelf();
        }
        $qb->method('executeQuery')->willReturn($stmt);
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);

        $mapper = new GuestDeviceMapper($db);
        // The WHERE clauses are built before findEntity executes — we only
        // care that the revoked/expired fence reached the statement.
        try {
            $mapper->findByTokenHash('h');
        } catch (\Throwable) {
        }
        self::assertContains('null:revoked_at', $clauses);
        self::assertContains('gte:expires_at', $clauses);
    }
}

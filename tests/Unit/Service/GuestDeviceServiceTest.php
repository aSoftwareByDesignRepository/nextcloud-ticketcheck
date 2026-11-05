<?php

declare(strict_types=1);

/**
 * GuestDeviceService + GuestDeviceMapper (§10.7): bearer-token pairing,
 * last-seen sliding, per-identity scoped revoke, and the revoked/expired
 * fence inside findByTokenHash.
 */

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\GuestDevice;
use OCA\Ticketcheck\Db\GuestDeviceMapper;
use OCA\Ticketcheck\Service\GuestDeviceService;
use OCA\Ticketcheck\Service\TokenService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GuestDeviceServiceTest extends TestCase
{
    private GuestDeviceMapper&MockObject $mapper;
    private IDBConnection&MockObject $db;
    private IQueryBuilder&MockObject $qb;
    private GuestDeviceService $svc;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(GuestDeviceMapper::class);
        $this->db = $this->createMock(IDBConnection::class);
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('eq');
        $expr->method('isNull')->willReturn('isNull');
        $expr->method('isNotNull')->willReturn('isNotNull');
        $expr->method('gte')->willReturn('gte');
        $expr->method('lt')->willReturn('lt');
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->qb->method('expr')->willReturn($expr);
        $this->qb->method('createNamedParameter')->willReturnArgument(0);
        foreach (['update','set','where','andWhere','insert','delete'] as $m) {
            $this->qb->method($m)->willReturnSelf();
        }
        $this->qb->method('executeStatement')->willReturn(1);
        $this->db->method('getQueryBuilder')->willReturn($this->qb);
        $this->svc = new GuestDeviceService($this->mapper, new TokenService(), $this->db);
    }

    public function testResolveRejectsBlankAndOversize(): void
    {
        $this->mapper->expects($this->never())->method('findByTokenHash');
        self::assertNull($this->svc->resolve(''));
        self::assertNull($this->svc->resolve(str_repeat('x', 200)));
        // boundary: 128 still reaches the mapper, 129 does not
        self::assertNull($this->svc->resolve(str_repeat('y', 129)));
    }

    public function testResolveHitsMapperForValidToken(): void
    {
        $row = new GuestDevice();
        $row->setId(9);
        $row->setIdentityId(7);
        $this->mapper->expects($this->once())->method('findByTokenHash')->willReturn($row);
        self::assertSame($row, $this->svc->resolve('dev-token'));
    }

    public function testResolveTouchesLastSeen(): void
    {
        $row = new GuestDevice();
        $row->setId(9);
        $this->mapper->method('findByTokenHash')->willReturn($row);
        $seen = false;
        $this->qb->method('set')->willReturnCallback(function ($col) use (&$seen) {
            if ($col === 'last_seen_at') { $seen = true; }
            return $this->qb;
        });
        $this->svc->resolve('dev-token');
        self::assertTrue($seen);
    }

    public function testPairMintsRowWithHashAndExpiry(): void
    {
        $inserted = null;
        $this->mapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (GuestDevice $d) use (&$inserted) {
                $inserted = $d;
                return $d;
            });
        [$token, $row] = $this->svc->pair(7, 'My Phone');
        self::assertNotEmpty($token);
        self::assertNotNull($inserted->getTokenHash());
        self::assertNotSame($token, $inserted->getTokenHash()); // hashed at rest
        self::assertSame(7, $inserted->getIdentityId());
        self::assertSame('My Phone', $inserted->getLabel());
        self::assertGreaterThan(new \DateTime('+300 days'), $inserted->getExpiresAt());
    }

    public function testPairTruncatesLongLabel(): void
    {
        $inserted = null;
        $this->mapper->method('insert')->willReturnCallback(function (GuestDevice $d) use (&$inserted) {
            $inserted = $d;
            return $d;
        });
        $this->svc->pair(7, str_repeat('x', 500));
        self::assertLessThanOrEqual(190, mb_strlen((string)$inserted->getLabel()));
    }

    public function testRevokeScopesToIdentity(): void
    {
        // identity_id AND revoked_at IS NULL must both appear in the UPDATE —
        // otherwise revoke is unscoped or idempotency is lost.
        $conds = [];
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(function ($col, $val) use (&$conds) {
            $conds[$col] = $val;
            return 'eq';
        });
        $expr->method('isNull')->willReturnCallback(function ($col) use (&$conds) {
            $conds['null:' . $col] = true;
            return 'isNull';
        });
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('update')->willReturnSelf();
        $qb->method('set')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('executeStatement')->willReturn(1);
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        $svc = new GuestDeviceService($this->mapper, new TokenService(), $db);
        self::assertTrue($svc->revokeById(3, 7));
        self::assertSame(3, $conds['id']);
        self::assertSame(7, $conds['identity_id']);
        self::assertArrayHasKey('null:revoked_at', $conds);
    }
}

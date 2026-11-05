<?php

declare(strict_types=1);

/**
 * OTP verify path (spec US-T01 AC-5/AC-6): attempts are counted BEFORE the
 * hash comparison via an SQL-fenced increment; consume is a CAS. Uniform
 * null on every failure — no oracles.
 */

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\PortalOtp;
use OCA\Ticketcheck\Db\PortalOtpMapper;
use OCA\Ticketcheck\Service\GuestAuthConfig;
use OCA\Ticketcheck\Service\PortalOtpService;
use OCA\Ticketcheck\Service\TokenService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class PortalOtpServiceTest extends TestCase
{
    private function qb(int $affected = 1): IQueryBuilder
    {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(static fn ($c, $v) => 'eq');
        $expr->method('lt')->willReturn('lt');
        $expr->method('isNull')->willReturn('isNull');
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('createFunction')->willReturnArgument(0);
        $qb->method('update')->willReturnSelf();
        $qb->method('set')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('executeStatement')->willReturn($affected);
        return $qb;
    }

    private function otpRow(int $id, string $code, int $attempts = 0, ?int $identityId = 7): PortalOtp
    {
        $row = new PortalOtp();
        $row->setId($id);
        $row->setEmail('g@example.com');
        $row->setIdentityId($identityId);
        $row->setPurpose('login');
        $row->setCodeHash(hash('sha256', $code));
        $row->setAttempts($attempts);
        $row->setExpiresAt(new \DateTime('+10 minutes'));
        return $row;
    }

    public function testWrongCodeReturnsNullAndStillCountsAttempt(): void
    {
        $mapper = $this->createMock(PortalOtpMapper::class);
        $mapper->method('findActive')->willReturn($this->otpRow(1, '654321'));
        $db = $this->createMock(IDBConnection::class);
        // First (and only) QB call must be the attempt increment — and it
        // must run even though the code will not match.
        $db->expects($this->once())->method('getQueryBuilder')
            ->willReturn($this->qb(1));
        $svc = new PortalOtpService($mapper, new TokenService(),
            $this->createMock(GuestAuthConfig::class), $db);
        self::assertNull($svc->verify('g@example.com', '000000'));
    }

    public function testCorrectCodeConsumesAndReturnsIdentity(): void
    {
        $mapper = $this->createMock(PortalOtpMapper::class);
        $mapper->method('findActive')->willReturn($this->otpRow(5, '123456'));
        $db = $this->createMock(IDBConnection::class);
        // attempts+1 QB then consume CAS QB — both must succeed.
        $db->expects($this->exactly(2))->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($this->qb(1), $this->qb(1));
        $svc = new PortalOtpService($mapper, new TokenService(),
            $this->createMock(GuestAuthConfig::class), $db);
        self::assertSame(7, $svc->verify('g@example.com', '123456'));
    }

    public function testLostConsumeRaceReturnsNull(): void
    {
        $mapper = $this->createMock(PortalOtpMapper::class);
        $mapper->method('findActive')->willReturn($this->otpRow(5, '123456'));
        $db = $this->createMock(IDBConnection::class);
        // attempt increment succeeds, CAS consume reports 0 rows — a
        // parallel request already consumed it. Result must be null.
        $db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($this->qb(1), $this->qb(0));
        $svc = new PortalOtpService($mapper, new TokenService(),
            $this->createMock(GuestAuthConfig::class), $db);
        self::assertNull($svc->verify('g@example.com', '123456'));
    }

    public function testExhaustedAttemptsReturnNullWithoutUpdate(): void
    {
        $mapper = $this->createMock(PortalOtpMapper::class);
        $mapper->method('findActive')
            ->willReturn($this->otpRow(1, '123456', PortalOtpService::MAX_ATTEMPTS));
        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->never())->method('getQueryBuilder');
        $svc = new PortalOtpService($mapper, new TokenService(),
            $this->createMock(GuestAuthConfig::class), $db);
        self::assertNull($svc->verify('g@example.com', '123456'));
    }

    public function testCasFenceUsesStrictLessThan(): void
    {
        // lt(attempts, 5) — not lte(5) — so the CAS refuses to count the 6th
        // attempt when the row already sits at the cap (concurrent-burst).
        $mapper = $this->createMock(PortalOtpMapper::class);
        $mapper->method('findActive')
            ->willReturn($this->otpRow(1, '123456', PortalOtpService::MAX_ATTEMPTS - 1));
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('eq');
        $expr->method('isNull')->willReturn('isNull');
        $ltSeen = false;
        $expr->method('lt')->willReturnCallback(function ($col, $val) use (&$ltSeen) {
            if ($col === 'attempts' && $val === PortalOtpService::MAX_ATTEMPTS) {
                $ltSeen = true;
            }
            return 'lt';
        });
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('createFunction')->willReturnArgument(0);
        foreach (['update','set','where','andWhere'] as $m) { $qb->method($m)->willReturnSelf(); }
        $qb->method('executeStatement')->willReturn(1);
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        $svc = new PortalOtpService($mapper, new TokenService(),
            $this->createMock(GuestAuthConfig::class), $db);
        $svc->verify('g@example.com', '123456');
        self::assertTrue($ltSeen, 'CAS attempt fence must use lt(attempts, MAX_ATTEMPTS)');
    }

    public function testNoActiveRowReturnsNull(): void
    {
        $mapper = $this->createMock(PortalOtpMapper::class);
        $mapper->method('findActive')->willReturn(null);
        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->never())->method('getQueryBuilder');
        $svc = new PortalOtpService($mapper, new TokenService(),
            $this->createMock(GuestAuthConfig::class), $db);
        self::assertNull($svc->verify('g@example.com', '123456'));
    }

    public function testMagicConsumeIsSingleUse(): void
    {
        $mapper = $this->createMock(PortalOtpMapper::class);
        $mapper->method('findByMagicHash')->willReturn($this->otpRow(9, '123456'));
        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->once())->method('getQueryBuilder')
            ->willReturn($this->qb(1));
        $svc = new PortalOtpService($mapper, new TokenService(),
            $this->createMock(GuestAuthConfig::class), $db);
        self::assertSame(7, $svc->consumeMagic('magic-token-abc'));
    }

    public function testMagicConsumeRejectsBlankAndOversize(): void
    {
        $svc = new PortalOtpService(
            $this->createMock(PortalOtpMapper::class), new TokenService(),
            $this->createMock(GuestAuthConfig::class), $this->createMock(IDBConnection::class));
        self::assertNull($svc->consumeMagic(''));
        self::assertNull($svc->consumeMagic(str_repeat('x', 200)));
    }

    public function testIssueWritesHashOnlyAndSupersedes(): void
    {
        $mapper = $this->createMock(PortalOtpMapper::class);
        $mapper->expects($this->once())->method('supersedeActive')
            ->with('g@example.com', 'login');
        $mapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (PortalOtp $o): PortalOtp {
                self::assertSame(64, strlen((string)$o->getCodeHash())); // hash, not code
                self::assertSame(64, strlen((string)$o->getMagicHash()));
                $o->setId(42);
                return $o;
            });
        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->once())->method('commit');
        $config = $this->createMock(GuestAuthConfig::class);
        $config->method('otpTtlMinutes')->willReturn(10);
        $svc = new PortalOtpService($mapper, new TokenService(), $config, $db);
        [$code, $magic, $id] = $svc->issue('G@EXAMPLE.COM ', 7, '127.0.0.1');
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        self::assertNotSame('', $magic);
        self::assertSame(42, $id);
    }
}

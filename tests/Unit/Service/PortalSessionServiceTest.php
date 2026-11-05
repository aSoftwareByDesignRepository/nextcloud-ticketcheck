<?php

declare(strict_types=1);

/**
 * Portal sessions (spec MH-T03): hash at rest, revoked/expired/abs-cap
 * rejection, sliding renewal bounded by the absolute cap.
 */

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\PortalSession;
use OCA\Ticketcheck\Db\PortalSessionMapper;
use OCA\Ticketcheck\Service\GuestAuthConfig;
use OCA\Ticketcheck\Service\PortalSessionService;
use OCA\Ticketcheck\Service\TokenService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class PortalSessionServiceTest extends TestCase
{
    private function session(?\DateTime $expires, ?\DateTime $abs, ?\DateTime $revoked = null): PortalSession
    {
        $s = new PortalSession();
        $s->setId(3);
        $s->setTokenHash('h');
        $s->setCsrfHash('c');
        $s->setIdentityId(7);
        $s->setCreatedAt(new \DateTime('-1 day'));
        $s->setLastSeenAt(new \DateTime('-1 hour'));
        $s->setExpiresAt($expires ?? new \DateTime('+30 days'));
        $s->setAbsExpiresAt($abs ?? new \DateTime('+365 days'));
        $s->setRevokedAt($revoked);
        return $s;
    }

    private function svc(?PortalSession $row, int $sessionDays = 30): array
    {
        $mapper = $this->createMock(PortalSessionMapper::class);
        $mapper->method('findByTokenHash')->willReturn($row);
        $expr = $this->createMock(IExpressionBuilder::class);
        $composite = $this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class);
        $expr->method('eq')->willReturn('eq');
        $expr->method('isNull')->willReturn('isNull');
        $expr->method('andX')->willReturn($composite);
        $expr->method('orX')->willReturn($composite);
        $expr->method('lt')->willReturn('lt');
        $expr->method('gte')->willReturn('gte');
        $expr->method('lte')->willReturn('lte');
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        foreach (['update', 'set', 'where', 'andWhere', 'delete'] as $m) {
            $qb->method($m)->willReturnSelf();
        }
        $qb->method('executeStatement')->willReturn(1);
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        $config = $this->createMock(GuestAuthConfig::class);
        $config->method('sessionDays')->willReturn($sessionDays);
        return [new PortalSessionService($mapper, new TokenService(), $config, $db), $qb];
    }

    public function testResolveReturnsLiveSession(): void
    {
        [$svc] = $this->svc($this->session(null, null));
        self::assertNotNull($svc->resolve('tok'));
    }

    public function testResolveRejectsRevoked(): void
    {
        [$svc] = $this->svc($this->session(null, null, new \DateTime('-1 hour')));
        self::assertNull($svc->resolve('tok'));
    }

    public function testResolveRejectsSlidingExpiry(): void
    {
        [$svc] = $this->svc($this->session(new \DateTime('-1 hour'), new \DateTime('+300 days')));
        self::assertNull($svc->resolve('tok'));
    }

    public function testResolveRejectsAbsoluteExpiry(): void
    {
        // Sliding expiry in the future but absolute cap passed → dead.
        [$svc] = $this->svc($this->session(new \DateTime('+10 days'), new \DateTime('-1 hour')));
        self::assertNull($svc->resolve('tok'));
    }

    public function testResolveRejectsBlankAndOversize(): void
    {
        [$svc] = $this->svc($this->session(null, null));
        self::assertNull($svc->resolve(''));
        self::assertNull($svc->resolve(str_repeat('x', 200)));
    }

    public function testTokenLengthBoundary(): void
    {
        // >128 must reject before mapper; exactly 128 must reach the mapper.
        $row = $this->session(null, null);
        $mapper = $this->createMock(PortalSessionMapper::class);
        $mapper->expects($this->once())->method('findByTokenHash')->willReturn($row);
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('eq');
        $expr->method('isNull')->willReturn('isNull');
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        foreach (['update', 'set', 'where', 'andWhere'] as $m) {
            $qb->method($m)->willReturnSelf();
        }
        $qb->method('executeStatement')->willReturn(1);
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        $config = $this->createMock(GuestAuthConfig::class);
        $config->method('sessionDays')->willReturn(30);
        $svc = new PortalSessionService($mapper, new TokenService(), $config, $db);
        self::assertNull($svc->resolve(str_repeat('x', 129)));
        self::assertSame($row, $svc->resolve(str_repeat('x', 128)));
    }

    public function testSlidingRenewalUsesSessionDaysNotCap(): void
    {
        // min(sessionDays, ABS_CAP): when sessionDays=30 renewal must be +30d,
        // never +365d (a min→max mutation silently extends every session).
        $row = $this->session(new \DateTime('+10 days'), new \DateTime('+365 days'));
        [$svc, $qb] = $this->svc($row, 30);
        $newExpiry = null;
        $qb->method('set')->willReturnCallback(function (string $col, $param) use ($qb, &$newExpiry) {
            if ($col === 'expires_at') {
                $newExpiry = $param;
            }
            return $qb;
        });
        self::assertNotNull($svc->resolve('tok'));
        self::assertNotNull($newExpiry);
        self::assertLessThanOrEqual(new \DateTime('+31 days'), $newExpiry);
        self::assertGreaterThan(new \DateTime('+29 days'), $newExpiry);
    }

    public function testSlidingRenewalCappedByAbsolute(): void
    {
        $row = $this->session(new \DateTime('+1 day'), new \DateTime('+2 days'));
        [$svc, $qb] = $this->svc($row);
        $newExpiry = null;
        $qb->method('set')->willReturnCallback(function (string $col, $param) use ($qb, &$newExpiry) {
            if ($col === 'expires_at') {
                $newExpiry = $param;
            }
            return $qb;
        });
        self::assertNotNull($svc->resolve('tok'));
        // Renewal target must be ≤ the +2d absolute cap, not +30d sliding.
        self::assertNotNull($newExpiry);
        self::assertLessThanOrEqual(new \DateTime('+2 days +5 seconds'), $newExpiry);
    }

    public function testVerifyCsrf(): void
    {
        $tokens = new TokenService();
        [, $hash] = $tokens->mint();
        $row = $this->session(null, null);
        $row->setCsrfHash($hash);
        [$svc] = $this->svc($row);
        self::assertFalse($svc->verifyCsrf($row, 'nope'));
        self::assertFalse($svc->verifyCsrf($row, ''));
        self::assertFalse($svc->verifyCsrf($row, null));
    }

    public function testKnownFingerprintRequiresBothIpAndUa(): void
    {
        // AND semantics: same IP + different UA must NOT count as known —
        // else the new-device mail is suppressed when it matters most.
        $sess = $this->session(null, null);
        $sess->setIpHash(hash('sha256', '10.0.0.1'));
        $sess->setUaHash(hash('sha256', 'Firefox'));
        $mapper = $this->createMock(PortalSessionMapper::class);
        $mapper->method('findLiveByIdentity')->willReturn([$sess]);
        $config = $this->createMock(GuestAuthConfig::class);
        $db = $this->createMock(IDBConnection::class);
        $svc = new PortalSessionService($mapper, new TokenService(), $config, $db);
        self::assertTrue($svc->isKnownFingerprint(1, '10.0.0.1', 'Firefox'));
        self::assertFalse($svc->isKnownFingerprint(1, '10.0.0.1', 'Chrome'), 'same IP, different UA');
        self::assertFalse($svc->isKnownFingerprint(1, '10.0.0.2', 'Firefox'), 'different IP, same UA');
    }

    public function testCreateProducesRowWithHashOnly(): void
    {
        $inserted = null;
        $mapper = $this->createMock(PortalSessionMapper::class);
        $mapper->method('insert')->willReturnCallback(function (PortalSession $s) use (&$inserted) {
            $inserted = $s;
            $s->setId(1);
            return $s;
        });
        $config = $this->createMock(GuestAuthConfig::class);
        $config->method('sessionDays')->willReturn(30);
        $svc = new PortalSessionService($mapper, new TokenService(), $config,
            $this->createMock(IDBConnection::class));
        [$token, $csrf, $row] = $svc->create(7, '10.0.0.1', 'UA/1.0');
        self::assertSame(64, strlen((string)$inserted->getTokenHash()));
        self::assertSame(64, strlen((string)$inserted->getCsrfHash()));
        self::assertNotSame($token, $inserted->getTokenHash()); // plaintext never stored
        self::assertNotSame($csrf, $inserted->getCsrfHash());
        self::assertNotNull($inserted->getAbsExpiresAt());
    }
}

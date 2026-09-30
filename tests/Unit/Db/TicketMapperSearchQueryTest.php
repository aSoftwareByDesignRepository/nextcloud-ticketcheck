<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Db;

use OCA\Ticketcheck\Db\TicketMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class TicketMapperSearchQueryTest extends TestCase
{
    public function testNormalizeTicketSearchQueryTrimsHashAndDisplayPrefix(): void
    {
        $mapper = new TicketMapper($this->createMock(IDBConnection::class));
        $method = new ReflectionMethod(TicketMapper::class, 'normalizeTicketSearchQuery');
        $method->setAccessible(true);

        $this->assertSame('HD-001', $method->invoke($mapper, '#HD-001'));
        $this->assertSame('HD-001', $method->invoke($mapper, 'HD-001 — Printer offline'));
        $this->assertSame('AcMe', $method->invoke($mapper, '  AcMe  '));
        $this->assertSame('', $method->invoke($mapper, '   '));
        $this->assertSame('', $method->invoke($mapper, '#'));
    }
}

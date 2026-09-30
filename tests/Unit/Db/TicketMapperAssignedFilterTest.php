<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Db;

use OCA\Ticketcheck\Db\TicketMapper;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for assignee filter sentinel (no database required).
 */
class TicketMapperAssignedFilterTest extends TestCase
{
    public function testUnassignedSentinelIsStable(): void
    {
        $this->assertSame('unassigned', TicketMapper::ASSIGNED_TO_UNASSIGNED);
    }
}

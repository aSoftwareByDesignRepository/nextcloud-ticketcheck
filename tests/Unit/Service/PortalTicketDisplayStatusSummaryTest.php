<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Service\PortalTicketDisplay;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the canonical portal status summary.
 *
 * The sidebar "Open" counter and the dashboard KPI cards must read the same
 * normalized semantics — legacy raw statuses count in their canonical bucket.
 */
class PortalTicketDisplayStatusSummaryTest extends TestCase
{
    public function testEmptyInput(): void
    {
        $this->assertSame(
            ['total' => 0, 'open' => 0, 'new' => 0, 'in_progress' => 0, 'waiting' => 0, 'done' => 0],
            PortalTicketDisplay::statusSummary([])
        );
    }

    public function testCanonicalStatusesBucketCorrectly(): void
    {
        $summary = PortalTicketDisplay::statusSummary([
            Ticket::STATUS_NEW,
            Ticket::STATUS_NEW,
            Ticket::STATUS_IN_PROGRESS,
            Ticket::STATUS_WAITING,
            Ticket::STATUS_DONE,
        ]);

        $this->assertSame(5, $summary['total']);
        $this->assertSame(2, $summary['new']);
        $this->assertSame(1, $summary['in_progress']);
        $this->assertSame(1, $summary['waiting']);
        $this->assertSame(1, $summary['done']);
        // open = everything not done (sidebar semantic).
        $this->assertSame(4, $summary['open']);
    }

    public function testLegacyStatusesFoldIntoCanonicalBuckets(): void
    {
        $summary = PortalTicketDisplay::statusSummary([
            'open',             // legacy -> new
            'working',          // legacy -> in_progress
            'inprogress',       // alias  -> in_progress
            'waiting_customer', // legacy -> waiting
            'resolved',         // legacy -> done
            'closed',           // legacy -> done
        ]);

        $this->assertSame(6, $summary['total']);
        $this->assertSame(1, $summary['new']);
        $this->assertSame(2, $summary['in_progress']);
        $this->assertSame(1, $summary['waiting']);
        $this->assertSame(2, $summary['done']);
        $this->assertSame(4, $summary['open']);
    }

    public function testUnknownStatusCountsOnlyTowardTotalAndOpen(): void
    {
        $summary = PortalTicketDisplay::statusSummary(['bogus_status', null]);

        $this->assertSame(2, $summary['total']);
        $this->assertSame(2, $summary['open']);
        $this->assertSame(0, $summary['new']);
        $this->assertSame(0, $summary['in_progress']);
        $this->assertSame(0, $summary['waiting']);
        $this->assertSame(0, $summary['done']);
    }

    public function testAllDoneLeavesOpenAtZero(): void
    {
        $summary = PortalTicketDisplay::statusSummary([
            Ticket::STATUS_DONE,
            'resolved',
        ]);

        $this->assertSame(2, $summary['total']);
        $this->assertSame(2, $summary['done']);
        $this->assertSame(0, $summary['open']);
    }
}

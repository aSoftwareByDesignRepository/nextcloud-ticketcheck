<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Db;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use PHPUnit\Framework\TestCase;

/**
 * Resolution-time metric rules (no database required).
 */
class TicketMapperResolutionMetricTest extends TestCase
{
    public function testComputeResolutionSecondsForCompletedTicket(): void
    {
        $ticket = new Ticket();
        $ticket->setStatus(Ticket::STATUS_DONE);
        $ticket->setCreatedAt(new \DateTime('2026-01-01 10:00:00'));
        $ticket->setClosedAt(new \DateTime('2026-01-03 10:00:00'));

        $this->assertSame(172800, TicketMapper::computeResolutionSeconds($ticket));
    }

    public function testComputeResolutionSecondsRequiresDoneStatus(): void
    {
        $ticket = new Ticket();
        $ticket->setStatus(Ticket::STATUS_IN_PROGRESS);
        $ticket->setCreatedAt(new \DateTime('2026-01-01 10:00:00'));
        $ticket->setClosedAt(new \DateTime('2026-01-03 10:00:00'));

        $this->assertNull(TicketMapper::computeResolutionSeconds($ticket));
    }

    public function testComputeResolutionSecondsRequiresCloseDate(): void
    {
        $ticket = new Ticket();
        $ticket->setStatus(Ticket::STATUS_DONE);
        $ticket->setCreatedAt(new \DateTime('2026-01-01 10:00:00'));

        $this->assertNull(TicketMapper::computeResolutionSeconds($ticket));
    }

    public function testComputeResolutionSecondsRejectsNonPositiveDuration(): void
    {
        $ticket = new Ticket();
        $ticket->setStatus(Ticket::STATUS_DONE);
        $ticket->setCreatedAt(new \DateTime('2026-01-03 10:00:00'));
        $ticket->setClosedAt(new \DateTime('2026-01-01 10:00:00'));

        $this->assertNull(TicketMapper::computeResolutionSeconds($ticket));
    }

    public function testComputeResolutionSecondsAcceptsLegacyResolvedStatus(): void
    {
        $ticket = new Ticket();
        $ticket->setStatus('resolved');
        $ticket->setCreatedAt(new \DateTime('2026-01-01 10:00:00'));
        $ticket->setClosedAt(new \DateTime('2026-01-02 10:00:00'));

        $this->assertSame(86400, TicketMapper::computeResolutionSeconds($ticket));
    }
}

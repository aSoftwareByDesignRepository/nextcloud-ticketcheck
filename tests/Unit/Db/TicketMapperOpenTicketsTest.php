<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Db;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for open/done ticket classification (no database required).
 */
class TicketMapperOpenTicketsTest extends TestCase
{
    public function testDoneStatusesConstant(): void
    {
        $this->assertSame(['done', 'resolved', 'closed'], TicketMapper::DONE_STATUSES);
    }

    /**
     * @dataProvider doneStatusProvider
     */
    public function testIsDoneStatusRecognizesClosedAliases(string $status): void
    {
        $this->assertTrue(TicketMapper::isDoneStatus($status));
    }

    /**
     * @return list<array{string}>
     */
    public static function doneStatusProvider(): array
    {
        return [
            ['done'],
            ['resolved'],
            ['closed'],
            ['DONE'],
            ['Resolved'],
        ];
    }

    /**
     * @dataProvider openStatusProvider
     */
    public function testIsDoneStatusLeavesLegacyOpenStatusesOpen(string $status): void
    {
        $this->assertFalse(TicketMapper::isDoneStatus($status));
    }

    /**
     * @return list<array{string}>
     */
    public static function openStatusProvider(): array
    {
        return [
            ['new'],
            ['open'],
            ['in_progress'],
            ['waiting'],
            ['waiting_customer'],
            ['working'],
        ];
    }

    /**
     * @dataProvider doneStatusProvider
     */
    public function testTicketIsOpenTreatsLegacyDoneAliasesAsClosed(string $status): void
    {
        $ticket = new Ticket();
        $ticket->setStatus($status);
        $this->assertFalse($ticket->isOpen(), "status {$status} must count as done");
    }

    /**
     * @dataProvider openStatusProvider
     */
    public function testTicketIsOpenKeepsOpenStatuses(string $status): void
    {
        $ticket = new Ticket();
        $ticket->setStatus($status);
        $this->assertTrue($ticket->isOpen(), "status {$status} must stay open");
    }

    public function testTicketEntityDoesNotDeclareRemovedFirstResponseAt(): void
    {
        $properties = array_map(
            static fn(\ReflectionProperty $p) => $p->getName(),
            (new \ReflectionClass(Ticket::class))->getProperties(\ReflectionProperty::IS_PROTECTED),
        );

        $this->assertNotContains('firstResponseAt', $properties);
    }
}

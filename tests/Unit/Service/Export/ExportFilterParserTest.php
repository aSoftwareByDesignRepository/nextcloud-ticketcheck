<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service\Export;

use OCA\Ticketcheck\Service\Export\ExportFilterParser;
use PHPUnit\Framework\TestCase;

class ExportFilterParserTest extends TestCase
{
    private ExportFilterParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ExportFilterParser();
    }

    public function testParseTicketFiltersDefaultsToAllScope(): void
    {
        $filters = $this->parser->parseTicketFilters([]);
        self::assertSame('all', $filters['scope']);
        self::assertFalse($filters['include_merged']);
        self::assertSame([], $filters['ticket_ids']);
    }

    public function testParseTicketFiltersRejectsInvalidEnums(): void
    {
        $filters = $this->parser->parseTicketFilters([
            'scope' => 'filtered',
            'status' => 'not-a-status',
            'priority' => 'urgent;drop table',
            'category' => 'general',
        ]);
        self::assertNull($filters['status']);
        self::assertNull($filters['priority']);
        self::assertSame('general', $filters['category']);
    }

    public function testParseTicketFiltersSelectedScopeUsesTicketIdsOnly(): void
    {
        $filters = $this->parser->parseTicketFilters([
            'scope' => 'selected',
            'ticket_ids' => '12, 0, abc, 45',
            'project_ids' => [99],
        ]);
        self::assertSame('selected', $filters['scope']);
        self::assertSame([12, 45], $filters['ticket_ids']);
        self::assertSame([], $filters['project_ids']);
    }

    public function testParseTicketFiltersCapsSelectedIds(): void
    {
        $ids = range(1, ExportFilterParser::MAX_SELECTED_IDS + 50);
        $filters = $this->parser->parseTicketFilters([
            'scope' => 'selected',
            'ticket_ids' => $ids,
        ]);
        self::assertCount(ExportFilterParser::MAX_SELECTED_IDS, $filters['ticket_ids']);
    }

    public function testParseTicketFiltersAcceptsSingularProjectAndCustomerId(): void
    {
        $filters = $this->parser->parseTicketFilters([
            'scope' => 'filtered',
            'project_id' => '12',
            'customer_id' => '3',
        ]);
        self::assertSame([12], $filters['project_ids']);
        self::assertSame([3], $filters['customer_ids']);
    }

    public function testParseProjectFiltersSelectedScope(): void
    {
        $filters = $this->parser->parseProjectFilters([
            'scope' => 'selected',
            'project_ids' => ['3', '7'],
            'customer_id' => '5',
        ]);
        self::assertSame([3, 7], $filters['project_ids']);
        self::assertNull($filters['customer_id']);
    }

    public function testParseColumnsAllowlistOnly(): void
    {
        $allowed = ['id', 'title', 'status'];
        self::assertSame(['title', 'status'], $this->parser->parseColumns(['title', 'status', 'evil'], $allowed));
        self::assertSame($allowed, $this->parser->parseColumns([], $allowed));
        self::assertSame($allowed, $this->parser->parseColumns(['evil'], $allowed));
    }

    public function testParseAssignedToRejectsUnsafeCharacters(): void
    {
        $filters = $this->parser->parseTicketFilters([
            'assigned_to' => "../admin\r\n",
        ]);
        self::assertNull($filters['assigned_to']);
    }

    public function testExcludeDoneSetsStatusNotWhenNoExplicitStatus(): void
    {
        $filters = $this->parser->parseTicketFilters([
            'scope' => 'filtered',
            'exclude_done' => '1',
        ]);
        self::assertSame('done', $filters['status_not']);
        self::assertNull($filters['status']);
    }
}

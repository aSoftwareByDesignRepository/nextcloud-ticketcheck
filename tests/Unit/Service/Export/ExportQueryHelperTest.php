<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service\Export;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Service\Export\ExportQueryHelper;
use PHPUnit\Framework\TestCase;

class ExportQueryHelperTest extends TestCase
{
    private ExportQueryHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = new ExportQueryHelper();
    }

    public function testTicketListFiltersToExportParamsMapsCommonFilters(): void
    {
        $params = $this->helper->ticketListFiltersToExportParams([
            'status' => 'in_progress',
            'priority' => 'high',
            'category' => 'technical',
            'assigned_to' => 'agent1',
            'project_id' => '12',
            'customer_id' => '3',
            'search' => 'printer',
            'hide_done' => '1',
        ]);

        self::assertSame('filtered', $params['scope']);
        self::assertSame('tickets', $params['entity']);
        self::assertSame(Ticket::STATUS_IN_PROGRESS, $params['status']);
        self::assertSame('high', $params['priority']);
        self::assertSame('agent1', $params['assigned_to']);
        self::assertSame([12], $params['project_ids']);
        self::assertSame([3], $params['customer_ids']);
        self::assertArrayNotHasKey('exclude_done', $params);
    }

    public function testTicketListFiltersAddsExcludeDoneWhenListHidesDone(): void
    {
        $params = $this->helper->ticketListFiltersToExportParams([
            'search' => 'printer',
            'hide_done' => '1',
        ]);

        self::assertSame('1', $params['exclude_done']);
    }

    public function testTicketListFiltersSkipsExcludeDoneWhenStatusIsExplicit(): void
    {
        $params = $this->helper->ticketListFiltersToExportParams([
            'status' => 'done',
            'hide_done' => '1',
        ]);

        self::assertSame('done', $params['status']);
        self::assertArrayNotHasKey('exclude_done', $params);
    }

    public function testBuildExportIndexQueryEncodesArrays(): void
    {
        $query = $this->helper->buildExportIndexQuery([
            'scope' => 'filtered',
            'project_ids' => [1, 2],
        ]);

        self::assertStringContainsString('scope=filtered', $query);
        self::assertStringContainsString('project_ids%5B%5D=1', $query);
        self::assertStringContainsString('project_ids%5B%5D=2', $query);
    }
}

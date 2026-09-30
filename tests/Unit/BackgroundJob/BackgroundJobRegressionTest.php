<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\BackgroundJob;

use PHPUnit\Framework\TestCase;

/**
 * Guards against regressions that broke production cron (removed entity fields, narrow status lists).
 */
class BackgroundJobRegressionTest extends TestCase
{
    public function testSlaMonitorJobDoesNotReferenceRemovedFirstResponseAt(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/lib/BackgroundJob/SLAMonitorJob.php',
        );
        $this->assertIsString($source);
        $this->assertStringNotContainsString('getFirstResponseAt', $source);
        $this->assertStringNotContainsString('firstResponseAt', $source);
        $this->assertStringNotContainsString('findByStatus', $source);
        $this->assertStringContainsString('findOpenForSlaMonitoring', $source);
    }

    public function testTicketEntityHasSlaAlertTimestampFields(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/lib/Db/Ticket.php',
        );
        $this->assertIsString($source);
        $this->assertStringContainsString('slaResponseAlertedAt', $source);
        $this->assertStringContainsString('slaResolutionAlertedAt', $source);
    }

    public function testSlaAlertColumnsMigrationExists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 3) . '/lib/Migration/Version4206Date20260604120000.php',
        );
    }

    public function testNotificationJobUsesFindOpenTicketsNotStatusWhitelist(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/lib/BackgroundJob/NotificationJob.php',
        );
        $this->assertIsString($source);
        $this->assertStringContainsString('findOpenTickets', $source);
        $this->assertStringNotContainsString("findByStatus(['new', 'open', 'in_progress'])", $source);
    }
}

<?php

declare(strict_types=1);

/**
 * Migration: track when SLA breach alerts were last sent
 *
 * Adds two nullable datetime columns to helpdesk_tickets so the SLA monitor
 * can de-duplicate breach notifications (one alert per cooldown window instead
 * of an email on every hourly run).
 *
 * - sla_response_alerted_at: last time a response-SLA breach alert was sent
 * - sla_resolution_alerted_at: last time a resolution-SLA breach alert was sent
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version4206Date20260604120000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('helpdesk_tickets')) {
            return null;
        }

        $ticketsTable = $schema->getTable('helpdesk_tickets');
        $changed = false;

        if (!$ticketsTable->hasColumn('sla_response_alerted_at')) {
            $ticketsTable->addColumn('sla_response_alerted_at', Types::DATETIME, [
                'notnull' => false,
                'default' => null,
            ]);
            $output->info('Added sla_response_alerted_at column to helpdesk_tickets');
            $changed = true;
        }

        if (!$ticketsTable->hasColumn('sla_resolution_alerted_at')) {
            $ticketsTable->addColumn('sla_resolution_alerted_at', Types::DATETIME, [
                'notnull' => false,
                'default' => null,
            ]);
            $output->info('Added sla_resolution_alerted_at column to helpdesk_tickets');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}

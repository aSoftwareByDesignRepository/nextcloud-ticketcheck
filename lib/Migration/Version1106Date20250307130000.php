<?php

declare(strict_types=1);

/**
 * Migration: add merged_into_id, surveys and escalation_rules tables
 *
 * - Add merged_into_id (nullable int) to helpdesk_tickets
 * - Create helpdesk_ticket_surveys: ticket_id, rating, comment, surveyed_at
 * - Create helpdesk_escalation_rules: id, name, conditions (json), action (json), is_active, created_at
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1106Date20250307130000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Add merged_into_id to helpdesk_tickets
        if ($schema->hasTable('helpdesk_tickets')) {
            $ticketsTable = $schema->getTable('helpdesk_tickets');
            if (!$ticketsTable->hasColumn('merged_into_id')) {
                $ticketsTable->addColumn('merged_into_id', Types::BIGINT, [
                    'notnull' => false,
                    'default' => null,
                ]);
                $ticketsTable->addIndex(['merged_into_id'], 'hd_ticket_merged_idx');
                $output->info('Added merged_into_id column to helpdesk_tickets');
            }
        }

        // Create helpdesk_ticket_surveys table
        if (!$schema->hasTable('helpdesk_ticket_surveys')) {
            $table = $schema->createTable('helpdesk_ticket_surveys');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('ticket_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('rating', Types::INTEGER, [
                'notnull' => true,
                'length' => 1,
                'comment' => '1-5 star rating',
            ]);
            $table->addColumn('comment', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('surveyed_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->setPrimaryKey(['id'], 'hd_survey_pk');
            $table->addIndex(['ticket_id'], 'hd_survey_ticket_idx');
            $table->addIndex(['surveyed_at'], 'hd_survey_at_idx');
            $output->info('Created helpdesk_ticket_surveys table');
        }

        // Create helpdesk_escalation_rules table
        if (!$schema->hasTable('helpdesk_escalation_rules')) {
            $table = $schema->createTable('helpdesk_escalation_rules');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('conditions', Types::JSON, [
                'notnull' => false,
                'comment' => 'age_hours, min_priority, statuses',
            ]);
            $table->addColumn('action', Types::JSON, [
                'notnull' => false,
                'comment' => 'set_priority, assign_to',
            ]);
            $table->addColumn('is_active', Types::INTEGER, [
                'notnull' => true,
                'default' => 1,
                'length' => 1,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->setPrimaryKey(['id'], 'hd_escalation_pk');
            $table->addIndex(['is_active'], 'hd_escalation_active_idx');
            $output->info('Created helpdesk_escalation_rules table');
        }

        return $schema;
    }
}

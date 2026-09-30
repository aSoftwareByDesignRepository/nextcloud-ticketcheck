<?php

declare(strict_types=1);

/**
 * Migration: add ticket links and watchers tables
 *
 * - helpdesk_ticket_links: ticket_id, linked_ticket_id, link_type (related|blocks|blocked_by)
 * - helpdesk_ticket_watchers: ticket_id, user_id, created_at
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

class Version1107Date20250307140000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Create helpdesk_ticket_links table
        if (!$schema->hasTable('helpdesk_ticket_links')) {
            $table = $schema->createTable('helpdesk_ticket_links');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('ticket_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('linked_ticket_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('link_type', Types::STRING, [
                'notnull' => true,
                'length' => 32,
                'default' => 'related',
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->setPrimaryKey(['id'], 'hd_link_pk');
            $table->addIndex(['ticket_id'], 'hd_link_ticket_idx');
            $table->addIndex(['linked_ticket_id'], 'hd_link_linked_idx');
            $table->addUniqueIndex(['ticket_id', 'linked_ticket_id', 'link_type'], 'hd_link_unique');
            $output->info('Created helpdesk_ticket_links table');
        }

        // Create helpdesk_ticket_watchers table
        if (!$schema->hasTable('helpdesk_ticket_watchers')) {
            $table = $schema->createTable('helpdesk_ticket_watchers');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('ticket_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->setPrimaryKey(['id'], 'hd_watcher_pk');
            $table->addUniqueIndex(['ticket_id', 'user_id'], 'hd_watcher_unique');
            $table->addIndex(['ticket_id'], 'hd_watcher_ticket_idx');
            $table->addIndex(['user_id'], 'hd_watcher_user_idx');
            $output->info('Created helpdesk_ticket_watchers table');
        }

        return $schema;
    }
}

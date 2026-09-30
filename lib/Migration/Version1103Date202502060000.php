<?php

declare(strict_types=1);

/**
 * Migration to align helpdesk_guest_access schema with runtime code.
 *
 * Historically the table used a column called guest_user_id, while
 * all runtime code (mappers, services, controllers) consistently
 * reads/writes a column called user_id. This migration:
 *
 * - Ensures a user_id column exists on helpdesk_guest_access
 * - Copies values from guest_user_id into user_id for existing rows
 * - Leaves guest_user_id in place for backward compatibility (safe no-op)
 *
 * After this migration:
 * - All inserts/selects using user_id will work correctly
 * - Existing installations that previously wrote guest_user_id
 *   (e.g. from early migrations) will still work because data is copied
 *
 * This is intentionally additive and non-destructive.
 *
 * @copyright Copyright (c) 2025
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1103Date202502060000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('helpdesk_guest_access')) {
            // Table doesn't exist yet; nothing to do here.
            return $schema;
        }

        $table = $schema->getTable('helpdesk_guest_access');

        // If a user_id column does not exist yet, create it.
        // We keep guest_user_id (if present) for backward compatibility.
        try {
            if (!$table->hasColumn('user_id')) {
                $output->info('Adding user_id column to helpdesk_guest_access');

                $table->addColumn('user_id', Types::STRING, [
                    'notnull' => false,
                    'length' => 64,
                    'default' => null,
                ]);

                // Normalise indexes: drop old guest_user_id-based indexes and create user_id-based ones.
                // MigrationValidationService expects hd_gpa_guest_idx and hd_gpa_project_idx.
                foreach (['hd_ga_guest_idx', 'hd_ga_unique_idx', 'hd_ga_project_idx', 'hd_gpa_guest_idx'] as $idx) {
                    if ($table->hasIndex($idx)) {
                        $table->dropIndex($idx);
                    }
                }

                $table->addIndex(['user_id'], 'hd_gpa_guest_idx');
                if (!$table->hasIndex('hd_gpa_project_idx')) {
                    $table->addIndex(['project_id'], 'hd_gpa_project_idx');
                }
                $table->addUniqueIndex(['user_id', 'project_id'], 'hd_gpa_unique_idx');
            }
        } catch (\Throwable $e) {
            $output->warning('Failed to adjust helpdesk_guest_access schema: ' . $e->getMessage());
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        try {
            $schema = $schemaClosure();
            if (!$schema->hasTable('helpdesk_guest_access')) {
                return;
            }
            $table = $schema->getTable('helpdesk_guest_access');
            // Only sync when legacy column exists (1000/1081/1082/1083); avoid false negatives from arbitrary query errors.
            if (!$table->hasColumn('guest_user_id')) {
                return;
            }

            $connection = \OC::$server->getDatabaseConnection();

            // Copy values from guest_user_id into user_id for any rows where user_id is NULL or empty.
            // *PREFIX* is expanded by the connection for every supported backend (MySQL, PostgreSQL, SQLite, Oracle).
            $sql = 'UPDATE *PREFIX*helpdesk_guest_access '
                . 'SET user_id = guest_user_id '
                . "WHERE (user_id IS NULL OR user_id = '') "
                . 'AND guest_user_id IS NOT NULL';

            try {
                $affected = $connection->executeStatement($sql);
                $output->info("Synchronized {$affected} helpdesk_guest_access rows from guest_user_id to user_id");
            } catch (\Throwable $e) {
                // non-fatal: auxiliary post-schema step — aborting the upgrade on a
                  // failing step (chmod/.htaccess/backfill) is worse than warning; the drift gate backstops schema state
                $output->warning('Failed to synchronize guest_user_id to user_id in helpdesk_guest_access: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            // non-fatal: auxiliary post-schema step — aborting the upgrade on a
              // failing step (chmod/.htaccess/backfill) is worse than warning; the drift gate backstops schema state
            $output->warning('Error in postSchemaChange for helpdesk_guest_access user_id sync: ' . $e->getMessage());
        }
    }
}


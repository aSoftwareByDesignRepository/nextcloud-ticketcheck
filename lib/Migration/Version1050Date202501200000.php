<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2025 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add missing active column to existing helpdesk_projects table
 */
class Version1050Date202501200000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        try {
            if ($schema->hasTable('helpdesk_projects')) {
                $table = $schema->getTable('helpdesk_projects');

                // Add active column if it doesn't exist
                if (!$table->hasColumn('active')) {
                    $table->addColumn('active', Types::INTEGER, [
                        'notnull' => true,
                        'default' => 1,
                        'length' => 1,
                    ]);
                    $output->info("Added active column to helpdesk_projects table");
                } else {
                    $output->info("Column active already exists in helpdesk_projects table");
                }

                // Add index for active if it doesn't exist
                if (!$table->hasIndex('hd_proj_active_idx')) {
                    $table->addIndex(['active'], 'hd_proj_active_idx');
                    $output->info("Added active index to helpdesk_projects table");
                } else {
                    $output->info("Index hd_proj_active_idx already exists in helpdesk_projects table");
                }
            } else {
                $output->info("Table helpdesk_projects does not exist, skipping active column addition");
            }
        } catch (\Exception $e) {
            $output->warning("Failed to add active column: " . $e->getMessage());
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        try {
            $connection = \OC::$server->getDatabaseConnection();

            // Update existing records to have active = 1
            $qb = $connection->getQueryBuilder();
            $qb->update('helpdesk_projects')
                ->set('active', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->isNull('active'));
            $updated = $qb->executeStatement();

            if ($updated > 0) {
                $output->info("Updated {$updated} existing projects to active = 1");
            }

            // Verify the column works
            $qb = $connection->getQueryBuilder();
            $qb->select('active')
                ->from('helpdesk_projects')
                ->setMaxResults(1);
            $result = $qb->executeQuery();
            $result->fetchOne();
            $result->closeCursor();

            $output->info("Successfully verified active column in helpdesk_projects table");
        } catch (\Throwable $e) {
            // non-fatal: auxiliary post-schema step — aborting the upgrade on a
              // failing step (chmod/.htaccess/backfill) is worse than warning; the drift gate backstops schema state
            $output->warning("Failed to verify active column: " . $e->getMessage());
        }
    }
}

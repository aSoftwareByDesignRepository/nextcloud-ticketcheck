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
 * Create missing hd_proj_members table
 */
class Version1070Date202501200000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        try {
            if (!$schema->hasTable('hd_proj_members')) {
                $table = $schema->createTable('hd_proj_members');
                $table->addColumn('id', 'bigint', [
                    'autoincrement' => true,
                    'notnull' => true,
                ]);
                $table->addColumn('project_id', 'bigint', [
                    'notnull' => true,
                ]);
                $table->addColumn('user_id', 'string', [
                    'notnull' => true,
                    'length' => 64,
                ]);
                $table->addColumn('role', 'string', [
                    'notnull' => true,
                    'length' => 50,
                    'default' => 'member',
                ]);
                $table->addColumn('created_at', 'datetime', [
                    'notnull' => true,
                ]);
                $table->addColumn('created_by', 'string', [
                    'notnull' => true,
                    'length' => 64,
                ]);

                $table->setPrimaryKey(['id']);
                $table->addIndex(['project_id'], 'hd_pm_project_idx');
                $table->addIndex(['user_id'], 'hd_pm_user_idx');
                $table->addIndex(['role'], 'hd_pm_role_idx');
                $table->addUniqueIndex(['project_id', 'user_id'], 'hd_pm_unique_idx');

                $output->info("Created missing hd_proj_members table");
            } else {
                $output->info("Table hd_proj_members already exists");
            }
        } catch (\Exception $e) {
            $output->warning("Failed to create hd_proj_members table: " . $e->getMessage());
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        try {
            $connection = \OC::$server->getDatabaseConnection();

            // Verify the table exists and is accessible
            $qb = $connection->getQueryBuilder();
            $qb->select('COUNT(*)')
                ->from('hd_proj_members')
                ->setMaxResults(1);
            $result = $qb->executeQuery();
            $result->fetchOne();
            $result->closeCursor();

            $output->info("Successfully verified hd_proj_members table");
        } catch (\Throwable $e) {
            $output->warning("Failed to verify hd_proj_members table: " . $e->getMessage());
        }
    }
}

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
 * Emergency fix for table name length issue
 * This migration ensures the correct table name is used
 */
class Version1082Date202501200000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        try {
            // Check if the old long table name exists and drop it
            if ($schema->hasTable('helpdesk_guest_project_access')) {
                $output->info("Found old long table name, will be handled in postSchemaChange");
            }

            // Ensure the correct short table name exists
            if (!$schema->hasTable('helpdesk_guest_access')) {
                $table = $schema->createTable('helpdesk_guest_access');
                $table->addColumn('id', Types::BIGINT, [
                    'autoincrement' => true,
                    'notnull' => true,
                ]);
                $table->addColumn('guest_user_id', Types::STRING, [
                    'notnull' => true,
                    'length' => 64,
                ]);
                $table->addColumn('project_id', Types::BIGINT, [
                    'notnull' => true,
                ]);
                $table->addColumn('customer_id', Types::BIGINT, [
                    'notnull' => false,
                ]);
                $table->addColumn('created_at', Types::DATETIME_IMMUTABLE, [
                    'notnull' => true,
                ]);
                $table->addColumn('created_by', Types::STRING, [
                    'notnull' => true,
                    'length' => 64,
                ]);

                $table->setPrimaryKey(['id']);
                $table->addIndex(['guest_user_id'], 'hd_ga_guest_idx');
                $table->addIndex(['project_id'], 'hd_ga_project_idx');
                $table->addIndex(['customer_id'], 'hd_ga_customer_idx');
                $table->addIndex(['created_at'], 'hd_ga_created_idx');
                $table->addIndex(['created_by'], 'hd_ga_creator_idx');
                $table->addUniqueIndex(['guest_user_id', 'project_id'], 'hd_ga_unique_idx');

                $output->info("Created helpdesk_guest_access table with correct short name");
            } else {
                $output->info("Table helpdesk_guest_access already exists");
            }
        } catch (\Exception $e) {
            $output->warning("Failed to create helpdesk_guest_access table: " . $e->getMessage());
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        try {
            $connection = \OC::$server->getDatabaseConnection();

            if ($connection->tableExists('helpdesk_guest_project_access')) {
                $output->info('Found legacy helpdesk_guest_project_access table; dropping it.');
                $connection->dropTable('helpdesk_guest_project_access');
                $output->info('Dropped legacy helpdesk_guest_project_access table.');
            }

            if (!$connection->tableExists('helpdesk_guest_access')) {
                $output->warning('helpdesk_guest_access table is missing after migration.');
                return;
            }

            $qb = $connection->getQueryBuilder();
            $qb->select($qb->func()->count('*'))
                ->from('helpdesk_guest_access')
                ->setMaxResults(1);
            $result = $qb->executeQuery();
            $result->fetchOne();
            $result->closeCursor();

            $output->info('Successfully verified helpdesk_guest_access table');
        } catch (\Throwable $e) {
            $output->warning('Failed to verify helpdesk_guest_access table: ' . $e->getMessage());
        }
    }
}

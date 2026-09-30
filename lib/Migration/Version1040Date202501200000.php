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
 * Add missing customer_id column to helpdesk_projects table
 */
class Version1040Date202501200000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        try {
            if ($schema->hasTable('helpdesk_projects')) {
                $table = $schema->getTable('helpdesk_projects');

                // Add customer_id column if it doesn't exist
                if (!$table->hasColumn('customer_id')) {
                    $table->addColumn('customer_id', Types::BIGINT, [
                        'notnull' => false,
                    ]);
                    $output->info("Added customer_id column to helpdesk_projects table");
                } else {
                    $output->info("Column customer_id already exists in helpdesk_projects table");
                }

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

                // Add index for customer_id if it doesn't exist
                if (!$table->hasIndex('hd_proj_customer_idx')) {
                    $table->addIndex(['customer_id'], 'hd_proj_customer_idx');
                    $output->info("Added customer_id index to helpdesk_projects table");
                } else {
                    $output->info("Index hd_proj_customer_idx already exists in helpdesk_projects table");
                }

                // Add index for active if it doesn't exist
                if (!$table->hasIndex('hd_proj_active_idx')) {
                    $table->addIndex(['active'], 'hd_proj_active_idx');
                    $output->info("Added active index to helpdesk_projects table");
                } else {
                    $output->info("Index hd_proj_active_idx already exists in helpdesk_projects table");
                }
            } else {
                $output->info("Table helpdesk_projects does not exist, skipping customer_id column addition");
            }

            // Add ticket templates table if missing
            if (!$schema->hasTable('helpdesk_ticket_templates')) {
                $table = $schema->createTable('helpdesk_ticket_templates');
                $table->addColumn('id', Types::BIGINT, [
                    'autoincrement' => true,
                    'notnull' => true,
                ]);
                $table->addColumn('name', Types::STRING, [
                    'notnull' => true,
                    'length' => 255,
                ]);
                $table->addColumn('content', Types::TEXT, [
                    'notnull' => true,
                ]);
                $table->addColumn('category', Types::STRING, [
                    'notnull' => true,
                    'length' => 100,
                    'default' => 'general',
                ]);
                $table->addColumn('is_active', Types::INTEGER, [
                    'notnull' => true,
                    'default' => 1,
                    'length' => 1,
                ]);
                $table->addColumn('created_by', Types::STRING, [
                    'notnull' => true,
                    'length' => 64,
                ]);
                $table->addColumn('created_at', Types::DATETIME, [
                    'notnull' => true,
                ]);
                $table->addColumn('updated_at', Types::DATETIME, [
                    'notnull' => true,
                ]);

                $table->setPrimaryKey(['id']);
                $table->addIndex(['name'], 'hd_tmpl_name_idx');
                $table->addIndex(['category'], 'hd_tmpl_category_idx');
                $table->addIndex(['is_active'], 'hd_tmpl_active_idx');
                $table->addIndex(['created_by'], 'hd_tmpl_creator_idx');
                $output->info("Created missing helpdesk_ticket_templates table");
            } else {
                $output->info("Table helpdesk_ticket_templates already exists");
            }

            // Add project members table if missing
            if (!$schema->hasTable('hd_proj_members')) {
                $table = $schema->createTable('hd_proj_members');
                $table->addColumn('id', Types::BIGINT, [
                    'autoincrement' => true,
                    'notnull' => true,
                ]);
                $table->addColumn('project_id', Types::BIGINT, [
                    'notnull' => true,
                ]);
                $table->addColumn('user_id', Types::STRING, [
                    'notnull' => true,
                    'length' => 64,
                ]);
                $table->addColumn('role', Types::STRING, [
                    'notnull' => true,
                    'length' => 50,
                    'default' => 'member',
                ]);
                $table->addColumn('added_at', Types::DATETIME, [
                    'notnull' => true,
                ]);
                $table->addColumn('added_by', Types::STRING, [
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
            $output->warning("Failed to add missing tables: " . $e->getMessage());
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        try {
            $connection = \OC::$server->getDatabaseConnection();

            // Verify the column was added successfully
            $qb = $connection->getQueryBuilder();
            $qb->select('customer_id')
                ->from('helpdesk_projects')
                ->setMaxResults(1);
            $result = $qb->executeQuery();
            $result->fetchOne();
            $result->closeCursor();

            $output->info("Successfully verified customer_id column in helpdesk_projects table");
        } catch (\Throwable $e) {
            $output->warning("Failed to verify customer_id column: " . $e->getMessage());
        }
    }
}

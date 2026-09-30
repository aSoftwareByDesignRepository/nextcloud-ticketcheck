<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Emergency migration to ensure all required tables exist
 * This is a safety net for production environments where initial migration might have failed
 */
class Version1030Date202501200000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Force create projects table if missing
        if (!$schema->hasTable('helpdesk_projects')) {
            $table = $schema->createTable('helpdesk_projects');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('name', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('description', 'text', [
                'notnull' => false,
            ]);
            $table->addColumn('customer_id', 'bigint', [
                'notnull' => false,
            ]);
            $table->addColumn('status', 'string', [
                'notnull' => true,
                'length' => 50,
                'default' => 'active',
            ]);
            $table->addColumn('active', 'integer', [
                'notnull' => true,
                'default' => 1,
                'length' => 1,
            ]);
            $table->addColumn('created_by', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id'], 'hd_proj_pk');
            $table->addIndex(['name'], 'hd_proj_name_idx');
            $table->addIndex(['status'], 'hd_proj_status_idx');
            $table->addIndex(['active'], 'hd_proj_active_idx');
            $table->addIndex(['created_by'], 'hd_proj_creator_idx');
            $table->addIndex(['customer_id'], 'hd_proj_customer_idx');
            $output->info("Created missing helpdesk_projects table");
        }

        // Force create customers table if missing
        if (!$schema->hasTable('helpdesk_customers')) {
            $table = $schema->createTable('helpdesk_customers');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('name', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('email', 'string', [
                'notnull' => false,
                'length' => 254,
            ]);
            $table->addColumn('company', 'string', [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('phone', 'string', [
                'notnull' => false,
                'length' => 50,
            ]);
            $table->addColumn('notes', 'text', [
                'notnull' => false,
            ]);
            $table->addColumn('created_by', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id'], 'hd_cust_pk');
            $table->addUniqueIndex(['email'], 'hd_cust_email_idx');
            $table->addIndex(['name'], 'hd_cust_name_idx');
            $table->addIndex(['company'], 'hd_cust_company_idx');
            $output->info("Created missing helpdesk_customers table");
        }

        // Force create tickets table if missing
        if (!$schema->hasTable('helpdesk_tickets')) {
            $table = $schema->createTable('helpdesk_tickets');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('ticket_number', 'string', [
                'notnull' => true,
                'length' => 50,
            ]);
            $table->addColumn('title', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('description', 'text', [
                'notnull' => true,
            ]);
            $table->addColumn('customer_id', 'bigint', [
                'notnull' => false,
            ]);
            $table->addColumn('customer_email', 'string', [
                'notnull' => true,
                'length' => 254,
            ]);
            $table->addColumn('customer_name', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('project_id', 'bigint', [
                'notnull' => false,
            ]);
            $table->addColumn('category', 'string', [
                'notnull' => true,
                'length' => 50,
                'default' => 'general',
            ]);
            $table->addColumn('priority', 'string', [
                'notnull' => true,
                'length' => 20,
                'default' => 'normal',
            ]);
            $table->addColumn('status', 'string', [
                'notnull' => true,
                'length' => 50,
                'default' => 'new',
            ]);
            $table->addColumn('assigned_to', 'string', [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('sla_response_due', 'datetime', [
                'notnull' => false,
            ]);
            $table->addColumn('sla_resolution_due', 'datetime', [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => true,
            ]);
            $table->addColumn('closed_at', 'datetime', [
                'notnull' => false,
            ]);
            $table->addColumn('created_by', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('created_by_guest', 'boolean', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id'], 'hd_ticket_pk');
            $table->addUniqueIndex(['ticket_number'], 'hd_ticket_num_idx');
            $table->addIndex(['customer_id'], 'hd_customer_idx');
            $table->addIndex(['customer_email'], 'hd_ticket_cust_email_idx');
            $table->addIndex(['project_id'], 'hd_project_idx');
            $table->addIndex(['status'], 'hd_status_idx');
            $table->addIndex(['priority'], 'hd_priority_idx');
            $table->addIndex(['category'], 'hd_category_idx');
            $table->addIndex(['assigned_to'], 'hd_assigned_idx');
            $table->addIndex(['created_at'], 'hd_created_idx');
            $table->addIndex(['created_by'], 'hd_creator_idx');
            $output->info("Created missing helpdesk_tickets table");
        }

        // Force create ticket templates table if missing
        if (!$schema->hasTable('helpdesk_ticket_templates')) {
            $table = $schema->createTable('helpdesk_ticket_templates');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('name', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('content', 'text', [
                'notnull' => true,
            ]);
            $table->addColumn('category', 'string', [
                'notnull' => true,
                'length' => 100,
                'default' => 'general',
            ]);
            $table->addColumn('is_active', 'integer', [
                'notnull' => true,
                'default' => 1,
                'length' => 1,
            ]);
            $table->addColumn('created_by', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id'], 'hd_tmpl_pk');
            $table->addIndex(['name'], 'hd_tmpl_name_idx');
            $table->addIndex(['category'], 'hd_tmpl_category_idx');
            $table->addIndex(['is_active'], 'hd_tmpl_active_idx');
            $table->addIndex(['created_by'], 'hd_tmpl_creator_idx');
            $output->info("Created missing helpdesk_ticket_templates table");
        }

        // Force create project members table if missing
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
            $table->addColumn('added_at', 'datetime', [
                'notnull' => true,
            ]);
            $table->addColumn('added_by', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);

            $table->setPrimaryKey(['id'], 'hd_pm_pk');
            $table->addIndex(['project_id'], 'hd_pm_project_idx');
            $table->addIndex(['user_id'], 'hd_pm_user_idx');
            $table->addIndex(['role'], 'hd_pm_role_idx');
            $table->addUniqueIndex(['project_id', 'user_id'], 'hd_pm_unique_idx');
            $output->info("Created missing hd_proj_members table");
        }

        return $schema;
    }

    /**
     * Post-schema change: Verify tables exist and are accessible
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        try {
            $connection = \OC::$server->getDatabaseConnection();

            // Test that we can query the critical tables
            $criticalTables = ['helpdesk_projects', 'helpdesk_customers', 'helpdesk_tickets', 'helpdesk_ticket_templates', 'hd_proj_members'];

            foreach ($criticalTables as $table) {
                $qb = $connection->getQueryBuilder();
                $qb->select('COUNT(*)')
                    ->from($table)
                    ->setMaxResults(1);
                $result = $qb->executeQuery();
                $count = $result->fetchOne();
                $result->closeCursor();
                $output->info("Table {$table} verified (contains {$count} records)");
            }
        } catch (\Throwable $e) {
            $output->warning("Table verification failed: " . $e->getMessage());
        }
    }
}

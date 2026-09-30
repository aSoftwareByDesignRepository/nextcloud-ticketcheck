<?php

declare(strict_types=1);

/**
 * Initial migration for the helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

/**
 * Initial migration for helpdesk
 */
class Version1000Date202501010000 extends SimpleMigrationStep
{

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Tickets table
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

            $table->setPrimaryKey(['id']);
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
        }

        // Comments table
        if (!$schema->hasTable('helpdesk_comments')) {
            $table = $schema->createTable('helpdesk_comments');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('ticket_id', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('author_name', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('author_email', 'string', [
                'notnull' => false,
                'length' => 254,
            ]);
            $table->addColumn('content', 'text', [
                'notnull' => true,
            ]);
            $table->addColumn('is_internal', 'boolean', [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['ticket_id'], 'hd_cmt_ticket_idx');
            $table->addIndex(['user_id'], 'hd_cmt_user_idx');
            $table->addIndex(['created_at'], 'hd_cmt_created_idx');
        }

        // Attachments table
        if (!$schema->hasTable('helpdesk_attachments')) {
            $table = $schema->createTable('helpdesk_attachments');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('ticket_id', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('comment_id', 'bigint', [
                'notnull' => false,
            ]);
            $table->addColumn('file_path', 'string', [
                'notnull' => true,
                'length' => 512,
            ]);
            $table->addColumn('file_name', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('file_size', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('mime_type', 'string', [
                'notnull' => true,
                'length' => 127,
            ]);
            $table->addColumn('uploaded_by', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('uploaded_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['ticket_id'], 'hd_att_ticket_idx');
            $table->addIndex(['comment_id'], 'hd_att_comment_idx');
        }

        // Knowledge Base Articles table
        if (!$schema->hasTable('helpdesk_kb_articles')) {
            $table = $schema->createTable('helpdesk_kb_articles');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('title', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('content', 'text', [
                'notnull' => true,
            ]);
            $table->addColumn('category', 'string', [
                'notnull' => true,
                'length' => 100,
            ]);
            $table->addColumn('views', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('helpful_count', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('published', 'boolean', [
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

            $table->setPrimaryKey(['id']);
            $table->addIndex(['category'], 'hd_kb_category_idx');
            $table->addIndex(['published'], 'hd_kb_published_idx');
            $table->addIndex(['created_at'], 'hd_kb_created_idx');
        }

        // Email Whitelist table
        if (!$schema->hasTable('helpdesk_whitelist')) {
            $table = $schema->createTable('helpdesk_whitelist');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('email_pattern', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('customer_id', 'bigint', [
                'notnull' => false,
            ]);
            $table->addColumn('project_id', 'bigint', [
                'notnull' => false,
            ]);
            $table->addColumn('default_category', 'string', [
                'notnull' => false,
                'length' => 50,
            ]);
            $table->addColumn('default_priority', 'string', [
                'notnull' => false,
                'length' => 20,
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['email_pattern'], 'hd_wl_pattern_idx');
            $table->addIndex(['customer_id'], 'hd_wl_customer_idx');
            $table->addIndex(['project_id'], 'hd_wl_project_idx');
        }

        // Assignment Rules table
        if (!$schema->hasTable('helpdesk_assignments')) {
            $table = $schema->createTable('helpdesk_assignments');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('project_id', 'bigint', [
                'notnull' => false,
            ]);
            $table->addColumn('category', 'string', [
                'notnull' => false,
                'length' => 50,
            ]);
            $table->addColumn('assigned_user_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('is_default', 'boolean', [
                'notnull' => false,
            ]);
            $table->addColumn('priority_order', 'integer', [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['project_id'], 'hd_asn_project_idx');
            $table->addIndex(['category'], 'hd_asn_category_idx');
            $table->addIndex(['assigned_user_id'], 'hd_asn_user_idx');
        }

        // Projects table (standalone projects)
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
            $table->addColumn('status', 'string', [
                'notnull' => true,
                'length' => 50,
                'default' => 'active',
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

            $table->setPrimaryKey(['id']);
            $table->addIndex(['name'], 'hd_proj_name_idx');
            $table->addIndex(['status'], 'hd_proj_status_idx');
            $table->addIndex(['created_by'], 'hd_proj_creator_idx');
        }

        // Customers table (standalone customers)
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

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['email'], 'hd_cust_email_idx');
            $table->addIndex(['name'], 'hd_cust_name_idx');
            $table->addIndex(['company'], 'hd_cust_company_idx');
        }

        // Guest Project Access table (multi-tenant support)
        if (!$schema->hasTable('helpdesk_guest_access')) {
            $table = $schema->createTable('helpdesk_guest_access');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('guest_user_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('project_id', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['guest_user_id'], 'hd_gpa_guest_idx');
            $table->addIndex(['project_id'], 'hd_gpa_project_idx');
        }

        $output->info("Helpdesk migration completed - created all required tables");
        return $schema;
    }

    /**
     * Post-schema change: Ensure all tables are properly created and add some default data
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        try {
            $connection = \OC::$server->getDatabaseConnection();

            // Verify that all required tables exist
            $requiredTables = [
                'helpdesk_tickets',
                'helpdesk_projects',
                'helpdesk_customers',
                'helpdesk_comments',
                'helpdesk_attachments',
                'helpdesk_kb_articles',
                'helpdesk_whitelist',
                'helpdesk_assignments',
                'helpdesk_guest_access'
            ];

            foreach ($requiredTables as $table) {
                $qb = $connection->getQueryBuilder();
                $qb->select('COUNT(*)')
                    ->from($table)
                    ->setMaxResults(1);
                $result = $qb->executeQuery();
                $result->fetchOne();
                $result->closeCursor();
            }

            $output->info("All helpdesk tables verified successfully");
        } catch (\Throwable $e) {
            $output->warning("Table verification failed: " . $e->getMessage());
        }
    }
}

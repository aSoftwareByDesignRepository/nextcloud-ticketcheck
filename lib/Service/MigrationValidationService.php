<?php

declare(strict_types=1);

/**
 * Service to validate and ensure all migrations are properly applied
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use OCA\Ticketcheck\Db\DbQueryGuard;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Service to validate migration status and ensure database integrity
 */
class MigrationValidationService
{
    private IDBConnection $db;
    private LoggerInterface $logger;
    private IConfig $config;

    /** @var ?Schema Cached for a single {@see validateDatabaseSchema()} run */
    private ?Schema $introspectedSchema = null;

    /** @var ?\Throwable Set when {@see IDBConnection::createSchema()} fails during a validation run */
    private ?\Throwable $introspectFailure = null;

    public function __construct(IDBConnection $db, LoggerInterface $logger, IConfig $config)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Validate that all required tables exist and have correct structure
     *
     * @return list<string>
     */
    public function validateDatabaseSchema(): array
    {
        $this->introspectedSchema = null;
        $this->introspectFailure = null;

        $issues = [];
        $requiredTables = [
            'helpdesk_tickets' => [
                'required_columns' => ['id', 'ticket_number', 'title', 'description', 'status', 'priority', 'created_at'],
                'required_indexes' => ['hd_ticket_num_idx', 'hd_status_idx', 'hd_priority_idx']
            ],
            'helpdesk_projects' => [
                'required_columns' => ['id', 'name', 'status', 'created_by', 'created_at'],
                'required_indexes' => ['hd_proj_name_idx', 'hd_proj_status_idx']
            ],
            'helpdesk_customers' => [
                'required_columns' => ['id', 'name', 'email', 'created_by', 'created_at'],
                'required_indexes' => ['hd_cust_email_idx', 'hd_cust_name_idx']
            ],
            'helpdesk_comments' => [
                'required_columns' => ['id', 'ticket_id', 'content', 'created_at', 'checklist_item_id'],
                'required_indexes' => ['hd_cmt_ticket_idx', 'hd_cmt_created_idx', 'hd_cmt_chk_idx']
            ],
            'helpdesk_attachments' => [
                'required_columns' => ['id', 'ticket_id', 'file_name', 'file_path', 'uploaded_at', 'checklist_item_id'],
                'required_indexes' => ['hd_att_ticket_idx', 'hd_att_chk_idx']
            ],
            'helpdesk_kb_articles' => [
                'required_columns' => ['id', 'title', 'content', 'published', 'created_at'],
                'required_indexes' => ['hd_kb_published_idx', 'hd_kb_created_idx']
            ],
            'helpdesk_kb_categories' => [
                'required_columns' => ['id', 'name', 'position', 'created_at'],
                'required_indexes' => ['hd_kb_cat_position_idx', 'hd_kb_cat_name_idx']
            ],
            'helpdesk_whitelist' => [
                'required_columns' => ['id', 'email_pattern', 'created_at'],
                'required_indexes' => ['hd_wl_pattern_idx']
            ],
            'helpdesk_assignments' => [
                'required_columns' => ['id', 'assigned_user_id', 'priority_order'],
                'required_indexes' => ['hd_asn_user_idx']
            ],
            'helpdesk_guest_access' => [
                'required_columns' => ['id', 'user_id', 'project_id', 'customer_id', 'created_at', 'created_by'],
                'required_indexes' => ['hd_gpa_guest_idx', 'hd_gpa_project_idx']
            ]
        ];

        foreach ($requiredTables as $tableName => $requirements) {
            try {
                if (!$this->tableExists($tableName)) {
                    $issues[] = "Missing table: {$tableName}";
                    continue;
                }

                foreach ($requirements['required_columns'] as $column) {
                    if (!$this->columnExists($tableName, $column)) {
                        $issues[] = "Missing column: {$tableName}.{$column}";
                    }
                }

                foreach ($requirements['required_indexes'] as $index) {
                    if (!$this->indexExists($tableName, $index, $issues)) {
                        $issues[] = "Missing index: {$tableName}.{$index}";
                    }
                }
            } catch (\Throwable $e) {
                if (DbQueryGuard::isMissingTableOrUnknownColumn($e)) {
                    $issues[] = "Error checking table {$tableName}: " . $e->getMessage();
                    continue;
                }
                throw $e;
            }
        }

        return $issues;
    }

    /**
     * Check if a table exists
     */
    private function tableExists(string $tableName): bool
    {
        return $this->db->tableExists($tableName);
    }

    /**
     * Check if a column exists in a table
     */
    private function columnExists(string $tableName, string $columnName): bool
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($columnName)
                ->from($tableName)
                ->setMaxResults(1);
            $result = $qb->executeQuery();
            $result->fetchOne();
            $result->closeCursor();
            return true;
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingTableOrUnknownColumn($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Verify an index exists on the logical table using Doctrine schema introspection (MySQL, PostgreSQL, SQLite, Oracle).
     *
     * @param list<string> $issues Output issues list; may receive a one-time introspection error entry
     */
    private function indexExists(string $logicalTableName, string $indexName, array &$issues): bool
    {
        if (!$this->db->tableExists($logicalTableName)) {
            return false;
        }

        $schema = $this->getOrLoadIntrospectedSchema($issues);
        if ($schema === null) {
            // Introspection already logged; do not emit false "missing index" for every expected index.
            return $this->introspectFailure !== null;
        }

        $table = $this->findIntrospectedTable($schema, $logicalTableName);
        if ($table === null) {
            return false;
        }

        return self::tableHasIndexNamed($table, $indexName);
    }

    /**
     * @param list<string> $issues
     */
    private function getOrLoadIntrospectedSchema(array &$issues): ?Schema
    {
        if ($this->introspectFailure !== null) {
            return null;
        }
        if ($this->introspectedSchema !== null) {
            return $this->introspectedSchema;
        }
        try {
            $this->introspectedSchema = $this->db->createSchema();
            return $this->introspectedSchema;
        } catch (\Throwable $e) {
            $this->introspectFailure = $e;
            $this->logger->error('Ticketcheck schema introspection failed during index validation', ['exception' => $e]);
            $issues[] = 'Cannot introspect database schema for index validation: ' . $e->getMessage();
            return null;
        }
    }

    private function findIntrospectedTable(Schema $schema, string $logicalTableName): ?Table
    {
        $prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');
        $physical = $prefix . $logicalTableName;

        if ($schema->hasTable($physical)) {
            return $schema->getTable($physical);
        }

        $lower = strtolower($physical);
        if ($lower !== $physical && $schema->hasTable($lower)) {
            return $schema->getTable($lower);
        }

        if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_ORACLE) {
            $upper = strtoupper($physical);
            if ($schema->hasTable($upper)) {
                return $schema->getTable($upper);
            }
        }

        return null;
    }

    /**
     * Case-insensitive match against Doctrine-reported index names (covers Oracle uppercasing).
     */
    private static function tableHasIndexNamed(Table $table, string $wantedName): bool
    {
        foreach ($table->getIndexes() as $index) {
            if (strcasecmp($index->getName(), $wantedName) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get database statistics for debugging
     *
     * @return array<string, int|string>
     */
    public function getDatabaseStatistics(): array
    {
        $stats = [];
        $tables = [
            'helpdesk_tickets',
            'helpdesk_projects',
            'helpdesk_customers',
            'helpdesk_comments',
            'helpdesk_attachments',
            'helpdesk_kb_articles',
            'helpdesk_kb_categories',
            'helpdesk_whitelist',
            'helpdesk_assignments',
            'helpdesk_guest_access'
        ];

        foreach ($tables as $table) {
            if (!$this->db->tableExists($table)) {
                $stats[$table] = 0;
                continue;
            }
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select($qb->createFunction('COUNT(*)'))
                    ->from($table);
                $result = $qb->executeQuery();
                $count = $result->fetchOne();
                $result->closeCursor();
                $stats[$table] = (int)$count;
            } catch (\Throwable $e) {
                if (DbQueryGuard::isMissingTableOrUnknownColumn($e)) {
                    $stats[$table] = 0;
                    continue;
                }
                throw $e;
            }
        }

        return $stats;
    }

    /**
     * Log database status for debugging
     */
    public function logDatabaseStatus(): void
    {
        $issues = $this->validateDatabaseSchema();
        $stats = $this->getDatabaseStatistics();

        if (empty($issues)) {
            $this->logger->info("Helpdesk database schema validation passed");
        } else {
            $this->logger->error("Helpdesk database schema issues found: " . implode(', ', $issues));
        }

        $this->logger->info("Helpdesk database statistics: " . json_encode($stats));
    }
}

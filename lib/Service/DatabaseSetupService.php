<?php

declare(strict_types=1);

/**
 * Database setup service to ensure all required tables exist
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Service to ensure database tables exist and are properly configured
 */
class DatabaseSetupService
{
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(IDBConnection $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Check if all required tables exist
     */
    public function checkTablesExist(): bool
    {
        $requiredTables = [
            'helpdesk_tickets',
            'helpdesk_projects',
            'helpdesk_customers',
            'helpdesk_comments',
            'helpdesk_attachments',
            'helpdesk_kb_articles',
            'helpdesk_kb_comments',
            'helpdesk_whitelist',
            'helpdesk_assignments',
            'helpdesk_guest_access'
        ];

        foreach ($requiredTables as $table) {
            if (!$this->tableExists($table)) {
                $this->logger->error("Required table {$table} does not exist");
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a specific table exists
     */
    public function tableExists(string $tableName): bool
    {
        return $this->db->tableExists($tableName);
    }

    /**
     * Get missing tables
     */
    public function getMissingTables(): array
    {
        $requiredTables = [
            'helpdesk_tickets',
            'helpdesk_projects',
            'helpdesk_customers',
            'helpdesk_comments',
            'helpdesk_attachments',
            'helpdesk_kb_articles',
            'helpdesk_kb_comments',
            'helpdesk_whitelist',
            'helpdesk_assignments',
            'helpdesk_guest_access'
        ];

        $missing = [];
        foreach ($requiredTables as $table) {
            if (!$this->tableExists($table)) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /**
     * Log database status for debugging
     */
    public function logDatabaseStatus(): void
    {
        $missing = $this->getMissingTables();
        if (empty($missing)) {
            $this->logger->info("All helpdesk tables exist and are accessible");
        } else {
            $this->logger->error("Missing helpdesk tables: " . implode(', ', $missing));
        }
    }
}

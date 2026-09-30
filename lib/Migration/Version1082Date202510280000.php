<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fix index name collision between helpdesk_tickets and helpdesk_customers tables
 * Renames hd_cust_email_idx on tickets table to hd_ticket_cust_email_idx
 */
class Version1082Date202510280000 extends SimpleMigrationStep
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

        $changed = false;

        // Fix index name collision on helpdesk_tickets table
        if ($schema->hasTable('helpdesk_tickets')) {
            $table = $schema->getTable('helpdesk_tickets');
            
            // Check if the old index exists and remove it
            if ($table->hasIndex('hd_cust_email_idx')) {
                $output->info('Removing old index hd_cust_email_idx from helpdesk_tickets');
                $table->dropIndex('hd_cust_email_idx');
                $changed = true;
            }
            
            // Add the new index with correct name (if it doesn't exist)
            if (!$table->hasIndex('hd_ticket_cust_email_idx') && $table->hasColumn('customer_email')) {
                $output->info('Creating new index hd_ticket_cust_email_idx on helpdesk_tickets');
                $table->addIndex(['customer_email'], 'hd_ticket_cust_email_idx');
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}


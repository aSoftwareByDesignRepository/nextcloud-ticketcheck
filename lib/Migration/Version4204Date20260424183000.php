<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version4204Date20260424183000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('helpdesk_customers')) {
            return $schema;
        }

        $table = $schema->getTable('helpdesk_customers');

        if (!$table->hasColumn('company')) {
            $table->addColumn('company', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $output->info('Added missing helpdesk_customers.company column');
        }

        if (!$table->hasIndex('hd_cust_company_idx')) {
            $table->addIndex(['company'], 'hd_cust_company_idx');
            $output->info('Added missing helpdesk_customers company index');
        }

        return $schema;
    }
}


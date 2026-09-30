<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * CRM write facade: CRM create-or-link may have no contact email.
 * email was NOT NULL + UNIQUE, so null inserts fail Integrity constraint 1048.
 * Nullable email keeps UNIQUE for real addresses; MySQL/PG allow multiple NULLs.
 */
class Version4212Date20260910180000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('helpdesk_customers')) {
			return null;
		}

		$table = $schema->getTable('helpdesk_customers');
		if (!$table->hasColumn('email')) {
			return null;
		}

		$col = $table->getColumn('email');
		if ($col->getNotnull() === false) {
			return null;
		}

		$col->setNotnull(false);
		$output->info('helpdesk_customers.email nullable for CRM link without contact email');

		return $schema;
	}
}

<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Legacy helpdesk_customers rows predate Version1000's createTable path,
 * so existing installs never received updated_at. CRM sibling create then
 * fatals: Unknown column 'updated_at' in 'INSERT INTO'.
 */
class Version4211Date20260818224500 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('helpdesk_customers')) {
			return $schema;
		}

		$table = $schema->getTable('helpdesk_customers');
		if ($table->hasColumn('updated_at')) {
			return $schema;
		}

		$table->addColumn('updated_at', Types::DATETIME, [
			'notnull' => false,
		]);
		$output->info('Added missing helpdesk_customers.updated_at column');

		return $schema;
	}
}

<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Companion S0: TKC2 license singleton + named mobile seats.
 */
class Version4210Date20260727120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('tc_license_state')) {
			$t = $schema->createTable('tc_license_state');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('customer_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('issued_at', Types::STRING, ['length' => 10, 'notnull' => true]);
			$t->addColumn('valid_until', Types::STRING, ['length' => 10, 'notnull' => true]);
			$t->addColumn('mobile_seats', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('payload_b64', Types::TEXT, ['notnull' => true]);
			$t->addColumn('signature_b64', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('applied_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('applied_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'tc_lic_pk');
		}

		if (!$schema->hasTable('tc_mobile_seats')) {
			$t = $schema->createTable('tc_mobile_seats');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('assigned_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('assigned_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'tc_seat_pk');
			$t->addUniqueIndex(['uid'], 'tc_seat_uid_uq');
		}

		return $schema;
	}
}

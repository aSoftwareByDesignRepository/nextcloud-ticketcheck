<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Companion idempotency keys (lost-200 / offline-queue double-submit hardening).
 *
 * POST /companion/api/v1/tickets replayed with the same idempotency key must
 * return the original ticket, not a duplicate row.
 */
class Version4215Date202610020000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('tc_idempotency')) {
			$t = $schema->createTable('tc_idempotency');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 96]);
			$t->addColumn('key_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('response_json', Types::TEXT, ['notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('expires_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tc_idemp_pk');
			$t->addUniqueIndex(['user_id', 'scope', 'key_hash'], 'tc_idemp_uq');
			$t->addIndex(['expires_at'], 'tc_idemp_exp_idx');
			return $schema;
		}
		return null;
	}
}

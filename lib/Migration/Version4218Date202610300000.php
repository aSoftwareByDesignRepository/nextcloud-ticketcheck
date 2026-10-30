<?php

declare(strict_types=1);

/**
 * Create helpdesk_ticket_resources + helpdesk_ticket_res_map — the
 * admin-managed resource catalog (rooms/devices/assets) and the
 * many-to-many ticket↔resource join.
 *
 * Spec: planning/features/ticketcheck/resources-2026-10/SPEC.md
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version4218Date202610300000 extends SimpleMigrationStep
{
	public const TABLE = 'helpdesk_ticket_resources';
	public const MAP_TABLE = 'helpdesk_ticket_res_map';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(self::TABLE)) {
			$t = $schema->createTable(self::TABLE);
			$t->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			// Immutable API/DB contract value stored in the map table.
			// Server-generated slug, never client input.
			$t->addColumn('res_key', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			// Display label — admin-controlled proper noun, renamable.
			$t->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 80,
			]);
			$t->addColumn('position', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			// notnull=false: Oracle forbids NOT NULL booleans (db-standards).
			$t->addColumn('is_enabled', Types::BOOLEAN, [
				'notnull' => false,
				'default' => true,
			]);
			$t->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$t->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$t->setPrimaryKey(['id'], 'htr_pk');
			$t->addUniqueIndex(['res_key'], 'htr_key_uq');
		}

		if (!$schema->hasTable(self::MAP_TABLE)) {
			$m = $schema->createTable(self::MAP_TABLE);
			$m->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$m->addColumn('ticket_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$m->addColumn('res_key', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$m->setPrimaryKey(['id'], 'htrm_pk');
			$m->addUniqueIndex(['ticket_id', 'res_key'], 'htrm_pair_uq');
			$m->addIndex(['res_key'], 'htrm_res_idx');
		}

		return $schema;
	}
}

<?php

declare(strict_types=1);

/**
 * Add res_type to helpdesk_ticket_resources — optional grouping dimension
 * (room/device/other) for picker sections (spec NH-08).
 *
 * Idempotent: hasColumn guard for re-runs and partial states.
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

class Version4219Date202610300001 extends SimpleMigrationStep
{
	public const TABLE = 'helpdesk_ticket_resources';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE)) {
			$t = $schema->getTable(self::TABLE);
			if (!$t->hasColumn('res_type')) {
				$t->addColumn('res_type', Types::STRING, [
					'notnull' => true,
					'length' => 16,
					'default' => 'other',
				]);
				$output->info('ticketcheck: added res_type column');
			}
		}

		return $schema;
	}
}

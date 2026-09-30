<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Normalize helpdesk_guest_access so the runtime insert path works.
 *
 * Instances whose table was created by Version1000 keep a legacy layout:
 * a NOT NULL guest_user_id column and no customer_id / created_by columns.
 * GuestProjectAccessMapper inserts user_id / customer_id / created_by, so
 * every grantAccess() insert fails on such instances — and the failure was
 * swallowed by the batch grant loop, so project picks silently never saved.
 *
 * This migration:
 * - ensures user_id exists (Version1103 already adds it; defensive no-op)
 * - adds the missing customer_id and created_by columns the entity writes
 * - relaxes the legacy guest_user_id column to NULL so inserts that do not
 *   populate it no longer violate NOT NULL on strict backends
 * - ensures the user_id-based indexes exist
 * - backfills user_id from guest_user_id for rows still missing it
 *
 * guest_user_id is kept (nullable) for backward compatibility — reads and
 * writes go through user_id exclusively.
 */
class Version4213Date202609280000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('helpdesk_guest_access')) {
			return null;
		}

		$table = $schema->getTable('helpdesk_guest_access');
		$changed = false;

		if (!$table->hasColumn('user_id')) {
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => null,
			]);
			$changed = true;
			$output->info('helpdesk_guest_access: added user_id column');
		}

		// The entity writes customer_id / created_by; legacy tables lack both.
		if (!$table->hasColumn('customer_id')) {
			$table->addColumn('customer_id', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			$changed = true;
			$output->info('helpdesk_guest_access: added customer_id column');
		}

		if (!$table->hasColumn('created_by')) {
			$table->addColumn('created_by', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => null,
			]);
			$changed = true;
			$output->info('helpdesk_guest_access: added created_by column');
		}

		// Runtime inserts never set guest_user_id; a NOT NULL legacy column
		// makes every insert fail on strict backends.
		if ($table->hasColumn('guest_user_id')) {
			$col = $table->getColumn('guest_user_id');
			if ($col->getNotnull()) {
				$col->setNotnull(false);
				$col->setDefault(null);
				$changed = true;
				$output->info('helpdesk_guest_access: guest_user_id relaxed to NULL');
			}
		}

		if ($table->hasColumn('user_id')) {
			foreach (['hd_ga_guest_idx', 'hd_ga_unique_idx'] as $legacyIndex) {
				if ($table->hasIndex($legacyIndex)) {
					$table->dropIndex($legacyIndex);
					$changed = true;
				}
			}
			if (!$table->hasIndex('hd_gpa_guest_idx')) {
				$table->addIndex(['user_id'], 'hd_gpa_guest_idx');
				$changed = true;
			}
			if (!$table->hasIndex('hd_gpa_project_idx')) {
				$table->addIndex(['project_id'], 'hd_gpa_project_idx');
				$changed = true;
			}
			if (!$table->hasIndex('hd_gpa_unique_idx')) {
				$table->addUniqueIndex(['user_id', 'project_id'], 'hd_gpa_unique_idx');
				$changed = true;
			}
		} elseif (!$table->hasIndex('hd_gpa_project_idx')) {
			$table->addIndex(['project_id'], 'hd_gpa_project_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		try {
			$schema = $schemaClosure();
			if (!$schema->hasTable('helpdesk_guest_access')) {
				return;
			}
			$table = $schema->getTable('helpdesk_guest_access');
			if (!$table->hasColumn('user_id') || !$table->hasColumn('guest_user_id')) {
				return;
			}

			$connection = \OC::$server->getDatabaseConnection();

			// Defensive repeat of Version1103: fill user_id from guest_user_id
			// for rows that predate the column rename.
			$sql = 'UPDATE *PREFIX*helpdesk_guest_access '
				. 'SET user_id = guest_user_id '
				. "WHERE (user_id IS NULL OR user_id = '') "
				. 'AND guest_user_id IS NOT NULL';

			$affected = $connection->executeStatement($sql);
			$output->info("helpdesk_guest_access: synchronized {$affected} rows from guest_user_id to user_id");
		} catch (\Throwable $e) {
			// non-fatal: auxiliary post-schema step — aborting the upgrade on a
			  // failing step (chmod/.htaccess/backfill) is worse than warning; the drift gate backstops schema state
			$output->warning('helpdesk_guest_access: user_id backfill failed: ' . $e->getMessage());
		}
	}
}

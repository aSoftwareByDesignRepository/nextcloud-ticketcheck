<?php

declare(strict_types=1);

/**
 * Create helpdesk_ticket_categories — dynamic, admin-managed ticket
 * categories with per-locale labels. Seeds the five builtin keys that
 * used to be a hardcoded enum in {@see \OCA\Ticketcheck\Db\Ticket}.
 *
 * Spec: planning/ticketcheck-category-labels-2026-10/SPEC.md
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version4217Date202610130000 extends SimpleMigrationStep
{
	public const TABLE = 'helpdesk_ticket_categories';

	/**
	 * Builtin category keys — must stay identical to the former
	 * Ticket::CATEGORY_* enum values; existing tickets and inbound
	 * routing rules already reference them.
	 */
	private const BUILTIN_KEYS = ['technical', 'bug', 'feature', 'general', 'billing'];

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(self::TABLE)) {
			$table = $schema->createTable(self::TABLE);
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			// Immutable API/DB contract value stored in tickets.category and
			// inbound routing rules. Server-generated slug, never client input.
			$table->addColumn('cat_key', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			// JSON object {locale: label}; null = no custom labels (builtin
			// rows render via shipped l10n).
			$table->addColumn('labels', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('position', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			// notnull=false: Oracle forbids NOT NULL booleans (db-standards).
			$table->addColumn('builtin', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
			// Disabled categories stay on existing tickets and in filters but
			// disappear from new-ticket selects.
			$table->addColumn('is_enabled', Types::BOOLEAN, [
				'notnull' => false,
				'default' => true,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id'], 'htc_pk');
			$table->addUniqueIndex(['cat_key'], 'htc_key_uq');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$now = new \DateTime();
		$inserted = 0;

		foreach (self::BUILTIN_KEYS as $position => $key) {
			// insert-if-missing by key — safe on re-run and on instances where
			// the table (or some rows) already exist.
			$check = $this->db->getQueryBuilder();
			$check->select($check->func()->count('id'))
				->from(self::TABLE)
				->where($check->expr()->eq('cat_key', $check->createNamedParameter($key)));
			if ((int)$check->executeQuery()->fetchOne() > 0) {
				continue;
			}

			try {
				$qb = $this->db->getQueryBuilder();
				$qb->insert(self::TABLE)
					->values([
						'cat_key' => $qb->createNamedParameter($key),
						'labels' => $qb->createNamedParameter(null),
						'position' => $qb->createNamedParameter($position, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
						'builtin' => $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
						'is_enabled' => $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
						'created_at' => $qb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_DATETIME_MUTABLE),
						'updated_at' => $qb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_DATETIME_MUTABLE),
					])
					->executeStatement();
				$inserted++;
			} catch (\Throwable $e) {
				// A racing installer may have won the unique index — that is
				// success, not failure. Anything else is still non-fatal:
				// EnsureTicketcheckSchema backstops a partial seed.
				$output->warning("ticketcheck: builtin category '{$key}' seed skipped: " . $e->getMessage());
			}
		}

		$output->info("ticketcheck: seeded {$inserted} builtin ticket categories");
	}
}

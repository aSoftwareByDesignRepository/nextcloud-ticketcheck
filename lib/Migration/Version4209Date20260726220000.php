<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Enforce UNIQUE on helpdesk_customers.name (CRM write facade TOCTOU fix).
 *
 * Mirrors ProjectCheck: CRM write facade rejects duplicate names in-process;
 * DB UNIQUE closes the concurrent create race.
 */
class Version4209Date20260726220000 extends SimpleMigrationStep
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		if (!$this->db->tableExists('helpdesk_customers')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('name')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from('helpdesk_customers')
			->groupBy('name')
			->having($qb->expr()->gt($qb->func()->count('*'), $qb->createNamedParameter(1, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$dupNames = [];
		while ($row = $result->fetch()) {
			$dupNames[] = (string)$row['name'];
		}
		$result->closeCursor();

		foreach ($dupNames as $name) {
			$find = $this->db->getQueryBuilder();
			$find->select('id', 'name')
				->from('helpdesk_customers')
				->where($find->expr()->eq('name', $find->createNamedParameter($name)))
				->orderBy('id', 'ASC');
			$rows = $find->executeQuery();
			$keepFirst = true;
			while ($row = $rows->fetch()) {
				$id = (int)$row['id'];
				if ($keepFirst) {
					$keepFirst = false;
					continue;
				}
				$newName = mb_substr($name . ' (#' . $id . ')', 0, 255);
				$upd = $this->db->getQueryBuilder();
				$upd->update('helpdesk_customers')
					->set('name', $upd->createNamedParameter($newName))
					->where($upd->expr()->eq('id', $upd->createNamedParameter($id, \PDO::PARAM_INT)));
				$upd->executeStatement();
				$output->info('Renamed duplicate helpdesk_customers id=' . $id . ' → ' . $newName);
			}
			$rows->closeCursor();
		}
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('helpdesk_customers')) {
			return null;
		}

		$table = $schema->getTable('helpdesk_customers');
		$changed = false;
		if ($table->hasIndex('hd_cust_name_idx')) {
			$table->dropIndex('hd_cust_name_idx');
			$changed = true;
		}
		if (!$table->hasIndex('hd_cust_name_uq')) {
			$table->addUniqueIndex(['name'], 'hd_cust_name_uq');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}

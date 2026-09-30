<?php

declare(strict_types=1);

/**
 * Migration: enforce one satisfaction survey per ticket
 *
 * Deduplicates any existing multi-row surveys (keep lowest id), then replaces
 * the non-unique ticket_id index with a UNIQUE constraint so concurrent
 * double-submits cannot insert two ratings for the same ticket.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version4207Date20260723120000 extends SimpleMigrationStep
{
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		if (!$this->db->tableExists('helpdesk_ticket_surveys')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'ticket_id')
			->from('helpdesk_ticket_surveys')
			->orderBy('ticket_id', 'ASC')
			->addOrderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$seenTicketIds = [];
		$idsToDelete = [];
		while ($row = $result->fetch()) {
			$ticketId = (int) $row['ticket_id'];
			$id = (int) $row['id'];
			if (isset($seenTicketIds[$ticketId])) {
				$idsToDelete[] = $id;
				continue;
			}
			$seenTicketIds[$ticketId] = true;
		}
		$result->closeCursor();

		if ($idsToDelete === []) {
			return;
		}

		$output->info(sprintf(
			'TicketCheck: removing %d duplicate satisfaction survey row(s)',
			count($idsToDelete)
		));

		foreach (array_chunk($idsToDelete, 100) as $chunk) {
			$deleteQb = $this->db->getQueryBuilder();
			$deleteQb->delete('helpdesk_ticket_surveys')
				->where($deleteQb->expr()->in(
					'id',
					$deleteQb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)
				));
			$deleteQb->executeStatement();
		}
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('helpdesk_ticket_surveys')) {
			return null;
		}

		$table = $schema->getTable('helpdesk_ticket_surveys');
		$changed = false;

		if ($table->hasIndex('hd_survey_ticket_idx')) {
			$table->dropIndex('hd_survey_ticket_idx');
			$changed = true;
		}

		if (!$table->hasIndex('hd_survey_ticket_uq')) {
			$table->addUniqueIndex(['ticket_id'], 'hd_survey_ticket_uq');
			$changed = true;
			$output->info('Added unique index hd_survey_ticket_uq on helpdesk_ticket_surveys.ticket_id');
		}

		return $changed ? $schema : null;
	}
}

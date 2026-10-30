<?php

declare(strict_types=1);

/**
 * Edge mapper for helpdesk_ticket_res_map — the ticket↔resource join.
 * Plain IDBConnection queries (no entity): the map is a pure link table.
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class TicketResourceMapMapper
{
	public const TABLE = 'helpdesk_ticket_res_map';

	public function __construct(private readonly IDBConnection $db)
	{
	}

	/**
	 * Ordered res_keys for one ticket (catalog order, then key).
	 *
	 * @return list<string>
	 */
	public function keysForTicket(int $ticketId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('res_key')
			->from(self::TABLE)
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
			->orderBy('res_key', 'ASC');
		$out = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$out[] = (string)$row['res_key'];
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * Batch edge read — one query for a whole ticket-id set (no N+1).
	 *
	 * @param list<int> $ticketIds
	 * @return array<int, list<string>> ticketId => ordered res_keys
	 */
	public function keysForTickets(array $ticketIds): array
	{
		if ($ticketIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id', 'res_key')
			->from(self::TABLE)
			->where($qb->expr()->in('ticket_id', $qb->createNamedParameter($ticketIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('ticket_id', 'ASC')
			->addOrderBy('res_key', 'ASC');
		$out = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$out[(int)$row['ticket_id']][] = (string)$row['res_key'];
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * Replace-set write: the map ends up exactly equal to $keys. Caller
	 * holds the domain lock and the enclosing DB transaction.
	 *
	 * @param list<string> $keys
	 */
	public function setForTicket(int $ticketId, array $keys): void
	{
		$del = $this->db->getQueryBuilder();
		$del->delete(self::TABLE)
			->where($del->expr()->eq('ticket_id', $del->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		$del->executeStatement();

		foreach (array_values(array_unique($keys)) as $key) {
			$ins = $this->db->getQueryBuilder();
			$ins->insert(self::TABLE)
				->values([
					'ticket_id' => $ins->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT),
					'res_key' => $ins->createNamedParameter($key),
				]);
			$ins->executeStatement();
		}
	}

	/**
	 * Union-write: add $keys missing from the ticket's set (merge survivor).
	 *
	 * @param list<string> $keys
	 */
	public function unionForTicket(int $ticketId, array $keys): void
	{
		$existing = $this->keysForTicket($ticketId);
		foreach (array_values(array_unique($keys)) as $key) {
			if (in_array($key, $existing, true)) {
				continue;
			}
			$ins = $this->db->getQueryBuilder();
			$ins->insert(self::TABLE)
				->values([
					'ticket_id' => $ins->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT),
					'res_key' => $ins->createNamedParameter($key),
				]);
			$ins->executeStatement();
			$existing[] = $key;
		}
	}

	/**
	 * Reassign every edge from $fromKey to $toKey, deduping tickets that
	 * already hold $toKey. Caller holds the enclosing transaction.
	 */
	public function reassignKey(string $fromKey, string $toKey): int
	{
		// Dedupe first: drop $fromKey edges on tickets already holding
		// $toKey — then the UPDATE can't collide on htrm_pair_uq.
		$holders = $this->ticketIdsUsing($toKey);
		if ($holders !== []) {
			$del = $this->db->getQueryBuilder();
			$del->delete(self::TABLE)
				->where($del->expr()->eq('res_key', $del->createNamedParameter($fromKey)))
				->andWhere($del->expr()->in('ticket_id', $del->createNamedParameter($holders, IQueryBuilder::PARAM_INT_ARRAY)));
			$del->executeStatement();
		}
		$upd = $this->db->getQueryBuilder();
		$upd->update(self::TABLE)
			->set('res_key', $upd->createNamedParameter($toKey))
			->where($upd->expr()->eq('res_key', $upd->createNamedParameter($fromKey)));
		return $upd->executeStatement();
	}

	public function deleteForTicket(int $ticketId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/**
	 * Detach one resource key from every ticket (catalog-delete path).
	 */
	public function detachKey(string $resKey): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('res_key', $qb->createNamedParameter($resKey)));
		return $qb->executeStatement();
	}

	/**
	 * Ticket counts grouped by res_key — one query for settings lists.
	 *
	 * @return array<string, int> resKey => ticket count (keys with >0)
	 */
	public function countTicketsGrouped(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('res_key')
			->selectAlias($qb->func()->count('id'), 'cnt')
			->from(self::TABLE)
			->groupBy('res_key');
		$out = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$out[(string)$row['res_key']] = (int)$row['cnt'];
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * @return list<int>
	 */
	public function ticketIdsUsing(string $resKey): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id')
			->from(self::TABLE)
			->where($qb->expr()->eq('res_key', $qb->createNamedParameter($resKey)));
		$out = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$out[] = (int)$row['ticket_id'];
		}
		$result->closeCursor();
		return $out;
	}

	public function countTicketsUsing(string $resKey): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from(self::TABLE)
			->where($qb->expr()->eq('res_key', $qb->createNamedParameter($resKey)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}

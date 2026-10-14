<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class TicketCategoryMapper extends QBMapper
{
	public const TABLE = 'helpdesk_ticket_categories';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, TicketCategory::class);
	}

	/**
	 * All categories ordered for dropdown display.
	 *
	 * @return TicketCategory[]
	 */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('position', 'ASC')
			->addOrderBy('cat_key', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByKey(string $catKey): TicketCategory
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('cat_key', $qb->createNamedParameter($catKey)));
		return $this->findEntity($qb);
	}

	public function keyExists(string $catKey): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($this->getTableName())
			->where($qb->expr()->eq('cat_key', $qb->createNamedParameter($catKey)));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	public function countAll(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($this->getTableName());
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * The configured default-category key, read straight off oc_appconfig.
	 * IConfig::getAppValue is request-cached, so guards inside the mutation
	 * lock must go through this committed-read — otherwise a concurrent
	 * setDefaultKey() commit stays invisible for the rest of the request.
	 */
	public function findDefaultCategoryKey(): string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('configvalue')
			->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('ticketcheck')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('default_category')));
		$value = $qb->executeQuery()->fetchOne();
		return strtolower(trim((string)($value === false ? '' : $value)));
	}

	/**
	 * Number of tickets currently carrying this category key.
	 */
	public function countTicketsUsing(string $catKey): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('helpdesk_tickets')
			->where($qb->expr()->eq('category', $qb->createNamedParameter($catKey)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Ticket counts grouped by category key — one query for settings lists
	 * instead of N per-category COUNTs.
	 *
	 * @return array<string, int> catKey => ticket count (only keys with >0)
	 */
	public function countTicketsGrouped(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('category')
			->selectAlias($qb->func()->count('id'), 'cnt')
			->from('helpdesk_tickets')
			->groupBy('category');
		$out = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$out[(string)$row['category']] = (int)$row['cnt'];
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * Bulk-reassign every ticket carrying $from to $to. Used by the
	 * delete-with-reassign transaction so no ticket row ever keeps a
	 * category key that no longer exists.
	 *
	 * @return int number of rows updated
	 */
	public function reassignTicketCategory(string $from, string $to): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('helpdesk_tickets')
			->set('category', $qb->createNamedParameter($to))
			->where($qb->expr()->eq('category', $qb->createNamedParameter($from)));
		return $qb->executeStatement();
	}
}

<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class TicketResourceMapper extends QBMapper
{
	public const TABLE = 'helpdesk_ticket_resources';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, TicketResource::class);
	}

	/**
	 * All resources ordered for picker display.
	 *
	 * @return TicketResource[]
	 */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('position', 'ASC')
			->addOrderBy('res_key', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByKey(string $resKey): TicketResource
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('res_key', $qb->createNamedParameter($resKey)));
		return $this->findEntity($qb);
	}

	public function keyExists(string $resKey): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($this->getTableName())
			->where($qb->expr()->eq('res_key', $qb->createNamedParameter($resKey)));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	public function countAll(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($this->getTableName());
		return (int)$qb->executeQuery()->fetchOne();
	}
}

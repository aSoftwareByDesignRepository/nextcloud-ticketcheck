<?php

declare(strict_types=1);

/**
 * Mapper for helpdesk_ticket_assignees (co-assignees, TC-MULTI-ASSIGN).
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<TicketAssignee>
 */
class TicketAssigneeMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'helpdesk_ticket_assignees', TicketAssignee::class);
	}

	/**
	 * @return TicketAssignee[]
	 */
	public function findByTicketId(int $ticketId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Co-assignee uids for one ticket (notification fan-out, serialization).
	 *
	 * @return string[]
	 */
	public function getUserIdsByTicketId(int $ticketId): array
	{
		return array_values(array_unique(array_map(
			fn (TicketAssignee $a) => (string)$a->getUserId(),
			$this->findByTicketId($ticketId)
		)));
	}

	/**
	 * Batch: co-assignee uids keyed by ticket id (list pages, +N badges).
	 *
	 * @param int[] $ticketIds
	 * @return array<int, string[]>
	 */
	public function getUserIdsForTickets(array $ticketIds): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ticketIds), fn (int $i) => $i > 0)));
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id', 'user_id')
			->from($this->getTableName())
			->where($qb->expr()->in('ticket_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('created_at', 'ASC');
		$result = $qb->executeQuery();
		$map = [];
		while ($row = $result->fetch()) {
			$map[(int)$row['ticket_id']][] = (string)$row['user_id'];
		}
		$result->closeCursor();
		return $map;
	}

	public function isAssignee(int $ticketId, string $userId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('1'))
			->from($this->getTableName())
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$exists = $result->fetchOne() !== false;
		$result->closeCursor();
		return $exists;
	}

	public function countByTicketId(int $ticketId): int
	{
		if ($ticketId <= 0) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	public function deleteByTicketAndUser(int $ticketId, string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $qb->executeStatement();
	}

	public function deleteByTicketId(int $ticketId): int
	{
		if ($ticketId <= 0) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/**
	 * Ticket ids where the user is a co-assignee (any-role "mine" widening).
	 *
	 * @return int[]
	 */
	public function getTicketIdsByUserId(string $userId): array
	{
		if ($userId === '') {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['ticket_id'];
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * Merge transfer: union source co-assignees onto the target; drop rows
	 * that collide with the target's unique (ticket_id, user_id) index or
	 * with the target's primary assignee. Cap does not apply to system ops.
	 *
	 * @return array{moved: int, dropped: int}
	 */
	public function moveToTicket(int $sourceId, int $targetId, ?string $targetPrimary): array
	{
		$moved = 0;
		$dropped = 0;
		if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
			return ['moved' => 0, 'dropped' => 0];
		}

		foreach ($this->findByTicketId($sourceId) as $row) {
			$userId = (string)$row->getUserId();
			if ($userId === '' || $userId === $targetPrimary || $this->isAssignee($targetId, $userId)) {
				$this->delete($row);
				$dropped++;
				continue;
			}
			$row->setTicketId($targetId);
			try {
				$this->update($row);
				$moved++;
			} catch (\OCP\DB\Exception $e) {
				// isAssignee()->update is check-then-act: a concurrent add on
				// the target wins the unique index; the source row is a drop.
				if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					$this->delete($row);
					$dropped++;
					continue;
				}
				throw $e;
			}
		}

		return ['moved' => $moved, 'dropped' => $dropped];
	}
}

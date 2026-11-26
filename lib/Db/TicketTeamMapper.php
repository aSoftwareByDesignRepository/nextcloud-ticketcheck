<?php

declare(strict_types=1);

/**
 * Mapper for helpdesk_ticket_teams (persistent team queues, NH-01).
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<TicketTeam>
 */
class TicketTeamMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'helpdesk_ticket_teams', TicketTeam::class);
	}

	/**
	 * @return TicketTeam[]
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
	 * @return string[]
	 */
	public function getGroupIdsByTicketId(int $ticketId): array
	{
		return array_values(array_unique(array_map(
			fn (TicketTeam $t) => (string)$t->getGroupId(),
			$this->findByTicketId($ticketId)
		)));
	}

	public function hasTeam(int $ticketId, string $groupId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('1'))
			->from($this->getTableName())
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)));
		$result = $qb->executeQuery();
		$exists = $result->fetchOne() !== false;
		$result->closeCursor();
		return $exists;
	}

	public function deleteByTicketAndGroup(int $ticketId, string $groupId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)));
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

	/**
	 * Merge transfer: union source team rows onto target; dedupe on
	 * (ticket_id, group_id).
	 *
	 * @return array{moved: int, dropped: int}
	 */
	public function moveToTicket(int $sourceId, int $targetId): array
	{
		$moved = 0;
		$dropped = 0;
		if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
			return ['moved' => 0, 'dropped' => 0];
		}
		foreach ($this->findByTicketId($sourceId) as $row) {
			$gid = (string)$row->getGroupId();
			if ($gid === '' || $this->hasTeam($targetId, $gid)) {
				$this->delete($row);
				$dropped++;
				continue;
			}
			$row->setTicketId($targetId);
			try {
				$this->update($row);
				$moved++;
			} catch (\OCP\DB\Exception $e) {
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

	/**
	 * Ticket ids queued for any of the given groups ("my team" queue, NH-01).
	 *
	 * @param string[] $groupIds
	 * @return int[]
	 */
	public function getTicketIdsByGroupIds(array $groupIds): array
	{
		$gids = array_values(array_filter(array_map('strval', $groupIds), fn (string $g) => $g !== ''));
		if ($gids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('t.id')
			->from('helpdesk_tickets', 't')
			->innerJoin('t', $this->getTableName(), 'tt', $qb->expr()->eq('tt.ticket_id', 't.id'))
			->where($qb->expr()->isNull('t.merged_into_id'))
			->andWhere($qb->expr()->in('tt.group_id', $qb->createNamedParameter($gids, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();
		return $ids;
	}
}

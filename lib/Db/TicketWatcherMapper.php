<?php

declare(strict_types=1);

/**
 * TicketWatcher mapper for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class TicketWatcherMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_ticket_watchers', TicketWatcher::class);
    }

    /**
     * @return TicketWatcher[]
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
     * Get user IDs watching a ticket (for notifications)
     *
     * @return string[]
     */
    public function getUserIdsByTicketId(int $ticketId): array
    {
        $watchers = $this->findByTicketId($ticketId);
        return array_values(array_unique(array_map(fn (TicketWatcher $w) => $w->getUserId(), $watchers)));
    }

    public function isWatching(int $ticketId, string $userId): bool
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
     * Move watchers from source to target; drop users already watching the target.
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

        foreach ($this->findByTicketId($sourceId) as $watcher) {
            $userId = (string)$watcher->getUserId();
            if ($this->isWatching($targetId, $userId)) {
                $this->delete($watcher);
                $dropped++;
                continue;
            }
            $watcher->setTicketId($targetId);
            try {
                $this->update($watcher);
                $moved++;
            } catch (\OCP\DB\Exception $e) {
                // isWatching()→update is check-then-act: a concurrent add of
                // the same user on the target wins the unique index — that
                // makes this source row redundant, so it is a drop, not a 500.
                if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    $this->delete($watcher);
                    $dropped++;
                    continue;
                }
                throw $e;
            }
        }

        return ['moved' => $moved, 'dropped' => $dropped];
    }
}

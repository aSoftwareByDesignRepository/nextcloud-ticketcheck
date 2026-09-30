<?php

declare(strict_types=1);

/**
 * Comment mapper for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Comment mapper
 */
class CommentMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_comments', Comment::class);
    }

    /**
     * Find comment by ID
     *
     * @param int $id
     * @return Comment
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function find(int $id): Comment
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * Find all comments for a ticket
     *
     * @param int $ticketId
     * @param bool $includeInternal Include internal notes
     * @return Comment[]
     */
    public function findByTicketId(int $ticketId, bool $includeInternal = true): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));

        if (!$includeInternal) {
            $qb->andWhere($qb->expr()->eq('is_internal', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
        }

        $qb->orderBy('created_at', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Find all comments by user
     *
     * @param string $userId
     * @return Comment[]
     */
    public function findByUserId(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * Count non-internal comments authored by a user since a given time (portal rate limiting).
     */
    public function countRecentByUserId(string $userId, \DateTime $since): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('is_internal', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();

        return $count;
    }

    /**
     * Count comments for a ticket
     *
     * @param int $ticketId
     * @param bool $includeInternal Include internal notes
     * @return int
     */
    public function countByTicketId(int $ticketId, bool $includeInternal = true): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));

        if (!$includeInternal) {
            $qb->andWhere($qb->expr()->eq('is_internal', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
        }

        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * Move all comments from source ticket to target ticket (for merge)
     *
     * @param int $sourceTicketId
     * @param int $targetTicketId
     * @return int Number of moved comments
     */
    public function moveToTicket(int $sourceTicketId, int $targetTicketId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('ticket_id', $qb->createNamedParameter($targetTicketId, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($sourceTicketId, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement();
    }

    /**
     * Delete a comment only if it still belongs to the given ticket.
     * Prevents wiping a row that merge already re-pointed to the survivor.
     */
    public function deleteIfOnTicket(int $commentId, int $ticketId): bool
    {
        if ($commentId <= 0 || $ticketId <= 0) {
            return false;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement() === 1;
    }

    /**
     * Delete all comments for a ticket
     *
     * @param int $ticketId
     * @return int Number of deleted comments
     */
    public function deleteByTicketId(int $ticketId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement();
    }
}


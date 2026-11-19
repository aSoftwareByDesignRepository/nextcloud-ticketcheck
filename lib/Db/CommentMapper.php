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
     * Find all comments for a ticket (soft-deleted tombstones excluded — the
     * default safe direction for every reader; staff uses
     * {@see findByTicketIdIncludingDeleted} to render tombstones).
     *
     * @param int $ticketId
     * @param bool $includeInternal Include internal notes
     * @return Comment[]
     */
    public function findByTicketId(int $ticketId, bool $includeInternal = true): array
    {
        return $this->findByTicketIdQuery($ticketId, $includeInternal, false);
    }

    /**
     * Find all comments including tombstoned rows (staff thread rendering).
     *
     * @param int $ticketId
     * @param bool $includeInternal Include internal notes
     * @return Comment[]
     */
    public function findByTicketIdIncludingDeleted(int $ticketId, bool $includeInternal = true): array
    {
        return $this->findByTicketIdQuery($ticketId, $includeInternal, true);
    }

    /**
     * @return Comment[]
     */
    private function findByTicketIdQuery(int $ticketId, bool $includeInternal, bool $includeDeleted): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));

        if (!$includeInternal) {
            $qb->andWhere($qb->expr()->eq('is_internal', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
        }
        if (!$includeDeleted) {
            $qb->andWhere($qb->expr()->isNull('deleted_at'));
        }

        $qb->orderBy('created_at', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Find comments by id regardless of ticket (move selection validation).
     *
     * @param int[] $ids
     * @return Comment[]
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
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
     * Count comments for a ticket — tombstones excluded (EC-10: dependency
     * counts and CSV comment_count stay honest).
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
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('deleted_at'));

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

    /**
     * Move a selected subset of comments to the target ticket. Re-verifies
     * ticket binding inside the statement so a row re-homed by a concurrent
     * merge is skipped instead of dragged along (TOCTOU guard under the
     * ticket lock). Clears the ticket-local checklist binding and stamps
     * moved_from provenance.
     *
     * @param int[] $commentIds
     * @return int Number of rows actually moved
     */
    public function moveByIdsToTicket(array $commentIds, int $sourceTicketId, int $targetTicketId): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $commentIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('ticket_id', $qb->createNamedParameter($targetTicketId, IQueryBuilder::PARAM_INT))
            ->set('moved_from_ticket_id', $qb->createNamedParameter($sourceTicketId, IQueryBuilder::PARAM_INT))
            ->set('checklist_item_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('ticket_id', $qb->createNamedParameter($sourceTicketId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('deleted_at'));
        return $qb->executeStatement();
    }

    /**
     * GDPR erasure: scrub content and author PII on tombstoned comments
     * authored by the erased actor. Active comments keep history per the
     * existing erasure semantics (actor string resolves anonymized).
     */
    public function scrubDeletedByUserId(string $userId, string $scrubbedName): int
    {
        if ($userId === '') {
            return 0;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('content', $qb->createNamedParameter(''))
            ->set('author_name', $qb->createNamedParameter($scrubbedName))
            ->set('author_email', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNotNull('deleted_at'));
        return $qb->executeStatement();
    }
}


<?php

declare(strict_types=1);

/**
 * Attachment mapper for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Attachment mapper
 */
class AttachmentMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_attachments', Attachment::class);
    }

    /**
     * Find attachment by ID
     *
     * @param int $id
     * @return Attachment
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function find(int $id): Attachment
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * Find all attachments for a ticket
     *
     * @param int $ticketId
     * @return Attachment[]
     */
    public function findByTicketId(int $ticketId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
            ->orderBy('uploaded_at', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Find all attachments for a comment
     *
     * @param int $commentId
     * @return Attachment[]
     */
    public function findByCommentId(int $commentId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('comment_id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT)))
            ->orderBy('uploaded_at', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Update ticket_id for all attachments of a ticket (for merge)
     *
     * @param int $sourceTicketId
     * @param int $targetTicketId
     * @return int Number of updated attachments
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
     * Delete an attachment row only if it still belongs to the given ticket.
     * Prevents deleting a survivor attachment after merge re-pointed the row.
     */
    public function deleteIfOnTicket(int $attachmentId, int $ticketId): bool
    {
        if ($attachmentId <= 0 || $ticketId <= 0) {
            return false;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($attachmentId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement() === 1;
    }

    /**
     * Delete all attachments for a ticket
     *
     * @param int $ticketId
     * @return int Number of deleted attachments
     */
    public function deleteByTicketId(int $ticketId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement();
    }

    /**
     * Delete all attachments for a comment
     *
     * @param int $commentId
     * @return int Number of deleted attachments
     */
    public function deleteByCommentId(int $commentId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('comment_id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement();
    }

    /**
     * Count attachments uploaded by a user since a given time (portal rate limiting).
     */
    public function countRecentByUserId(string $userId, \DateTime $since): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uploaded_by', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gte('uploaded_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();

        return $count;
    }
}


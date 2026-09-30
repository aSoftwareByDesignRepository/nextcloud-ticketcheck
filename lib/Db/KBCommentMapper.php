<?php

declare(strict_types=1);

/**
 * KB Comment mapper for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * KB Comment mapper
 */
class KBCommentMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_kb_comments', KBComment::class);
    }

    /**
     * Find comments by article ID
     *
     * @param int $articleId
     * @return KBComment[]
     */
    public function findByArticleId(int $articleId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Find comment by ID
     *
     * @param int $id
     * @return KBComment
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function find(int $id): KBComment
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * Delete all comments for an article (used on article delete — no FK cascade).
     *
     * @return int Number of rows deleted
     */
    public function deleteByArticleId(int $articleId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement();
    }

    public function countRecentByUserId(string $userId, \DateTimeInterface $since): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gte(
                'created_at',
                $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATETIME_MUTABLE)
            ));
        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }
}

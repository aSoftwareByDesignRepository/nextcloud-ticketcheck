<?php

declare(strict_types=1);

/**
 * KB Article mapper for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCA\Ticketcheck\Service\KbSearchTextHelper;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * KB Article mapper
 *
 * @extends QBMapper<KBArticle>
 */
class KBArticleMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_kb_articles', KBArticle::class);
    }

    /**
     * Find article by ID
     *
     * @param int $id
     * @return KBArticle
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function find(int $id): KBArticle
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * Find all published articles
     *
     * @return KBArticle[]
     */
    public function findPublished(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('published', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('pinned', 'DESC')
            ->addOrderBy('helpful_count', 'DESC')
            ->addOrderBy('views', 'DESC')
            ->addOrderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * Find all articles (including unpublished)
     *
     * @return KBArticle[]
     */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('pinned', 'DESC')
            ->addOrderBy('helpful_count', 'DESC')
            ->addOrderBy('views', 'DESC')
            ->addOrderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * Find articles by category
     *
     * @param string $category
     * @param bool $publishedOnly
     * @return KBArticle[]
     */
    public function findByCategory(string $category, bool $publishedOnly = true): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('category', $qb->createNamedParameter($category)));

        if ($publishedOnly) {
            $qb->andWhere($qb->expr()->eq('published', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }

        $qb->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * Search articles
     *
     * @param string $query
     * @param bool $publishedOnly
     * @return KBArticle[]
     */
    public function search(string $query, bool $publishedOnly = true): array
    {
        $query = KbSearchTextHelper::normalizeQuery($query);
        $tokens = KbSearchTextHelper::extractSearchTokens($query);
        if ($tokens === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        // Broad candidate fetch: any meaningful token may match (ranking filters noise afterward).
        $tokenConditions = $qb->expr()->orX();
        foreach ($tokens as $token) {
            $likeParam = '%' . $this->db->escapeLikeParameter($token) . '%';
            $titleParam = $qb->createNamedParameter($likeParam, IQueryBuilder::PARAM_STR);
            $contentParam = $qb->createNamedParameter($likeParam, IQueryBuilder::PARAM_STR);

            $tokenConditions->add(
                $qb->expr()->orX(
                    $qb->expr()->iLike('title', $titleParam),
                    $qb->expr()->iLike('content', $contentParam),
                ),
            );
        }
        $qb->where($tokenConditions);

        if ($publishedOnly) {
            $qb->andWhere($qb->expr()->eq('published', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }

        $candidates = $this->findEntities($qb);

        return KbSearchTextHelper::rankArticles($candidates, $query);
    }

    /**
     * Atomically increment views (avoids lost updates under concurrent portal/staff reads).
     */
    public function incrementViewsAtomic(int $id): int
    {
        $qb = $this->db->getQueryBuilder();
        $viewsCol = $qb->getColumnName('views');
        $qb->update($this->getTableName())
            ->set('views', $qb->createFunction($viewsCol . ' + 1'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }

    /**
     * Atomically increment helpful_count (avoids lost updates under concurrent votes).
     */
    public function incrementHelpfulCountAtomic(int $id): int
    {
        $qb = $this->db->getQueryBuilder();
        $col = $qb->getColumnName('helpful_count');
        $qb->update($this->getTableName())
            ->set('helpful_count', $qb->createFunction($col . ' + 1'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }

    /**
     * Whether any published article body references this stored KB image filename.
     * Used to deny guest/non-manager downloads of draft-only or unreferenced images.
     */
    public function isFilenameReferencedByPublishedArticle(string $filename): bool
    {
        return $this->isFilenameReferenced($filename, true);
    }

    /**
     * Whether any article body (draft or published) references this KB image filename.
     * Used for orphan GC after delete/update.
     */
    public function isFilenameReferencedByAnyArticle(string $filename): bool
    {
        return $this->isFilenameReferenced($filename, false);
    }

    private function isFilenameReferenced(string $filename, bool $publishedOnly): bool
    {
        $filename = trim($filename);
        if ($filename === '' || str_contains($filename, "\0")) {
            return false;
        }

        $qb = $this->db->getQueryBuilder();
        $like = '%' . $this->db->escapeLikeParameter($filename) . '%';
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->like('content', $qb->createNamedParameter($like)));

        if ($publishedOnly) {
            $qb->andWhere($qb->expr()->eq('published', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }

        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();

        return $count > 0;
    }
}

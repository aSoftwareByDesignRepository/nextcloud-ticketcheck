<?php

declare(strict_types=1);

/**
 * Per-user KB helpful vote rows (unique article_id + user_id).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class KBHelpfulVoteMapper
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * Record a helpful vote. Returns true when a new row was inserted.
	 * Concurrent duplicates hit the UNIQUE constraint and return false.
	 */
	public function tryInsert(int $articleId, string $userId): bool
	{
		$articleId = max(0, $articleId);
		$userId = trim($userId);
		if ($articleId <= 0 || $userId === '' || strlen($userId) > 64) {
			return false;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert('hd_kb_helpful')
			->values([
				'article_id' => $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($userId),
				'created_at' => $qb->createNamedParameter(
					(new \DateTimeImmutable('now'))->format('Y-m-d H:i:s')
				),
			]);

		try {
			$qb->executeStatement();
			return true;
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}
	}

	public function hasVoted(int $articleId, string $userId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('hd_kb_helpful')
			->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$result = $qb->executeQuery();
		$count = (int) $result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}

    public function deleteByArticleId(int $articleId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('hd_kb_helpful')
            ->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }

    public function countRecentByUserId(string $userId, \DateTimeInterface $since): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from('hd_kb_helpful')
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

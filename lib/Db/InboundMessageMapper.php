<?php

declare(strict_types=1);

/**
 * InboundMessage mapper for helpdesk app
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class InboundMessageMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'helpdesk_inbound_msgs', InboundMessage::class);
	}

	/**
	 * Claim-before-process (spec §9.3 step 5): the unique index on msg_hash
	 * is the dedupe boundary — only the first claim wins. A row already in a
	 * terminal action is returned as 'duplicate'; a stale 'processing' row
	 * may be reclaimed once (bounded retry handles it from there).
	 *
	 * @return array{row: InboundMessage, status: 'claimed'|'duplicate'|'reclaim'}
	 */
	public function claim(InboundMessage $row, int $staleSeconds = 900): array
	{
		$row->setCreatedAt(new \DateTime());
		$row->setUpdatedAt(new \DateTime());
		try {
			$inserted = $this->insert($row);
			assert($inserted instanceof InboundMessage);
			return ['row' => $inserted, 'status' => 'claimed'];
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}

		$existing = $this->findByMsgHash($row->getMsgHash());
		if ($existing === null) {
			// Unique violation without a visible row — impossible on a sane
			// index; surface as duplicate rather than reprocessing blindly.
			return ['row' => $row, 'status' => 'duplicate'];
		}
		if ($existing->isTerminal()) {
			return ['row' => $existing, 'status' => 'duplicate'];
		}

		// Stale claim (crash between claim and finish) — one bounded reprocess.
		// The reclaim is a single conditional UPDATE so two concurrent
		// reclaimers cannot both win the stale window: only the first writer
		// satisfies the WHERE clause, the loser sees rowCount=0.
		$cutoff = new \DateTime();
		$cutoff->setTimestamp(time() - $staleSeconds);
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('claimed_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
			->set('attempts', $qb->createFunction('attempts + 1'))
			->set('updated_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($existing->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('action', $qb->createNamedParameter(
				[InboundMessage::ACTION_PROCESSING, InboundMessage::ACTION_ERROR_RETRYABLE],
				IQueryBuilder::PARAM_STR_ARRAY
			)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('claimed_at'),
				$qb->expr()->lt('claimed_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE))
			));
		if ($qb->executeStatement() === 1) {
			$reclaimed = $this->findByMsgHash($row->getMsgHash());
			if ($reclaimed !== null) {
				return ['row' => $reclaimed, 'status' => 'reclaim'];
			}
		}

		return ['row' => $existing, 'status' => 'duplicate'];
	}

	public function findByMsgHash(string $msgHash): ?InboundMessage
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('msg_hash', $qb->createNamedParameter($msgHash)));
		try {
			/** @var InboundMessage */
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Terminal dedupe lookup for the UID cursor (spec §9.3 step 2):
	 * a message already processed via a different channel/key must not run again.
	 */
	public function isProcessedUid(string $mailboxId, int $uidvalidity, int $uid): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('1'))
			->from($this->getTableName())
			->where($qb->expr()->eq('mailbox_id', $qb->createNamedParameter($mailboxId)))
			->andWhere($qb->expr()->eq('uidvalidity', $qb->createNamedParameter($uidvalidity, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$exists = $result->fetchOne() !== false;
		$result->closeCursor();
		return $exists;
	}

	public function findByUid(string $mailboxId, int $uidvalidity, int $uid): ?InboundMessage
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('mailbox_id', $qb->createNamedParameter($mailboxId)))
			->andWhere($qb->expr()->eq('uidvalidity', $qb->createNamedParameter($uidvalidity, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_INT)));
		try {
			/** @var InboundMessage */
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function finish(InboundMessage $row, string $action, ?int $ticketId = null, ?int $commentId = null, ?string $error = null): InboundMessage
	{
		$row->setAction($action);
		$row->setTicketId($ticketId);
		$row->setCommentId($commentId);
		$row->setError($error !== null ? mb_substr($error, 0, 255) : null);
		$row->setFinishedAt(new \DateTime());
		$row->setUpdatedAt(new \DateTime());
		return $this->update($row);
	}

	/**
	 * Count non-terminal rows in the mailbox cursor range — used for the
	 * standing triage/backlog count on the status chip.
	 *
	 * @return array{byAction: array<string, int>, total: int}
	 */
	public function triageCounts(string $mailboxId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('action')
			->selectAlias($qb->createFunction('COUNT(*)'), 'n')
			->from($this->getTableName())
			->where($qb->expr()->eq('mailbox_id', $qb->createNamedParameter($mailboxId)))
			->andWhere($qb->expr()->like('action', $qb->createNamedParameter('ignored_%')))
			->groupBy('action');
		$result = $qb->executeQuery();
		$byAction = [];
		$total = 0;
		while ($row = $result->fetch()) {
			$n = (int)$row['n'];
			$byAction[(string)$row['action']] = $n;
			$total += $n;
		}
		$result->closeCursor();
		return ['byAction' => $byAction, 'total' => $total];
	}

	/**
	 * Retention: drop terminal rows older than the cutoff (spec NN-17).
	 * In-flight rows are never purged here.
	 */
	public function purgeFinishedBefore(\DateTime $cutoff): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->isNotNull('finished_at'))
			->andWhere($qb->expr()->lt('finished_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE)));
		return $qb->executeStatement();
	}

	/**
	 * Per-sender ticket-creation count in a rolling window (spec §9.6 cap).
	 */
	public function countTicketCreationsBySender(string $fromAddr, \DateTime $since): int
	{
		// Compare in the same normalized+truncated space the claim row stores
		// (mb_substr at insert) — a >255-char From must not sidestep the cap.
		$storedForm = mb_substr(strtolower($fromAddr), 0, 255);
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('from_addr', $qb->createNamedParameter($storedForm)))
			->andWhere($qb->expr()->eq('action', $qb->createNamedParameter(InboundMessage::ACTION_TICKET_CREATED)))
			->andWhere($qb->expr()->gte('finished_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATE)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * Global ticket-creation count in a rolling window — the runaway-create
	 * breaker (spec §9.6): a botnet of distinct senders must not mint tickets
	 * without bound even when each sender is under its own cap.
	 */
	public function countTicketCreationsSince(\DateTime $since): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('action', $qb->createNamedParameter(InboundMessage::ACTION_TICKET_CREATED)))
			->andWhere($qb->expr()->gte('finished_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATE)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}
}

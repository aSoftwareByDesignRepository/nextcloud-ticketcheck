<?php

declare(strict_types=1);

/**
 * Passwordless guest portal (spec: passwordless-guests-2026-11 §10.1).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<MailOutbox> */
class MailOutboxMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_mail_outbox', MailOutbox::class);
    }

    public function find(int $id): ?MailOutbox
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function findByIdempotencyKey(string $key): ?MailOutbox
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('idempotency_key', $qb->createNamedParameter($key)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /** Due 'pending' rows + 'sending' rows with expired lease (dead worker). */
    public function dueBatch(int $limit = 50): array
    {
        $qb = $this->db->getQueryBuilder();
        $now = new \DateTime();
        $due = $qb->expr()->orX(
            $qb->expr()->isNull('next_try_at'),
            $qb->expr()->lte('next_try_at', $qb->createNamedParameter($now, 'datetime'))
        );
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->orX(
                $qb->expr()->andX(
                    $qb->expr()->eq('status', $qb->createNamedParameter('pending')),
                    $qb->expr()->lt('attempts', $qb->createNamedParameter(5)),
                    $due
                ),
                $qb->expr()->andX(
                    $qb->expr()->eq('status', $qb->createNamedParameter('sending')),
                    $qb->expr()->lte('next_try_at', $qb->createNamedParameter($now, 'datetime'))
                )
            ))
            ->orderBy('id', 'ASC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /** Atomic lease claim — true iff this worker won the row. */
    public function claim(int $id, \DateTime $lease): bool
    {
        $qb = $this->db->getQueryBuilder();
        $now = new \DateTime();
        $due = $qb->expr()->orX(
            $qb->expr()->isNull('next_try_at'),
            $qb->expr()->lte('next_try_at', $qb->createNamedParameter($now, 'datetime'))
        );
        $qb->update('helpdesk_mail_outbox')
            ->set('status', $qb->createNamedParameter('sending'))
            ->set('next_try_at', $qb->createNamedParameter($lease, 'datetime'))
            ->set('attempts', $qb->createFunction('attempts + 1'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->andX(
                    $qb->expr()->eq('status', $qb->createNamedParameter('pending')),
                    $qb->expr()->lt('attempts', $qb->createNamedParameter(5)),
                    $due
                ),
                $qb->expr()->andX(
                    $qb->expr()->eq('status', $qb->createNamedParameter('sending')),
                    $qb->expr()->lte('next_try_at', $qb->createNamedParameter($now, 'datetime'))
                )
            ));
        return $qb->executeStatement() === 1;
    }

    public function markSent(int $id, int $epoch): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('helpdesk_mail_outbox')
            ->set('status', $qb->createNamedParameter('sent'))
            ->set('sent_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sending')))
            ->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter($epoch)));
        $qb->executeStatement();
    }

    public function markFailed(int $id, int $epoch, bool $dead, string $errorCode = ''): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('helpdesk_mail_outbox')
            ->set('status', $qb->createNamedParameter($dead ? 'failed' : 'pending'))
            ->set('error_code', $qb->createNamedParameter($errorCode !== '' ? $errorCode : null))
            ->set('next_try_at', $qb->createNamedParameter(
                $dead ? null : new \DateTime('+' . (2 ** $epoch) . ' minutes'), 'datetime'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sending')))
            ->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter($epoch)));
        $qb->executeStatement();
    }

    /** Sends (queued or sent) to one address within the window — mail cap. */
    public function recentSendCount(string $email, \DateTime $since): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))->from($this->getTableName())
            ->where($qb->expr()->eq('to_email', $qb->createNamedParameter($email)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, 'datetime')));
        return (int)$qb->executeQuery()->fetchOne();
    }

    public function countPending(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')));
        return (int)$qb->executeQuery()->fetchOne();
    }

    /** Latest outbox row for one address (diagnostics; never exposes payload). */
    public function lastForEmail(string $email): ?MailOutbox
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('to_email', $qb->createNamedParameter($email)))
            ->orderBy('id', 'DESC')->setMaxResults(1);
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function deleteSentOlderThan(\DateTime $cutoff, string $status): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
            ->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, 'datetime')));
        return $qb->executeStatement();
    }

    public function deleteByIdentityKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->in('idempotency_key', $qb->createNamedParameter($keys, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
        $qb->executeStatement();
    }

    /** @return list<MailOutbox> */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
        return $this->findEntities($qb);
    }
}

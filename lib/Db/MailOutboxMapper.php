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
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, 'datetime')))
            // Synchronous external-leg ledger rows share this table but are
            // capped per (ticket,address) — they must not eat the per-address
            // portal outbox budget (OTP invites would starve on busy tickets).
            ->andWhere($qb->expr()->notLike(
                'idempotency_key',
                $qb->createNamedParameter(self::EXTERNAL_LEG_KEY_PREFIX . '%')
            ));
        return (int)$qb->executeQuery()->fetchOne();
    }

    /**
     * Key prefix for synchronous external-customer-leg ledger rows
     * ('extmail:{ticketId}:{rand}'). Rows are terminal on insert — they
     * never enter the outbox worker's pending/sending lifecycle.
     */
    public const EXTERNAL_LEG_KEY_PREFIX = 'extmail:';

    /**
     * External-leg send attempts to one address on one ticket within the
     * window — the (ticket,address)/hour cap ledger.
     */
    public function recentExternalLegCount(int $ticketId, string $email, \DateTime $since): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))->from($this->getTableName())
            ->where($qb->expr()->eq('to_email', $qb->createNamedParameter($email)))
            ->andWhere($qb->expr()->like(
                'idempotency_key',
                $qb->createNamedParameter(self::EXTERNAL_LEG_KEY_PREFIX . $ticketId . ':%')
            ))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, 'datetime')));
        return (int)$qb->executeQuery()->fetchOne();
    }

    /**
     * Record one external-leg send attempt for the (ticket,address) cap.
     * The row is a terminal ledger entry (never queued for the worker);
     * retention cleanup treats it like any other completed outbox row.
     */
    public function recordExternalLegSend(int $ticketId, string $email): ?int
    {
        $row = new MailOutbox();
        $row->setIdempotencyKey(
            self::EXTERNAL_LEG_KEY_PREFIX . $ticketId . ':' . bin2hex(random_bytes(8))
        );
        $row->setToEmail($email);
        $row->setTemplate('ticket_external');
        $row->setPayload('{}');
        $row->setStatus('sent');
        $row->setAttempts(1);
        $now = new \DateTime();
        $row->setSentAt($now);
        $row->setCreatedAt($now);
        try {
            $saved = $this->insert($row);
        } catch (\OCP\DB\Exception $e) {
            // Random per-attempt key cannot collide by construction; the
            // catch encodes the conflict contract so a future deterministic
            // key degrades to an unledgered attempt, not a raw 500.
            if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                return null;
            }
            throw $e;
        }
        $id = $saved->getId();
        return is_int($id) ? $id : null;
    }

    /** Flip a ledger row to 'failed' when the synchronous send errored. */
    public function markExternalLegFailed(int $rowId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('helpdesk_mail_outbox')
            ->set('status', $qb->createNamedParameter('failed'))
            ->set('error_code', $qb->createNamedParameter('send_failed'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($rowId)));
        $qb->executeStatement();
    }

    /**
     * Prefix for cap-suppressed external-leg events. Deliberately distinct
     * from EXTERNAL_LEG_KEY_PREFIX: drop rows must never count toward the
     * (ticket,address) send cap or the window could never roll.
     */
    public const EXTERNAL_DROP_KEY_PREFIX = 'extdrop:';

    /** Prefix for digest sends — likewise excluded from the send cap. */
    public const EXTERNAL_DIGEST_KEY_PREFIX = 'extdigest:';

    /**
     * Ledger one cap-suppressed external-leg event (NH-03). Status 'capped'
     * keeps it out of every worker queue; retention treats it like 'sent'.
     */
    public function recordExternalLegDrop(int $ticketId, string $email): void
    {
        $row = new MailOutbox();
        $row->setIdempotencyKey(
            self::EXTERNAL_DROP_KEY_PREFIX . $ticketId . ':' . bin2hex(random_bytes(8))
        );
        $row->setToEmail($email);
        $row->setTemplate('ticket_external');
        $row->setPayload('{}');
        $row->setStatus('capped');
        $row->setAttempts(0);
        $row->setCreatedAt(new \DateTime());
        try {
            $this->insert($row);
        } catch (\OCP\DB\Exception $e) {
            // See recordExternalLegSend — random key; a unique hit loses one
            // bookkeeping row, which must never break the send path.
            if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
        }
    }

    /**
     * Distinct (ticket,address) pairs with cap-dropped events since $since.
     * The ticket id rides inside the idempotency key ('extdrop:{id}:{rand}').
     *
     * @return list<array{ticket_id: int, email: string}>
     */
    public function cappedExternalLegPairs(\DateTime $since): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('idempotency_key', 'to_email')->from($this->getTableName())
            ->where($qb->expr()->like(
                'idempotency_key',
                $qb->createNamedParameter(self::EXTERNAL_DROP_KEY_PREFIX . '%')
            ))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, 'datetime')))
            ->setMaxResults(1000);
        $pairs = [];
        $stmt = $qb->executeQuery();
        while (($r = $stmt->fetch()) !== false) {
            $key = (string)$r['idempotency_key'];
            if (preg_match('/^' . self::EXTERNAL_DROP_KEY_PREFIX . '(\d+):/', $key, $m) !== 1) {
                continue;
            }
            $pairs[(int)$m[1] . ':' . strtolower((string)$r['to_email'])] = [
                'ticket_id' => (int)$m[1],
                'email' => strtolower((string)$r['to_email']),
            ];
        }
        $stmt->closeCursor();
        return array_values($pairs);
    }

    /** Cap-dropped events for one (ticket,address) since $since. */
    public function cappedExternalLegCount(int $ticketId, string $email, \DateTime $since): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))->from($this->getTableName())
            ->where($qb->expr()->eq('to_email', $qb->createNamedParameter($email)))
            ->andWhere($qb->expr()->like(
                'idempotency_key',
                $qb->createNamedParameter(self::EXTERNAL_DROP_KEY_PREFIX . $ticketId . ':%')
            ))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, 'datetime')));
        return (int)$qb->executeQuery()->fetchOne();
    }

    /** Last digest send time for one (ticket,address), or null. */
    public function lastExternalDigestTime(int $ticketId, string $email): ?\DateTime
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->max('created_at'))->from($this->getTableName())
            ->where($qb->expr()->eq('to_email', $qb->createNamedParameter($email)))
            ->andWhere($qb->expr()->like(
                'idempotency_key',
                $qb->createNamedParameter(self::EXTERNAL_DIGEST_KEY_PREFIX . $ticketId . ':%')
            ));
        $v = $qb->executeQuery()->fetchOne();
        return is_string($v) && $v !== '' ? new \DateTime($v) : null;
    }

    /**
     * Ledger one digest send (dedupe anchor; excluded from the send cap).
     * The key is deterministic per (ticket,address,hour bucket) so the
     * hd_mbox_idem_uq unique index serialises racing senders at the write
     * boundary — the service-level lastExternalDigestTime check is only a
     * fast-path and cannot close the check-then-act race on its own.
     *
     * @return int|false|null row id, false when the unique index deduped
     * (a concurrent sender already ledgered this hour), null when the
     * insert yielded no int id
     */
    public function recordExternalLegDigest(int $ticketId, string $email): int|false|null
    {
        $row = new MailOutbox();
        $row->setIdempotencyKey(
            self::EXTERNAL_DIGEST_KEY_PREFIX . $ticketId . ':'
            . substr(hash('sha256', strtolower(trim($email))), 0, 32) . ':'
            . (new \DateTime())->format('YmdH')
        );
        $row->setToEmail($email);
        $row->setTemplate('ticket_external_digest');
        $row->setPayload('{}');
        $row->setStatus('sent');
        $row->setAttempts(1);
        $now = new \DateTime();
        $row->setSentAt($now);
        $row->setCreatedAt($now);
        try {
            $saved = $this->insert($row);
        } catch (\OCP\DB\Exception $e) {
            if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                return false;
            }
            throw $e;
        }
        $id = $saved->getId();
        return is_int($id) ? $id : null;
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

    /** @param list<string> $keys */
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

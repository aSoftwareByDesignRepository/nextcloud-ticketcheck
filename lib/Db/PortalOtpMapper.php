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

/** @template-extends QBMapper<PortalOtp> */
class PortalOtpMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_portal_otp', PortalOtp::class);
    }

    public function find(int $id): ?PortalOtp
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

    /** New send supersedes every prior unused code for this e-mail+purpose. */
    public function supersedeActive(string $email, string $purpose): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('superseded_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
            ->where($qb->expr()->eq('email', $qb->createNamedParameter($email)))
            ->andWhere($qb->expr()->eq('purpose', $qb->createNamedParameter($purpose)))
            ->andWhere($qb->expr()->isNull('used_at'))
            ->andWhere($qb->expr()->isNull('superseded_at'));
        $qb->executeStatement();
    }

    /** Active = unused, unsuperseded, unexpired. At most one by design. */
    public function findActive(string $email, string $purpose = 'login'): ?PortalOtp
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('email', $qb->createNamedParameter($email)))
            ->andWhere($qb->expr()->eq('purpose', $qb->createNamedParameter($purpose)))
            ->andWhere($qb->expr()->isNull('used_at'))
            ->andWhere($qb->expr()->isNull('superseded_at'))
            ->andWhere($qb->expr()->gte('expires_at', $qb->createNamedParameter(new \DateTime(), 'datetime')))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function findByMagicHash(string $hash): ?PortalOtp
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('magic_hash', $qb->createNamedParameter($hash)))
            ->andWhere($qb->expr()->isNull('used_at'))
            ->andWhere($qb->expr()->isNull('superseded_at'))
            ->andWhere($qb->expr()->gte('expires_at', $qb->createNamedParameter(new \DateTime(), 'datetime')));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function deleteByIdentityId(int $identityId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)));
        $qb->executeStatement();
    }

    public function deleteOlderThan(\DateTime $cutoff): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, 'datetime')));
        return $qb->executeStatement();
    }
}

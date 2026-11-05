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

/** @template-extends QBMapper<PortalSession> */
class PortalSessionMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_portal_session', PortalSession::class);
    }

    public function find(int $id): ?PortalSession
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

    public function findByTokenHash(string $hash): ?PortalSession
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($hash)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /** @return list<PortalSession> live sessions for one identity (sign-in page) */
    public function findLiveByIdentity(int $identityId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
            ->andWhere($qb->expr()->isNull('revoked_at'))
            ->andWhere($qb->expr()->gte('expires_at', $qb->createNamedParameter(new \DateTime(), 'datetime')))
            ->orderBy('last_seen_at', 'DESC');
        return $this->findEntities($qb);
    }

    public function revokeByIdentity(int $identityId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('revoked_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
            ->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
            ->andWhere($qb->expr()->isNull('revoked_at'));
        $qb->executeStatement();
    }

    public function deleteByIdentityId(int $identityId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)));
        $qb->executeStatement();
    }

    /** Expired/revoked sessions older than the retention cutoff. */
    public function deleteDeadOlderThan(\DateTime $cutoff): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->orX(
                $qb->expr()->lt('abs_expires_at', $qb->createNamedParameter($cutoff, 'datetime')),
                $qb->expr()->lt('revoked_at', $qb->createNamedParameter($cutoff, 'datetime'))
            ));
        return $qb->executeStatement();
    }

    /** @return list<PortalSession> */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
        return $this->findEntities($qb);
    }
}

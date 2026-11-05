<?php

declare(strict_types=1);

/**
 * Passwordless guest portal (spec: passwordless-guests-2026-11 §10.7).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<GuestDevice> */
class GuestDeviceMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_guest_device', GuestDevice::class);
    }

    public function findByTokenHash(string $hash): ?GuestDevice
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($hash)))
            ->andWhere($qb->expr()->isNull('revoked_at'))
            ->andWhere($qb->expr()->gte('expires_at', $qb->createNamedParameter(new \DateTime(), 'datetime')));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /** @return list<GuestDevice> */
    public function findByIdentityId(int $identityId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
            ->andWhere($qb->expr()->isNull('revoked_at'))
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

    public function deleteDeadOlderThan(\DateTime $cutoff): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->orX(
                $qb->expr()->lt('expires_at', $qb->createNamedParameter($cutoff, 'datetime')),
                $qb->expr()->lt('revoked_at', $qb->createNamedParameter($cutoff, 'datetime'))
            ));
        return $qb->executeStatement();
    }
}

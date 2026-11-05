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

/** @template-extends QBMapper<GuestAlias> */
class GuestAliasMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_guest_alias', GuestAlias::class);
    }

    public function findByUid(string $legacyUid): ?GuestAlias
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('legacy_uid', $qb->createNamedParameter($legacyUid)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /** @return list<GuestAlias> */
    public function findByIdentityId(int $identityId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)));
        return $this->findEntities($qb);
    }

    /** @return list<string> uids that already have an alias */
    public function aliasedUids(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('legacy_uid')->from($this->getTableName());
        $result = $qb->executeQuery();
        $uids = [];
        while (($r = $result->fetch()) !== false) {
            $uids[] = (string)$r['legacy_uid'];
        }
        $result->closeCursor();
        return $uids;
    }

    public function deleteByUid(string $legacyUid): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('legacy_uid', $qb->createNamedParameter($legacyUid)));
        $qb->executeStatement();
    }

    public function deleteByIdentityId(int $identityId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)));
        $qb->executeStatement();
    }

    /** @return list<GuestAlias> */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
        return $this->findEntities($qb);
    }
}

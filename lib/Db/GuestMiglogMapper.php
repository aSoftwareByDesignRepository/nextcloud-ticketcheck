<?php

declare(strict_types=1);

/**
 * Passwordless guest portal (spec: passwordless-guests-2026-11 §10.1).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<GuestMiglog> */
class GuestMiglogMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_guest_miglog', GuestMiglog::class);
    }

    public function log(?string $legacyUid, ?int $identityId, string $stage, array $detail, string $createdBy): GuestMiglog
    {
        $e = new GuestMiglog();
        $e->setLegacyUid($legacyUid);
        $e->setIdentityId($identityId);
        $e->setStage($stage);
        $e->setDetail(json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        $e->setCreatedAt(new \DateTime());
        $e->setCreatedBy($createdBy);
        return $this->insert($e);
    }

    /** @return list<GuestMiglog> newest first */
    public function forUid(string $legacyUid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('legacy_uid', $qb->createNamedParameter($legacyUid)))
            ->orderBy('id', 'DESC');
        return $this->findEntities($qb);
    }

    /** @return list<string> uids with at least one miglog row in $stage */
    public function uidsForStage(string $stage): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('legacy_uid')->from($this->getTableName())
            ->where($qb->expr()->eq('stage', $qb->createNamedParameter($stage)))
            ->andWhere($qb->expr()->isNotNull('legacy_uid'))
            ->groupBy('legacy_uid');
        $result = $qb->executeQuery();
        $uids = [];
        while (($r = $result->fetch()) !== false) {
            $uids[] = (string)$r['legacy_uid'];
        }
        $result->closeCursor();
        return $uids;
    }

    public function countForStage(string $stage): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))->from($this->getTableName())
            ->where($qb->expr()->eq('stage', $qb->createNamedParameter($stage)));
        return (int)$qb->executeQuery()->fetchOne();
    }

    /** @return list<GuestMiglog> newest first, capped */
    public function recent(int $limit = 200): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->orderBy('id', 'DESC')->setMaxResults($limit);
        return $this->findEntities($qb);
    }
}

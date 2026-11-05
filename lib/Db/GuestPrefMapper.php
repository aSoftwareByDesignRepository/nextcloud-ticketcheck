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

/** @template-extends QBMapper<GuestPref> */
class GuestPrefMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_guest_prefs', GuestPref::class);
    }

    /** @return array<string,string> pref_key => pref_value for one identity */
    public function mapForIdentity(int $identityId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('pref_key', 'pref_value')->from($this->getTableName())
            ->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)));
        $result = $qb->executeQuery();
        $map = [];
        while (($r = $result->fetch()) !== false) {
            $map[(string)$r['pref_key']] = (string)$r['pref_value'];
        }
        $result->closeCursor();
        return $map;
    }

    public function upsert(int $identityId, string $key, string $value): void
    {
        $now = new \DateTime();
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('pref_value', $qb->createNamedParameter($value))
            ->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
            ->andWhere($qb->expr()->eq('pref_key', $qb->createNamedParameter($key)));
        if ($qb->executeStatement() === 0) {
            $p = new GuestPref();
            $p->setIdentityId($identityId);
            $p->setPrefKey($key);
            $p->setPrefValue($value);
            try {
                $this->insert($p);
            } catch (\OCP\DB\Exception $e) {
                if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    throw $e;
                }
                // raced a concurrent upsert — unique key kept exactly one row
            }
        }
    }

    public function deleteByIdentityId(int $identityId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)));
        $qb->executeStatement();
    }
}

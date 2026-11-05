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

/** @template-extends QBMapper<GuestAudit> */
class GuestAuditMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_guest_audit', GuestAudit::class);
    }

    /** Audit rows never carry codes/tokens — caller passes scrubbed detail only. */
    public function log(string $actor, string $action, ?string $target = null, array $detail = []): GuestAudit
    {
        $e = new GuestAudit();
        $e->setActor($actor);
        $e->setAction($action);
        $e->setTarget($target);
        $e->setDetail($detail === [] ? null : (json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null));
        $e->setCreatedAt(new \DateTime());
        return $this->insert($e);
    }

    /** @return list<GuestAudit> newest first */
    public function recent(int $limit = 200, ?string $action = null): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->orderBy('id', 'DESC')->setMaxResults($limit);
        if ($action !== null) {
            $qb->where($qb->expr()->eq('action', $qb->createNamedParameter($action)));
        }
        return $this->findEntities($qb);
    }

    public function deleteOlderThan(\DateTime $cutoff): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, 'datetime')));
        return $qb->executeStatement();
    }
}

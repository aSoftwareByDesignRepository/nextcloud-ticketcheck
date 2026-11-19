<?php

declare(strict_types=1);

/**
 * Comment move audit mapper (spec comment-management §9.1).
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class CommentMoveMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_comment_moves', CommentMove::class);
    }

    /**
     * All audit rows where the ticket is source or target (system lines on
     * both sides of a move).
     *
     * @return CommentMove[]
     */
    public function findForTicket(int $ticketId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->orX(
                $qb->expr()->eq('source_ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)),
                $qb->expr()->eq('target_ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT))
            ))
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Insert one audit row per moved item in a batch.
     *
     * @param list<array{kind: string, id: int}> $items
     */
    public function insertBatch(string $batchId, int $sourceTicketId, int $targetTicketId, string $actorUid, array $items, \DateTime $createdAt): void
    {
        foreach ($items as $item) {
            $row = new CommentMove();
            $row->setBatchId($batchId);
            $row->setSourceTicketId($sourceTicketId);
            $row->setTargetTicketId($targetTicketId);
            $row->setItemKind((string)$item['kind']);
            $row->setItemId((int)$item['id']);
            $row->setActorUid($actorUid);
            $row->setCreatedAt($createdAt);
            $this->insert($row);
        }
    }

    /**
     * Uninstall cascade helper — ticket deletion drops its audit rows too.
     */
    public function deleteByTicketId(int $ticketId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->orX(
                $qb->expr()->eq('source_ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)),
                $qb->expr()->eq('target_ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT))
            ));
        return $qb->executeStatement();
    }
}

<?php

declare(strict_types=1);

/**
 * TicketLink mapper for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class TicketLinkMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_ticket_links', TicketLink::class);
    }

    public function find(int $id): TicketLink
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * @return TicketLink[]
     */
    public function findByTicketId(int $ticketId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Find links where ticket is either ticket_id or linked_ticket_id (bidirectional view)
     *
     * @return array{ticket_id: int, linked_ticket_id: int, link_type: string}[]
     */
    public function findBidirectionalForTicket(int $ticketId): array
    {
        $links = $this->findByTicketId($ticketId);
        $outbound = array_map(fn (TicketLink $l) => [
            'ticket_id' => $l->getTicketId(),
            'linked_ticket_id' => $l->getLinkedTicketId(),
            'link_type' => $l->getLinkType(),
        ], $links);

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('linked_ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
        $inbound = $this->findEntities($qb);
        foreach ($inbound as $l) {
            $outbound[] = [
                'ticket_id' => $l->getTicketId(),
                'linked_ticket_id' => $l->getLinkedTicketId(),
                'link_type' => $l->getLinkType() === TicketLink::TYPE_BLOCKS ? TicketLink::TYPE_BLOCKED_BY :
                    ($l->getLinkType() === TicketLink::TYPE_BLOCKED_BY ? TicketLink::TYPE_BLOCKS : $l->getLinkType()),
            ];
        }
        return $outbound;
    }

    public function exists(int $ticketId, int $linkedTicketId, string $linkType): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('1'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('linked_ticket_id', $qb->createNamedParameter($linkedTicketId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('link_type', $qb->createNamedParameter($linkType)));
        $result = $qb->executeQuery();
        $exists = $result->fetchOne() !== false;
        $result->closeCursor();
        return $exists;
    }

    public function deleteByTicketAndLinked(int $ticketId, int $linkedTicketId, string $linkType): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('linked_ticket_id', $qb->createNamedParameter($linkedTicketId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('link_type', $qb->createNamedParameter($linkType)));
        return $qb->executeStatement();
    }

    public function deleteById(int $id): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement();
    }

    /**
     * Delete every link that references the ticket on either side.
     */
    public function deleteByTicketIdEitherSide(int $ticketId): int
    {
        if ($ticketId <= 0) {
            return 0;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->orX(
                $qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)),
                $qb->expr()->eq('linked_ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT))
            ));
        return $qb->executeStatement();
    }

    public function countByTicketIdEitherSide(int $ticketId): int
    {
        if ($ticketId <= 0) {
            return 0;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->orX(
                $qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)),
                $qb->expr()->eq('linked_ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT))
            ));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * Re-point links from a merged source onto the survivor.
     * Drops self-loops and unique-constraint collisions instead of failing the merge.
     *
     * @return array{moved: int, dropped: int}
     */
    public function repointTicketOnMerge(int $sourceId, int $targetId): array
    {
        $moved = 0;
        $dropped = 0;
        if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
            return ['moved' => 0, 'dropped' => 0];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->orX(
                $qb->expr()->eq('ticket_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)),
                $qb->expr()->eq('linked_ticket_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT))
            ));
        /** @var TicketLink[] $links */
        $links = $this->findEntities($qb);

        foreach ($links as $link) {
            $newTicketId = ((int)$link->getTicketId() === $sourceId) ? $targetId : (int)$link->getTicketId();
            $newLinkedId = ((int)$link->getLinkedTicketId() === $sourceId) ? $targetId : (int)$link->getLinkedTicketId();
            $linkType = (string)$link->getLinkType();

            if ($newTicketId === $newLinkedId || $this->exists($newTicketId, $newLinkedId, $linkType)) {
                $this->delete($link);
                $dropped++;
                continue;
            }

            $link->setTicketId($newTicketId);
            $link->setLinkedTicketId($newLinkedId);
            $this->update($link);
            $moved++;
        }

        return ['moved' => $moved, 'dropped' => $dropped];
    }
}

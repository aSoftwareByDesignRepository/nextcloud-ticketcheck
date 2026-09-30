<?php

declare(strict_types=1);

/**
 * Ticket survey mapper for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Ticket survey mapper
 */
class TicketSurveyMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_ticket_surveys', TicketSurvey::class);
    }

    /**
     * Find survey by ticket ID
     *
     * @param int $ticketId
     * @return TicketSurvey|null
     */
    public function findByTicket(int $ticketId): ?TicketSurvey
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    public function deleteByTicketId(int $ticketId): int
    {
        if ($ticketId <= 0) {
            return 0;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement();
    }

    public function countByTicketId(int $ticketId): int
    {
        if ($ticketId <= 0) {
            return 0;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * On merge: keep the survivor's survey if present; otherwise move the source survey.
     *
     * @return array{moved: int, dropped: int}
     */
    public function moveOrDropOnMerge(int $sourceId, int $targetId): array
    {
        if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
            return ['moved' => 0, 'dropped' => 0];
        }

        $sourceSurvey = $this->findByTicket($sourceId);
        if ($sourceSurvey === null) {
            return ['moved' => 0, 'dropped' => 0];
        }

        if ($this->findByTicket($targetId) !== null) {
            $this->delete($sourceSurvey);
            return ['moved' => 0, 'dropped' => 1];
        }

        $sourceSurvey->setTicketId($targetId);
        $this->update($sourceSurvey);
        return ['moved' => 1, 'dropped' => 0];
    }

}

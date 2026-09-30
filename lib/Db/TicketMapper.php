<?php

declare(strict_types=1);

/**
 * Ticket mapper for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCA\Ticketcheck\Service\Export\ExportFilterParser;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Ticket mapper
 *
 * @extends QBMapper<Ticket>
 */
class TicketMapper extends QBMapper
{
    /** Statuses considered "done" (excluded from dashboard "my tickets" and "recent activity" by default) */
    public const DONE_STATUSES = ['done', 'resolved', 'closed'];

    /** Columns matched by ticket list / export text search (case-insensitive). */
    private const TEXT_SEARCH_COLUMNS = [
        'title',
        'description',
        'ticket_number',
        'customer_name',
        'customer_email',
    ];

    /**
     * Whether a raw DB status value should be treated as closed/done (includes legacy aliases).
     */
    public static function isDoneStatus(string $status): bool
    {
        $normalized = Ticket::normalizeStatus($status);
        if ($normalized === Ticket::STATUS_DONE) {
            return true;
        }

        return in_array(strtolower(trim($status)), self::DONE_STATUSES, true);
    }

    /**
     * Seconds from ticket creation to close, or null when not measurable.
     * Requires done status, a close timestamp, and a positive duration.
     */
    public static function computeResolutionSeconds(Ticket $ticket): ?int
    {
        if (!self::isDoneStatus((string)$ticket->getStatus())) {
            return null;
        }

        $closedAt = $ticket->getClosedAt();
        $createdAt = $ticket->getCreatedAt();
        if ($closedAt === null || $createdAt === null) {
            return null;
        }

        $seconds = $closedAt->getTimestamp() - $createdAt->getTimestamp();
        if ($seconds <= 0) {
            return null;
        }

        return $seconds;
    }

    /**
     * @param list<int> $resolutionTimes
     * @return array{resolution_sample_count: int, avg_resolution_time_hours: float, avg_resolution_time_days: float}
     */
    private static function summarizeResolutionTimes(array $resolutionTimes): array
    {
        $count = count($resolutionTimes);
        if ($count === 0) {
            return [
                'resolution_sample_count' => 0,
                'avg_resolution_time_hours' => 0,
                'avg_resolution_time_days' => 0,
            ];
        }

        $avgSeconds = array_sum($resolutionTimes) / $count;

        return [
            'resolution_sample_count' => $count,
            'avg_resolution_time_hours' => round($avgSeconds / 3600, 2),
            'avg_resolution_time_days' => round($avgSeconds / (3600 * 24), 2),
        ];
    }

    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_tickets', Ticket::class);
    }

    /**
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    private function findTicketEntity(IQueryBuilder $qb): Ticket
    {
        /** @var Ticket $entity */
        $entity = parent::findEntity($qb);
        return $entity;
    }

    /**
     * @return list<Ticket>
     */
    private function findTicketEntities(IQueryBuilder $qb): array
    {
        /** @var list<Ticket> $entities */
        $entities = parent::findEntities($qb);
        return $entities;
    }


    /**
     * Companion inbox page (cursor on updated_at DESC, id DESC). Caller filters visibility.
     *
     * @param list<string>|null $statuses
     * @return list<Ticket>
     */
    public function findCompanionInboxPage(
        ?array $statuses,
        ?string $assignedTo,
        ?string $updatedBefore,
        ?int $idBefore,
        int $limit,
        ?int $projectId = null,
        ?string $priority = null,
        ?string $watchedBy = null,
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('t.*')
            ->from($this->getTableName(), 't')
            ->where($qb->expr()->isNull('t.merged_into_id'));

        if ($watchedBy !== null && $watchedBy !== '') {
            $qb->innerJoin(
                't',
                'helpdesk_ticket_watchers',
                'w',
                $qb->expr()->andX(
                    $qb->expr()->eq('w.ticket_id', 't.id'),
                    $qb->expr()->eq('w.user_id', $qb->createNamedParameter($watchedBy)),
                )
            );
        }

        if ($statuses !== null && $statuses !== []) {
            $qb->andWhere($qb->expr()->in('t.status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)));
        }
        if ($assignedTo !== null && $assignedTo !== '') {
            $qb->andWhere($qb->expr()->eq('t.assigned_to', $qb->createNamedParameter($assignedTo)));
        }
        if ($projectId !== null && $projectId > 0) {
            $qb->andWhere($qb->expr()->eq('t.project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));
        }
        if ($priority !== null && $priority !== '') {
            $qb->andWhere($qb->expr()->eq('t.priority', $qb->createNamedParameter($priority)));
        }
        if ($updatedBefore !== null && $idBefore !== null) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->lt('t.updated_at', $qb->createNamedParameter($updatedBefore)),
                    $qb->expr()->andX(
                        $qb->expr()->eq('t.updated_at', $qb->createNamedParameter($updatedBefore)),
                        $qb->expr()->lt('t.id', $qb->createNamedParameter($idBefore, IQueryBuilder::PARAM_INT)),
                    ),
                )
            );
        }

        $qb->orderBy('t.updated_at', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults($limit);

        return $this->findTicketEntities($qb);
    }

    /**
     * Companion queue count (agents/admins see all tickets, so no per-row ACL pass).
     *
     * @param list<string>|null $statuses
     */
    public function countCompanionQueue(?array $statuses, ?string $assignedTo, ?string $watchedBy): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName(), 't')
            ->where($qb->expr()->isNull('t.merged_into_id'));

        if ($watchedBy !== null && $watchedBy !== '') {
            $qb->innerJoin(
                't',
                'helpdesk_ticket_watchers',
                'w',
                $qb->expr()->andX(
                    $qb->expr()->eq('w.ticket_id', 't.id'),
                    $qb->expr()->eq('w.user_id', $qb->createNamedParameter($watchedBy)),
                )
            );
        }
        if ($statuses !== null && $statuses !== []) {
            $qb->andWhere($qb->expr()->in('t.status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)));
        }
        if ($assignedTo !== null && $assignedTo !== '') {
            $qb->andWhere($qb->expr()->eq('t.assigned_to', $qb->createNamedParameter($assignedTo)));
        }

        $result = $qb->executeQuery();
        $count = (int)($result->fetchOne() ?: 0);
        $result->closeCursor();
        return $count;
    }

    /**
     * Find ticket by ID
     *
     * @param int $id
     * @return Ticket
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function find(int $id): Ticket
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findTicketEntity($qb);
    }

    /**
     * Ticket ids that were merged into $survivorId (merge shells).
     *
     * @return list<int>
     */
    public function findIdsMergedInto(int $survivorId): array
    {
        if ($survivorId <= 0) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq(
                'merged_into_id',
                $qb->createNamedParameter($survivorId, IQueryBuilder::PARAM_INT)
            ));

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $result->closeCursor();

        return $ids;
    }

    public function countMergedInto(int $survivorId): int
    {
        if ($survivorId <= 0) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq(
                'merged_into_id',
                $qb->createNamedParameter($survivorId, IQueryBuilder::PARAM_INT)
            ));

        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }

    /**
     * Shell ticket ids whose merged_into_id points at a missing ticket.
     *
     * @return list<int>
     */
    public function findDanglingMergeShellIds(int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));
        $qb = $this->db->getQueryBuilder();
        $qb->select('t.id')
            ->from($this->getTableName(), 't')
            ->leftJoin('t', $this->getTableName(), 's', $qb->expr()->eq('t.merged_into_id', 's.id'))
            ->where($qb->expr()->isNotNull('t.merged_into_id'))
            ->andWhere($qb->expr()->isNull('s.id'))
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * Find ticket by ticket number
     *
     * @param string $ticketNumber
     * @return Ticket
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function findByTicketNumber(string $ticketNumber): Ticket
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('ticket_number', $qb->createNamedParameter($ticketNumber)));
        return $this->findTicketEntity($qb);
    }

    /**
     * Find all tickets
     *
     * @param int|null $limit
     * @param int|null $offset
     * @return Ticket[]
     */
    /**
     * @param int|null $limit
     * @param int|null $offset
     * @param bool $excludeDone If true, exclude tickets with status done/resolved/closed (for dashboard recent activity)
     * @return Ticket[]
     */
    public function findAll(?int $limit = null, ?int $offset = null, bool $excludeDone = false): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from($this->getTableName())
                ->orderBy('created_at', 'DESC');

            if ($excludeDone) {
                $qb->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(self::DONE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)));
            }
            if ($limit !== null) {
                $qb->setMaxResults($limit);
            }
            if ($offset !== null) {
                $qb->setFirstResult($offset);
            }

            return $this->findTicketEntities($qb);
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Find tickets by customer email
     *
     * @param string $customerEmail
     * @param int $limit Maximum number of tickets to return (default 100)
     * @return Ticket[]
     */
    public function findByCustomerEmail(string $customerEmail, int $limit = 100): array
    {
        $normalized = strtolower(trim($customerEmail));
        if ($normalized === '') {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq(
                $qb->func()->lower('customer_email'),
                $qb->createNamedParameter($normalized)
            ))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findTicketEntities($qb);
    }

    /**
     * Count tickets for a customer email (sidebar footer / lightweight summaries).
     */
    public function countByCustomerEmail(string $customerEmail): int
    {
        $normalized = strtolower(trim($customerEmail));
        if ($normalized === '') {
            return 0;
        }
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from($this->getTableName())
                ->where($qb->expr()->eq(
                    $qb->func()->lower('customer_email'),
                    $qb->createNamedParameter($normalized)
                ));
            $result = $qb->executeQuery();
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            return $count;
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * Count open tickets (anything not in DONE_STATUSES) for a customer email.
     */
    public function countOpenByCustomerEmail(string $customerEmail): int
    {
        $normalized = strtolower(trim($customerEmail));
        if ($normalized === '') {
            return 0;
        }
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from($this->getTableName())
                ->where($qb->expr()->eq(
                    $qb->func()->lower('customer_email'),
                    $qb->createNamedParameter($normalized)
                ))
                ->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(self::DONE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)));
            $result = $qb->executeQuery();
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            return $count;
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * Count tickets a guest can see — mirrors PermissionService::canViewTicketWithProjectAccess
     * for guests: own tickets without a project or in accessible projects
     * (created_by match), plus tickets in accessible projects addressed to the
     * guest's customer_email.
     *
     * @param string $userId guest uid
     * @param string $customerEmail guest email (matched case-insensitively)
     * @param list<int> $projectIds projects the guest may access
     * @param bool $openOnly exclude DONE_STATUSES when true
     */
    public function countGuestScope(string $userId, string $customerEmail, array $projectIds, bool $openOnly = false): int
    {
        $userId = trim($userId);
        $normalized = strtolower(trim($customerEmail));
        $projectIds = array_values(array_unique(array_map('intval', $projectIds)));

        if ($userId === '' && ($normalized === '' || $projectIds === [])) {
            return 0;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $expr = $qb->expr();
            $or = $expr->orX();

            // Own tickets: created_by match requires no project or an accessible one.
            if ($userId !== '') {
                $ownProjectScope = $projectIds === []
                    ? $expr->isNull('project_id')
                    : $expr->orX(
                        $expr->isNull('project_id'),
                        $expr->in('project_id', $qb->createNamedParameter($projectIds, IQueryBuilder::PARAM_INT_ARRAY))
                    );
                $or->add($expr->andX(
                    $expr->eq('created_by', $qb->createNamedParameter($userId)),
                    $ownProjectScope
                ));
            }

            // Tickets addressed to the guest inside accessible projects only.
            if ($normalized !== '' && $projectIds !== []) {
                $or->add($expr->andX(
                    $expr->eq($qb->func()->lower('customer_email'), $qb->createNamedParameter($normalized)),
                    $expr->in('project_id', $qb->createNamedParameter($projectIds, IQueryBuilder::PARAM_INT_ARRAY))
                ));
            }

            $qb->select($qb->createFunction('COUNT(*)'))
                ->from($this->getTableName())
                ->where($or);
            if ($openOnly) {
                $qb->andWhere($expr->notIn('status', $qb->createNamedParameter(self::DONE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)));
            }
            $result = $qb->executeQuery();
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            return $count;
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * Count open tickets (status not in DONE_STATUSES) across the whole instance for the staff sidebar.
     */
    public function countOpenAllTickets(): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from($this->getTableName())
                ->where($qb->expr()->notIn('status', $qb->createNamedParameter(self::DONE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)));
            $result = $qb->executeQuery();
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            return $count;
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * Total ticket count (single query for nav footer).
     */
    public function countAllTickets(): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from($this->getTableName());
            $result = $qb->executeQuery();
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            return $count;
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * Find tickets by project IDs (for multi-project guest access).
     *
     * @param list<int> $projectIds
     * @param int $limit Cap rows (0 = no limit). Prefer a positive limit on portal paths.
     * @return list<Ticket>
     */
    public function findByProjects(array $projectIds, int $limit = 0): array
    {
        if (empty($projectIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('project_id', $qb->createNamedParameter($projectIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('created_at', 'DESC');
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }
        return $this->findTicketEntities($qb);
    }

    /**
     * Atomically mark a ticket as merged if it is still unmerged.
     * Returns true only when this call won the race (exactly one row updated).
     */
    public function markMergedIfUnmerged(int $sourceId, int $targetId): bool
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('merged_into_id', $qb->createNamedParameter($targetId, IQueryBuilder::PARAM_INT))
            ->set('status', $qb->createNamedParameter(Ticket::STATUS_DONE))
            ->set('updated_at', $qb->createNamedParameter($now))
            ->set('closed_at', $qb->createNamedParameter($now))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('merged_into_id'));

        return $qb->executeStatement() === 1;
    }

    /**
     * Update dirty entity fields only while the ticket is still unmerged.
     * Prevents concurrent merge from being clobbered (e.g. status re-opened on a merged row).
     *
     * @return bool true when exactly one row was updated
     */
    public function updateIfUnmerged(Ticket $ticket): bool
    {
        $properties = $ticket->getUpdatedFields();
        if ($properties === []) {
            try {
                $fresh = $this->find((int) $ticket->getId());
            } catch (\Throwable $e) {
                return false;
            }
            return $fresh->getMergedIntoId() === null;
        }

        $id = $ticket->getId();
        if ($id === null) {
            throw new \InvalidArgumentException('Entity which should be updated has no id');
        }

        unset($properties['id']);

        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName());

        foreach ($properties as $property => $updated) {
            $column = $ticket->propertyToColumn($property);
            $getter = 'get' . ucfirst($property);
            $value = $ticket->$getter();
            $type = $this->parameterTypeForProperty($ticket, $property);
            $qb->set($column, $qb->createNamedParameter($value, $type));
        }

        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('merged_into_id'));

        return $qb->executeStatement() === 1;
    }

    /**
     * QBMapper::getParameterTypeForProperty() has no declared return type, so
     * validate its result against the PARAM_* constants accepted by
     * createNamedParameter(); anything unexpected safely binds as a string.
     *
     * @return IQueryBuilder::PARAM_*
     */
    private function parameterTypeForProperty(Ticket $ticket, string $property): int|string
    {
        $type = $this->getParameterTypeForProperty($ticket, $property);

        return match ($type) {
            IQueryBuilder::PARAM_NULL,
            IQueryBuilder::PARAM_BOOL,
            IQueryBuilder::PARAM_INT,
            IQueryBuilder::PARAM_LOB,
            IQueryBuilder::PARAM_TIME_MUTABLE,
            IQueryBuilder::PARAM_DATE_MUTABLE,
            IQueryBuilder::PARAM_DATETIME_MUTABLE,
            IQueryBuilder::PARAM_DATETIME_TZ_MUTABLE,
            IQueryBuilder::PARAM_DATE_IMMUTABLE,
            IQueryBuilder::PARAM_DATETIME_IMMUTABLE,
            IQueryBuilder::PARAM_DATETIME_TZ_IMMUTABLE,
            IQueryBuilder::PARAM_JSON => $type,
            default => IQueryBuilder::PARAM_STR,
        };
    }

    /**
     * Bump updated_at only if the ticket is still the surviving (unmerged) row.
     */
    public function touchUpdatedAtIfUnmerged(int $ticketId): bool
    {
        if ($ticketId <= 0) {
            return false;
        }
        try {
            $ticket = $this->find($ticketId);
        } catch (\Throwable) {
            return false;
        }
        if ($ticket->getMergedIntoId() !== null) {
            return false;
        }
        $ticket->setUpdatedAt(Ticket::nextUpdatedAt($ticket->getUpdatedAt()));
        return $this->updateIfUnmerged($ticket);
    }

    /**
     * Close a ticket for split only when it is still open and unmerged.
     */
    public function closeIfOpenAndUnmerged(int $ticketId): bool
    {
        if ($ticketId <= 0) {
            return false;
        }
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter(Ticket::STATUS_DONE))
            ->set('closed_at', $qb->createNamedParameter($now))
            ->set('updated_at', $qb->createNamedParameter($now))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('merged_into_id'))
            ->andWhere($qb->expr()->notIn(
                'status',
                $qb->createNamedParameter(self::DONE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)
            ));

        return $qb->executeStatement() === 1;
    }

    /**
     * Persist SLA breach-alert timestamps without rewriting unrelated ticket columns.
     */
    public function stampSlaAlertTimestamps(int $ticketId, bool $response, bool $resolution, \DateTimeInterface $at): bool
    {
        if ($ticketId <= 0 || (!$response && !$resolution)) {
            return false;
        }
        $formatted = $at->format('Y-m-d H:i:s');
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName());
        if ($response) {
            $qb->set('sla_response_alerted_at', $qb->createNamedParameter($formatted));
        }
        if ($resolution) {
            $qb->set('sla_resolution_alerted_at', $qb->createNamedParameter($formatted));
        }
        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement() === 1;
    }

    /**
     * Find tickets by customer ID
     *
     * @param int $customerId
     * @return Ticket[]
     */
    public function findByCustomerId(int $customerId, int $limit = 0): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('customer_id', $qb->createNamedParameter($customerId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC');
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }
        return $this->findTicketEntities($qb);
    }

    /**
     * Find tickets by project ID
     *
     * @param int $projectId
     * @param int $limit Maximum rows (default 100). Pass 0 for no LIMIT (cascade delete / full counts).
     * @return Ticket[]
     */
    public function findByProjectId(int $projectId, int $limit = 100): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC');
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }
        return $this->findTicketEntities($qb);
    }

    /**
     * Find tickets assigned to a user
     *
     * @param string $userId
     * @param int $limit Maximum number of tickets to return (default 100)
     * @param bool $excludeDone If true, exclude tickets with status done/resolved/closed (for dashboard "my tickets")
     * @return Ticket[]
     */
    public function findByAssignedTo(string $userId, int $limit = 100, bool $excludeDone = false): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('assigned_to', $qb->createNamedParameter($userId)));
        if ($excludeDone) {
            $qb->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(self::DONE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)));
        }
        $qb->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findTicketEntities($qb);
    }

    /**
     * Find tickets by status (supports both string and array)
     *
     * @param string|array $status
     * @param int $limit Maximum number of tickets to return (default 100)
     * @return Ticket[]
     */
    /**
     * @param string|list<string> $status
     * @return list<Ticket>
     */
    public function findByStatus($status, int $limit = 100): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        if (is_array($status)) {
            $qb->where($qb->expr()->in('status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            $qb->where($qb->expr()->eq('status', $qb->createNamedParameter($status)));
        }

        $qb->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findTicketEntities($qb);
    }

    /**
     * All non-done tickets (any open/legacy status), excluding rows merged into another ticket.
     *
     * @return list<Ticket>
     */
    public function findOpenTickets(int $limit = 5000): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->notIn('status', $qb->createNamedParameter(self::DONE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->isNull('merged_into_id'))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);

        return $this->findTicketEntities($qb);
    }

    /**
     * @return list<Ticket>
     */
    public function findOpenForSlaMonitoring(int $limit = 5000): array
    {
        return $this->findOpenTickets($limit);
    }

    /**
     * Find tickets created since a specific date/time
     *
     * @param \DateTime $since
     * @param int $limit Maximum number of tickets to return (default 100)
     * @return Ticket[]
     */
    public function findCreatedSince(\DateTime $since, int $limit = 100): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATE)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findTicketEntities($qb);
    }

    /**
     * Find tickets updated since a specific date/time
     *
     * @param \DateTime $since
     * @param int $limit Maximum number of tickets to return (default 100)
     * @return Ticket[]
     */
    public function findUpdatedSince(\DateTime $since, int $limit = 100): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->gte('updated_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATE)))
            ->orderBy('updated_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findTicketEntities($qb);
    }

    /**
     * Find old open tickets (not done, created before a specific date)
     *
     * @param \DateTime $before
     * @param int $limit Maximum number of tickets to return (default 100)
     * @return Ticket[]
     */
    public function findOldOpenTickets(\DateTime $before, int $limit = 100): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->lte('created_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATE)))
            ->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(self::DONE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('created_at', 'ASC')
            ->setMaxResults($limit);
        return $this->findTicketEntities($qb);
    }

    /**
     * Find stalled urgent tickets (urgent priority, no updates for 2+ days, not done)
     *
     * @param \DateTime $before
     * @param int $limit Maximum number of tickets to return (default 100)
     * @return Ticket[]
     */
    public function findStalledUrgentTickets(\DateTime $before, int $limit = 100): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('priority', $qb->createNamedParameter('urgent')))
            ->andWhere($qb->expr()->lte('updated_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATE)))
            ->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(self::DONE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('updated_at', 'ASC')
            ->setMaxResults($limit);
        return $this->findTicketEntities($qb);
    }

    /**
     * Find tickets resolved since a specific date/time
     *
     * @param \DateTime $since
     * @param int $limit Maximum number of tickets to return (default 100)
     * @return Ticket[]
     */
    public function findResolvedSince(\DateTime $since, int $limit = 100): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('done')))
            ->andWhere($qb->expr()->gte('updated_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATE)))
            ->orderBy('updated_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findTicketEntities($qb);
    }

    /**
     * Search tickets by query
     *
     * @param string $query
     * @param array $filters
     * @return Ticket[]
     */
    /**
     * @param array{
     *   status?: string,
     *   status_in?: list<string>,
     *   status_not?: string,
     *   priority?: string,
     *   category?: string,
     *   assigned_to?: string,
     *   project_id?: int,
     *   customer_id?: int
     * } $filters
     * @return list<Ticket>
     */
    public function search(string $query, array $filters = []): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        $this->applyTextSearchConditions($qb, $query);

        // Apply filters
        if (isset($filters['status'])) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($filters['status'])));
        }
        // Support filtering by multiple statuses (e.g. open group)
        if (isset($filters['status_in']) && !empty($filters['status_in'])) {
            $qb->andWhere(
                $qb->expr()->in(
                    'status',
                    $qb->createNamedParameter($filters['status_in'], IQueryBuilder::PARAM_STR_ARRAY)
                )
            );
        }
        // Support excluding a specific status (e.g. hide 'done' tickets)
        if (isset($filters['status_not'])) {
            $qb->andWhere($qb->expr()->neq('status', $qb->createNamedParameter($filters['status_not'])));
        }
        if (isset($filters['priority'])) {
            $qb->andWhere($qb->expr()->eq('priority', $qb->createNamedParameter($filters['priority'])));
        }
        if (isset($filters['category'])) {
            $qb->andWhere($qb->expr()->eq('category', $qb->createNamedParameter($filters['category'])));
        }
        if (isset($filters['assigned_to'])) {
            $this->applyAssignedToFilter($qb, (string)$filters['assigned_to']);
        }
        if (isset($filters['project_id'])) {
            $qb->andWhere($qb->expr()->eq('project_id', $qb->createNamedParameter($filters['project_id'], IQueryBuilder::PARAM_INT)));
        }
        if (isset($filters['customer_id'])) {
            $qb->andWhere($qb->expr()->eq('customer_id', $qb->createNamedParameter($filters['customer_id'], IQueryBuilder::PARAM_INT)));
        }

        $qb->orderBy('created_at', 'DESC');
        return $this->findTicketEntities($qb);
    }

    /**
     * Search eligible merge target candidates by number, title, customer name, or email.
     *
     * Permission checks are intentionally left to the controller/service layer because
     * project access depends on the current user.
     *
     * @param string $query
     * @param int $excludeTicketId
     * @param int $limit
     * @return Ticket[]
     */
    public function searchMergeTargets(string $query, int $excludeTicketId, int $limit = 10): array
    {
        $query = $this->normalizeTicketSearchQuery($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min($limit, 25));
        $like = $this->wrapLikeParameter($query);

        $qb = $this->db->getQueryBuilder();
        $searchConditions = [];
        foreach (self::TEXT_SEARCH_COLUMNS as $column) {
            $searchConditions[] = $qb->expr()->iLike($column, $qb->createNamedParameter($like));
        }
        if (ctype_digit($query)) {
            $searchConditions[] = $qb->expr()->eq('id', $qb->createNamedParameter((int)$query, IQueryBuilder::PARAM_INT));
        }

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->neq('id', $qb->createNamedParameter($excludeTicketId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('merged_into_id'))
            ->andWhere($qb->expr()->orX(...$searchConditions))
            ->orderBy('updated_at', 'DESC')
            ->setMaxResults($limit);

        return $this->findTicketEntities($qb);
    }

    /**
     * Find tickets matching escalation rule conditions
     *
     * @param int $ageHours Hours since last update (tickets updated_at <= now - ageHours)
     * @param string|null $minPriority Minimum priority (low, normal, high, urgent) - tickets with this or higher
     * @param array $statuses Ticket statuses to match (empty = all non-done)
     * @param int $limit Maximum number of tickets to return
     * @return Ticket[]
     */
    /**
     * @param list<string> $statuses
     * @return list<Ticket>
     */
    public function findMatchingForEscalation(int $ageHours, ?string $minPriority, array $statuses, int $limit = 500): array
    {
        $threshold = (new \DateTime())->modify('-' . $ageHours . ' hours');
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->lte('updated_at', $qb->createNamedParameter($threshold, IQueryBuilder::PARAM_DATE)))
            ->andWhere($qb->expr()->isNull('merged_into_id'));

        if (!empty($statuses)) {
            $qb->andWhere($qb->expr()->in('status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            $qb->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(self::DONE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)));
        }

        $priorityOrder = ['low' => 1, 'normal' => 2, 'high' => 3, 'urgent' => 4];
        if ($minPriority !== null && isset($priorityOrder[$minPriority])) {
            $minLevel = $priorityOrder[$minPriority];
            $allowedPriorities = array_keys(array_filter($priorityOrder, fn ($v) => $v >= $minLevel));
            $qb->andWhere($qb->expr()->in('priority', $qb->createNamedParameter($allowedPriorities, IQueryBuilder::PARAM_STR_ARRAY)));
        }

        $qb->orderBy('updated_at', 'ASC')
            ->setMaxResults($limit);
        return $this->findTicketEntities($qb);
    }

    /**
     * Filter by assignee. The sentinel value {@see ASSIGNED_TO_UNASSIGNED} matches
     * NULL or empty string — same rule as the ticket list URL `?assigned_to=unassigned`.
     */
    public const ASSIGNED_TO_UNASSIGNED = 'unassigned';

    /**
     * @param IQueryBuilder $qb
     */
    private function applyAssignedToFilter(IQueryBuilder $qb, string $assignedTo): void
    {
        if ($assignedTo === self::ASSIGNED_TO_UNASSIGNED) {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('assigned_to'),
                $qb->expr()->eq('assigned_to', $qb->createNamedParameter('')),
            ));
            return;
        }
        $qb->andWhere($qb->expr()->eq('assigned_to', $qb->createNamedParameter($assignedTo)));
    }

    /**
     * Active tickets (not done/resolved/closed) with no assignee.
     */
    public function countActiveUnassigned(): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
                ->from($this->getTableName())
                ->where($qb->expr()->orX(
                    $qb->expr()->isNull('assigned_to'),
                    $qb->expr()->eq('assigned_to', $qb->createNamedParameter('')),
                ))
                ->andWhere($qb->expr()->notIn(
                    'status',
                    $qb->createNamedParameter(self::DONE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY),
                ));
            $result = $qb->executeQuery();
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            return $count;
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * Active unassigned tickets that are no longer in status {@see Ticket::STATUS_NEW}.
     */
    public function countActiveUnassignedExcludingStatusNew(): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
                ->from($this->getTableName())
                ->where($qb->expr()->orX(
                    $qb->expr()->isNull('assigned_to'),
                    $qb->expr()->eq('assigned_to', $qb->createNamedParameter('')),
                ))
                ->andWhere($qb->expr()->notIn(
                    'status',
                    $qb->createNamedParameter(
                        array_merge(self::DONE_STATUSES, [Ticket::STATUS_NEW]),
                        IQueryBuilder::PARAM_STR_ARRAY,
                    ),
                ));
            $result = $qb->executeQuery();
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            return $count;
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * New tickets that still need an assignee (dashboard "review and assign" alert).
     */
    public function countNewUnassigned(): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
                ->from($this->getTableName())
                ->where($qb->expr()->eq('status', $qb->createNamedParameter(Ticket::STATUS_NEW)))
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->isNull('assigned_to'),
                    $qb->expr()->eq('assigned_to', $qb->createNamedParameter('')),
                ));
            $result = $qb->executeQuery();
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            return $count;
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * Get ticket statistics
     *
     * @return array
     */
    /**
     * @return array{
     *   total: int,
     *   by_status: array<string, int>,
     *   by_priority: array<string, int>
     * }
     */
    public function getStatistics(): array
    {
        try {
            $qb = $this->db->getQueryBuilder();

            // Total tickets
            $qb->select($qb->createFunction('COUNT(*) as total'))
                ->from($this->getTableName());
            $result = $qb->executeQuery();
            $total = (int)$result->fetchOne();
            $result->closeCursor();

            // Tickets by status
            $qb = $this->db->getQueryBuilder();
            $qb->select('status', $qb->createFunction('COUNT(*) as count'))
                ->from($this->getTableName())
                ->groupBy('status');
            $result = $qb->executeQuery();
            $byStatus = [];
            while ($row = $result->fetch()) {
                $byStatus[$row['status']] = (int)$row['count'];
            }
            $result->closeCursor();

            // Tickets by priority
            $qb = $this->db->getQueryBuilder();
            $qb->select('priority', $qb->createFunction('COUNT(*) as count'))
                ->from($this->getTableName())
                ->groupBy('priority');
            $result = $qb->executeQuery();
            $byPriority = [];
            while ($row = $result->fetch()) {
                $byPriority[$row['priority']] = (int)$row['count'];
            }
            $result->closeCursor();

            return [
                'total' => $total,
                'by_status' => $byStatus,
                'by_priority' => $byPriority,
            ];
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return [
                    'total' => 0,
                    'by_status' => [],
                    'by_priority' => [],
                ];
            }
            throw $e;
        }
    }

    /**
     * Count tickets created by a guest user
     *
     * @param string $userId
     * @param \DateTime $since
     * @return int
     */
    public function countRecentByUser(string $userId, \DateTime $since): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('created_by', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATE)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * Get customer analytics including time-to-resolution metrics
     *
     * @param int $customerId
     * @return array
     */
    /**
     * @return array<string, mixed>
     */
    public function getCustomerAnalytics(int $customerId): array
    {
        // Get all tickets for this customer
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('customer_id', $qb->createNamedParameter($customerId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC');
        $tickets = $this->findTicketEntities($qb);

        $analytics = [
            'total_tickets' => count($tickets),
            'open_tickets' => 0,
            'resolved_tickets' => 0,
            'closed_tickets' => 0,
            'avg_resolution_time_hours' => 0,
            'avg_resolution_time_days' => 0,
            'resolution_sample_count' => 0,
            'first_response_time_hours' => 0,
            'resolution_times' => [],
            'by_priority' => [],
            'by_status' => [],
            'by_category' => [],
            'recent_activity' => []
        ];

        $resolutionTimes = [];
        $responseTimes = [];

        foreach ($tickets as $ticket) {
            // Count by status
            $status = $ticket->getStatus();
            $analytics['by_status'][$status] = ($analytics['by_status'][$status] ?? 0) + 1;

            if (in_array($status, ['resolved', 'closed', 'done'])) {
                $analytics['resolved_tickets']++;
            } elseif (in_array($status, ['new', 'open', 'in_progress', 'waiting_customer', 'working', 'waiting'])) {
                $analytics['open_tickets']++;
            }

            // Count by priority
            $priority = $ticket->getPriority();
            $analytics['by_priority'][$priority] = ($analytics['by_priority'][$priority] ?? 0) + 1;

            // Count by category
            $category = $ticket->getCategory();
            $analytics['by_category'][$category] = ($analytics['by_category'][$category] ?? 0) + 1;

            $resolutionSeconds = self::computeResolutionSeconds($ticket);
            if ($resolutionSeconds !== null) {
                $resolutionTimes[] = $resolutionSeconds;
                $createdAt = $ticket->getCreatedAt();
                $closedAt = $ticket->getClosedAt();
                if ($createdAt === null || $closedAt === null) {
                    continue;
                }
                $analytics['resolution_times'][] = [
                    'ticket_id' => $ticket->getId(),
                    'ticket_number' => $ticket->getTicketNumber(),
                    'title' => $ticket->getTitle(),
                    'resolution_time_hours' => round($resolutionSeconds / 3600, 2),
                    'resolution_time_days' => round($resolutionSeconds / (3600 * 24), 2),
                    'created_at' => $createdAt->format('Y-m-d H:i:s'),
                    'closed_at' => $closedAt->format('Y-m-d H:i:s'),
                ];
            }

            // Add to recent activity (last 10)
            if (count($analytics['recent_activity']) < 10) {
                $analytics['recent_activity'][] = [
                    'ticket_id' => $ticket->getId(),
                    'ticket_number' => $ticket->getTicketNumber(),
                    'title' => $ticket->getTitle(),
                    'status' => $status,
                    'priority' => $priority,
                    'created_at' => $ticket->getCreatedAt()->format('Y-m-d H:i:s'),
                    'updated_at' => $ticket->getUpdatedAt()->format('Y-m-d H:i:s')
                ];
            }
        }

        $analytics = array_merge($analytics, self::summarizeResolutionTimes($resolutionTimes));

        return $analytics;
    }

    /**
     * Get project analytics including time-to-resolution metrics
     *
     * @param int $projectId
     * @return array
     */
    /**
     * @return array<string, mixed>
     */
    public function getProjectAnalytics(int $projectId): array
    {
        // Get all tickets for this project
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC');
        $tickets = $this->findTicketEntities($qb);

        $analytics = [
            'total_tickets' => count($tickets),
            'open_tickets' => 0,
            'resolved_tickets' => 0,
            'closed_tickets' => 0,
            'avg_resolution_time_hours' => 0,
            'avg_resolution_time_days' => 0,
            'resolution_sample_count' => 0,
            'sla_compliance_rate' => 0,
            'resolution_times' => [],
            'by_priority' => [],
            'by_status' => [],
            'by_category' => [],
            'by_assignee' => [],
            'recent_activity' => [],
            'sla_metrics' => []
        ];

        $resolutionTimes = [];
        $slaCompliant = 0;
        $slaTotal = 0;

        foreach ($tickets as $ticket) {
            // Count by status
            $status = $ticket->getStatus();
            $analytics['by_status'][$status] = ($analytics['by_status'][$status] ?? 0) + 1;

            if (in_array($status, ['resolved', 'closed', 'done'])) {
                $analytics['resolved_tickets']++;
            } elseif (in_array($status, ['new', 'open', 'in_progress', 'waiting_customer', 'working', 'waiting'])) {
                $analytics['open_tickets']++;
            }

            // Count by priority
            $priority = $ticket->getPriority();
            $analytics['by_priority'][$priority] = ($analytics['by_priority'][$priority] ?? 0) + 1;

            // Count by category
            $category = $ticket->getCategory();
            $analytics['by_category'][$category] = ($analytics['by_category'][$category] ?? 0) + 1;

            // Count by assignee
            $assignedTo = $ticket->getAssignedTo();
            if ($assignedTo) {
                $analytics['by_assignee'][$assignedTo] = ($analytics['by_assignee'][$assignedTo] ?? 0) + 1;
            }

            $resolutionSeconds = self::computeResolutionSeconds($ticket);
            if ($resolutionSeconds !== null) {
                $resolutionTimes[] = $resolutionSeconds;
                $createdAt = $ticket->getCreatedAt();
                $closedAt = $ticket->getClosedAt();
                if ($createdAt === null || $closedAt === null) {
                    continue;
                }

                $analytics['resolution_times'][] = [
                    'ticket_id' => $ticket->getId(),
                    'ticket_number' => $ticket->getTicketNumber(),
                    'title' => $ticket->getTitle(),
                    'resolution_time_hours' => round($resolutionSeconds / 3600, 2),
                    'resolution_time_days' => round($resolutionSeconds / (3600 * 24), 2),
                    'created_at' => $createdAt->format('Y-m-d H:i:s'),
                    'closed_at' => $closedAt->format('Y-m-d H:i:s'),
                ];

                if ($ticket->getSlaResolutionDue()) {
                    $slaTotal++;
                    if ($closedAt <= $ticket->getSlaResolutionDue()) {
                        $slaCompliant++;
                    }
                }
            }

            // Add to recent activity (last 10)
            if (count($analytics['recent_activity']) < 10) {
                $analytics['recent_activity'][] = [
                    'ticket_id' => $ticket->getId(),
                    'ticket_number' => $ticket->getTicketNumber(),
                    'title' => $ticket->getTitle(),
                    'status' => $status,
                    'priority' => $priority,
                    'assigned_to' => $assignedTo,
                    'created_at' => $ticket->getCreatedAt()->format('Y-m-d H:i:s'),
                    'updated_at' => $ticket->getUpdatedAt()->format('Y-m-d H:i:s')
                ];
            }
        }

        $analytics = array_merge($analytics, self::summarizeResolutionTimes($resolutionTimes));

        // Calculate SLA compliance rate
        if ($slaTotal > 0) {
            $analytics['sla_compliance_rate'] = round(($slaCompliant / $slaTotal) * 100, 2);
        }

        return $analytics;
    }

    /**
     * Get system-wide analytics for dashboard
     *
     * @return array
     */
    /**
     * @return array<string, mixed>
     */
    public function getSystemAnalytics(): array
    {
        // Get resolution time statistics for all resolved/closed tickets
        $qb = $this->db->getQueryBuilder();
        $qb->select('created_at', 'closed_at', 'priority', 'status', 'customer_id', 'project_id')
            ->from($this->getTableName())
            ->where($qb->expr()->in('status', $qb->createNamedParameter(['resolved', 'closed', 'done'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->isNotNull('closed_at'));
        $result = $qb->executeQuery();

        $resolutionTimes = [];
        $byCustomer = [];
        $byProject = [];
        $byPriority = [];

        while ($row = $result->fetch()) {
            try {
                $createdAt = new \DateTime($row['created_at']);
                $closedAt = new \DateTime($row['closed_at']);
            } catch (\Throwable $e) {
                continue;
            }
            $resolutionTime = $closedAt->getTimestamp() - $createdAt->getTimestamp();
            if ($resolutionTime <= 0) {
                continue;
            }
            $resolutionTimeHours = $resolutionTime / 3600;

            $resolutionTimes[] = $resolutionTimeHours;

            // Group by customer
            if ($row['customer_id']) {
                $customerId = (int)$row['customer_id'];
                $byCustomer[$customerId][] = $resolutionTimeHours;
            }

            // Group by project
            if ($row['project_id']) {
                $projectId = (int)$row['project_id'];
                $byProject[$projectId][] = $resolutionTimeHours;
            }

            // Group by priority
            $priority = $row['priority'];
            $byPriority[$priority][] = $resolutionTimeHours;
        }
        $result->closeCursor();

        $analytics = [
            'system_avg_resolution_time_hours' => 0,
            'system_avg_resolution_time_days' => 0,
            'customer_performance' => [],
            'project_performance' => [],
            'priority_performance' => []
        ];

        // Calculate system-wide average
        if (!empty($resolutionTimes)) {
            $analytics['system_avg_resolution_time_hours'] = round(array_sum($resolutionTimes) / count($resolutionTimes), 2);
            $analytics['system_avg_resolution_time_days'] = round($analytics['system_avg_resolution_time_hours'] / 24, 2);
        }

        // Calculate customer performance
        foreach ($byCustomer as $customerId => $times) {
            $analytics['customer_performance'][$customerId] = [
                'avg_resolution_time_hours' => round(array_sum($times) / count($times), 2),
                'avg_resolution_time_days' => round(array_sum($times) / count($times) / 24, 2),
                'total_tickets' => count($times)
            ];
        }

        // Calculate project performance
        foreach ($byProject as $projectId => $times) {
            $analytics['project_performance'][$projectId] = [
                'avg_resolution_time_hours' => round(array_sum($times) / count($times), 2),
                'avg_resolution_time_days' => round(array_sum($times) / count($times) / 24, 2),
                'total_tickets' => count($times)
            ];
        }

        // Calculate priority performance
        foreach ($byPriority as $priority => $times) {
            $analytics['priority_performance'][$priority] = [
                'avg_resolution_time_hours' => round(array_sum($times) / count($times), 2),
                'avg_resolution_time_days' => round(array_sum($times) / count($times) / 24, 2),
                'total_tickets' => count($times)
            ];
        }

        return $analytics;
    }

    /**
     * Count tickets matching export filters.
     *
     * @param array<string,mixed> $filters
     */
    public function countForExport(array $filters): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from($this->getTableName());
            $this->applyExportFilters($qb, $filters);
            $result = $qb->executeQuery();
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            return $count;
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * Find tickets matching export filters.
     *
     * @param array<string,mixed> $filters
     * @return list<Ticket>
     */
    public function findForExport(array $filters, int $limit, int $offset = 0): array
    {
        $limit = max(1, min($limit, ExportFilterParser::MAX_EXPORT_ROWS));
        $offset = max(0, $offset);

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from($this->getTableName());
            $this->applyExportFilters($qb, $filters);
            $qb->orderBy('created_at', 'DESC')
                ->setMaxResults($limit)
                ->setFirstResult($offset);
            return $this->findTicketEntities($qb);
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function applyExportFilters(IQueryBuilder $qb, array $filters): void
    {
        $scope = (string)($filters['scope'] ?? 'all');
        $ticketIds = is_array($filters['ticket_ids'] ?? null) ? $filters['ticket_ids'] : [];
        if ($scope === 'selected') {
            if ($ticketIds === []) {
                $qb->andWhere($qb->expr()->eq('id', $qb->createNamedParameter(-1, IQueryBuilder::PARAM_INT)));
                return;
            }
            $qb->andWhere($qb->expr()->in('id', $qb->createNamedParameter($ticketIds, IQueryBuilder::PARAM_INT_ARRAY)));
            return;
        }

        $this->applyTextSearchConditions($qb, (string)($filters['search'] ?? ''));

        if (!empty($filters['status'])) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter((string)$filters['status'])));
        }
        $statusIn = is_array($filters['status_in'] ?? null) ? $filters['status_in'] : [];
        if ($statusIn !== []) {
            $qb->andWhere(
                $qb->expr()->in('status', $qb->createNamedParameter($statusIn, IQueryBuilder::PARAM_STR_ARRAY))
            );
        }
        if (!empty($filters['status_not'])) {
            $qb->andWhere($qb->expr()->neq('status', $qb->createNamedParameter((string)$filters['status_not'])));
        }
        if (!empty($filters['priority'])) {
            $qb->andWhere($qb->expr()->eq('priority', $qb->createNamedParameter((string)$filters['priority'])));
        }
        if (!empty($filters['category'])) {
            $qb->andWhere($qb->expr()->eq('category', $qb->createNamedParameter((string)$filters['category'])));
        }
        if (!empty($filters['assigned_to'])) {
            $this->applyAssignedToFilter($qb, (string)$filters['assigned_to']);
        }

        $projectIds = is_array($filters['project_ids'] ?? null) ? $filters['project_ids'] : [];
        if ($projectIds !== []) {
            $qb->andWhere($qb->expr()->in('project_id', $qb->createNamedParameter($projectIds, IQueryBuilder::PARAM_INT_ARRAY)));
        }

        $customerIds = is_array($filters['customer_ids'] ?? null) ? $filters['customer_ids'] : [];
        if ($customerIds !== []) {
            $qb->andWhere($qb->expr()->in('customer_id', $qb->createNamedParameter($customerIds, IQueryBuilder::PARAM_INT_ARRAY)));
        }

        if (!empty($filters['date_from'])) {
            $qb->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter(self::normalizeCreatedAtLowerBound((string)$filters['date_from']))));
        }
        if (!empty($filters['date_to'])) {
            $qb->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter(self::normalizeCreatedAtUpperBound((string)$filters['date_to']))));
        }

        if (empty($filters['include_merged'])) {
            $qb->andWhere($qb->expr()->isNull('merged_into_id'));
        }
    }

    private static function normalizeCreatedAtLowerBound(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        return $value . ' 00:00:00';
    }

    private static function normalizeCreatedAtUpperBound(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        return $value . ' 23:59:59';
    }

    private function wrapLikeParameter(string $query): string
    {
        return '%' . $this->db->escapeLikeParameter($query) . '%';
    }

    /**
     * Normalize free-text ticket search input (merge/link pickers, list filters).
     */
    private function normalizeTicketSearchQuery(string $query): string
    {
        $query = trim($query);
        if ($query === '') {
            return '';
        }

        $query = ltrim($query, '#');
        if ($query === '') {
            return '';
        }

        // Pasted picker label: "HD-001 — Printer offline"
        if (preg_match('/^(.+?)\s*[—–\-]\s+/u', $query, $matches)) {
            return trim($matches[1]);
        }

        return $query;
    }

    private function applyTextSearchConditions(IQueryBuilder $qb, string $query): void
    {
        $trimmed = $this->normalizeTicketSearchQuery($query);
        if ($trimmed === '') {
            return;
        }

        $like = $this->wrapLikeParameter($trimmed);
        $conditions = [];
        foreach (self::TEXT_SEARCH_COLUMNS as $column) {
            $conditions[] = $qb->expr()->iLike($column, $qb->createNamedParameter($like));
        }
        $qb->andWhere($qb->expr()->orX(...$conditions));
    }
}

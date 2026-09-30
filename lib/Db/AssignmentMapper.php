<?php

declare(strict_types=1);

/**
 * Assignment mapper for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Assignment mapper
 */
class AssignmentMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_assignments', Assignment::class);
    }

    /**
     * Find assignment by ID
     *
     * @param int $id
     * @return Assignment
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function find(int $id): Assignment
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * Find all assignments
     *
     * @return Assignment[]
     */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('priority_order', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Find assignment for a project
     *
     * @param int $projectId
     * @return Assignment|null
     */
    public function findByProjectId(int $projectId): ?Assignment
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->orderBy('priority_order', 'ASC')
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return null;
        }

        return Assignment::fromRow($row);
    }

    /**
     * Find assignment for a category
     *
     * @param string $category
     * @return Assignment|null
     */
    public function findByCategory(string $category): ?Assignment
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('category', $qb->createNamedParameter($category)))
            ->andWhere($qb->expr()->isNull('project_id'))
            ->orderBy('priority_order', 'ASC')
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return null;
        }

        return Assignment::fromRow($row);
    }

    /**
     * Find default assignment
     *
     * @return Assignment|null
     */
    public function findDefault(): ?Assignment
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('is_default', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('priority_order', 'ASC')
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return null;
        }

        return Assignment::fromRow($row);
    }

    /**
     * Find assignment for a ticket
     *
     * @param int|null $projectId
     * @param string $category
     * @return string|null User ID to assign to
     */
    public function findAssignmentForTicket(?int $projectId, string $category): ?string
    {
        // Priority 1: Project-specific assignment
        if ($projectId !== null) {
            $assignment = $this->findByProjectId($projectId);
            if ($assignment !== null) {
                return $assignment->getAssignedUserId();
            }
        }

        // Priority 2: Category-specific assignment
        $assignment = $this->findByCategory($category);
        if ($assignment !== null) {
            return $assignment->getAssignedUserId();
        }

        // Priority 3: Default assignment
        $assignment = $this->findDefault();
        if ($assignment !== null) {
            return $assignment->getAssignedUserId();
        }

        return null;
    }
}


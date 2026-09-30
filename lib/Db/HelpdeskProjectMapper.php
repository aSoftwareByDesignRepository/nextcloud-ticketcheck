<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2025 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for helpdesk projects
 */
class HelpdeskProjectMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_projects', HelpdeskProject::class);
    }

    /**
     * Find all projects
     *
     * @param int $limit
     * @param int $offset
     * @return HelpdeskProject[]
     */
    public function findAll(int $limit = 100, int $offset = 0): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('name', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }

    /**
     * Find project by ID
     *
     * @param int $id
     * @return HelpdeskProject
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function find(int $id): HelpdeskProject
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * Find projects by customer ID
     *
     * @param int $customerId
     * @return HelpdeskProject[]
     */
    public function findByCustomerId(int $customerId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('customer_id', $qb->createNamedParameter($customerId, IQueryBuilder::PARAM_INT)))
            ->orderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Search projects by name
     *
     * @param string $query
     * @param int $limit
     * @return HelpdeskProject[]
     */
    public function search(string $query, int $limit = 50): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->like('name', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($query) . '%')))
            ->orderBy('name', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }
}

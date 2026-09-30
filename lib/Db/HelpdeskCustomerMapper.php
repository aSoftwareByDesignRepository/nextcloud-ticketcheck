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
 * Mapper for helpdesk customers
 */
class HelpdeskCustomerMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_customers', HelpdeskCustomer::class);
    }

    /**
     * Find all customers
     *
     * @param int $limit
     * @param int $offset
     * @return HelpdeskCustomer[]
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
     * Find customer by ID
     *
     * @param int $id
     * @return HelpdeskCustomer
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function find(int $id): HelpdeskCustomer
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * Find customer by email
     *
     * @param string $email
     * @return HelpdeskCustomer|null
     */
    public function findByEmail(string $email): ?HelpdeskCustomer
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('email', $qb->createNamedParameter($email)));

        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        } catch (\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
            // Return first one if multiple exist
            $results = $this->findEntities($qb);
            return $results[0] ?? null;
        }
    }

    /**
     * Exact name match for CRM write-facade duplicate detection (CRM write facade).
     */
    public function findByName(string $name): ?HelpdeskCustomer
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('name', $qb->createNamedParameter($name)))
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return null;
        } catch (\OCP\AppFramework\Db\MultipleObjectsReturnedException) {
            $results = $this->findEntities($qb);
            return $results[0] ?? null;
        }
    }

    /**
     * Search customers by name or email
     *
     * @param string $query
     * @param int $limit
     * @return HelpdeskCustomer[]
     */
    public function search(string $query, int $limit = 50): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->like('name', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($query) . '%')),
                    $qb->expr()->like('email', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($query) . '%'))
                )
            )
            ->orderBy('name', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }
}

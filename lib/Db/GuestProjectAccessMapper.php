<?php

declare(strict_types=1);

/**
 * Guest Project Access mapper for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for guest project access
 */
class GuestProjectAccessMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'helpdesk_guest_access', GuestProjectAccess::class);
    }

    /**
     * Get all project IDs a guest user can access
     *
     * @param string $userId
     * @return array Array of project IDs
     */
    public function getProjectsForUser(string $userId): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('project_id')
                ->from($this->getTableName())
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

            $result = $qb->executeQuery();
            $projectIds = [];
            while ($row = $result->fetch()) {
                $projectIds[] = (int)$row['project_id'];
            }
            $result->closeCursor();

            return $projectIds;
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Get all guest users who can access a project
     *
     * @param int $projectId
     * @return array Array of user IDs
     */
    public function getUsersForProject(int $projectId): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('user_id')
                ->from($this->getTableName())
                ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)));

            $result = $qb->executeQuery();
            $userIds = [];
            while ($row = $result->fetch()) {
                $userIds[] = $row['user_id'];
            }
            $result->closeCursor();

            return $userIds;
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Check if user has access to project
     *
     * @param string $userId
     * @param int $projectId
     * @return bool
     */
    public function hasAccess(string $userId, int $projectId): bool
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
                ->from($this->getTableName())
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            return $row !== false;
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Grant access to a project for a user
     *
     * @param string $userId
     * @param int $projectId
     * @param int|null $customerId
     * @param string $createdBy
     * @return GuestProjectAccess
     */
    public function grantAccess(string $userId, int $projectId, ?int $customerId, string $createdBy): GuestProjectAccess
    {
        // Check if already exists
        if ($this->hasAccess($userId, $projectId)) {
            throw new \Exception('User already has access to this project');
        }

        $access = new GuestProjectAccess();
        $access->setUserId($userId);
        $access->setProjectId($projectId);
        $access->setCustomerId($customerId);
        $access->setCreatedAt(new \DateTime());
        $access->setCreatedBy($createdBy);

        return $this->insert($access);
    }

    /**
     * Revoke access to a project for a user
     *
     * @param string $userId
     * @param int $projectId
     */
    public function revokeAccess(string $userId, int $projectId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)));

        $qb->executeStatement();
    }

    /**
     * Get all access records for a user
     *
     * @param string $userId
     * @return GuestProjectAccess[]
     */
    public function findByUser(string $userId): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from($this->getTableName())
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

            return $this->findEntities($qb);
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Get all access records for a project
     *
     * @param int $projectId
     * @return GuestProjectAccess[]
     */
    public function findByProject(int $projectId): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from($this->getTableName())
                ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)));

            return $this->findEntities($qb);
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Remove all access for a user
     *
     * @param string $userId
     */
    public function deleteByUser(string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $qb->executeStatement();
    }

    /**
     * Remove all access for a project
     *
     * @param int $projectId
     */
    public function deleteByProject(int $projectId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)));

        $qb->executeStatement();
    }

    /**
     * Count how many projects a user has access to
     *
     * @param string $userId
     * @return int
     */
    public function countProjectsForUser(string $userId): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*'))
                ->from($this->getTableName())
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

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
     * Remove all access rows by project
     *
     * @param int $projectId
     * @return void
     */
    public function removeByProject(int $projectId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)));
        $qb->executeStatement();
    }
}

<?php

declare(strict_types=1);

/**
 * Project service for helpdesk app (standalone)
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;

/**
 * Service for managing standalone projects and customers
 */
class ProjectService
{
    private IDBConnection $db;
    private IUserManager $userManager;
    private LoggerInterface $logger;

    public function __construct(
        IDBConnection $db,
        IUserManager $userManager,
        LoggerInterface $logger,
    ) {
        $this->db = $db;
        $this->userManager = $userManager;
        $this->logger = $logger;
    }

    /**
     * Get all projects with stats
     * Use with pagination for large datasets
     *
     * @return list<array<string, mixed>>
     */
    public function getAllProjects(int $limit = 500, int $offset = 0, bool $includeInactive = false, ?string $search = null, ?int $customerId = null): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('p.id', 'p.name', 'p.description', 'p.customer_id', 'p.active', 'c.name as customer_name', 'c.email as customer_email')
                ->from('helpdesk_projects', 'p')
                ->leftJoin('p', 'helpdesk_customers', 'c', $qb->expr()->eq('p.customer_id', 'c.id'))
                // By default, only include active projects. When includeInactive is true, return all.
                ->orderBy('p.name', 'ASC')
                ->setMaxResults($limit)
                ->setFirstResult($offset);

            if ($includeInactive === false) {
                // Backward compatibility: treat NULL as active until fully backfilled
                $qb->where($qb->expr()->orX(
                    $qb->expr()->eq('p.active', $qb->createNamedParameter(1)),
                    $qb->expr()->isNull('p.active')
                ));
            }

            if ($search !== null && $search !== '') {
                $normalizedSearch = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
                $like = '%' . $normalizedSearch . '%';
                $expr = $qb->expr()->orX(
                    $qb->expr()->like($qb->func()->lower('p.name'), $qb->createNamedParameter($like, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->like($qb->func()->lower('p.description'), $qb->createNamedParameter($like, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->like($qb->func()->lower('c.name'), $qb->createNamedParameter($like, IQueryBuilder::PARAM_STR))
                );
                if ($includeInactive === false) {
                    $qb->andWhere($expr);
                } else {
                    $qb->where($expr);
                }
            }

            if ($customerId !== null && $customerId > 0) {
                $qb->andWhere($qb->expr()->eq('p.customer_id', $qb->createNamedParameter($customerId, IQueryBuilder::PARAM_INT)));
            }

            $result = $qb->executeQuery();
            /** @var list<array<string, mixed>> $projects */
            $projects = $result->fetchAll();
            $result->closeCursor();

            // Add stats for each project
            foreach ($projects as &$project) {
                $project['member_count'] = $this->getProjectMemberCount($project['id']);
                $project['ticket_count'] = $this->getProjectTicketCount($project['id'], false);
                $project['resolved_count'] = $this->getProjectTicketCount($project['id'], true);
            }

            return $projects;
        } catch (\Throwable $e) {
            // Fallback for pre-migration instances (no 'active' column yet)
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select('p.id', 'p.name', 'p.description', 'p.customer_id', 'p.status', 'c.name as customer_name')
                    ->from('helpdesk_projects', 'p')
                    ->leftJoin('p', 'helpdesk_customers', 'c', $qb->expr()->eq('p.customer_id', 'c.id'))
                    ->where($qb->expr()->eq('p.status', $qb->createNamedParameter('active')))
                    ->orderBy('p.name', 'ASC')
                    ->setMaxResults($limit)
                    ->setFirstResult($offset);

                if ($customerId !== null && $customerId > 0) {
                    $qb->andWhere($qb->expr()->eq('p.customer_id', $qb->createNamedParameter($customerId, IQueryBuilder::PARAM_INT)));
                }

                $result = $qb->executeQuery();
                /** @var list<array<string, mixed>> $projects */
                $projects = $result->fetchAll();
                $result->closeCursor();

                foreach ($projects as &$project) {
                    // Synthesize active flag from legacy status
                    $project['active'] = 1;
                    $project['member_count'] = $this->getProjectMemberCount($project['id']);
                    $project['ticket_count'] = $this->getProjectTicketCount($project['id'], false);
                    $project['resolved_count'] = $this->getProjectTicketCount($project['id'], true);
                }

                return $projects;
            } catch (\Throwable $ignored) {
                return [];
            }
        }
    }

    /**
     * Get project by ID
     */
    public function getProject(int $projectId): ?array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('p.*', 'c.name as customer_name', 'c.email as customer_email')
                ->from('helpdesk_projects', 'p')
                ->leftJoin('p', 'helpdesk_customers', 'c', $qb->expr()->eq('p.customer_id', 'c.id'))
                ->where($qb->expr()->eq('p.id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));

            $result = $qb->executeQuery();
            $project = $result->fetch();
            $result->closeCursor();

            if ($project !== false && $project !== null) {
                if (!array_key_exists('active', $project) && array_key_exists('status', $project)) {
                    $project['active'] = ($project['status'] === 'active') ? 1 : 0;
                }
                return $project;
            }
            return null;
        } catch (\Throwable $e) {
            // Fallback: fetch without active, then synthesize from status
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select('p.*', 'c.name as customer_name', 'c.email as customer_email')
                    ->from('helpdesk_projects', 'p')
                    ->leftJoin('p', 'helpdesk_customers', 'c', $qb->expr()->eq('p.customer_id', 'c.id'))
                    ->where($qb->expr()->eq('p.id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));
                $result = $qb->executeQuery();
                $project = $result->fetch();
                $result->closeCursor();
                if ($project !== false && $project !== null) {
                    if (!array_key_exists('active', $project) && array_key_exists('status', $project)) {
                        $project['active'] = ($project['status'] === 'active') ? 1 : 0;
                    }
                    return $project;
                }
            } catch (\Throwable $ignored) {
            }
            return null;
        }
    }

    /**
     * Get all customers with pagination
     * Use pagination for large datasets
     *
     * @return list<array<string, mixed>>
     */
    public function getAllCustomers(int $limit = 500, int $offset = 0, ?string $search = null): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'name', 'email', 'company', 'phone', 'notes', 'created_at', 'updated_at', 'created_by')
                ->from('helpdesk_customers');

            if ($search !== null && $search !== '') {
                $normalizedSearch = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
                $like = '%' . $normalizedSearch . '%';
                $qb->where($qb->expr()->orX(
                    $qb->expr()->like($qb->func()->lower('name'), $qb->createNamedParameter($like, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->like($qb->func()->lower('email'), $qb->createNamedParameter($like, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->like($qb->func()->lower('phone'), $qb->createNamedParameter($like, IQueryBuilder::PARAM_STR)),
                ));
            }

            $qb->orderBy('name', 'ASC')
                ->setMaxResults($limit)
                ->setFirstResult($offset);

            $result = $qb->executeQuery();
            /** @var list<array<string, mixed>> $customers */
            $customers = $result->fetchAll();
            $result->closeCursor();

            return $customers;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Projects the user administers, plus customers linked to those projects only.
     * Used so project admins never receive the global directory.
     *
     * @return array{projects: list<array<string, mixed>>, customers: list<array<string, mixed>>}
     */
    public function getAdministeredProjectsDirectory(string $userId, int $limit = 500): array
    {
        if ($userId === '') {
            return ['projects' => [], 'customers' => []];
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('p.*', 'c.name as customer_name', 'c.email as customer_email', 'c.id as joined_customer_id', 'c.phone as customer_phone')
                ->from('helpdesk_projects', 'p')
                ->innerJoin(
                    'p',
                    'hd_proj_members',
                    'pm',
                    $qb->expr()->andX(
                        $qb->expr()->eq('p.id', 'pm.project_id'),
                        $qb->expr()->eq('pm.user_id', $qb->createNamedParameter($userId)),
                        $qb->expr()->eq('pm.role', $qb->createNamedParameter('Admin'))
                    )
                )
                ->leftJoin('p', 'helpdesk_customers', 'c', $qb->expr()->eq('p.customer_id', 'c.id'))
                ->orderBy('p.name', 'ASC')
                ->setMaxResults(max(1, min($limit, 500)));

            $result = $qb->executeQuery();
            $projects = [];
            $customersById = [];
            while ($row = $result->fetch()) {
                $projects[] = $row;
                $cid = isset($row['customer_id']) ? (int) $row['customer_id'] : 0;
                if ($cid > 0 && !isset($customersById[$cid])) {
                    $customersById[$cid] = [
                        'id' => $cid,
                        'name' => $row['customer_name'] ?? '',
                        'email' => $row['customer_email'] ?? '',
                        'phone' => $row['customer_phone'] ?? null,
                    ];
                }
            }
            $result->closeCursor();

            return [
                'projects' => $projects,
                'customers' => array_values($customersById),
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load administered projects directory', [
                'user_id' => $userId,
                'exception' => $e,
            ]);
            return ['projects' => [], 'customers' => []];
        }
    }

    /**
     * Create project
     */
    public function createProject(string $name, ?string $description, ?int $customerId, string $createdBy, bool $active = true): int
    {
        $now = new \DateTime();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('helpdesk_projects')
            ->values([
                'name' => $qb->createNamedParameter($name),
                'description' => $qb->createNamedParameter($description),
                'customer_id' => $qb->createNamedParameter($customerId, IQueryBuilder::PARAM_INT),
                'status' => $qb->createNamedParameter($active ? 'active' : 'inactive'),
                'active' => $qb->createNamedParameter($active ? 1 : 0, IQueryBuilder::PARAM_INT),
                'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
                'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
                'created_by' => $qb->createNamedParameter($createdBy),
            ]);

        $qb->executeStatement();
        return (int)$qb->getLastInsertId();
    }

    /**
     * Create customer
     */
    public function createCustomer(string $name, ?string $email, ?string $company, ?string $phone, ?string $notes, string $createdBy): int
    {
        $now = new \DateTime();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('helpdesk_customers')
            ->values([
                'name' => $qb->createNamedParameter($name),
                'email' => $qb->createNamedParameter($email),
                'company' => $qb->createNamedParameter($company),
                'phone' => $qb->createNamedParameter($phone),
                'notes' => $qb->createNamedParameter($notes),
                'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
                'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
                'created_by' => $qb->createNamedParameter($createdBy),
            ]);

        $qb->executeStatement();
        return (int)$qb->getLastInsertId();
    }

    /**
     * @deprecated Use DeletionService::deleteEntity("project", $id, true).
     * Retired: raw SQL cascade skipped TicketWorkflowLock, attachment files,
     * links/watchers/surveys, and merge-shell cleanup.
     */
    public function deleteProject(int $projectId): void
    {
        throw new \LogicException(
            'ProjectService::deleteProject is retired. Use DeletionService::deleteEntity("project", $id, true).'
        );
    }

    /**
     * @deprecated Use DeletionService::deleteEntity("customer", $id, true).
     * Retired: raw SQL cascade skipped TicketWorkflowLock and relation cleanup.
     */
    public function deleteCustomer(int $customerId): void
    {
        throw new \LogicException(
            'ProjectService::deleteCustomer is retired. Use DeletionService::deleteEntity("customer", $id, true).'
        );
    }

    /**
     * Get customer by ID
     */
    public function getCustomer(int $customerId): ?array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from('helpdesk_customers')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($customerId, IQueryBuilder::PARAM_INT)));

            $result = $qb->executeQuery();
            $customer = $result->fetch();
            $result->closeCursor();

            return $customer !== false ? $customer : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get projects by customer
     */
    public function getProjectsByCustomer(int $customerId, ?string $search = null): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from('helpdesk_projects')
                ->where($qb->expr()->eq('customer_id', $qb->createNamedParameter($customerId, IQueryBuilder::PARAM_INT)))
                ->orderBy('name', 'ASC');

            if ($search !== null && $search !== '') {
                $normalizedSearch = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
                $like = '%' . $normalizedSearch . '%';
                $qb->andWhere($qb->expr()->orX(
                    $qb->expr()->like($qb->func()->lower('name'), $qb->createNamedParameter($like, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->like($qb->func()->lower('description'), $qb->createNamedParameter($like, IQueryBuilder::PARAM_STR))
                ));
            }

            $result = $qb->executeQuery();
            $projects = $result->fetchAll();
            $result->closeCursor();

            foreach ($projects as &$project) {
                $projectId = isset($project['id']) ? (int)$project['id'] : 0;
                if ($projectId > 0) {
                    $project['member_count'] = $this->getProjectMemberCount($projectId);
                    $project['ticket_count'] = $this->getProjectTicketCount($projectId, false);
                }
            }
            unset($project);

            return $projects;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Update project
     */
    public function updateProject(int $projectId, string $name, ?int $customerId, ?string $description = null, ?bool $active = null): void
    {
        // Detect if active flips true->false to disable only-project guests
        $prev = $this->getProject($projectId);
        $prevActive = $prev ? (bool)$prev['active'] : true;

        $qb = $this->db->getQueryBuilder();
        $qb->update('helpdesk_projects')
            ->set('name', $qb->createNamedParameter($name))
            ->set('customer_id', $qb->createNamedParameter($customerId, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));

        if ($description !== null) {
            $qb->set('description', $qb->createNamedParameter($description));
        }
        if ($active !== null) {
            $qb->set('active', $qb->createNamedParameter($active ? 1 : 0, IQueryBuilder::PARAM_INT));
        }

        $qb->executeStatement();

        if ($prevActive && $active === false) {
            // Disable guest users who only have this project
            $qb = $this->db->getQueryBuilder();
            $qb->select('user_id')
                ->from('helpdesk_guest_access')
                ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));
            $result = $qb->executeQuery();
            $userIds = [];
            while ($row = $result->fetch()) {
                $userIds[] = $row['user_id'];
            }
            $result->closeCursor();

            if (!empty($userIds)) {
                $userManager = $this->userManager;
                foreach (array_unique($userIds) as $userId) {
                    // Count projects for this user
                    $qb = $this->db->getQueryBuilder();
                    $qb->select($qb->func()->count('*'))
                        ->from('helpdesk_guest_access')
                        ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
                    $count = (int)$qb->executeQuery()->fetchOne();
                    if ($count === 1) {
                        $user = $userManager->get($userId);
                        if ($user) {
                            try {
                                $this->logger->info('Deleting guest user after project deactivation (sole project access)', [
                                    'user_id' => $userId,
                                    'project_id' => $projectId,
                                ]);
                                // Delete user completely from database
                                $this->deleteUserFromDatabase($userId);
                                $this->logger->info('Successfully deleted guest user after project deactivation', [
                                    'user_id' => $userId,
                                ]);
                            } catch (\Throwable $t) {
                                $this->logger->warning('Failed to delete guest user after project deactivation', [
                                    'user_id' => $userId,
                                    'exception' => $t,
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Update customer
     */
    public function updateCustomer(int $customerId, string $name, ?string $email = null, ?string $company = null, ?string $phone = null, ?string $notes = null): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('helpdesk_customers')
            ->set('name', $qb->createNamedParameter($name))
            ->set('email', $qb->createNamedParameter($email))
            ->set('phone', $qb->createNamedParameter($phone))
            ->set('notes', $qb->createNamedParameter($notes))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($customerId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Get project member count
     */
    private function getProjectMemberCount(int $projectId): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*'))
                ->from('hd_proj_members')
                ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));

            $result = $qb->executeQuery();
            $count = (int)$result->fetchOne();
            $result->closeCursor();

            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get project ticket count
     */
    private function getProjectTicketCount(int $projectId, bool $resolvedOnly = false): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*'))
                ->from('helpdesk_tickets')
                ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));

            if ($resolvedOnly) {
                $qb->andWhere($qb->expr()->in(
                    'status',
                    $qb->createNamedParameter(['resolved', 'closed'], IQueryBuilder::PARAM_STR_ARRAY)
                ));
            } else {
                $qb->andWhere($qb->expr()->notIn(
                    'status',
                    $qb->createNamedParameter(['resolved', 'closed'], IQueryBuilder::PARAM_STR_ARRAY)
                ));
            }

            $result = $qb->executeQuery();
            $count = (int)$result->fetchOne();
            $result->closeCursor();

            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get all members of a project
     *
     * @param int $projectId
     * @return array
     */
    public function getProjectMembers(int $projectId): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from('hd_proj_members')
                ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)));

            $result = $qb->executeQuery();
            $members = [];
            $userManager = $this->userManager;

            while ($row = $result->fetch()) {
                $userId = $row['user_id'];
                $user = $userManager->get($userId);

                $members[] = [
                    'project_id' => (int)$row['project_id'],
                    'user_id' => $userId,
                    'user_name' => $user ? $user->getDisplayName() : $userId,
                    'user_email' => $user ? $user->getEMailAddress() : '',
                    'role' => $row['role'] ?? 'Agent',
                    'created_at' => $row['added_at'] ?? $row['created_at'] ?? null,
                ];
            }
            $result->closeCursor();

            return $members;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Delete a guest user account and its TicketCheck access rows.
     *
     * Runs through IUser::delete() so the user backend and every registered
     * cleanup listener (sessions, tokens, mounts, app hooks) run — the
     * previous raw-SQL multi-table delete bypassed all of that and could
     * leave a half-deleted account. TicketCheck's own access rows are removed
     * first: if account deletion then fails, the guest is left with zero
     * project access (consistent) instead of dangling rows.
     *
     * @param string $userId
     * @return void
     */
    private function deleteUserFromDatabase(string $userId): void
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('helpdesk_guest_access')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->orWhere($qb->expr()->eq('guest_user_id', $qb->createNamedParameter($userId)));
            $qb->executeStatement();

            $user = $this->userManager->get($userId);
            if ($user === null) {
                return;
            }
            if (!$user->delete()) {
                throw new \RuntimeException('User backend refused deletion');
            }

            $this->logger->info('Successfully deleted guest user', ['user_id' => $userId]);
        } catch (\Throwable $e) {
            $this->logger->error('Error deleting guest user', [
                'user_id' => $userId,
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}

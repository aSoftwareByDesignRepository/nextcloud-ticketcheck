<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2025 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Project Member Management Service
 * 
 * Simple service for managing
 * which users have access to which projects.
 */
class ProjectMemberService
{
    private const GROUP_HELPDESK_CUSTOMERS = 'helpdesk_customers';
    private const ROLE_ADMIN = 'Admin';
    private const ROLE_AGENT = 'Agent';
    private const ROLE_SUPPORT = 'Support User';
    private const VALID_ROLES = [self::ROLE_ADMIN, self::ROLE_AGENT, self::ROLE_SUPPORT];

    private IDBConnection $db;
    private IUserManager $userManager;
    private IUserSession $userSession;
    private IGroupManager $groupManager;
    private PermissionService $permissionService;
    private TicketWorkflowLock $workflowLock;
    private LoggerInterface $logger;

    public function __construct(
        IDBConnection $db,
        IUserManager $userManager,
        IUserSession $userSession,
        IGroupManager $groupManager,
        PermissionService $permissionService,
        TicketWorkflowLock $workflowLock,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
        $this->permissionService = $permissionService;
        $this->workflowLock = $workflowLock;
        $this->logger = $logger;
    }

    /**
     * Add a team member to a project
     *
     * @param int $projectId
     * @param string $userId
     * @param string $role 'Admin', 'Agent', or 'Support User'
     * @return array{id: int, project_id: int, user_id: string, role: string, user_name: string, user_email: string}
     * @throws \Exception
     */
    public function addMember(int $projectId, string $userId, string $role = 'Support User'): array
    {
        return $this->workflowLock->withExclusiveKey(
            $this->projectMembersLockKey($projectId),
            fn (): array => $this->addMemberLocked($projectId, $userId, $role),
            'Project member add'
        );
    }

    /**
     * @return array{id: int, project_id: int, user_id: string, role: string, user_name: string, user_email: string}
     */
    private function addMemberLocked(int $projectId, string $userId, string $role): array
    {
        $role = $this->normalizeRole($role);
        $this->assertActorMayAddMember($projectId, $role);

        // Validate user exists
        $user = $this->userManager->get($userId);
        if (!$user) {
            throw new \Exception('User not found');
        }

        // Validate user is enabled
        if (!$user->isEnabled()) {
            throw new \Exception('User is disabled and cannot be added to projects');
        }
        if ($this->groupManager->isInGroup($userId, self::GROUP_HELPDESK_CUSTOMERS)) {
            throw new \Exception('Guest users cannot be added as project team members');
        }

        // Check if already a member
        if ($this->isMember($projectId, $userId)) {
            throw new \Exception("User '{$userId}' is already a member of this project");
        }

        $currentUser = $this->userSession->getUser();
        if (!$currentUser) {
            throw new \Exception('User not authenticated');
        }

        $this->insertProjectMemberRow($projectId, $userId, $role, $currentUser->getUID());
        $createdMember = $this->getMember($projectId, $userId);
        $id = (int)($createdMember['id'] ?? 0);

        return [
            'id' => $id,
            'project_id' => $projectId,
            'user_id' => $userId,
            'role' => $role,
            'user_name' => $user->getDisplayName(),
            'user_email' => $user->getEMailAddress() ?? '',
        ];
    }

    /**
     * Remove a team member from a project
     *
     * @param int $projectId
     * @param string $userId
     * @return bool
     * @throws \Exception
     */
    public function removeMember(int $projectId, string $userId): bool
    {
        return $this->workflowLock->withExclusiveKey(
            $this->projectMembersLockKey($projectId),
            function () use ($projectId, $userId): bool {
                $member = $this->getMember($projectId, $userId);
                if ($member === null) {
                    throw new \Exception('User is not a member of this project');
                }
                $this->assertActorMayRemoveMember($projectId, (string)$member['role']);
                $this->assertCanRemoveMember($projectId, (string)$member['role']);

                $qb = $this->db->getQueryBuilder();
                $qb->delete('hd_proj_members')
                    ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

                $qb->executeStatement();

                return true;
            },
            'Project member remove',
        );
    }

    /**
     * Update a member's role
     *
     * @param int $projectId
     * @param string $userId
     * @param string $newRole
     * @return array{id: int, project_id: int, user_id: string, role: string, created_at: mixed, created_by: mixed, user_name: string, user_email: string}
     * @throws \Exception
     */
    public function updateMemberRole(int $projectId, string $userId, string $newRole): array
    {
        $newRole = $this->normalizeRole($newRole);

        return $this->workflowLock->withExclusiveKey(
            $this->projectMembersLockKey($projectId),
            function () use ($projectId, $userId, $newRole): array {
                $member = $this->getMember($projectId, $userId);
                if ($member === null) {
                    throw new \Exception('User is not a member of this project');
                }
                $this->assertActorMayChangeRole($projectId, (string)$member['role'], $newRole);
                $this->assertCanChangeRole($projectId, (string)$member['role'], $newRole);

                $qb = $this->db->getQueryBuilder();
                $qb->update('hd_proj_members')
                    ->set('role', $qb->createNamedParameter($newRole))
                    ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

                $qb->executeStatement();

                $member = $this->getMember($projectId, $userId);
                if ($member === null) {
                    throw new \Exception('User is not a member of this project');
                }
                return $member;
            },
            'Project member role change',
        );
    }

    /**
     * Get all members of a project
     *
     * @param int $projectId
     * @return list<array{id: int, project_id: int, user_id: string, role: string, created_at: mixed, created_by: mixed, user_name: string, user_email: string}>
     */
    public function getProjectMembers(int $projectId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('hd_proj_members')
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->orderBy('role', 'ASC')
            ->addOrderBy('user_id', 'ASC');

        $result = $qb->executeQuery();
        $members = [];

        while ($row = $result->fetch()) {
            $user = $this->userManager->get($row['user_id']);
            $members[] = [
                'id' => (int)$row['id'],
                'project_id' => (int)$row['project_id'],
                'user_id' => $row['user_id'],
                'role' => $row['role'],
                'created_at' => $row['created_at'] ?? $row['added_at'] ?? null,
                'created_by' => $row['created_by'] ?? $row['added_by'] ?? null,
                'user_name' => $user ? $user->getDisplayName() : $row['user_id'],
                'user_email' => $user ? ($user->getEMailAddress() ?? '') : '',
            ];
        }

        $result->closeCursor();
        return $members;
    }

    /**
     * Get all projects a user is a member of
     *
     * @param string $userId
     * @return list<array{id: int, name: mixed, customer_id: int|null, role: mixed}>
     */
    public function getUserProjects(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('p.*', 'pm.role')
            ->from('helpdesk_projects', 'p')
            ->innerJoin(
                'p',
                'hd_proj_members',
                'pm',
                $qb->expr()->eq('p.id', 'pm.project_id')
            )
            ->where($qb->expr()->eq('pm.user_id', $qb->createNamedParameter($userId)))
            ->orderBy('p.name', 'ASC');

        $result = $qb->executeQuery();
        $projects = [];

        while ($row = $result->fetch()) {
            $projects[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'customer_id' => $row['customer_id'] ? (int)$row['customer_id'] : null,
                'role' => $row['role'],
            ];
        }

        $result->closeCursor();
        return $projects;
    }

    /**
     * Check if a user is a member of a project
     *
     * @param int $projectId
     * @param string $userId
     * @return bool
     */
    public function isMember(int $projectId, string $userId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from('hd_proj_members')
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();

        return $count > 0;
    }

    /**
     * Get a specific member's details
     *
     * @param int $projectId
     * @param string $userId
     * @return array{id: int, project_id: int, user_id: string, role: string, created_at: mixed, created_by: mixed, user_name: string, user_email: string}|null
     */
    public function getMember(int $projectId, string $userId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('hd_proj_members')
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            return null;
        }

        $user = $this->userManager->get($row['user_id']);
        return [
            'id' => (int)$row['id'],
            'project_id' => (int)$row['project_id'],
            'user_id' => $row['user_id'],
            'role' => $row['role'],
            'created_at' => $row['created_at'] ?? $row['added_at'] ?? null,
            'created_by' => $row['created_by'] ?? $row['added_by'] ?? null,
            'user_name' => $user ? $user->getDisplayName() : $row['user_id'],
            'user_email' => $user ? ($user->getEMailAddress() ?? '') : '',
        ];
    }

    private function normalizeRole(string $role): string
    {
        $role = trim($role);
        if (!in_array($role, self::VALID_ROLES, true)) {
            throw new \Exception('Invalid role. Must be: Admin, Agent, or Support User');
        }
        return $role;
    }

    private function assertCanRemoveMember(int $projectId, string $role): void
    {
        if ($role === self::ROLE_ADMIN && $this->countAdmins($projectId) <= 1) {
            throw new \Exception('Cannot remove the last project administrator');
        }
    }

    public function canActorRemoveMember(int $projectId, string $targetRole): bool
    {
        try {
            $this->assertActorMayRemoveMember($projectId, $targetRole);
            $this->assertCanRemoveMember($projectId, $targetRole);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function assertActorMayRemoveMember(int $projectId, string $targetRole): void
    {
        $actor = $this->userSession->getUser();
        if ($actor === null) {
            throw new \Exception('User not authenticated');
        }
        $actorId = $actor->getUID();

        if ($this->permissionService->isNextcloudAdministrator($actorId)) {
            return;
        }

        if (!$this->permissionService->canManageProjectMembers($projectId)) {
            throw new \Exception('Access denied');
        }

        $actorRole = $this->permissionService->getUserProjectRole($projectId, $actorId);
        if ($actorRole === self::ROLE_ADMIN) {
            if ($this->roleRank($targetRole) > $this->roleRank($actorRole)) {
                throw new \Exception('Cannot remove a member with a higher role');
            }
            return;
        }

        if ($this->countAdmins($projectId) === 0) {
            return;
        }

        throw new \Exception('Only project administrators can remove team members');
    }

    /**
     * Re-assert manage-members + role-rank under the project-members lock
     * before inserting a new membership (mirrors updateMemberRole).
     */
    private function assertActorMayAddMember(int $projectId, string $newRole): void
    {
        $actor = $this->userSession->getUser();
        if ($actor === null) {
            throw new \Exception('User not authenticated');
        }
        $actorId = $actor->getUID();

        if ($this->permissionService->isNextcloudAdministrator($actorId)) {
            return;
        }

        if (!$this->permissionService->canManageProjectMembers($projectId)) {
            throw new \Exception('Access denied');
        }

        $actorRole = $this->permissionService->getUserProjectRole($projectId, $actorId);
        if ($actorRole === self::ROLE_ADMIN) {
            if ($this->roleRank($newRole) > $this->roleRank($actorRole)) {
                throw new \Exception('Cannot assign a higher role than your own');
            }
            return;
        }

        // Helpdesk admins with no project-admin role may manage when the project
        // has no Admins yet (bootstrap / recovery) — same as role-change path.
        if ($this->countAdmins($projectId) === 0) {
            return;
        }

        throw new \Exception('Only project administrators can add team members');
    }

    private function roleRank(string $role): int
    {
        return match ($role) {
            self::ROLE_ADMIN => 3,
            self::ROLE_AGENT => 2,
            self::ROLE_SUPPORT => 1,
            default => 0,
        };
    }

    private function assertActorMayChangeRole(int $projectId, string $currentRole, string $newRole): void
    {
        if ($currentRole === $newRole) {
            return;
        }

        $actor = $this->userSession->getUser();
        if ($actor === null) {
            throw new \Exception('User not authenticated');
        }
        $actorId = $actor->getUID();

        if ($this->permissionService->isNextcloudAdministrator($actorId)) {
            return;
        }

        if (!$this->permissionService->canManageProjectMembers($projectId)) {
            throw new \Exception('Access denied');
        }

        $actorRole = $this->permissionService->getUserProjectRole($projectId, $actorId);
        if ($actorRole === self::ROLE_ADMIN) {
            if ($this->roleRank($newRole) > $this->roleRank($actorRole)
                || $this->roleRank($currentRole) > $this->roleRank($actorRole)) {
                throw new \Exception('Cannot change role for a member with a higher role');
            }
            return;
        }

        if ($this->countAdmins($projectId) === 0) {
            return;
        }

        throw new \Exception('Only project administrators can change team roles');
    }

    private function assertCanChangeRole(int $projectId, string $currentRole, string $newRole): void
    {
        if ($currentRole === self::ROLE_ADMIN && $newRole !== self::ROLE_ADMIN && $this->countAdmins($projectId) <= 1) {
            throw new \Exception('Cannot remove the last project administrator');
        }
    }

    private function countAdmins(int $projectId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from('hd_proj_members')
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('role', $qb->createNamedParameter(self::ROLE_ADMIN)));

        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();

        return $count;
    }

    private function projectMembersLockKey(int $projectId): string
    {
        return 'ticketcheck_proj_members_' . $projectId;
    }

    private function insertProjectMemberRow(int $projectId, string $userId, string $role, string $actorUserId): void
    {
        $now = new \DateTime();
        $qb = $this->db->getQueryBuilder();
        try {
            $qb->insert('hd_proj_members')
                ->values([
                    'project_id' => $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT),
                    'user_id' => $qb->createNamedParameter($userId),
                    'role' => $qb->createNamedParameter($role),
                    'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
                    'created_by' => $qb->createNamedParameter($actorUserId),
                ]);
            $qb->executeStatement();
            return;
        } catch (\Throwable $e) {
            // Backward-compatible fallback for older schemas using added_* columns.
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert('hd_proj_members')
            ->values([
                'project_id' => $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT),
                'user_id' => $qb->createNamedParameter($userId),
                'role' => $qb->createNamedParameter($role),
                'added_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
                'added_by' => $qb->createNamedParameter($actorUserId),
            ]);
        $qb->executeStatement();
    }

    /**
     * Get member count for a project
     *
     * @param int $projectId
     * @return int
     */
    public function getMemberCount(int $projectId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from('hd_proj_members')
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();

        return $count;
    }

    /**
     * Get all available users that can be added as team members to projects
     * EXCLUDES guest users (helpdesk_customers) - they use separate guest access system
     * EXCLUDES users already in the specified project
     * 
     * @param int|null $projectId If provided, excludes users already in this project
     * @return list<array{user_id: string, user_name: string, user_email: string, groups: list<string>}>
     */
    /**
     * Users that can be added as project team members.
     *
     * Fail closed: only enabled helpdesk agents/admins (never a blank dump of
     * all Nextcloud users — that was a directory-disclosure IDOR for project Admins).
     * Guests and existing members are excluded.
     *
     * @return list<array{user_id: string, user_name: string, user_email: string, groups: list<string>}>
     */
    public function getAvailableUsers(?int $projectId = null): array
    {
        $users = [];

        $existingMemberIds = [];
        if ($projectId !== null) {
            $existingMembers = $this->getProjectMembers($projectId);
            foreach ($existingMembers as $member) {
                $existingMemberIds[] = $member['user_id'];
            }
        }

        $staffGroups = ['helpdesk_admins', 'helpdesk_agents'];
        foreach ($staffGroups as $groupName) {
            $group = $this->groupManager->get($groupName);
            if ($group === null) {
                continue;
            }
            foreach ($group->getUsers() as $user) {
                $userId = $user->getUID();
                if (!$user->isEnabled()) {
                    continue;
                }
                if ($this->groupManager->isInGroup($userId, self::GROUP_HELPDESK_CUSTOMERS)) {
                    continue;
                }
                if ($projectId !== null && in_array($userId, $existingMemberIds, true)) {
                    continue;
                }
                if (!isset($users[$userId])) {
                    $users[$userId] = [
                        'user_id' => $userId,
                        'user_name' => $user->getDisplayName(),
                        'user_email' => $user->getEMailAddress() ?? '',
                        'groups' => [],
                    ];
                }
                $users[$userId]['groups'][] = $groupName;
            }
        }

        $sortedUsers = array_values($users);
        usort($sortedUsers, static function (array $a, array $b): int {
            return strcmp($a['user_name'], $b['user_name']);
        });

        return $sortedUsers;
    }

    /**
     * Bulk add members to a project
     *
     * @param int $projectId
     * @param list<string> $userIds Array of user IDs
     * @param string $role Role for all users
     * @return list<array{user_id: string, success: bool, member?: array{id: int, project_id: int, user_id: string, role: string, user_name: string, user_email: string}, error?: string}>
     */
    public function bulkAddMembers(int $projectId, array $userIds, string $role = 'Support User'): array
    {
        return $this->workflowLock->withExclusiveKey(
            $this->projectMembersLockKey($projectId),
            function () use ($projectId, $userIds, $role): array {
                $results = [];
                foreach ($userIds as $userId) {
                    try {
                        $user = $this->userManager->get($userId);
                        if (!$user) {
                            throw new \Exception('User not found');
                        }
                        if (!$user->isEnabled()) {
                            throw new \Exception('User is disabled and cannot be added to projects');
                        }

                        $member = $this->addMemberLocked($projectId, $userId, $role);
                        $results[] = [
                            'user_id' => $userId,
                            'success' => true,
                            'member' => $member,
                        ];
                    } catch (\Exception $e) {
                        $this->logger->warning('bulkAddMembers: failed to add user to project', [
                            'user_id' => $userId,
                            'project_id' => $projectId,
                            'reason' => $e->getMessage(),
                        ]);
                        $results[] = [
                            'user_id' => $userId,
                            'success' => false,
                            'error' => 'Operation failed',
                        ];
                    }
                }
                return $results;
            },
            'Project member bulk add'
        );
    }

}

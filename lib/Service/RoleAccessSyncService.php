<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\DbQueryGuard;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * One-time and repeatable role/group consistency sync.
 *
 * Conservative behavior:
 * - Adds missing staff groups based on project roles.
 * - Enforces guest isolation (customers cannot also be staff).
 * - Does NOT remove existing staff groups solely because project membership is missing.
 */
class RoleAccessSyncService
{
    private const GROUP_CUSTOMERS = 'helpdesk_customers';
    private const GROUP_AGENTS = 'helpdesk_agents';
    private const GROUP_ADMINS = 'helpdesk_admins';
    private const TABLE_PROJECT_MEMBERS = 'hd_proj_members';

    public function __construct(
        private readonly IDBConnection $db,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{
     *   scanned_users:int,
     *   users_changed:int,
     *   groups_added:int,
     *   groups_removed:int,
     *   missing_users:int,
     *   changed_users:array<int,string>
     * }
     */
    public function syncExistingUsers(bool $dryRun = true): array
    {
        $customers = $this->getOrCreateGroup(self::GROUP_CUSTOMERS);
        $agents = $this->getOrCreateGroup(self::GROUP_AGENTS);
        $admins = $this->getOrCreateGroup(self::GROUP_ADMINS);

        $projectRolesByUser = $this->getProjectRolesByUser();
        $knownUsers = $this->collectKnownUserIds($projectRolesByUser, $customers, $agents, $admins);

        $summary = [
            'scanned_users' => count($knownUsers),
            'users_changed' => 0,
            'groups_added' => 0,
            'groups_removed' => 0,
            'missing_users' => 0,
            'changed_users' => [],
        ];

        foreach ($knownUsers as $userId) {
            $user = $this->userManager->get($userId);
            if ($user === null) {
                $summary['missing_users']++;
                continue;
            }

            $roles = $projectRolesByUser[$userId] ?? [];
            $requiresAdminFromRole = in_array('Admin', $roles, true);
            $requiresAgentFromRole = $requiresAdminFromRole || in_array('Agent', $roles, true);
            $isCustomer = $this->groupManager->isInGroup($userId, self::GROUP_CUSTOMERS);

            $userChanged = false;

            // Add missing staff groups based on project role.
            if (!$isCustomer && $requiresAdminFromRole && !$this->groupManager->isInGroup($userId, self::GROUP_ADMINS)) {
                $userChanged = true;
                $summary['groups_added']++;
                if (!$dryRun) {
                    $admins->addUser($user);
                }
            }
            if (!$isCustomer && $requiresAgentFromRole && !$this->groupManager->isInGroup($userId, self::GROUP_AGENTS)) {
                $userChanged = true;
                $summary['groups_added']++;
                if (!$dryRun) {
                    $agents->addUser($user);
                }
            }

            // Hard isolation rule: guest users cannot keep staff groups.
            if ($isCustomer && $this->groupManager->isInGroup($userId, self::GROUP_AGENTS)) {
                $userChanged = true;
                $summary['groups_removed']++;
                if (!$dryRun) {
                    $agents->removeUser($user);
                }
            }
            if ($isCustomer && $this->groupManager->isInGroup($userId, self::GROUP_ADMINS)) {
                $userChanged = true;
                $summary['groups_removed']++;
                if (!$dryRun) {
                    $admins->removeUser($user);
                }
            }

            if ($userChanged) {
                $summary['users_changed']++;
                $summary['changed_users'][] = $userId;
            }
        }

        return $summary;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function getProjectRolesByUser(): array
    {
        $rolesByUser = [];
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'role')
            ->from(self::TABLE_PROJECT_MEMBERS);

        try {
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $userId = (string)($row['user_id'] ?? '');
                $role = (string)($row['role'] ?? '');
                if ($userId === '' || $role === '') {
                    continue;
                }
                $rolesByUser[$userId][$role] = $role;
            }
            $result->closeCursor();
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                $this->logger->warning('Failed to read project membership during role sync (schema not ready)', ['exception' => $e]);
            } else {
                throw $e;
            }
        }

        foreach ($rolesByUser as $userId => $roleSet) {
            $rolesByUser[$userId] = array_values($roleSet);
        }

        return $rolesByUser;
    }

    /**
     * @param array<string, array<int, string>> $projectRolesByUser
     * @return array<int, string>
     */
    private function collectKnownUserIds(array $projectRolesByUser, IGroup $customers, IGroup $agents, IGroup $admins): array
    {
        $userIds = array_fill_keys(array_keys($projectRolesByUser), true);
        foreach ([$customers, $agents, $admins] as $group) {
            foreach ($group->getUsers() as $user) {
                $userIds[$user->getUID()] = true;
            }
        }
        return array_values(array_keys($userIds));
    }

    private function getOrCreateGroup(string $groupId): IGroup
    {
        $group = $this->groupManager->get($groupId);
        if ($group !== null) {
            return $group;
        }

        $created = $this->groupManager->createGroup($groupId);
        if ($created !== null) {
            return $created;
        }

        // Fail hard if group cannot be created; proceeding would produce inconsistent results.
        throw new \RuntimeException('Unable to resolve required group: ' . $groupId);
    }
}


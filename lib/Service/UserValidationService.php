<?php

declare(strict_types=1);

/**
 * User validation service for helpdesk app
 * Prevents assigning tickets to inactive, disabled, or inappropriate users
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for validating users before assignment
 */
class UserValidationService
{
    private IUserManager $userManager;
    private IGroupManager $groupManager;
    private IConfig $config;
    private PermissionService $permissionService;
    private LoggerInterface $logger;

    public function __construct(
        IUserManager $userManager,
        IGroupManager $groupManager,
        IConfig $config,
        PermissionService $permissionService,
        LoggerInterface $logger
    ) {
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->config = $config;
        $this->permissionService = $permissionService;
        $this->logger = $logger;
    }

    /**
     * Validate if a user can be assigned to tickets / added as a watcher.
     *
     * When $projectId is set, the user must be a helpdesk admin/agent OR a
     * member of that project (matches the assignable-user search UI).
     * When $projectId is null, only helpdesk admins/agents pass — never
     * arbitrary Nextcloud accounts (prevents email PII fan-out).
     *
     * @return array{valid: bool, reason: string|null}
     */
    public function validateUserForAssignment(string $userId, ?int $projectId = null): array
    {
        try {
            // Check if user exists
            $user = $this->userManager->get($userId);
            if (!$user) {
                return [
                    'valid' => false,
                    'reason' => 'User not found'
                ];
            }

            // Check if user is enabled
            if (!$user->isEnabled()) {
                return [
                    'valid' => false,
                    'reason' => 'User is disabled'
                ];
            }

            // Check if user is a guest (customer) - they shouldn't be assigned tickets
            if ($this->groupManager->isInGroup($userId, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {
                return [
                    'valid' => false,
                    'reason' => 'Cannot assign tickets to guest users'
                ];
            }

            // Check if user has been deleted (soft delete check)
            if ($this->isUserDeleted($userId)) {
                return [
                    'valid' => false,
                    'reason' => 'User has been deleted'
                ];
            }

            // Check if user account is locked
            if ($this->isUserLocked($userId)) {
                return [
                    'valid' => false,
                    'reason' => 'User account is locked'
                ];
            }

            // Check if user has quota issues that might indicate problems
            if ($this->hasQuotaIssues($userId)) {
                $this->logger->warning("User $userId has quota issues but is still valid for assignment");
            }

            if (!$this->isAssignableStaffOrProjectMember($userId, $projectId)) {
                return [
                    'valid' => false,
                    'reason' => 'User is not an assignable helpdesk agent or project member',
                ];
            }

            return [
                'valid' => true,
                'reason' => null
            ];
        } catch (\Exception $e) {
            $this->logger->error("Error validating user $userId: " . $e->getMessage());
            return [
                'valid' => false,
                'reason' => 'Validation error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Helpdesk admin/agent, or (when project scoped) a non-guest project member.
     */
    public function isAssignableStaffOrProjectMember(string $userId, ?int $projectId = null): bool
    {
        if ($userId === '') {
            return false;
        }

        $isHelpdeskStaff = $this->permissionService->isUserInGroup($userId, PermissionService::GROUP_HELPDESK_ADMINS)
            || $this->permissionService->isUserInGroup($userId, PermissionService::GROUP_HELPDESK_AGENTS);

        if ($isHelpdeskStaff) {
            return true;
        }

        if ($projectId !== null && $projectId > 0
            && $this->permissionService->isProjectMember($projectId, $userId)) {
            return true;
        }

        return false;
    }

    /**
     * Get all valid users for ticket assignment
     *
     * @return array Array of valid user IDs with their details
     */
    public function getValidUsersForAssignment(): array
    {
        $validUsers = [];

        try {
            // Get all users (limit to 1000 for performance)
            $users = $this->userManager->search('', 1000);

            foreach ($users as $user) {
                $userId = $user->getUID();
                $validation = $this->validateUserForAssignment($userId);

                if ($validation['valid']) {
                    $validUsers[] = [
                        'user_id' => $userId,
                        'display_name' => $user->getDisplayName(),
                        'email' => $user->getEMailAddress(),
                        'groups' => $this->getUserGroups($userId)
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Error getting valid users: " . $e->getMessage());
        }

        return $validUsers;
    }

    /**
     * Check if user is deleted (soft delete)
     *
     * @param string $userId
     * @return bool
     */
    private function isUserDeleted(string $userId): bool
    {
        try {
            // Check if user has been soft-deleted by checking config
            $deletedFlag = $this->config->getUserValue($userId, 'core', 'deleted', 'false');
            return $deletedFlag === 'true';
        } catch (\Exception $e) {
            // If we can't check, assume not deleted
            return false;
        }
    }

    /**
     * Check if user account is locked
     *
     * @param string $userId
     * @return bool
     */
    private function isUserLocked(string $userId): bool
    {
        try {
            // Check if user account is locked
            $lockedFlag = $this->config->getUserValue($userId, 'core', 'locked', 'false');
            return $lockedFlag === 'true';
        } catch (\Exception $e) {
            // If we can't check, assume not locked
            return false;
        }
    }

    /**
     * Check if user has quota issues
     *
     * @param string $userId
     * @return bool
     */
    private function hasQuotaIssues(string $userId): bool
    {
        try {
            // Check if user has quota set to 0 (might indicate account issues)
            $quota = $this->config->getUserValue($userId, 'files', 'quota', 'default');
            return $quota === '0 B' || $quota === '0';
        } catch (\Exception $e) {
            // If we can't check, assume no quota issues
            return false;
        }
    }

    /**
     * Get user's groups
     *
     * @param string $userId
     * @return array
     */
    private function getUserGroups(string $userId): array
    {
        try {
            $groups = [];
            $userGroups = $this->groupManager->getUserGroups($userId);
            foreach ($userGroups as $group) {
                $groups[] = $group->getGID();
            }
            return $groups;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Validate multiple users at once
     *
     * @param array $userIds
     * @return array ['valid' => string[], 'invalid' => array]
     */
    public function validateMultipleUsers(array $userIds): array
    {
        $valid = [];
        $invalid = [];

        foreach ($userIds as $userId) {
            $validation = $this->validateUserForAssignment($userId);
            if ($validation['valid']) {
                $valid[] = $userId;
            } else {
                $invalid[] = [
                    'user_id' => $userId,
                    'reason' => $validation['reason']
                ];
            }
        }

        return [
            'valid' => $valid,
            'invalid' => $invalid
        ];
    }
}

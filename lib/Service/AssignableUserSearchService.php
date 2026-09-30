<?php

declare(strict_types=1);

/**
 * Search helpdesk agents/admins assignable to tickets (export filters, assignment UI).
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCP\IGroupManager;
use OCP\IUserManager;

class AssignableUserSearchService
{
    public function __construct(
        private readonly ProjectService $projectService,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
    ) {
    }

    /**
     * @return list<array{user_id: string, user_name: string, is_project_member: bool}>
     */
    public function search(?int $projectId, ?string $query, int $limit = 25): array
    {
        $query = $query !== null ? trim($query) : '';
        $normalizedNeedle = $query !== ''
            ? (function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query))
            : '';
        $limit = max(5, min($limit, 50));

        /** @var array<string, array{user_id: string, user_name: string, is_project_member: bool}> $availableAgents */
        $availableAgents = [];

        if ($projectId !== null && $projectId > 0) {
            try {
                $projectMembers = $this->projectService->getProjectMembers($projectId);
                foreach ($projectMembers as $member) {
                    if ($this->groupManager->isInGroup((string)$member['user_id'], PermissionService::GROUP_HELPDESK_CUSTOMERS)) {
                        continue;
                    }
                    $availableAgents[$member['user_id']] = [
                        'user_id' => $member['user_id'],
                        'user_name' => $member['user_name'] . ' (Project Team)',
                        'is_project_member' => true,
                    ];
                }
            } catch (\Throwable) {
                // project lookup failures should not break assignment search
            }
        }

        $helpdeskGroups = ['helpdesk_admins', 'helpdesk_agents'];
        foreach ($helpdeskGroups as $groupName) {
            $group = $this->groupManager->get($groupName);
            if ($group === null) {
                continue;
            }
            $groupUsers = $group->getUsers();
            if (!is_iterable($groupUsers)) {
                continue;
            }
            foreach ($groupUsers as $user) {
                $userId = $user->getUID();
                if (isset($availableAgents[$userId])) {
                    continue;
                }
                if ($this->groupManager->isInGroup($userId, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {
                    continue;
                }
                $availableAgents[$userId] = [
                    'user_id' => $userId,
                    'user_name' => $user->getDisplayName(),
                    'is_project_member' => false,
                ];
            }
        }

        // Fail closed: never fall back to searching all Nextcloud users.
        // Assignees/watchers must be helpdesk staff or project members.
        $availableAgents = array_values($availableAgents);
        if ($normalizedNeedle !== '') {
            $availableAgents = array_values(array_filter(
                $availableAgents,
                static function (array $user) use ($normalizedNeedle): bool {
                    $hayName = function_exists('mb_strtolower')
                        ? mb_strtolower($user['user_name'], 'UTF-8')
                        : strtolower($user['user_name']);
                    $hayId = function_exists('mb_strtolower')
                        ? mb_strtolower($user['user_id'], 'UTF-8')
                        : strtolower($user['user_id']);

                    return str_contains($hayName, $normalizedNeedle) || str_contains($hayId, $normalizedNeedle);
                },
            ));
        }

        usort($availableAgents, static function (array $a, array $b): int {
            if ($a['is_project_member'] && !$b['is_project_member']) {
                return -1;
            }
            if (!$a['is_project_member'] && $b['is_project_member']) {
                return 1;
            }

            return strcmp($a['user_name'], $b['user_name']);
        });

        return array_slice($availableAgents, 0, $limit);
    }
}

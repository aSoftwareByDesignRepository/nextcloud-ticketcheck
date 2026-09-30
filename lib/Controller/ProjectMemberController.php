<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2025 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectMemberService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\EmailService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Project Member API Controller
 * Mutations (add/remove/update/bulkAdd) require project-admin authority.
 */
class ProjectMemberController extends Controller
{

    private ProjectMemberService $projectMemberService;
    private ProjectService $projectService;
    private EmailService $emailService;
    private IUserSession $userSession;
    private PermissionService $permissionService;
    private IFactory $l10nFactory;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        ProjectMemberService $projectMemberService,
        ProjectService $projectService,
        EmailService $emailService,
        IUserSession $userSession,
        PermissionService $permissionService,
        IFactory $l10nFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->projectMemberService = $projectMemberService;
        $this->projectService = $projectService;
        $this->emailService = $emailService;
        $this->userSession = $userSession;
        $this->permissionService = $permissionService;
        $this->l10nFactory = $l10nFactory;
        $this->logger = $logger;
    }

    /**
     * Add a member to a project
     *
     * @param int $projectId
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function addMember(int $projectId): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageProjectMembers($projectId)) {
            return new DataResponse(['error' => $l->t('access_denied')], 403);
        }
        try {
            $userId = $this->request->getParam('user_id');
            $role = $this->request->getParam('role');
            $jsonPayload = $this->readJsonPayload();

            if (!empty($jsonPayload)) {
                $userId = $jsonPayload['user_id'] ?? $userId;
                $role = $jsonPayload['role'] ?? $role;
            }

            if (!$role) {
                $role = 'Support User';
            }

            if (!$userId) {
                return new DataResponse(['error' => $l->t('user_id_required')], 400);
            }

            $userId = (string) $userId;
            if (strlen($userId) > 64 || str_contains($userId, "\0")) {
                return new DataResponse(['error' => $l->t('invalid_user_id')], 400);
            }
            $role = is_string($role) ? $role : 'Support User';

            $member = $this->projectMemberService->addMember($projectId, $userId, $role);

            // Send email notification to the added user
            try {
                $project = $this->projectService->getProject($projectId);
                if ($project) {
                    $currentUser = $this->userSession->getUser();
                    $addedByName = $currentUser ? $currentUser->getDisplayName() : 'System';

                    $this->emailService->sendProjectMemberAddedNotification(
                        $userId,
                        $projectId,
                        $project['name'],
                        $role,
                        $addedByName
                    );
                }
            } catch (\Throwable $e) {
                // Log but don't fail if email fails
                $this->logWarning('Failed to send project member email: ' . $e->getMessage());
            }

            return new DataResponse([
                'success' => true,
                'member' => $member,
                'message' => $l->t('team_member_added_successfully'),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Project member operation failed', ['exception' => $e]);
            return $this->memberOperationErrorResponse($e, 400);
        }
    }

    /**
     * Remove a member from a project
     *
     * @param int $projectId
     * @param string $userId
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function removeMember(int $projectId, string $userId): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageProjectMembers($projectId)) {
            return new DataResponse(['error' => $l->t('access_denied')], 403);
        }
        try {
            $this->projectMemberService->removeMember($projectId, $userId);

            return new DataResponse([
                'success' => true,
                'message' => $l->t('team_member_removed'),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Project member operation failed', ['exception' => $e]);
            return $this->memberOperationErrorResponse($e, 400);
        }
    }

    /**
     * Update a member's role
     *
     * @param int $projectId
     * @param string $userId
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function updateRole(int $projectId, string $userId): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageProjectMembers($projectId)) {
            return new DataResponse(['error' => $l->t('access_denied')], 403);
        }
        try {
            $newRole = $this->request->getParam('role');
            $jsonPayload = $this->readJsonPayload();

            if ($newRole === null && !empty($jsonPayload)) {
                $newRole = isset($jsonPayload['role']) ? (string) $jsonPayload['role'] : null;
            }

            if (!$newRole || !is_string($newRole)) {
                return new DataResponse(['error' => $l->t('role_required')], 400);
            }

            $member = $this->projectMemberService->updateMemberRole($projectId, $userId, $newRole);

            return new DataResponse([
                'success' => true,
                'member' => $member,
                'message' => $l->t('member_role_updated_successfully'),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Project member operation failed', ['exception' => $e]);
            return $this->memberOperationErrorResponse($e, 400);
        }
    }

    /**
     * Get all members of a project
     *
     * @param int $projectId
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function getMembers(int $projectId): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if ($this->permissionService->isGuest() || !$this->permissionService->canAccessProject($projectId)) {
            return new DataResponse(['error' => $l->t('access_denied')], 403);
        }
        try {
            $members = $this->projectMemberService->getProjectMembers($projectId);

            return new DataResponse([
                'success' => true,
                'members' => $members,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Project member operation failed', ['exception' => $e]);
            return $this->memberOperationErrorResponse($e, 400);
        }
    }

    /**
     * Get available users that can be added
     *
     * @param int|null $projectId Optional project ID to exclude existing members
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function getAvailableUsers(?int $projectId = null): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        $projectIdRaw = $this->request->getParam('projectId', $this->request->getParam('project_id', null));
        $projectId = is_numeric($projectIdRaw) ? (int)$projectIdRaw : $projectId;
        if ($projectId === null || !$this->permissionService->canManageProjectMembers($projectId)) {
            return new DataResponse(['error' => $l->t('access_denied')], 403);
        }
        try {
            $users = $this->projectMemberService->getAvailableUsers($projectId);

            return new DataResponse([
                'success' => true,
                'users' => $users,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Project member operation failed', ['exception' => $e]);
            return $this->memberOperationErrorResponse($e, 400);
        }
    }

    /**
     * Bulk add members to a project
     *
     * @param int $projectId
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function bulkAdd(int $projectId): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageProjectMembers($projectId)) {
            return new DataResponse(['error' => $l->t('access_denied')], 403);
        }
        try {
            $userIds = $this->request->getParam('user_ids', []);
            $role = $this->request->getParam('role', 'Support User');
            $jsonPayload = $this->readJsonPayload();
            [$userIds, $role] = $this->mergeBulkAddPayload($userIds, $role, $jsonPayload);

            if (empty($userIds) || !is_array($userIds)) {
                return new DataResponse(['error' => $l->t('user_ids_array_required')], 400);
            }

            $results = $this->projectMemberService->bulkAddMembers($projectId, $userIds, $role);

            $successCount = count(array_filter($results, fn($r) => $r['success']));
            $failCount = count($results) - $successCount;

            // Send email notifications to successfully added members
            try {
                $project = $this->projectService->getProject($projectId);
                if ($project) {
                    $currentUser = $this->userSession->getUser();
                    $addedByName = $currentUser ? $currentUser->getDisplayName() : 'System';

                    foreach ($results as $result) {
                        if ($result['success']) {
                            try {
                                $this->emailService->sendProjectMemberAddedNotification(
                                    $result['user_id'],
                                    $projectId,
                                    $project['name'],
                                    $role,
                                    $addedByName
                                );
                            } catch (\Throwable $e) {
                                // Log but continue with other notifications
                                $this->logWarning(
                                    'Failed to send project member email to ' . $result['user_id'] . ': ' . $e->getMessage()
                                );
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Log but don't fail
                $this->logWarning('Failed to send bulk member emails: ' . $e->getMessage());
            }

            return new DataResponse([
                'success' => true,
                'results' => $results,
                'message' => $l->t('added_members_successfully', [$successCount]) . ($failCount > 0 ? ' (' . $l->t('failed_count', [$failCount]) . ')' : ''),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Project member operation failed', ['exception' => $e]);
            return new DataResponse(['error' => $l->t('an_error_occurred')], 400);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonPayload(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '' || strlen($raw) > 8192) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $jsonPayload
     * @return array{0: mixed, 1: mixed}
     */
    private function mergeBulkAddPayload(mixed $userIds, mixed $role, array $jsonPayload): array
    {
        if (empty($jsonPayload)) {
            return [$userIds, $role];
        }

        if (array_key_exists('user_ids', $jsonPayload) && is_array($jsonPayload['user_ids'])) {
            $userIds = $jsonPayload['user_ids'];
        }
        if (array_key_exists('role', $jsonPayload) && is_string($jsonPayload['role'])) {
            $role = $jsonPayload['role'];
        }

        return [$userIds, $role];
    }

    private function memberOperationErrorResponse(\Throwable $e, int $status): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        $message = strtolower(trim($e->getMessage()));
        $mapped = match (true) {
            str_contains($message, 'guest users cannot be added as project team members') => $l->t('guests_cannot_be_team_members'),
            str_contains($message, 'user not found') => $l->t('user_not_found'),
            str_contains($message, 'user is disabled') => $l->t('user_is_disabled'),
            str_contains($message, 'already a member') => $l->t('user_already_project_member'),
            str_contains($message, 'user is not a member') => $l->t('user_not_project_member'),
            str_contains($message, 'invalid role') => $l->t('invalid_member_role'),
            str_contains($message, 'last project administrator') => $l->t('cannot_remove_last_project_admin'),
            str_contains($message, 'user not authenticated') => $l->t('access_denied'),
            default => $l->t('an_error_occurred'),
        };

        return new DataResponse(['error' => $mapped], $status);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logError(string $message, array $context = []): void
    {
        try {
            $this->logger->error($message, $context);
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logWarning(string $message, array $context = []): void
    {
        try {
            $this->logger->warning($message, $context);
        } catch (\Throwable $ignored) {
        }
    }
}

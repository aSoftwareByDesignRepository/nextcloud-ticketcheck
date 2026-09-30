<?php

declare(strict_types=1);

/**
 * Centralized deletion service for helpdesk entities
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\HelpdeskCustomerMapper;
use OCA\Ticketcheck\Db\HelpdeskProjectMapper;
use OCA\Ticketcheck\Db\KBArticleMapper;
use OCA\Ticketcheck\Db\KBCategoryMapper;
use OCA\Ticketcheck\Db\AssignmentMapper;
use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;

/**
 * Service for handling entity deletions with dependency analysis
 */
class DeletionService
{
    private IDBConnection $db;
    private TicketMapper $ticketMapper;
    private CommentMapper $commentMapper;
    private AttachmentMapper $attachmentMapper;
    private HelpdeskCustomerMapper $customerMapper;
    private HelpdeskProjectMapper $projectMapper;
    private KBArticleMapper $kbArticleMapper;
    private KBCategoryMapper $kbCategoryMapper;
    private AssignmentMapper $assignmentMapper;
    /** @phpstan-ignore-next-line */
    private GuestProjectAccessMapper $guestAccessMapper;
    private IUserSession $userSession;
    private LoggerInterface $logger;
    private AttachmentCleanupService $attachmentCleanupService;
    private TicketRelationService $ticketRelationService;
    private TicketWorkflowLock $workflowLock;
    private TemplateService $templateService;
    private IUserManager $userManager;

    public function __construct(
        IDBConnection $db,
        TicketMapper $ticketMapper,
        CommentMapper $commentMapper,
        AttachmentMapper $attachmentMapper,
        HelpdeskCustomerMapper $customerMapper,
        HelpdeskProjectMapper $projectMapper,
        KBArticleMapper $kbArticleMapper,
        KBCategoryMapper $kbCategoryMapper,
        AssignmentMapper $assignmentMapper,
        GuestProjectAccessMapper $guestAccessMapper,
        IUserSession $userSession,
        LoggerInterface $logger,
        AttachmentCleanupService $attachmentCleanupService,
        TicketRelationService $ticketRelationService,
        TicketWorkflowLock $workflowLock,
        TemplateService $templateService,
        IUserManager $userManager
    ) {
        $this->db = $db;
        $this->ticketMapper = $ticketMapper;
        $this->commentMapper = $commentMapper;
        $this->attachmentMapper = $attachmentMapper;
        $this->customerMapper = $customerMapper;
        $this->projectMapper = $projectMapper;
        $this->kbArticleMapper = $kbArticleMapper;
        $this->kbCategoryMapper = $kbCategoryMapper;
        $this->assignmentMapper = $assignmentMapper;
        $this->guestAccessMapper = $guestAccessMapper;
        $this->userSession = $userSession;
        $this->logger = $logger;
        $this->attachmentCleanupService = $attachmentCleanupService;
        $this->ticketRelationService = $ticketRelationService;
        $this->workflowLock = $workflowLock;
        $this->templateService = $templateService;
        $this->userManager = $userManager;
    }

    /**
     * Analyze dependencies for an entity
     *
     * @param string $entityType
     * @param int|string $entityId
     * @return array<string, mixed>
     */
    public function analyzeDependencies(string $entityType, $entityId): array
    {
        $dependencies = [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'can_delete' => true,
            'dependencies' => [],
            'total_affected' => 0,
            'requires_cascade' => false
        ];

        switch ($entityType) {
            case 'ticket':
                $dependencies = $this->analyzeTicketDependencies($this->toIntId($entityId), $dependencies);
                break;
            case 'customer':
                $dependencies = $this->analyzeCustomerDependencies($this->toIntId($entityId), $dependencies);
                break;
            case 'project':
                $dependencies = $this->analyzeProjectDependencies($this->toIntId($entityId), $dependencies);
                break;
            case 'kb_article':
                $dependencies = $this->analyzeKBArticleDependencies($this->toIntId($entityId), $dependencies);
                break;
            case 'kb_category':
                $dependencies = $this->analyzeKBCategoryDependencies($this->toIntId($entityId), $dependencies);
                break;
            case 'template':
                $dependencies = $this->analyzeTemplateDependencies($this->toIntId($entityId), $dependencies);
                break;
            case 'guest_user':
                $dependencies = $this->analyzeGuestUserDependencies($this->toStringId($entityId), $dependencies);
                break;
            case 'assignment':
                $dependencies = $this->analyzeAssignmentDependencies($this->toIntId($entityId), $dependencies);
                break;
        }

        return $dependencies;
    }

    /**
     * Delete entity with cascade if dependencies exist
     *
     * @param string $entityType
     * @param int|string $entityId
     * @param bool $cascade
     * @return array{success: bool, message: string, deleted_counts?: array<string, int>}
     * @throws \Exception
     */
    /**
     * @param string $entityType
     * @param int|string $entityId
     * @param bool $cascade
     * @param null|callable(\OCA\Ticketcheck\Db\Ticket):void $assertAuthorized Ticket-only authz re-check under lock
     * @return array{success: bool, message: string, deleted_counts?: array<string, int>}
     */
    public function deleteEntity(string $entityType, $entityId, bool $cascade = false, ?callable $assertAuthorized = null): array
    {
        if ($entityType === 'ticket') {
            $ticketId = $this->toIntId($entityId);
            $maxPasses = 5;
            $result = null;
            for ($pass = 0; $pass < $maxPasses; $pass++) {
                $shellIds = $this->ticketMapper->findIdsMergedInto($ticketId);
                $lockIds = array_values(array_unique(array_merge([$ticketId], $shellIds)));
                $stable = false;
                $passResult = null;
                $this->workflowLock->withTicketLocks(
                    $lockIds,
                    function () use ($entityType, $entityId, $cascade, $ticketId, $lockIds, $assertAuthorized, &$stable, &$passResult): void {
                        $shellIdsNow = $this->ticketMapper->findIdsMergedInto($ticketId);
                        $needed = array_values(array_unique(array_merge([$ticketId], $shellIdsNow)));
                        sort($needed, SORT_NUMERIC);
                        $lockedSorted = $lockIds;
                        sort($lockedSorted, SORT_NUMERIC);
                        if ($needed !== $lockedSorted) {
                            return;
                        }
                        if ($assertAuthorized !== null) {
                            try {
                                $fresh = $this->ticketMapper->find($ticketId);
                            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                                throw new \Exception('Ticket not found');
                            }
                            $assertAuthorized($fresh);
                        }
                        $passResult = $this->deleteEntityUnlocked($entityType, $entityId, $cascade);
                        $stable = true;
                    },
                    'TicketCheck delete'
                );
                if ($stable) {
                    $result = $passResult;
                    break;
                }
            }
            if ($result === null) {
                throw new \Exception('Failed to delete ticket: merge shells changed under concurrency');
            }

            return $result;
        }

        return $this->deleteEntityUnlocked($entityType, $entityId, $cascade);
    }

    /**
     * @param string $entityType
     * @param int|string $entityId
     * @return array{success: bool, message: string, deleted_counts?: array<string, int>}
     */
    private function deleteEntityUnlocked(string $entityType, $entityId, bool $cascade): array
    {
        $user = $this->userSession->getUser();
        $userId = $user ? $user->getUID() : 'system';

        // Analyze dependencies first
        $dependencies = $this->analyzeDependencies($entityType, $entityId);

        // Check if deletion is possible
        if (!$dependencies['can_delete'] && !$cascade) {
            throw new \Exception('Cannot delete entity with dependencies. Use cascade mode.');
        }

        $this->db->beginTransaction();
        try {
            $deletedCounts = [];

            switch ($entityType) {
                case 'ticket':
                    $deletedCounts = $this->deleteTicket($this->toIntId($entityId), $cascade);
                    break;
                case 'customer':
                    $deletedCounts = $this->deleteCustomer($this->toIntId($entityId), $cascade);
                    break;
                case 'project':
                    $deletedCounts = $this->deleteProject($this->toIntId($entityId), $cascade);
                    break;
                case 'kb_article':
                    $deletedCounts = $this->deleteKBArticle($this->toIntId($entityId));
                    break;
                case 'kb_category':
                    $deletedCounts = $this->deleteKBCategory($this->toIntId($entityId));
                    break;
                case 'template':
                    $deletedCounts = $this->deleteTemplate($this->toIntId($entityId));
                    break;
                case 'guest_user':
                    $deletedCounts = $this->deleteGuestUser($this->toStringId($entityId), $cascade);
                    break;
                case 'assignment':
                    $deletedCounts = $this->deleteAssignment($this->toIntId($entityId));
                    break;
                default:
                    throw new \Exception('Unknown entity type: ' . $entityType);
            }

            $this->db->commit();

            // Log deletion
            $this->logDeletion($entityType, $entityId, [
                'deleted_by' => $userId,
                'cascade_mode' => $cascade,
                'dependencies_deleted' => $deletedCounts,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            return [
                'success' => true,
                'message' => ucfirst($entityType) . ' deleted successfully',
                'deleted_counts' => $deletedCounts
            ];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Deletion failed: ' . $e->getMessage(), [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'cascade' => $cascade,
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * Check if entity can be safely deleted
     *
     * @param string $entityType
     * @param int|string $entityId
     * @return bool
     */
    public function canDelete(string $entityType, $entityId): bool
    {
        $dependencies = $this->analyzeDependencies($entityType, $entityId);
        return $dependencies['can_delete'];
    }

    /**
     * Log deletion to system log
     *
     * @param string $entityType
     * @param int|string $entityId
     * @param array{
     *   deleted_by: string,
     *   cascade_mode: bool,
     *   dependencies_deleted: array<string, int>,
     *   timestamp: string
     * } $metadata
     */
    private function logDeletion(string $entityType, $entityId, array $metadata): void
    {
        $this->logger->info('Entity deleted', [
            'action' => 'entity_deleted',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'deleted_by' => $metadata['deleted_by'],
            'cascade_mode' => $metadata['cascade_mode'],
            'dependencies_deleted' => $metadata['dependencies_deleted'],
            'timestamp' => $metadata['timestamp']
        ]);
    }

    /**
     * Analyze ticket dependencies
     */
    /**
     * @param array<string, mixed> $dependencies
     * @return array<string, mixed>
     */
    private function analyzeTicketDependencies(int $ticketId, array $dependencies): array
    {
        // Check comments
        $commentCount = $this->commentMapper->countByTicketId($ticketId);
        if ($commentCount > 0) {
            $dependencies['dependencies']['comments'] = $commentCount;
            $dependencies['total_affected'] += $commentCount;
        }

        // Check attachments
        $attachments = $this->attachmentMapper->findByTicketId($ticketId);
        $attachmentCount = count($attachments);
        if ($attachmentCount > 0) {
            $dependencies['dependencies']['attachments'] = $attachmentCount;
            $dependencies['total_affected'] += $attachmentCount;
        }

        $relations = $this->ticketRelationService->countForTicket($ticketId);
        foreach (['links', 'watchers', 'surveys'] as $key) {
            $count = $relations[$key] ?? 0;
            if ($count > 0) {
                $dependencies['dependencies'][$key] = $count;
                $dependencies['total_affected'] += $count;
            }
        }

        // Merge shells that would be removed with this survivor.
        $shellCount = $this->ticketMapper->countMergedInto($ticketId);
        if ($shellCount > 0) {
            $dependencies['dependencies']['merged_shells'] = $shellCount;
            $dependencies['total_affected'] += $shellCount;
            $dependencies['requires_cascade'] = true;
        }

        if ($dependencies['total_affected'] > 0) {
            $dependencies['requires_cascade'] = true;
        }

        return $dependencies;
    }

    /**
     * Analyze customer dependencies
     */
    /**
     * @param array<string, mixed> $dependencies
     * @return array<string, mixed>
     */
    private function analyzeCustomerDependencies(int $customerId, array $dependencies): array
    {
        // Get projects for this customer
        $projects = $this->projectMapper->findByCustomerId($customerId);
        $projectCount = count($projects);

        if ($projectCount > 0) {
            $dependencies['dependencies']['projects'] = $projectCount;
            $dependencies['total_affected'] += $projectCount;

            // Count tickets for all projects
            $totalTickets = 0;
            $totalComments = 0;
            $totalAttachments = 0;

            foreach ($projects as $project) {
        $tickets = $this->ticketMapper->findByProjectId($project->getId(), 0);
                $totalTickets += count($tickets);

                foreach ($tickets as $ticket) {
                    $totalComments += $this->commentMapper->countByTicketId($ticket->getId());
                    $totalAttachments += count($this->attachmentMapper->findByTicketId($ticket->getId()));
                }
            }

            if ($totalTickets > 0) {
                $dependencies['dependencies']['tickets'] = $totalTickets;
                $dependencies['total_affected'] += $totalTickets;
            }
            if ($totalComments > 0) {
                $dependencies['dependencies']['comments'] = $totalComments;
                $dependencies['total_affected'] += $totalComments;
            }
            if ($totalAttachments > 0) {
                $dependencies['dependencies']['attachments'] = $totalAttachments;
                $dependencies['total_affected'] += $totalAttachments;
            }
        }

        if ($dependencies['total_affected'] > 0) {
            $dependencies['requires_cascade'] = true;
        }

        return $dependencies;
    }

    /**
     * Analyze project dependencies
     */
    /**
     * @param array<string, mixed> $dependencies
     * @return array<string, mixed>
     */
    private function analyzeProjectDependencies(int $projectId, array $dependencies): array
    {
        // Check tickets
        $tickets = $this->ticketMapper->findByProjectId($projectId, 0);
        $ticketCount = count($tickets);

        if ($ticketCount > 0) {
            $dependencies['dependencies']['tickets'] = $ticketCount;
            $dependencies['total_affected'] += $ticketCount;

            // Count comments and attachments for all tickets
            $totalComments = 0;
            $totalAttachments = 0;

            foreach ($tickets as $ticket) {
                $totalComments += $this->commentMapper->countByTicketId($ticket->getId());
                $totalAttachments += count($this->attachmentMapper->findByTicketId($ticket->getId()));
            }

            if ($totalComments > 0) {
                $dependencies['dependencies']['comments'] = $totalComments;
                $dependencies['total_affected'] += $totalComments;
            }
            if ($totalAttachments > 0) {
                $dependencies['dependencies']['attachments'] = $totalAttachments;
                $dependencies['total_affected'] += $totalAttachments;
            }
        }

        // Check assignment rules
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('helpdesk_assignments')
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $assignmentCount = (int)$result->fetchOne();
        $result->closeCursor();

        if ($assignmentCount > 0) {
            $dependencies['dependencies']['assignment_rules'] = $assignmentCount;
            $dependencies['total_affected'] += $assignmentCount;
        }

        // Check guest access
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('helpdesk_guest_access')
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $guestAccessCount = (int)$result->fetchOne();
        $result->closeCursor();

        if ($guestAccessCount > 0) {
            $dependencies['dependencies']['guest_access'] = $guestAccessCount;
            $dependencies['total_affected'] += $guestAccessCount;
        }

        if ($dependencies['total_affected'] > 0) {
            $dependencies['requires_cascade'] = true;
        }

        return $dependencies;
    }

    /**
     * Analyze KB article dependencies
     */
    /**
     * @param array<string, mixed> $dependencies
     * @return array<string, mixed>
     */
    private function analyzeKBArticleDependencies(int $articleId, array $dependencies): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('helpdesk_kb_comments')
            ->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $commentCount = (int)$result->fetchOne();
        $result->closeCursor();

        if ($commentCount > 0) {
            $dependencies['dependencies']['kb_comments'] = $commentCount;
            $dependencies['total_affected'] += $commentCount;
            // Comments are cascade-deleted with the article; still surface the count.
            $dependencies['requires_cascade'] = true;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('hd_kb_helpful')
            ->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $voteCount = (int)$result->fetchOne();
        $result->closeCursor();

        if ($voteCount > 0) {
            $dependencies['dependencies']['kb_helpful_votes'] = $voteCount;
            $dependencies['total_affected'] += $voteCount;
            $dependencies['requires_cascade'] = true;
        }

        return $dependencies;
    }

    /**
     * Analyze KB category dependencies
     */
    /**
     * @param array<string, mixed> $dependencies
     * @return array<string, mixed>
     */
    private function analyzeKBCategoryDependencies(int $categoryId, array $dependencies): array
    {
        // Check if any articles use this category
        $articles = $this->kbArticleMapper->findByCategory($this->kbCategoryMapper->find($categoryId)->getName());
        $articleCount = count($articles);

        if ($articleCount > 0) {
            $dependencies['dependencies']['articles'] = $articleCount;
            $dependencies['total_affected'] += $articleCount;
            $dependencies['can_delete'] = false; // Cannot delete category with articles
        }

        return $dependencies;
    }

    /**
     * Analyze template dependencies
     */
    /**
     * @param array<string, mixed> $dependencies
     * @return array<string, mixed>
     */
    private function analyzeTemplateDependencies(int $templateId, array $dependencies): array
    {
        // Templates are standalone, no dependencies
        return $dependencies;
    }

    /**
     * Analyze guest user dependencies
     */
    /**
     * @param array<string, mixed> $dependencies
     * @return array<string, mixed>
     */
    private function analyzeGuestUserDependencies(string $userId, array $dependencies): array
    {
        // Check guest access records
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('helpdesk_guest_access')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $accessCount = (int)$result->fetchOne();
        $result->closeCursor();

        if ($accessCount > 0) {
            $dependencies['dependencies']['project_access'] = $accessCount;
            $dependencies['total_affected'] += $accessCount;
        }

        if ($dependencies['total_affected'] > 0) {
            $dependencies['requires_cascade'] = true;
        }

        return $dependencies;
    }

    /**
     * Analyze assignment dependencies
     */
    /**
     * @param array<string, mixed> $dependencies
     * @return array<string, mixed>
     */
    private function analyzeAssignmentDependencies(int $assignmentId, array $dependencies): array
    {
        // Assignment rules are standalone, no dependencies
        return $dependencies;
    }

    /**
     * Delete ticket with dependencies
     */
    /**
     * @return array<string, int>
     */
    private function deleteTicket(int $ticketId, bool $cascade): array
    {
        // Caller (deleteEntity) already holds locks on survivor + merge shells.
        // Re-entrant withTicketLocks keeps the same set (or a subset).
        $shellIds = $this->ticketMapper->findIdsMergedInto($ticketId);
        $lockIds = array_values(array_unique(array_merge([$ticketId], $shellIds)));

        return $this->workflowLock->withTicketLocks(
            $lockIds,
            function () use ($ticketId): array {
                $deletedCounts = [];
                $shellIdsNow = $this->ticketMapper->findIdsMergedInto($ticketId);
                foreach ($shellIdsNow as $shellId) {
                    $shellCounts = $this->purgeTicketRowUnlocked($shellId);
                    foreach ($shellCounts as $key => $value) {
                        $deletedCounts[$key] = ($deletedCounts[$key] ?? 0) + $value;
                    }
                    $deletedCounts['merged_shells'] = ($deletedCounts['merged_shells'] ?? 0) + 1;
                }

                $survivorCounts = $this->purgeTicketRowUnlocked($ticketId);
                foreach ($survivorCounts as $key => $value) {
                    $deletedCounts[$key] = ($deletedCounts[$key] ?? 0) + $value;
                }

                return $deletedCounts;
            },
            'TicketCheck delete'
        );
    }

    /**
     * @return array<string, int>
     */
    private function purgeTicketRowUnlocked(int $ticketId): array
    {
        $deletedCounts = [];

        try {
            $ticket = $this->ticketMapper->find($ticketId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return [];
        }

        $commentCount = $this->commentMapper->deleteByTicketId($ticketId);
        if ($commentCount > 0) {
            $deletedCounts['comments'] = $commentCount;
        }

        $attachments = $this->attachmentMapper->findByTicketId($ticketId);
        $attachmentCount = count($attachments);
        if ($attachmentCount > 0) {
            $cleanupResult = $this->attachmentCleanupService->cleanupTicketAttachments($ticketId);
            $deletedCounts['attachments'] = $attachmentCount;
            $deletedCounts['files_cleaned'] = $cleanupResult['files_deleted'];
        }
        $this->attachmentMapper->deleteByTicketId($ticketId);

        $relations = $this->ticketRelationService->purgeForTicket($ticketId);
        foreach ($relations as $key => $count) {
            if ($count > 0) {
                $deletedCounts[$key] = $count;
            }
        }

        $this->ticketMapper->delete($ticket);

        return $deletedCounts;
    }

    /**
     * Delete customer with dependencies
     */
    /**
     * @return array<string, int>
     */
    private function deleteCustomer(int $customerId, bool $cascade): array
    {
        $deletedCounts = [];

        // Get all projects for this customer
        $projects = $this->projectMapper->findByCustomerId($customerId);

        foreach ($projects as $project) {
            $projectResult = $this->deleteProject($project->getId(), true);
            foreach ($projectResult as $key => $value) {
                $deletedCounts[$key] = ($deletedCounts[$key] ?? 0) + $value;
            }
        }

        // Delete customer
        $customer = $this->customerMapper->find($customerId);
        $this->customerMapper->delete($customer);

        return $deletedCounts;
    }

    /**
     * Delete project with dependencies
     */
    /**
     * @return array<string, int>
     */
    private function deleteProject(int $projectId, bool $cascade): array
    {
        $deletedCounts = [];

        // Lock snapshot includes merge shells (possibly other projects) so nested
        // deleteTicket() never expands locks downward mid-transaction.
        $maxPasses = 5;
        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $tickets = $this->ticketMapper->findByProjectId($projectId, 0);
            $projectIds = [];
            foreach ($tickets as $ticket) {
                $projectIds[] = (int) $ticket->getId();
            }

            if ($projectIds === []) {
                break;
            }

            $lockIds = $this->expandTicketIdsWithMergeShells($projectIds);
            $stable = false;
            $this->workflowLock->withTicketLocks(
                $lockIds,
                function () use ($projectId, $projectIds, &$deletedCounts, &$stable): void {
                    $freshTickets = $this->ticketMapper->findByProjectId($projectId, 0);
                    $freshIds = [];
                    foreach ($freshTickets as $ticket) {
                        $freshIds[] = (int) $ticket->getId();
                    }
                    sort($freshIds, SORT_NUMERIC);
                    $snapshotSorted = $projectIds;
                    sort($snapshotSorted, SORT_NUMERIC);
                    if ($freshIds !== $snapshotSorted) {
                        return;
                    }

                    foreach ($freshTickets as $ticket) {
                        $ticketResult = $this->deleteTicket($ticket->getId(), true);
                        foreach ($ticketResult as $key => $value) {
                            $deletedCounts[$key] = ($deletedCounts[$key] ?? 0) + $value;
                        }
                    }
                    $stable = true;
                },
                'TicketCheck project cascade delete'
            );

            if ($stable) {
                break;
            }
        }

        $leftover = $this->ticketMapper->findByProjectId($projectId, 0);
        if ($leftover !== []) {
            $leftoverProjectIds = array_values(array_map(static fn ($t) => (int) $t->getId(), $leftover));
            $leftoverLockIds = $this->expandTicketIdsWithMergeShells($leftoverProjectIds);
            $this->workflowLock->withTicketLocks(
                $leftoverLockIds,
                function () use ($projectId, &$deletedCounts): void {
                    $fresh = $this->ticketMapper->findByProjectId($projectId, 0);
                    foreach ($fresh as $ticket) {
                        $ticketResult = $this->deleteTicket($ticket->getId(), true);
                        foreach ($ticketResult as $key => $value) {
                            $deletedCounts[$key] = ($deletedCounts[$key] ?? 0) + $value;
                        }
                    }
                },
                'TicketCheck project cascade delete leftover'
            );
        }

        // Delete assignment rules
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('helpdesk_assignments')
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $assignmentIds = [];
        while ($row = $result->fetch()) {
            $assignmentIds[] = (int)$row['id'];
        }
        $result->closeCursor();

        foreach ($assignmentIds as $assignmentId) {
            $assignment = $this->assignmentMapper->find($assignmentId);
            $this->assignmentMapper->delete($assignment);
        }
        if (count($assignmentIds) > 0) {
            $deletedCounts['assignment_rules'] = count($assignmentIds);
        }

        // Delete guest access
        $qb = $this->db->getQueryBuilder();
        $qb->delete('helpdesk_guest_access')
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));
        $deletedAccessCount = $qb->executeStatement();

        if ($deletedAccessCount > 0) {
            $deletedCounts['guest_access'] = $deletedAccessCount;
        }

        // Delete project membership rows (no FK cascade).
        $qb = $this->db->getQueryBuilder();
        $qb->delete('hd_proj_members')
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));
        $deletedMembers = $qb->executeStatement();
        if ($deletedMembers > 0) {
            $deletedCounts['project_members'] = $deletedMembers;
        }

        // Delete project
        $project = $this->projectMapper->find($projectId);
        $this->projectMapper->delete($project);

        return $deletedCounts;
    }

    /**
     * Delete KB article
     */
    /**
     * @return array<string, int>
     */
    private function deleteKBArticle(int $articleId): array
    {
        $deletedCounts = [];

        $qb = $this->db->getQueryBuilder();
        $qb->delete('helpdesk_kb_comments')
            ->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)));
        $commentCount = $qb->executeStatement();
        if ($commentCount > 0) {
            $deletedCounts['kb_comments'] = $commentCount;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete('hd_kb_helpful')
            ->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)));
        $voteCount = $qb->executeStatement();
        if ($voteCount > 0) {
            $deletedCounts['kb_helpful_votes'] = $voteCount;
        }

        $article = $this->kbArticleMapper->find($articleId);
        $this->kbArticleMapper->delete($article);

        return $deletedCounts;
    }

    /**
     * Delete KB category
     */
    /**
     * @return array<string, int>
     */
    private function deleteKBCategory(int $categoryId): array
    {
        $category = $this->kbCategoryMapper->find($categoryId);
        $this->kbCategoryMapper->delete($category);

        return [];
    }

    /**
     * Delete template
     */
    /**
     * @return array<string, int>
     */
    private function deleteTemplate(int $templateId): array
    {
        $this->templateService->deleteTemplate($templateId);

        return [];
    }

    /**
     * Delete guest user with dependencies
     */
    /**
     * @return array<string, int>
     */
    private function deleteGuestUser(string $userId, bool $cascade): array
    {
        $deletedCounts = [];

        // Delete guest access records
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('helpdesk_guest_access')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $accessIds = [];
        while ($row = $result->fetch()) {
            $accessIds[] = (int)$row['id'];
        }
        $result->closeCursor();

        // Delete guest access records directly
        $qb = $this->db->getQueryBuilder();
        $qb->delete('helpdesk_guest_access')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $deletedAccessCount = $qb->executeStatement();

        if ($deletedAccessCount > 0) {
            $deletedCounts['project_access'] = $deletedAccessCount;
        }

        $user = $this->userManager->get($userId);
        if ($user) {
            $user->delete();
        }

        return $deletedCounts;
    }

    /**
     * Delete assignment
     */
    /**
     * @return array<string, int>
     */
    private function deleteAssignment(int $assignmentId): array
    {
        $assignment = $this->assignmentMapper->find($assignmentId);
        $this->assignmentMapper->delete($assignment);

        return [];
    }

    private function toIntId(int|string $id): int
    {
        return (int)$id;
    }

    private function toStringId(int|string $id): string
    {
        return (string)$id;
    }

    /**
     * Union ticket ids with every merge shell pointing at them (repeat until stable).
     * Needed so project cascade can lock shells that live in another project.
     *
     * @param list<int> $ticketIds
     * @return list<int>
     */
    private function expandTicketIdsWithMergeShells(array $ticketIds): array
    {
        $ids = [];
        foreach ($ticketIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        $changed = true;
        $guard = 0;
        while ($changed && $guard < 20) {
            $changed = false;
            $guard++;
            foreach (array_keys($ids) as $id) {
                foreach ($this->ticketMapper->findIdsMergedInto((int) $id) as $shellId) {
                    if (!isset($ids[$shellId])) {
                        $ids[$shellId] = $shellId;
                        $changed = true;
                    }
                }
            }
        }

        return array_values($ids);
    }
}

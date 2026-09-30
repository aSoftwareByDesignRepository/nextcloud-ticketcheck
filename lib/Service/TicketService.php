<?php

declare(strict_types=1);

/**
 * Ticket service for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\Attachment;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Service\AssignmentService;
use OCA\Ticketcheck\Service\AttachmentCleanupService;
use OCA\Ticketcheck\Service\CompanionNotificationService;
use OCA\Ticketcheck\Service\UserValidationService;
use OCP\IUserSession;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Service for managing tickets
 */
class TicketService
{
    private TicketMapper $ticketMapper;
    private CommentMapper $commentMapper;
    private AttachmentMapper $attachmentMapper;
    private AttachmentCleanupService $attachmentCleanupService;
    private AttachmentUploadService $attachmentUploadService;
    private TicketRelationService $ticketRelationService;
    private UserValidationService $userValidationService;
    private TicketWorkflowLock $workflowLock;
    private CompanionNotificationService $companionNotifications;
    private IUserSession $userSession;
    private IConfig $config;
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(
        TicketMapper $ticketMapper,
        CommentMapper $commentMapper,
        AttachmentMapper $attachmentMapper,
        AttachmentCleanupService $attachmentCleanupService,
        AttachmentUploadService $attachmentUploadService,
        TicketRelationService $ticketRelationService,
        UserValidationService $userValidationService,
        TicketWorkflowLock $workflowLock,
        CompanionNotificationService $companionNotifications,
        IUserSession $userSession,
        IConfig $config,
        LoggerInterface $logger,
        IDBConnection $db,
    ) {
        $this->ticketMapper = $ticketMapper;
        $this->commentMapper = $commentMapper;
        $this->attachmentMapper = $attachmentMapper;
        $this->attachmentCleanupService = $attachmentCleanupService;
        $this->attachmentUploadService = $attachmentUploadService;
        $this->ticketRelationService = $ticketRelationService;
        $this->userValidationService = $userValidationService;
        $this->workflowLock = $workflowLock;
        $this->companionNotifications = $companionNotifications;
        $this->userSession = $userSession;
        $this->config = $config;
        $this->logger = $logger;
        $this->db = $db;
    }

    /**
     * Create a new ticket
     *
     * @param array<string, mixed> $data
     * @return Ticket
     * @throws \Exception
     */
    public function createTicket(array $data): Ticket
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $ticket = new Ticket();
        $ticket->setTicketNumber($this->generateUniqueTicketNumber());
        $ticket->setTitle($data['title'] ?? '');
        $ticket->setDescription($data['description'] ?? '');
        $ticket->setCustomerId($data['customer_id'] ?? null);
        $ticket->setCustomerEmail($data['customer_email'] ?? $user->getEMailAddress());
        $ticket->setCustomerName($data['customer_name'] ?? $user->getDisplayName());
        $ticket->setProjectId($data['project_id'] ?? null);
        $ticket->setCategory($data['category'] ?? Ticket::CATEGORY_GENERAL);
        $ticket->setPriority($data['priority'] ?? Ticket::PRIORITY_NORMAL);
        $ticket->setStatus(Ticket::STATUS_NEW);
        $assignedTo = isset($data['assigned_to']) ? (string) $data['assigned_to'] : '';
        if ($assignedTo !== '') {
            $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;
            $validation = $this->userValidationService->validateUserForAssignment($assignedTo, $projectId);
            if (!$validation['valid']) {
                throw new \Exception('Invalid assignee: ' . $validation['reason']);
            }
            $ticket->setAssignedTo($assignedTo);
        } else {
            $ticket->setAssignedTo(null);
        }
        $ticket->setCreatedAt(new \DateTime());
        $ticket->setUpdatedAt(Ticket::nextUpdatedAt($ticket->getUpdatedAt()));
        $ticket->setCreatedBy($user->getUID());
        $ticket->setCreatedByGuest($data['created_by_guest'] ?? false);

        // Calculate SLA due dates
        $this->calculateSLADates($ticket);

        // Validate ticket
        $errors = $ticket->validate();
        if (!empty($errors)) {
            throw new \Exception('Validation failed: ' . implode(', ', $errors));
        }

        // Unique ticket_number has a DB unique index. Two concurrent creates can still
        // collide after the pre-check; retry with a fresh number instead of failing closed.
        // When an outer TX is open (e.g. split), wrap each attempt in a nested TX/savepoint
        // so a unique violation does not abort the whole PostgreSQL transaction.
        $maxInsertAttempts = 5;
        $useSavepoints = $this->db->inTransaction();
        for ($insertAttempt = 1; $insertAttempt <= $maxInsertAttempts; $insertAttempt++) {
            if ($useSavepoints) {
                $this->db->beginTransaction();
            }
            try {
                $saved = $this->ticketMapper->insert($ticket);
                if ($useSavepoints) {
                    $this->db->commit();
                }
                return $saved;
            } catch (\OCP\DB\Exception $e) {
                if ($useSavepoints && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                $isUniqueViolation = $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION;
                if (!$isUniqueViolation || $insertAttempt === $maxInsertAttempts) {
                    $this->logger->error('Failed to create ticket: ' . $e->getMessage(), ['exception' => $e]);
                    throw new \Exception('Failed to create ticket', 0, $e);
                }
                $ticket->setTicketNumber($this->generateUniqueTicketNumber());
            }
        }

        throw new \Exception('Failed to create ticket');
    }

    /**
     * Update a ticket
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @return Ticket
     * @throws \Exception
     */
    public function updateTicket(int $id, array $data, ?callable $assertAuthorized = null): Ticket
    {
        try {
            return $this->workflowLock->withTicketLocks(
                [$id],
                fn (): Ticket => $this->updateTicketLocked($id, $data, $assertAuthorized),
                'TicketCheck update'
            );
        } catch (\InvalidArgumentException $e) {
            throw new \Exception($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param null|callable(Ticket):void $assertAuthorized Re-check authz on the locked fresh entity
     */
    private function updateTicketLocked(int $id, array $data, ?callable $assertAuthorized = null): Ticket
    {
        try {
            $ticket = $this->ticketMapper->find($id);
        } catch (\Exception $e) {
            throw new \Exception('Ticket not found');
        }

        $this->assertTicketNotMerged($ticket);

        if ($assertAuthorized !== null) {
            $assertAuthorized($ticket);
        }

        // Update allowed fields
        if (isset($data['title'])) {
            $ticket->setTitle($data['title']);
        }
        if (isset($data['description'])) {
            $ticket->setDescription($data['description']);
        }
        if (isset($data['category'])) {
            $ticket->setCategory($data['category']);
        }
        if (isset($data['priority'])) {
            $ticket->setPriority($data['priority']);
            $this->calculateSLADates($ticket); // Recalculate SLA on priority change
        }
        if (isset($data['project_id'])) {
            $ticket->setProjectId($data['project_id']);
        }
        if (array_key_exists('customer_id', $data)) {
            $ticket->setCustomerId($data['customer_id']);
        }
        if (array_key_exists('customer_email', $data) && $data['customer_email'] !== null) {
            $ticket->setCustomerEmail((string) $data['customer_email']);
        }
        if (array_key_exists('customer_name', $data) && $data['customer_name'] !== null) {
            $ticket->setCustomerName((string) $data['customer_name']);
        }
        if (array_key_exists('status', $data) && $data['status'] !== null && $data['status'] !== '') {
            $status = (string) $data['status'];
            if (!in_array($status, Ticket::getStatuses(), true)) {
                throw new \Exception('Invalid status');
            }
            $ticket->setStatus($status);
            $this->syncClosedAtForStatus($ticket, $status);
        }
        if (array_key_exists('assigned_to', $data)) {
            $assignedRaw = $data['assigned_to'];
            $assignedTo = $assignedRaw === null ? '' : (string) $assignedRaw;
            if ($assignedTo !== '') {
                $validation = $this->userValidationService->validateUserForAssignment(
                    $assignedTo,
                    (int) $ticket->getProjectId()
                );
                if (!$validation['valid']) {
                    throw new \Exception('Invalid assignee: ' . $validation['reason']);
                }
            }
            $ticket->setAssignedTo($assignedTo !== '' ? $assignedTo : null);
        }

        $ticket->setUpdatedAt(Ticket::nextUpdatedAt($ticket->getUpdatedAt()));

        // Validate ticket
        $errors = $ticket->validate();
        if (!empty($errors)) {
            throw new \Exception('Validation failed: ' . implode(', ', $errors));
        }

        try {
            return $this->persistUnmergedOrFail($ticket, 'Failed to update ticket');
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Cannot modify a ticket that has been merged into another ticket') {
                throw $e;
            }
            $this->logger->error('Failed to update ticket: ' . $e->getMessage(), ['exception' => $e]);
            throw new \Exception('Failed to update ticket');
        }
    }

    /**
     * Change ticket status
     *
     * @param int $id
     * @param string $status
     * @return Ticket
     * @throws \Exception
     */
    public function changeStatus(int $id, string $status, ?callable $assertAuthorized = null): Ticket
    {
        try {
            return $this->workflowLock->withTicketLocks(
                [$id],
                fn (): Ticket => $this->changeStatusLocked($id, $status, $assertAuthorized),
                'TicketCheck status'
            );
        } catch (\InvalidArgumentException $e) {
            throw new \Exception($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param null|callable(Ticket):void $assertAuthorized
     */
    private function changeStatusLocked(int $id, string $status, ?callable $assertAuthorized = null): Ticket
    {
        try {
            $ticket = $this->ticketMapper->find($id);
        } catch (\Exception $e) {
            throw new \Exception('Ticket not found');
        }

        $this->assertTicketNotMerged($ticket);
        if ($assertAuthorized !== null) {
            $assertAuthorized($ticket);
        }

        if (!in_array($status, Ticket::getStatuses(), true)) {
            throw new \Exception('Invalid status');
        }

        $ticket->setStatus($status);
        $ticket->setUpdatedAt(Ticket::nextUpdatedAt($ticket->getUpdatedAt()));
        $this->syncClosedAtForStatus($ticket, $status);

        try {
            return $this->persistUnmergedOrFail($ticket, 'Failed to change ticket status');
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Cannot modify a ticket that has been merged into another ticket') {
                throw $e;
            }
            $this->logger->error('Failed to change ticket status: ' . $e->getMessage(), ['exception' => $e]);
            throw new \Exception('Failed to change ticket status');
        }
    }

    /**
     * Assign ticket to user
     *
     * @param int $id
     * @param string $userId
     * @return Ticket
     * @throws \Exception
     */
    public function assignTicket(int $id, ?string $userId, ?callable $assertAuthorized = null): Ticket
    {
        try {
            return $this->workflowLock->withTicketLocks(
                [$id],
                fn (): Ticket => $this->assignTicketLocked($id, $userId, $assertAuthorized),
                'TicketCheck assign'
            );
        } catch (\InvalidArgumentException $e) {
            throw new \Exception($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param null|callable(Ticket):void $assertAuthorized
     */
    private function assignTicketLocked(int $id, ?string $userId, ?callable $assertAuthorized = null): Ticket
    {
        try {
            $ticket = $this->ticketMapper->find($id);
        } catch (\Exception $e) {
            throw new \Exception('Ticket not found');
        }

        $this->assertTicketNotMerged($ticket);
        if ($assertAuthorized !== null) {
            $assertAuthorized($ticket);
        }

        if ($userId !== null && $userId !== '') {
            $validation = $this->userValidationService->validateUserForAssignment(
                $userId,
                (int) $ticket->getProjectId()
            );
            if (!$validation['valid']) {
                throw new \Exception('Invalid assignee: ' . $validation['reason']);
            }
        }

        // Set assigned user (null for unassigned)
        $ticket->setAssignedTo($userId !== null && $userId !== '' ? $userId : null);
        $ticket->setUpdatedAt(Ticket::nextUpdatedAt($ticket->getUpdatedAt()));

        // Auto-change status from NEW to IN_PROGRESS when assigning to someone
        if ($userId && $ticket->getStatus() === Ticket::STATUS_NEW) {
            $ticket->setStatus(Ticket::STATUS_IN_PROGRESS);
        }

        try {
            $saved = $this->persistUnmergedOrFail($ticket, 'Failed to assign ticket');
            $actor = $this->userSession->getUser()?->getUID();
            if (is_string($actor) && $actor !== '') {
                $this->companionNotifications->notifyAssigned($saved, $actor);
            }
            return $saved;
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Cannot modify a ticket that has been merged into another ticket') {
                throw $e;
            }
            $this->logger->error('Failed to assign ticket: ' . $e->getMessage(), ['exception' => $e]);
            throw new \Exception('Failed to assign ticket');
        }
    }

    /**
     * Serialize guest create/reply activity so the daily rate ceiling cannot be
     * exceeded by parallel requests that both pass a check-then-act gate.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws \InvalidArgumentException when another request holds the gate
     */
    public function withGuestActivityGate(string $userId, callable $callback): mixed
    {
        $userId = trim($userId);
        if ($userId === '') {
            return $callback();
        }

        return $this->workflowLock->withExclusiveKey(
            'ticketcheck/guest/activity/' . $userId,
            $callback,
            'TicketCheck guest rate gate'
        );
    }

    /**
     * Run $callback under an exclusive per-guest password-fail gate so parallel
     * wrong-password POSTs cannot exceed the attempt ceiling.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withGuestPasswordFailGate(string $userId, callable $callback): mixed
    {
        $userId = trim($userId);
        if ($userId === '') {
            return $callback();
        }

        return $this->workflowLock->withExclusiveKey(
            'ticketcheck/guest/pwfail/' . $userId,
            $callback,
            'TicketCheck guest password fail gate'
        );
    }

    /**
     * Delete a ticket and any merge shells that point at it.
     *
     * Shells must be removed with the survivor: otherwise getActiveTicket /
     * inbound/+address hops break on dangling merged_into_id rows.
     *
     * @param int $id
     * @param null|callable(\OCA\Ticketcheck\Db\Ticket):void $assertAuthorized
     * @return void
     * @throws \Exception
     */
    public function deleteTicket(int $id, ?callable $assertAuthorized = null): void
    {
        try {
            $maxPasses = 5;
            $stable = false;
            for ($pass = 0; $pass < $maxPasses; $pass++) {
                $shellIds = $this->ticketMapper->findIdsMergedInto($id);
                $lockIds = array_values(array_unique(array_merge([$id], $shellIds)));
                $stable = false;
                $this->workflowLock->withTicketLocks(
                    $lockIds,
                    function () use ($id, $lockIds, $assertAuthorized, &$stable): void {
                        $shellIdsNow = $this->ticketMapper->findIdsMergedInto($id);
                        $needed = array_values(array_unique(array_merge([$id], $shellIdsNow)));
                        sort($needed, SORT_NUMERIC);
                        $lockedSorted = $lockIds;
                        sort($lockedSorted, SORT_NUMERIC);
                        if ($needed !== $lockedSorted) {
                            // Shell set changed while waiting for locks — retry.
                            return;
                        }

                        try {
                            $ticket = $this->ticketMapper->find($id);
                        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                            $stable = true;
                            return;
                        }
                        if ($assertAuthorized !== null) {
                            $assertAuthorized($ticket);
                        }

                        foreach ($shellIdsNow as $shellId) {
                            $this->purgeTicketRowAndDependencies($shellId);
                        }
                        $this->purgeTicketRowAndDependencies($id);
                        $stable = true;
                    },
                    'TicketCheck delete'
                );
                if ($stable) {
                    break;
                }
            }
            if (!$stable) {
                throw new \Exception('Failed to delete ticket: merge shells changed under concurrency');
            }
        } catch (\InvalidArgumentException $e) {
            throw new \Exception($e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            if (str_starts_with($e->getMessage(), 'Failed to delete ticket')) {
                throw $e;
            }
            // Preserve authz denials (AppAccessDeniedException extends Exception).
            if ($e instanceof \OCA\Ticketcheck\Exception\AppAccessDeniedException) {
                throw $e;
            }
            $this->logger->error('Failed to delete ticket: ' . $e->getMessage(), ['exception' => $e]);
            throw new \Exception('Failed to delete ticket');
        }
    }

    /**
     * Purge comments, attachments, relations, and the ticket row (caller holds locks).
     */
    private function purgeTicketRowAndDependencies(int $id): void
    {
        try {
            $ticket = $this->ticketMapper->find($id);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Already removed (e.g. project cascade deleted a shell first).
            return;
        }

        $this->commentMapper->deleteByTicketId($id);

        $cleanupResult = $this->attachmentCleanupService->cleanupTicketAttachments($id);
        $this->logger->info('Ticket attachment cleanup completed', [
            'ticket_id' => $id,
            'files_deleted' => $cleanupResult['files_deleted'],
            'files_failed' => $cleanupResult['files_failed'],
            'directories_cleaned' => $cleanupResult['directories_cleaned'],
        ]);

        $this->attachmentMapper->deleteByTicketId($id);
        $this->ticketRelationService->purgeForTicket($id);
        $this->ticketMapper->delete($ticket);
    }

    /**
     * Add comment to ticket
     *
     * @param int $ticketId
     * @param string $content
     * @param bool $isInternal
     * @return Comment
     * @throws \Exception
     */
    public function addComment(
        int $ticketId,
        string $content,
        bool $isInternal = false,
        bool $requireOpen = false,
        ?callable $assertAuthorized = null
    ): Comment {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        try {
            return $this->workflowLock->withTicketLocks(
                [$ticketId],
                fn (): Comment => $this->addCommentLocked(
                    $ticketId,
                    $content,
                    $isInternal,
                    $user,
                    $requireOpen,
                    $assertAuthorized
                ),
                'TicketCheck comment'
            );
        } catch (\InvalidArgumentException $e) {
            throw new \Exception($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param \OCP\IUser $user
     * @param null|callable(Ticket):void $assertAuthorized
     */
    private function addCommentLocked(
        int $ticketId,
        string $content,
        bool $isInternal,
        $user,
        bool $requireOpen = false,
        ?callable $assertAuthorized = null
    ): Comment {
        // Callers must authorize the surviving ticket and pass its id.
        // Silent merge-chain hops here would re-open IDOR if authz ran on the source.
        try {
            $ticket = $this->ticketMapper->find($ticketId);
        } catch (\Exception $e) {
            throw new \Exception('Ticket not found');
        }
        $this->assertTicketNotMerged($ticket);
        if ($assertAuthorized !== null) {
            $assertAuthorized($ticket);
        }
        if ($requireOpen && !$ticket->isOpen()) {
            throw new \Exception('portal_ticket_closed_no_reply');
        }

        $comment = new Comment();
        $comment->setTicketId($ticketId);
        $comment->setUserId($user->getUID());

        // Get display name - fallback to UID if display name is empty
        $displayName = $user->getDisplayName();
        if (empty($displayName) || trim($displayName) === '') {
            $displayName = $user->getUID();
        }
        $comment->setAuthorName($displayName);

        $comment->setAuthorEmail($user->getEMailAddress());
        $comment->setContent($content);
        $comment->setIsInternal($isInternal);
        $comment->setCreatedAt(new \DateTime());

        // Validate comment
        $errors = $comment->validate();
        if (!empty($errors)) {
            throw new \Exception('Validation failed: ' . implode(', ', $errors));
        }

        try {
            $savedComment = $this->commentMapper->insert($comment);

            // Atomic bump: if a concurrent merge won, remove the orphan comment
            // only while it still points at this ticket (never the survivor).
            if (!$this->ticketMapper->touchUpdatedAtIfUnmerged($ticketId)) {
                try {
                    $this->commentMapper->deleteIfOnTicket((int) $savedComment->getId(), $ticketId);
                } catch (\Throwable $cleanup) {
                    $this->logger->error('Failed to clean orphan comment after merge race', [
                        'ticket_id' => $ticketId,
                        'comment_id' => $savedComment->getId(),
                        'exception' => $cleanup,
                    ]);
                }
                throw new \Exception('Cannot modify a ticket that has been merged into another ticket');
            }

            /** @var Comment $savedComment */
            if (!$isInternal) {
                try {
                    $freshTicket = $this->ticketMapper->find($ticketId);
                    $this->companionNotifications->notifyPublicComment($freshTicket, $user->getUID());
                } catch (\Throwable $notifyError) {
                    $this->logger->warning('TicketCheck public comment notification failed', [
                        'ticket_id' => $ticketId,
                        'exception' => $notifyError,
                    ]);
                }
            }
            return $savedComment;
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Cannot modify a ticket that has been merged into another ticket') {
                throw $e;
            }
            $this->logger->error('Failed to add comment: ' . $e->getMessage(), ['exception' => $e]);
            throw new \Exception('Failed to add comment');
        }
    }

    /**
     * Add comment from external source (e.g. inbound email) - no user session required
     *
     * @param int $ticketId
     * @param string $content
     * @param string $authorEmail
     * @param string $authorName
     * @return Comment
     * @throws \Exception
     */
    public function addCommentFromEmail(int $ticketId, string $content, string $authorEmail, string $authorName = ''): Comment
    {
        // Plus-addressing may still target a merged source id; follow the chain.
        // Sender authz is re-checked under the lock against the ticket being written
        // so a concurrent merge cannot inject a comment onto a different survivor.
        // One retry covers a merge that lands between resolve and the unmerged touch.
        $lastError = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $ticket = $this->getActiveTicket($ticketId);
            } catch (\Exception $e) {
                throw new \Exception('Ticket not found');
            }
            $activeId = (int) $ticket->getId();

            try {
                return $this->workflowLock->withTicketLocks(
                    [$activeId],
                    function () use ($activeId, $content, $authorEmail, $authorName): Comment {
                        // Re-resolve under the lock. If a merge just finished, the
                        // source lock does not protect the survivor — bail out so
                        // the outer retry locks the real active ticket.
                        $ticket = $this->getActiveTicket($activeId);
                        $lockedId = (int) $ticket->getId();
                        if ($lockedId !== $activeId) {
                            throw new \Exception('Cannot modify a ticket that has been merged into another ticket');
                        }

                        $customerEmail = strtolower(trim((string) $ticket->getCustomerEmail()));
                        $senderEmail = strtolower(trim($authorEmail));
                        if ($customerEmail === '' || $customerEmail !== $senderEmail) {
                            throw new \Exception('Sender not authorized for this ticket');
                        }

                        $comment = new Comment();
                        $comment->setTicketId($lockedId);
                        $comment->setUserId(null);
                        $comment->setAuthorName(trim($authorName) !== '' ? trim($authorName) : $authorEmail);
                        $comment->setAuthorEmail($authorEmail);
                        $comment->setContent($content);
                        $comment->setIsInternal(false);
                        $comment->setCreatedAt(new \DateTime());

                        $errors = $comment->validate();
                        if (!empty($errors)) {
                            throw new \Exception('Validation failed: ' . implode(', ', $errors));
                        }

                        $savedComment = $this->commentMapper->insert($comment);
                        if ($this->ticketMapper->touchUpdatedAtIfUnmerged($lockedId)) {
                            /** @var Comment $savedComment */
                            return $savedComment;
                        }
                        try {
                            $this->commentMapper->deleteIfOnTicket((int) $savedComment->getId(), $lockedId);
                        } catch (\Throwable $cleanup) {
                            $this->logger->error('Failed to clean orphan inbound comment after merge race', [
                                'ticket_id' => $lockedId,
                                'comment_id' => $savedComment->getId(),
                                'exception' => $cleanup,
                            ]);
                        }
                        throw new \Exception('Cannot modify a ticket that has been merged into another ticket');
                    },
                    'TicketCheck inbound comment'
                );
            } catch (\InvalidArgumentException $e) {
                $lastError = new \Exception($e->getMessage(), 0, $e);
                continue;
            } catch (\Exception $e) {
                if ($e->getMessage() === 'Sender not authorized for this ticket') {
                    throw $e;
                }
                if ($e->getMessage() === 'Cannot modify a ticket that has been merged into another ticket') {
                    $lastError = $e;
                    // Follow the new survivor on the next attempt.
                    $ticketId = $activeId;
                    continue;
                }
                $this->logger->error('Failed to add comment from email: ' . $e->getMessage(), ['exception' => $e]);
                throw new \Exception('Failed to add comment');
            }
        }

        throw $lastError ?? new \Exception('Failed to add comment');
    }

    /**
     * Get comments for a ticket
     *
     * @param int $ticketId
     * @param bool $includeInternal
     * @return list<Comment>
     */
    public function getComments(int $ticketId, bool $includeInternal = true): array
    {
        return array_values($this->commentMapper->findByTicketId($ticketId, $includeInternal));
    }

    /**
     * Get attachments for a ticket
     *
     * @param int $ticketId
     * @return list<Attachment>
     */
    public function getAttachments(int $ticketId): array
    {
        return array_values($this->attachmentMapper->findByTicketId($ticketId));
    }

    /**
     * Whether the viewer may download this attachment.
     *
     * Ticket-level attachments (no comment) are visible to anyone who can view the ticket.
     * Attachments on internal notes are hidden unless the viewer may see internal notes —
     * otherwise sequential attachment IDs would leak private staff files.
     */
    public function canViewerAccessAttachment(Attachment $attachment, bool $canViewInternalNotes): bool
    {
        if ($canViewInternalNotes) {
            return true;
        }

        $commentId = $attachment->getCommentId();
        if ($commentId === null || $commentId <= 0) {
            return true;
        }

        try {
            $comment = $this->commentMapper->find($commentId);
        } catch (\Throwable $e) {
            // Fail closed: orphaned comment_id must not expose bytes.
            $this->logger->warning('Attachment references missing comment; denying download', [
                'attachment_id' => $attachment->getId(),
                'comment_id' => $commentId,
                'exception' => $e,
            ]);
            return false;
        }

        return !$comment->getIsInternal();
    }

    /**
     * Generate a unique ticket number
     *
     * @return string
     */
    private function generateUniqueTicketNumber(): string
    {
        $maxAttempts = 10;
        $attempt = 0;

        do {
            $ticketNumber = Ticket::generateTicketNumber();
            try {
                $this->ticketMapper->findByTicketNumber($ticketNumber);
                // If we get here, the number exists, try again
                $attempt++;
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Perfect, this number doesn't exist yet
                return $ticketNumber;
            }
        } while ($attempt < $maxAttempts);

        throw new \Exception('Failed to generate unique ticket number');
    }

    /**
     * Recalculate SLA due dates for the ticket's current priority.
     * Used by updateTicket and escalation so priority changes keep deadlines consistent.
     */
    public function recalculateSlaDates(Ticket $ticket): void
    {
        $this->calculateSLADates($ticket);
    }

    /**
     * Calculate SLA due dates based on priority
     *
     * @param Ticket $ticket
     * @return void
     */
    private function calculateSLADates(Ticket $ticket): void
    {
        // Get SLA settings from config (hours)
        $slaResponseTime = [
            Ticket::PRIORITY_URGENT => 4,
            Ticket::PRIORITY_HIGH => 8,
            Ticket::PRIORITY_NORMAL => 24,
            Ticket::PRIORITY_LOW => 48,
        ];

        $slaResolutionTime = [
            Ticket::PRIORITY_URGENT => 24,
            Ticket::PRIORITY_HIGH => 48,
            Ticket::PRIORITY_NORMAL => 120, // 5 days
            Ticket::PRIORITY_LOW => 240, // 10 days
        ];

        $priority = $ticket->getPriority();
        $responseHours = $slaResponseTime[$priority] ?? 24;
        $resolutionHours = $slaResolutionTime[$priority] ?? 120;

        $now = new \DateTime();
        $responseDue = (clone $now)->modify("+{$responseHours} hours");
        $resolutionDue = (clone $now)->modify("+{$resolutionHours} hours");

        $ticket->setSlaResponseDue($responseDue);
        $ticket->setSlaResolutionDue($resolutionDue);

        // Fresh SLA clock: allow breach alerts for the new due dates (e.g. after priority change).
        $ticket->setSlaResponseAlertedAt(null);
        $ticket->setSlaResolutionAlertedAt(null);
    }

    /**
     * Add attachment to ticket
     *
     * @param int $ticketId
     * @param string $filePath
     * @param string $fileName
     * @param int $fileSize
     * @param string $mimeType
     * @return \OCA\Ticketcheck\Db\Attachment
     * @throws \Exception
     */
    public function addAttachment(
        int $ticketId,
        string $filePath,
        string $fileName,
        int $fileSize,
        string $mimeType,
        ?int $commentId = null,
        bool $requireOpen = false,
        ?callable $assertAuthorized = null,
        bool $rejectInternalComments = false
    ): \OCA\Ticketcheck\Db\Attachment {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        try {
            return $this->workflowLock->withTicketLocks(
                [$ticketId],
                fn (): Attachment => $this->addAttachmentLocked(
                    $ticketId,
                    $filePath,
                    $fileName,
                    $fileSize,
                    $mimeType,
                    $commentId,
                    $user,
                    $requireOpen,
                    $assertAuthorized,
                    $rejectInternalComments
                ),
                'TicketCheck attachment'
            );
        } catch (\InvalidArgumentException $e) {
            throw new \Exception($e->getMessage(), 0, $e);
        }
    }

    /**
     * Persist upload bytes and insert the attachment row under one ticket lock
     * (closes merge↔orphan races where files were written before the lock).
     *
     * @param null|callable(Ticket):void $assertAuthorized
     */
    public function addAttachmentFromUploadedFile(
        int $ticketId,
        string $tmpPath,
        string $originalName,
        int $fileSize,
        string $mimeType,
        ?int $commentId = null,
        bool $requireOpen = false,
        ?callable $assertAuthorized = null,
        bool $rejectInternalComments = false
    ): Attachment {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        try {
            return $this->workflowLock->withTicketLocks(
                [$ticketId],
                function () use (
                    $ticketId,
                    $tmpPath,
                    $originalName,
                    $fileSize,
                    $mimeType,
                    $commentId,
                    $user,
                    $requireOpen,
                    $assertAuthorized,
                    $rejectInternalComments
                ): Attachment {
                    try {
                        $ticket = $this->ticketMapper->find($ticketId);
                    } catch (\Exception $e) {
                        throw new \Exception('Ticket not found');
                    }
                    $this->assertTicketNotMerged($ticket);
                    if ($assertAuthorized !== null) {
                        $assertAuthorized($ticket);
                    }
                    if ($requireOpen && !$ticket->isOpen()) {
                        throw new \Exception('portal_ticket_closed_no_reply');
                    }
                    $boundCommentId = $this->assertCommentBelongsToTicket(
                        $ticketId,
                        $commentId,
                        $rejectInternalComments
                    );

                    $storedName = $this->attachmentUploadService->persistUploadedFile(
                        $ticketId,
                        $tmpPath,
                        $originalName
                    );
                    if ($storedName === null) {
                        throw new \Exception('Failed to save file');
                    }

                    try {
                        return $this->insertAttachmentRow(
                            $ticketId,
                            $storedName,
                            $originalName,
                            $fileSize,
                            $mimeType,
                            $boundCommentId,
                            $user
                        );
                    } catch (\Throwable $e) {
                        $this->attachmentUploadService->deletePersistedFile($ticketId, $storedName);
                        throw $e;
                    }
                },
                'TicketCheck attachment upload'
            );
        } catch (\InvalidArgumentException $e) {
            throw new \Exception($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param \OCP\IUser $user
     * @param null|callable(Ticket):void $assertAuthorized
     */
    private function addAttachmentLocked(
        int $ticketId,
        string $filePath,
        string $fileName,
        int $fileSize,
        string $mimeType,
        ?int $commentId,
        $user,
        bool $requireOpen = false,
        ?callable $assertAuthorized = null,
        bool $rejectInternalComments = false
    ): Attachment {
        try {
            $ticket = $this->ticketMapper->find($ticketId);
        } catch (\Exception $e) {
            throw new \Exception('Ticket not found');
        }
        $this->assertTicketNotMerged($ticket);
        if ($assertAuthorized !== null) {
            $assertAuthorized($ticket);
        }
        if ($requireOpen && !$ticket->isOpen()) {
            throw new \Exception('portal_ticket_closed_no_reply');
        }
        $boundCommentId = $this->assertCommentBelongsToTicket(
            $ticketId,
            $commentId,
            $rejectInternalComments
        );

        return $this->insertAttachmentRow(
            $ticketId,
            $filePath,
            $fileName,
            $fileSize,
            $mimeType,
            $boundCommentId,
            $user
        );
    }

    /**
     * @param \OCP\IUser $user
     */
    private function insertAttachmentRow(
        int $ticketId,
        string $filePath,
        string $fileName,
        int $fileSize,
        string $mimeType,
        ?int $commentId,
        $user
    ): Attachment {
        $attachment = new Attachment();
        $attachment->setTicketId($ticketId);
        $attachment->setCommentId($commentId);
        $attachment->setFilePath($filePath);
        $attachment->setFileName($fileName);
        $attachment->setFileSize($fileSize);
        $attachment->setMimeType($mimeType);
        $attachment->setUploadedBy($user->getUID());
        $attachment->setUploadedAt(new \DateTime());

        $errors = $attachment->validate();
        if (!empty($errors)) {
            throw new \Exception('Validation failed: ' . implode(', ', $errors));
        }

        try {
            $savedAttachment = $this->attachmentMapper->insert($attachment);

            if (!$this->ticketMapper->touchUpdatedAtIfUnmerged($ticketId)) {
                try {
                    $attachmentId = (int) $savedAttachment->getId();
                    if ($this->attachmentMapper->deleteIfOnTicket($attachmentId, $ticketId)) {
                        $dataDir = (string) $this->config->getSystemValue('datadirectory', '');
                        $safePath = basename($filePath);
                        if ($safePath !== '' && $safePath !== '.' && $dataDir !== '') {
                            $absolute = rtrim($dataDir, '/') . '/helpdesk_attachments/' . $ticketId . '/' . $safePath;
                            if (is_file($absolute)) {
                                @unlink($absolute);
                            }
                        }
                    }
                } catch (\Throwable $cleanup) {
                    // best-effort: orphan cleanup after merge race; failure path still throws the merge error
                    $this->logger->error('Failed to clean orphan attachment after merge race', [
                        'ticket_id' => $ticketId,
                        'attachment_id' => $savedAttachment->getId(),
                        'exception' => $cleanup,
                    ]);
                }
                throw new \Exception('Cannot modify a ticket that has been merged into another ticket');
            }

            /** @var Attachment $savedAttachment */
            return $savedAttachment;
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Cannot modify a ticket that has been merged into another ticket') {
                throw $e;
            }
            $this->logger->error('Failed to add attachment: ' . $e->getMessage(), ['exception' => $e]);
            throw new \Exception('Failed to add attachment');
        }
    }

    /**
     * Ensure comment_id belongs to this ticket (and is public when required).
     */
    private function assertCommentBelongsToTicket(
        int $ticketId,
        ?int $commentId,
        bool $rejectInternalComments
    ): ?int {
        if ($commentId === null) {
            return null;
        }
        try {
            $comment = $this->commentMapper->find($commentId);
        } catch (\Exception $e) {
            throw new \Exception('Invalid comment');
        }
        if ((int) $comment->getTicketId() !== $ticketId) {
            throw new \Exception('Invalid comment');
        }
        if ($rejectInternalComments && $comment->getIsInternal()) {
            throw new \Exception('Invalid comment');
        }
        return $commentId;
    }

    /**
     * Delete an attachment (DB row and file bytes) under the ticket workflow lock,
     * so it cannot race a concurrent merge that re-points the row to the survivor.
     *
     * @param null|callable(Ticket):void $assertAuthorized
     * @throws \Exception 'Attachment not found' when the row is missing or no longer on this ticket
     */
    public function deleteAttachment(int $ticketId, int $attachmentId, ?callable $assertAuthorized = null): void
    {
        try {
            $this->workflowLock->withTicketLocks(
                [$ticketId],
                function () use ($ticketId, $attachmentId, $assertAuthorized): void {
                    try {
                        $ticket = $this->ticketMapper->find($ticketId);
                    } catch (\Exception $e) {
                        throw new \Exception('Ticket not found');
                    }
                    $this->assertTicketNotMerged($ticket);
                    if ($assertAuthorized !== null) {
                        $assertAuthorized($ticket);
                    }
                    $this->deleteAttachmentLocked($ticketId, $attachmentId);
                },
                'TicketCheck attachment delete'
            );
        } catch (\InvalidArgumentException $e) {
            throw new \Exception($e->getMessage(), 0, $e);
        }
    }

    private function deleteAttachmentLocked(int $ticketId, int $attachmentId): void
    {
        try {
            $attachment = $this->attachmentMapper->find($attachmentId);
        } catch (\Exception $e) {
            throw new \Exception('Attachment not found');
        }
        if ((int) $attachment->getTicketId() !== $ticketId) {
            // A merge may have re-pointed the row to the survivor; it is no
            // longer this ticket's attachment, so never touch row or bytes here.
            throw new \Exception('Attachment not found');
        }

        // Row first: if this fails nothing is lost. A crash after the row
        // delete can only leak orphan bytes, never leave a dangling row.
        if (!$this->attachmentMapper->deleteIfOnTicket($attachmentId, $ticketId)) {
            throw new \Exception('Attachment not found');
        }

        $dataDir = (string) $this->config->getSystemValue('datadirectory', '');
        $safeName = basename((string) $attachment->getFilePath());
        if ($dataDir !== '' && $safeName !== '' && $safeName !== '.') {
            $absolute = rtrim($dataDir, '/') . '/helpdesk_attachments/' . $ticketId . '/' . $safeName;
            if (is_file($absolute) && !@unlink($absolute)) {
                $this->logger->warning('Attachment row deleted but file could not be removed', [
                    'ticket_id' => $ticketId,
                    'attachment_id' => $attachmentId,
                    'file' => $safeName,
                ]);
            }
        }
    }

    /**
     * Delete an attachment by id at whatever ticket currently owns the row.
     * Used for batch compensation when a concurrent merge may have moved the
     * attachment to the survivor after it was uploaded under a source id.
     *
     * Best-effort: missing rows are treated as already cleaned up.
     * Retries once if the row moves again while waiting for the lock.
     */
    public function deleteAttachmentWherever(int $attachmentId): void
    {
        if ($attachmentId <= 0) {
            return;
        }

        $last = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                try {
                    $attachment = $this->attachmentMapper->find($attachmentId);
                } catch (\Exception $e) {
                    return;
                }
                $homeId = (int) $attachment->getTicketId();
                if ($homeId <= 0) {
                    return;
                }

                $this->workflowLock->withTicketLocks(
                    [$homeId],
                    function () use ($attachmentId, $homeId): void {
                        try {
                            $fresh = $this->attachmentMapper->find($attachmentId);
                        } catch (\Exception $e) {
                            return;
                        }
                        $currentId = (int) $fresh->getTicketId();
                        if ($currentId !== $homeId) {
                            throw new \InvalidArgumentException('attachment_moved_retry');
                        }
                        try {
                            $ticket = $this->ticketMapper->find($currentId);
                        } catch (\Exception $e) {
                            throw new \Exception('Ticket not found');
                        }
                        // Survivor is fine; merged shells should not still hold the row.
                        $this->assertTicketNotMerged($ticket);
                        $this->deleteAttachmentLocked($currentId, $attachmentId);
                    },
                    'TicketCheck attachment compensate-delete'
                );
                return;
            } catch (\InvalidArgumentException $e) {
                $last = $e;
                if ($e->getMessage() !== 'attachment_moved_retry') {
                    throw new \Exception($e->getMessage(), 0, $e);
                }
            }
        }
        if ($last instanceof \InvalidArgumentException) {
            throw new \Exception($last->getMessage(), 0, $last);
        }
    }

    /**
     * Delete a comment (and its attachments) under the ticket workflow lock.
     * Used to compensate when a bundled attach batch fails after the comment insert.
     *
     * Merge-safe: resolves the comment's current ticket_id (may be the survivor
     * after a concurrent merge moved the row) and locks that home ticket.
     * Retries once if the comment moves again while waiting for the lock.
     *
     * @throws \InvalidArgumentException when the workflow lock cannot be acquired
     * @throws \Exception when the comment row could not be removed
     */
    public function deleteComment(int $ticketId, int $commentId): void
    {
        $last = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->deleteCommentOnce($ticketId, $commentId);
                return;
            } catch (\InvalidArgumentException $e) {
                $last = $e;
                if ($e->getMessage() !== 'comment_moved_retry') {
                    throw $e;
                }
            }
        }
        if ($last instanceof \InvalidArgumentException) {
            throw $last;
        }
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    private function deleteCommentOnce(int $ticketId, int $commentId): void
    {
        if ($commentId <= 0) {
            return;
        }

        try {
            $comment = $this->commentMapper->find($commentId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return;
        }

        $homeId = (int) $comment->getTicketId();
        if ($homeId <= 0) {
            return;
        }

        // Compensation must only touch this comment if it still belongs to the
        // requested ticket or that ticket's merge survivor.
        if ($ticketId > 0 && $homeId !== $ticketId) {
            try {
                $survivor = $this->getActiveTicket($ticketId);
                if ((int) $survivor->getId() !== $homeId) {
                    throw new \InvalidArgumentException('comment_not_on_ticket');
                }
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Source row already gone; comment lives on $homeId — proceed.
            } catch (\InvalidArgumentException $e) {
                throw $e;
            }
        }

        $this->workflowLock->withTicketLocks(
            [$homeId],
            function () use ($homeId, $commentId): void {
                try {
                    $comment = $this->commentMapper->find($commentId);
                } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                    return;
                }
                $currentTicketId = (int) $comment->getTicketId();
                if ($currentTicketId !== $homeId) {
                    // Another merge moved the row while we waited for the lock.
                    throw new \InvalidArgumentException('comment_moved_retry');
                }

                $attachments = $this->attachmentMapper->findByCommentId($commentId);
                foreach ($attachments as $attachment) {
                    if ((int) $attachment->getTicketId() !== $homeId) {
                        continue;
                    }
                    try {
                        $this->deleteAttachmentLocked($homeId, (int) $attachment->getId());
                    } catch (\Throwable $e) {
                        $this->logger->warning('Compensation attachment delete failed', [
                            'ticket_id' => $homeId,
                            'comment_id' => $commentId,
                            'attachment_id' => $attachment->getId(),
                            'exception' => $e,
                        ]);
                    }
                }

                if (!$this->commentMapper->deleteIfOnTicket($commentId, $homeId)) {
                    throw new \Exception('Comment compensation delete failed');
                }
            },
            'TicketCheck comment compensate delete'
        );
    }

    /**
     * Count guest portal actions (tickets + public comments + attachments) in a time window.
     * Attachments must count so standalone uploads cannot bypass the daily ceiling.
     */
    public function countRecentPortalActionsByUser(string $userId, \DateTime $since): int
    {
        return $this->ticketMapper->countRecentByUser($userId, $since)
            + $this->commentMapper->countRecentByUserId($userId, $since)
            + $this->attachmentMapper->countRecentByUserId($userId, $since);
    }

    private function syncClosedAtForStatus(Ticket $ticket, string $status): void
    {
        if ($status === Ticket::STATUS_DONE) {
            // Preserve the original close time when re-saving an already-done ticket.
            if ($ticket->getClosedAt() === null) {
                $ticket->setClosedAt(new \DateTime());
            }
            return;
        }

        $ticket->setClosedAt(null);
    }

    /**
     * Persist dirty fields only when the row is still unmerged (lost-update safe vs merge).
     *
     * @throws \Exception
     */
    private function persistUnmergedOrFail(Ticket $ticket, string $genericFailureMessage): Ticket
    {
        try {
            if (!$this->ticketMapper->updateIfUnmerged($ticket)) {
                throw new \Exception('Cannot modify a ticket that has been merged into another ticket');
            }
            return $ticket;
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Cannot modify a ticket that has been merged into another ticket') {
                throw $e;
            }
            throw new \Exception($genericFailureMessage, 0, $e);
        }
    }

    /**
     * Reject field mutations on tickets that were merged away.
     * Interactive comments/attachments must target the survivor after authz;
     * inbound email may follow the chain via getActiveTicket() / addCommentFromEmail().
     */
    private function assertTicketNotMerged(Ticket $ticket): void
    {
        if ($ticket->getMergedIntoId() !== null) {
            throw new \Exception('Cannot modify a ticket that has been merged into another ticket');
        }
    }

    /**
     * Walk merged_into_id until the surviving ticket. Detects cycles.
     *
     * Controllers must authorize the returned ticket before mutating it.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \Exception
     */
    public function getActiveTicket(int $ticketId): Ticket
    {
        $ticket = $this->ticketMapper->find($ticketId);
        $seen = [];
        while ($ticket->getMergedIntoId() !== null) {
            $id = (int) $ticket->getId();
            if (isset($seen[$id])) {
                $this->logger->error('Ticket merge cycle detected', ['ticket_id' => $id]);
                throw new \Exception('Ticket merge cycle detected');
            }
            $seen[$id] = true;
            $ticket = $this->ticketMapper->find((int) $ticket->getMergedIntoId());
        }

        return $ticket;
    }

    /**
     * Delete merge shells whose merged_into_id points at a missing ticket.
     *
     * @return int Number of dangling shells removed
     */
    public function cleanupDanglingMergeShells(int $limit = 100): int
    {
        $ids = $this->ticketMapper->findDanglingMergeShellIds($limit);
        $removed = 0;
        foreach ($ids as $shellId) {
            try {
                $this->deleteTicket($shellId);
                $removed++;
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to purge dangling merge shell', [
                    'ticket_id' => $shellId,
                    'exception' => $e,
                ]);
            }
        }

        return $removed;
    }
}

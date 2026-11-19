<?php

declare(strict_types=1);

/**
 * Comment lifecycle service — edit, visibility, tombstone delete, admin
 * purge and selective move of comments/attachments between tickets
 * (spec: comment-management-2026-11, GH#6 + GH#7).
 *
 * Concurrency contract: every mutation re-reads the comment under the
 * ticket workflow lock, re-verifies its home ticket binding, and evaluates
 * permissions at submit time (never at render time). Move locks source and
 * target in ONE ascending withTicketLocks call — no lock-ordering deadlock.
 * Files follow the merge safety pattern: copy to target before commit,
 * delete source originals only after commit.
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\Attachment;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\CommentMove;
use OCA\Ticketcheck\Db\CommentMoveMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Exception\CommentLifecycleException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class CommentLifecycleService
{
    /** Maximum selectable items (comments + standalone attachments) per move. */
    public const MOVE_ITEM_LIMIT = 50;

    public function __construct(
        private readonly TicketMapper $ticketMapper,
        private readonly CommentMapper $commentMapper,
        private readonly AttachmentMapper $attachmentMapper,
        private readonly CommentMoveMapper $moveMapper,
        private readonly TicketService $ticketService,
        private readonly PermissionService $permissions,
        private readonly TicketWorkflowLock $workflowLock,
        private readonly IdempotencyService $idempotency,
        private readonly ActivityEventService $activity,
        private readonly IConfig $config,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Update a comment's content and/or visibility. Per-field authorization
     * is atomic: any submitted field the actor may not change denies the
     * whole request and writes nothing (MH-A12).
     *
     * @param string|null $content null = field absent
     * @param bool|null $isInternal null = field absent
     * @param null|callable(Ticket):void $assertAuthorized CAS/hook inside lock
     * @throws CommentLifecycleException
     */
    public function updateComment(
        int $ticketId,
        int $commentId,
        ?string $content,
        ?bool $isInternal,
        ?callable $assertAuthorized = null
    ): Comment {
        if ($content === null && $isInternal === null) {
            throw new CommentLifecycleException('no_fields', 400);
        }

        $comment = $this->findCommentOr404($commentId);
        $homeId = (int)$comment->getTicketId();
        $pathId = $this->resolvePathTicketId($ticketId);

        return $this->workflowLock->withTicketLocks(
            [$homeId],
            function () use ($homeId, $pathId, $commentId, $content, $isInternal, $assertAuthorized): Comment {
                $home = $this->requireUnmergedTicket($homeId);
                if ($pathId !== $homeId) {
                    // URL ticket must bind to the comment's real home, with the
                    // merge-survivor hop already resolved (NN-A09).
                    throw new CommentLifecycleException('comment_not_found', 404);
                }
                $comment = $this->requireCommentOnTicket($commentId, $homeId);
                if ($assertAuthorized !== null) {
                    $assertAuthorized($home);
                }

                // Per-field authz — evaluated inside the lock at submit time.
                $canEdit = $this->permissions->canEditComment($comment, $home);
                if ($content !== null && !$canEdit) {
                    throw $this->denied($comment, $home);
                }
                if ($isInternal !== null
                    && !$this->permissions->canToggleCommentVisibility($comment, $home, $isInternal)) {
                    throw $this->denied($comment, $home);
                }
                if ($content !== null && trim($content) === '') {
                    throw new CommentLifecycleException('content_required', 400, ['content' => 'comment_content_required']);
                }

                $changed = false;
                if ($content !== null) {
                    $trimmed = trim($content);
                    if ($comment->getContent() !== $trimmed) {
                        $comment->setContent($trimmed);
                        $changed = true;
                    }
                }
                if ($isInternal !== null && $comment->getIsInternal() !== $isInternal) {
                    $comment->setIsInternal($isInternal);
                    $changed = true;
                }

                if ($changed) {
                    $comment->setEditedAt(new \DateTime());
                    $comment->setEditedBy($this->actorUid());
                    $this->commentMapper->update($comment);
                    $this->ticketMapper->touchUpdatedAtIfUnmerged($homeId);
                    $this->activity->logTicketCommentEdited(
                        $this->actorUid(),
                        $home,
                        ['comment_id' => (string)$commentId],
                        $comment->getIsInternal()
                    );
                }

                return $comment;
            },
            'TicketCheck comment update'
        );
    }

    /**
     * Soft-delete: tombstone row + bound attachment rows wiped (files are
     * unlinked right after commit — a tombstone that still serves files is
     * a leak). Other comments' attachments are never touched.
     *
     * @param null|callable(Ticket):void $assertAuthorized CAS/hook inside lock
     * @throws CommentLifecycleException
     */
    public function softDeleteComment(
        int $ticketId,
        int $commentId,
        ?callable $assertAuthorized = null
    ): Comment {
        $comment = $this->findCommentOr404($commentId);
        $homeId = (int)$comment->getTicketId();
        $pathId = $this->resolvePathTicketId($ticketId);

        return $this->workflowLock->withTicketLocks(
            [$homeId],
            function () use ($homeId, $pathId, $commentId, $assertAuthorized): Comment {
                $home = $this->requireUnmergedTicket($homeId);
                if ($pathId !== $homeId) {
                    throw new CommentLifecycleException('comment_not_found', 404);
                }
                $comment = $this->requireCommentOnTicket($commentId, $homeId);
                if ($assertAuthorized !== null) {
                    $assertAuthorized($home);
                }
                if (!$this->permissions->canDeleteComment($comment, $home)) {
                    throw $this->denied($comment, $home);
                }

                $boundFiles = [];
                foreach ($this->attachmentMapper->findByCommentId($commentId) as $attachment) {
                    // Only wipe rows still bound to this ticket (defense vs drift).
                    if ((int)$attachment->getTicketId() === $homeId) {
                        $boundFiles[] = basename((string)$attachment->getFilePath());
                    }
                }

                $actor = $this->actorUid();
                $this->db->beginTransaction();
                try {
                    $comment->setDeletedAt(new \DateTime());
                    $comment->setDeletedBy($actor);
                    $this->commentMapper->update($comment);
                    $this->attachmentMapper->deleteByCommentId($commentId);
                    $this->ticketMapper->touchUpdatedAtIfUnmerged($homeId);
                    $this->db->commit();
                } catch (\Throwable $e) {
                    $this->db->rollBack();
                    throw $e;
                }

                // Post-commit file unlink (files cannot join a DB tx; the row
                // deletions above already deny every download path).
                $this->deleteTicketAttachmentFiles($homeId, $boundFiles);
                $this->removeEmptyAttachmentDir($homeId);

                $this->activity->logTicketCommentDeleted(
                    $actor,
                    $home,
                    ['comment_id' => (string)$commentId],
                    $comment->getIsInternal()
                );
                $this->logger->info('TicketCheck comment soft-deleted', [
                    'ticket_id' => $homeId,
                    'comment_id' => $commentId,
                    'actor' => $actor,
                ]);

                return $comment;
            },
            'TicketCheck comment delete'
        );
    }

    /**
     * Permanently remove a tombstoned comment row. Helpdesk admin only;
     * active comments are rejected (purge is tombstone-only, MH-A13).
     * Move audit rows survive — they are decoupled from item_id.
     *
     * @throws CommentLifecycleException
     */
    public function purgeComment(int $ticketId, int $commentId): void
    {
        if (!$this->permissions->isHelpdeskAdmin()) {
            // Uniform deny — never reveal that a purgeable row exists.
            throw new CommentLifecycleException('comment_not_found', 404);
        }
        $comment = $this->findCommentOr404($commentId);
        $homeId = (int)$comment->getTicketId();
        $pathId = $this->resolvePathTicketId($ticketId);

        $this->workflowLock->withTicketLocks(
            [$homeId],
            function () use ($homeId, $pathId, $commentId): void {
                $home = $this->requireUnmergedTicket($homeId);
                if ($pathId !== $homeId) {
                    throw new CommentLifecycleException('comment_not_found', 404);
                }
                $comment = $this->requireCommentOnTicket($commentId, $homeId, includeDeleted: true);
                if ($comment->getDeletedAt() === null) {
                    throw new CommentLifecycleException('comment_not_deleted', 400);
                }
                if (!$this->commentMapper->deleteIfOnTicket($commentId, $homeId)) {
                    throw new CommentLifecycleException('comment_not_found', 404);
                }
                $this->ticketMapper->touchUpdatedAtIfUnmerged($homeId);
                $this->activity->logTicketCommentPurged(
                    $this->actorUid(),
                    $home,
                    ['comment_id' => (string)$commentId],
                    $comment->getIsInternal()
                );
                $this->logger->info('TicketCheck comment purged', [
                    'ticket_id' => $homeId,
                    'comment_id' => $commentId,
                    'actor' => $this->actorUid(),
                ]);
            },
            'TicketCheck comment purge'
        );
    }

    /**
     * Move selected comments (with bound attachments) and standalone
     * attachments to another ticket. Single transaction under the ordered
     * [source,target] lock; files copied before commit, originals deleted
     * after commit; filename collisions get a `name (2).ext` basename;
     * one audit batch + one per-item row; idempotent via caller-supplied key.
     *
     * @param int[] $commentIds
     * @param int[] $attachmentIds standalone attachments only
     * @param null|callable(Ticket):void $assertAuthorized CAS/hook inside lock
     * @return array{batch_id: string, comments_moved: int, attachments_moved: int,
     *     cleared_checklist_bindings: int, source_ticket_id: int, target_ticket_id: int,
     *     request_fingerprint?: string}
     * @throws CommentLifecycleException
     */
    public function moveCommentsAndAttachments(
        int $sourceId,
        int $targetId,
        array $commentIds,
        array $attachmentIds,
        ?string $idempotencyKey,
        ?callable $assertAuthorized = null
    ): array {
        $commentIds = $this->normalizeIds($commentIds);
        $attachmentIds = $this->normalizeIds($attachmentIds);
        if ($sourceId === $targetId) {
            throw new CommentLifecycleException('invalid_selection', 400);
        }
        if ($commentIds === [] && $attachmentIds === []) {
            throw new CommentLifecycleException('invalid_selection', 400);
        }
        if (count($commentIds) + count($attachmentIds) > self::MOVE_ITEM_LIMIT) {
            throw new CommentLifecycleException('too_many_items', 400);
        }

        $source = $this->requireSourceTicketForMove($sourceId);
        $actorUid = $this->actorUid();
        // Same key + different payload must conflict, not silently replay a
        // different move (EC-14) — the selection fingerprint is stored in the
        // receipt and compared on replay.
        $fingerprint = hash('sha256', (string)json_encode([$sourceId, $targetId, $commentIds, $attachmentIds]));

        /** @var array{batch_id: string, comments_moved: int, attachments_moved: int, cleared_checklist_bindings: int, source_ticket_id: int, target_ticket_id: int, request_fingerprint?: string} $receipt */
        $receipt = $this->idempotency->run(
            $actorUid,
            'comment-move-' . $sourceId,
            $idempotencyKey,
            function () use ($sourceId, $targetId, $commentIds, $attachmentIds, $actorUid, $assertAuthorized, $fingerprint): array {
                return $this->workflowLock->withTicketLocks(
                    [$sourceId, $targetId],
                    function () use ($sourceId, $targetId, $commentIds, $attachmentIds, $actorUid, $assertAuthorized, $fingerprint): array {
                        return $this->moveLocked($sourceId, $targetId, $commentIds, $attachmentIds, $actorUid, $assertAuthorized, $fingerprint);
                    },
                    'TicketCheck comment move'
                );
            }
        );
        $this->assertReceiptMatchesSelection($receipt, $fingerprint);
        return $receipt;
    }

    /**
     * @param int[] $commentIds
     * @param int[] $attachmentIds
     * @return array<string, mixed>
     */
    private function moveLocked(
        int $sourceId,
        int $targetId,
        array $commentIds,
        array $attachmentIds,
        string $actorUid,
        ?callable $assertAuthorized,
        string $fingerprint
    ): array {
        // Re-verify everything under the lock set (TOCTOU vs merge/split).
        $source = $this->requireSourceTicketForMove($sourceId);
        try {
            $target = $this->ticketMapper->find($targetId);
        } catch (DoesNotExistException|\OCP\AppFramework\Db\MultipleObjectsReturnedException) {
            // Nonexistent and uneditable targets collapse into one answer.
            throw new CommentLifecycleException('ticket_not_found', 404);
        }
        if ($target->getMergedIntoId() !== null) {
            throw new CommentLifecycleException('ticket_merged', 400);
        }
        if (!$this->permissions->canEditTicket($target)) {
            throw new CommentLifecycleException('ticket_not_found', 404);
        }
        if ($assertAuthorized !== null) {
            $assertAuthorized($source);
        }

        $comments = [];
        $internalMoved = false;
        foreach ($this->commentMapper->findByIds($commentIds) as $comment) {
            if ($comment->getDeletedAt() !== null) {
                throw new CommentLifecycleException('comment_deleted', 400);
            }
            if ((int)$comment->getTicketId() !== $sourceId) {
                throw new CommentLifecycleException('invalid_selection', 400);
            }
            if ($comment->getIsInternal()) {
                $internalMoved = true;
            }
            $comments[(int)$comment->getId()] = $comment;
        }
        if (count($comments) !== count($commentIds)) {
            throw new CommentLifecycleException('invalid_selection', 400);
        }

        $standalone = [];
        foreach ($this->attachmentMapper->findByIds($attachmentIds) as $attachment) {
            if ($attachment->getCommentId() !== null) {
                throw new CommentLifecycleException('attachment_bound_to_comment', 400, [
                    'attachment_ids' => 'attachment_bound_to_comment',
                ]);
            }
            if ((int)$attachment->getTicketId() !== $sourceId) {
                throw new CommentLifecycleException('invalid_selection', 400);
            }
            $standalone[(int)$attachment->getId()] = $attachment;
        }
        if (count($standalone) !== count($attachmentIds)) {
            throw new CommentLifecycleException('invalid_selection', 400);
        }

        // Bound attachments always travel with their comment.
        $movingAttachments = $standalone;
        foreach ($comments as $comment) {
            foreach ($this->attachmentMapper->findByCommentId((int)$comment->getId()) as $bound) {
                if ((int)$bound->getTicketId() !== $sourceId) {
                    throw new CommentLifecycleException('invalid_selection', 400);
                }
                $movingAttachments[(int)$bound->getId()] = $bound;
            }
        }

        // Plan file relocation: unique basename per file on the target dir,
        // considering both the filesystem and this batch's planned names.
        $dataDir = rtrim((string)$this->config->getSystemValue('datadirectory', ''), '/');
        $sourceDir = $dataDir . '/helpdesk_attachments/' . $sourceId;
        $targetDir = $dataDir . '/helpdesk_attachments/' . $targetId;
        /** @var array<int, string> attachment id => target basename */
        $filePlan = [];
        $reserved = [];
        foreach ($movingAttachments as $attachment) {
            $filePlan[(int)$attachment->getId()] = $this->uniqueAttachmentBasename(
                $targetDir,
                basename((string)$attachment->getFilePath()),
                $reserved
            );
        }

        // Copy files BEFORE the transaction commits (merge safety pattern):
        // a DB rollback must never leave source bytes gone.
        $copied = [];
        if ($filePlan !== []) {
            if (!is_dir($targetDir) && !@mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
                throw new \RuntimeException('Could not create attachment directory for target ticket');
            }
            foreach ($filePlan as $attachmentId => $targetName) {
                $src = $sourceDir . '/' . basename((string)$movingAttachments[$attachmentId]->getFilePath());
                if (!is_file($src)) {
                    $this->rollbackCopiedFiles($targetDir, $copied);
                    throw new \RuntimeException('Attachment file missing during move');
                }
                $dst = $targetDir . '/' . $targetName;
                if (!@copy($src, $dst)) {
                    $this->rollbackCopiedFiles($targetDir, $copied);
                    throw new \RuntimeException('Could not copy attachment file during move');
                }
                $copied[] = $targetName;
            }
        }

        $batchId = $this->newBatchId();
        $movedComments = 0;
        $clearedChecklist = 0;
        try {
            $this->db->beginTransaction();

            // All-or-nothing: a short count means a row re-homed mid-move.
            $movedComments = $this->commentMapper->moveByIdsToTicket($commentIds, $sourceId, $targetId);
            if ($movedComments !== count($commentIds)) {
                throw new CommentLifecycleException('invalid_selection', 400);
            }

            foreach ($movingAttachments as $attachment) {
                if ($attachment->getChecklistItemId() !== null) {
                    $clearedChecklist++;
                }
                $attachment->setTicketId($targetId);
                $attachment->setChecklistItemId(null);
                $attachment->setFilePath($filePlan[(int)$attachment->getId()]);
                $this->attachmentMapper->update($attachment);
            }
            foreach ($comments as $comment) {
                if ($comment->getChecklistItemId() !== null) {
                    $clearedChecklist++;
                }
            }

            $auditItems = [];
            foreach ($commentIds as $cid) {
                $auditItems[] = ['kind' => CommentMove::KIND_COMMENT, 'id' => $cid];
            }
            foreach (array_keys($movingAttachments) as $aid) {
                $auditItems[] = ['kind' => CommentMove::KIND_ATTACHMENT, 'id' => (int)$aid];
            }
            $this->moveMapper->insertBatch($batchId, $sourceId, $targetId, $actorUid, $auditItems, new \DateTime());

            if (!$this->ticketMapper->touchUpdatedAtIfUnmerged($sourceId)
                || !$this->ticketMapper->touchUpdatedAtIfUnmerged($targetId)) {
                throw new \RuntimeException('Move raced a merge; aborting');
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->rollbackCopiedFiles($targetDir, $copied);
            if ($e instanceof CommentLifecycleException) {
                throw $e;
            }
            $this->logger->error('TicketCheck comment move failed', [
                'source_id' => $sourceId,
                'target_id' => $targetId,
                'exception' => $e,
            ]);
            throw new \RuntimeException('Comment move failed');
        }

        // Post-commit: delete the source originals (copies are committed).
        $sourceBasenames = array_values(array_map(
            static fn (Attachment $a): string => basename((string)$a->getFilePath()),
            $movingAttachments
        ));
        $this->deleteTicketAttachmentFiles($sourceId, $sourceBasenames);
        $this->removeEmptyAttachmentDir($sourceId);

        $commentCount = count($commentIds);
        $attachmentCount = count($movingAttachments);
        $params = [
            'comment_count' => (string)$commentCount,
            'attachment_count' => (string)$attachmentCount,
            'counterpart_ticket_id' => (string)$targetId,
            'cleared_checklist_bindings' => (string)$clearedChecklist,
            'batch_id' => $batchId,
            'direction' => 'out',
        ];
        $this->activity->logTicketCommentMoved($actorUid, $source, $params, $internalMoved);
        $this->activity->logTicketCommentMoved($actorUid, $target, $params + [
            'counterpart_ticket_id' => (string)$sourceId,
            'direction' => 'in',
        ], $internalMoved);
        $this->logger->info('TicketCheck comment move committed', [
            'actor' => $actorUid,
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'comments' => $commentCount,
            'attachments' => $attachmentCount,
        ]);

        return [
            'batch_id' => $batchId,
            'comments_moved' => $commentCount,
            'attachments_moved' => $attachmentCount,
            'cleared_checklist_bindings' => $clearedChecklist,
            'source_ticket_id' => $sourceId,
            'target_ticket_id' => $targetId,
            'request_fingerprint' => $fingerprint,
        ];
    }

    /**
     * Replay-vs-conflict check: a stored receipt whose selection fingerprint
     * differs was a key reuse on a different payload (EC-14).
     */
    /**
     * @param array<string, mixed> $receipt
     */
    private function assertReceiptMatchesSelection(array $receipt, string $fingerprint): void
    {
        if (($receipt['request_fingerprint'] ?? null) !== $fingerprint) {
            throw new CommentLifecycleException('idempotency_conflict', 409);
        }
    }

    /**
     * @param int[] $ids
     * @return int[]
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
    }

    /**
     * @throws CommentLifecycleException
     */
    private function findCommentOr404(int $commentId): Comment
    {
        try {
            return $this->commentMapper->find($commentId);
        } catch (\Throwable) {
            throw new CommentLifecycleException('comment_not_found', 404);
        }
    }

    /**
     * The path ticket must exist and resolve (merge-survivor hop) to an
     * active ticket id; callers compare it against the comment's real home
     * for the URL↔comment binding (NN-A09, EC-2 stale-dialog hop).
     *
     * @throws CommentLifecycleException
     */
    private function resolvePathTicketId(int $ticketId): int
    {
        try {
            return (int)$this->ticketService->getActiveTicket($ticketId)->getId();
        } catch (\Throwable) {
            throw new CommentLifecycleException('ticket_not_found', 404);
        }
    }

    /**
     * @throws CommentLifecycleException
     */
    private function requireUnmergedTicket(int $ticketId): Ticket
    {
        try {
            $ticket = $this->ticketMapper->find($ticketId);
        } catch (\Throwable) {
            throw new CommentLifecycleException('ticket_not_found', 404);
        }
        if ($ticket->getMergedIntoId() !== null) {
            // A comment cannot legitimately live on a merged shell.
            throw new CommentLifecycleException('comment_not_found', 404);
        }
        return $ticket;
    }

    /**
     * @throws CommentLifecycleException
     */
    private function requireCommentOnTicket(int $commentId, int $homeId, bool $includeDeleted = false): Comment
    {
        $comment = $this->findCommentOr404($commentId);
        if ((int)$comment->getTicketId() !== $homeId) {
            // Re-homed between discovery and lock acquisition (EC-4) — the
            // caller may retry; to the client this is a uniform miss.
            throw new CommentLifecycleException('comment_not_found', 404);
        }
        if (!$includeDeleted && $comment->getDeletedAt() !== null) {
            throw new CommentLifecycleException('comment_not_found', 404);
        }
        return $comment;
    }

    /**
     * Uniform denial for the comment permission matrix. A failed author is
     * told the window expired only when they really are the author — the
     * 'expired' oracle never reaches non-author/non-staff actors.
     *
     * @throws CommentLifecycleException
     */
    private function denied(Comment $comment, Ticket $home): CommentLifecycleException
    {
        $uid = $this->permissions->getCurrentUserId();
        $authorId = $comment->getUserId();
        $isAuthor = $uid !== null && $authorId !== null
            && hash_equals((string)$authorId, (string)$uid)
            && !$this->permissions->canEditTicket($home);
        if ($isAuthor) {
            // Author exists but the window closed (or is disabled) — honest 403.
            return new CommentLifecycleException('edit_window_expired', 403);
        }
        return new CommentLifecycleException('access_denied', 403);
    }

    /**
     * @throws CommentLifecycleException
     */
    private function requireSourceTicketForMove(int $sourceId): Ticket
    {
        try {
            $source = $this->ticketMapper->find($sourceId);
        } catch (\Throwable) {
            throw new CommentLifecycleException('ticket_not_found', 404);
        }
        if ($source->getMergedIntoId() !== null) {
            throw new CommentLifecycleException('ticket_merged', 400);
        }
        if (!$this->permissions->canEditTicket($source)) {
            // Source is on-screen for the actor — a denied move there is a
            // permission failure, not an existence oracle (403, no detail).
            throw new CommentLifecycleException('access_denied', 403);
        }
        return $source;
    }

    private function actorUid(): string
    {
        return (string)($this->permissions->getCurrentUserId() ?? '');
    }

    /**
     * Unique target basename: `name.ext` → `name (2).ext` → `name (3).ext`
     * (MH-B11/NN-B10 — never silent overwrite, never abort on collision).
     *
     * @param array<string, true> $reserved names already planned in this batch
     */
    private function uniqueAttachmentBasename(string $targetDir, string $basename, array &$reserved): string
    {
        $basename = basename($basename);
        $stem = (string)pathinfo($basename, PATHINFO_FILENAME);
        $ext = pathinfo($basename, PATHINFO_EXTENSION);
        $suffix = $ext !== '' ? '.' . $ext : '';
        $candidate = $basename;
        $counter = 1;
        while (isset($reserved[$candidate]) || file_exists($targetDir . '/' . $candidate)) {
            $counter++;
            $candidate = $stem . ' (' . $counter . ')' . $suffix;
        }
        $reserved[$candidate] = true;
        return $candidate;
    }

    private function newBatchId(): string
    {
        return bin2hex(random_bytes(18));
    }

    /**
     * @param list<string> $basenames
     */
    private function rollbackCopiedFiles(string $targetDir, array $basenames): void
    {
        foreach ($basenames as $name) {
            $path = $targetDir . '/' . basename($name);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param list<string> $basenames
     */
    private function deleteTicketAttachmentFiles(int $ticketId, array $basenames): void
    {
        $dataDir = rtrim((string)$this->config->getSystemValue('datadirectory', ''), '/');
        if ($dataDir === '') {
            return;
        }
        $dir = $dataDir . '/helpdesk_attachments/' . $ticketId;
        foreach ($basenames as $name) {
            $path = $dir . '/' . basename($name);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function removeEmptyAttachmentDir(int $ticketId): void
    {
        $dataDir = rtrim((string)$this->config->getSystemValue('datadirectory', ''), '/');
        if ($dataDir === '') {
            return;
        }
        $dir = $dataDir . '/helpdesk_attachments/' . $ticketId;
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }
}

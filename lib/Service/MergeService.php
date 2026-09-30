<?php

declare(strict_types=1);

/**
 * Merge tickets: move comments/attachments from source to target, mark source as merged
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class MergeService
{
	public function __construct(
		private readonly TicketMapper $ticketMapper,
		private readonly CommentMapper $commentMapper,
		private readonly AttachmentMapper $attachmentMapper,
		private readonly PermissionService $permissionService,
		private readonly TicketRelationService $ticketRelationService,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
		private readonly IDBConnection $db,
		private readonly TicketWorkflowLock $workflowLock,
	) {
	}

	/**
	 * Merge source ticket into target ticket.
	 * Moves all comments and attachments from source to target,
	 * sets source.merged_into_id = target, source.status = done.
	 *
	 * Shared ticket locks (ordered by id) serialize concurrent merges/splits and
	 * avoid A→B / B→A deadlocks. Attachment bytes are **copied** to the target
	 * before the DB transaction; source originals are deleted only after commit.
	 * A hard crash mid-merge therefore never leaves DB rows pointing at missing
	 * files (worst case: orphan copies under the target, which remain downloadable
	 * once the DB is repaired). The source row is updated with
	 * WHERE merged_into_id IS NULL so a lost race cannot double-merge.
	 *
	 * @param int $sourceId Source ticket ID
	 * @param int|null $targetId Target ticket ID (optional if targetTicketNumber provided)
	 * @param string|null $targetTicketNumber Target ticket number (optional if targetId provided)
	 * @return array{target_id: int, comments_moved: int, attachments_moved: int}
	 * @throws \InvalidArgumentException
	 */
	public function mergeTickets(int $sourceId, ?int $targetId = null, ?string $targetTicketNumber = null): array
	{
		$targetTicketNumber = $targetTicketNumber !== null ? trim($targetTicketNumber) : null;
		if ($targetTicketNumber === '') {
			$targetTicketNumber = null;
		}

		if ($targetId === null && $targetTicketNumber === null) {
			throw new \InvalidArgumentException('Either target_id or target_ticket_number is required');
		}

		// Resolve target id before locking so we can lock both tickets in a
		// stable order (prevents deadlock between A→B and B→A).
		$source = $this->ticketMapper->find($sourceId);
		$target = $targetId !== null
			? $this->ticketMapper->find($targetId)
			: $this->ticketMapper->findByTicketNumber($targetTicketNumber);
		$targetId = (int)$target->getId();

		if ($sourceId === $targetId) {
			throw new \InvalidArgumentException('Cannot merge a ticket into itself');
		}

		// Pre-lock authz (uniform not-found) so callers cannot burn exclusive
		// locks on arbitrary ticket pairs and then fail permission.
		if (!$this->permissionService->canEditTicket($source)
			|| !$this->permissionService->canEditTicket($target)) {
			throw new \InvalidArgumentException('ticket_not_found');
		}

		return $this->workflowLock->withTicketLocks(
			[$sourceId, $targetId],
			fn (): array => $this->mergeTicketsLocked($sourceId, $targetId),
			'TicketCheck merge'
		);
	}

	/**
	 * @return array{target_id: int, comments_moved: int, attachments_moved: int}
	 */
	private function mergeTicketsLocked(int $sourceId, int $targetId): array
	{
		$source = $this->ticketMapper->find($sourceId);
		$target = $this->ticketMapper->find($targetId);

		if (!$this->permissionService->canEditTicket($source)) {
			throw new \InvalidArgumentException('ticket_not_found');
		}
		if (!$this->permissionService->canEditTicket($target)) {
			throw new \InvalidArgumentException('ticket_not_found');
		}

		if ($source->getMergedIntoId() !== null) {
			throw new \InvalidArgumentException('Source ticket is already merged');
		}
		if ($target->getMergedIntoId() !== null) {
			throw new \InvalidArgumentException('Target ticket is already merged into another ticket');
		}

		// Copy (do not rename) so a crash before commit never orphans DB rows.
		$copiedFiles = $this->copyAttachmentFiles($sourceId, $targetId);
		$attachmentsMoved = 0;
		$commentsMoved = 0;

		$this->db->beginTransaction();
		try {
			$sourceFresh = $this->ticketMapper->find($sourceId);
			$targetFresh = $this->ticketMapper->find($targetId);
			if ($sourceFresh->getMergedIntoId() !== null) {
				throw new \InvalidArgumentException('Source ticket is already merged');
			}
			if ($targetFresh->getMergedIntoId() !== null) {
				throw new \InvalidArgumentException('Target ticket is already merged into another ticket');
			}

			$attachmentsMoved = $this->attachmentMapper->moveToTicket($sourceId, $targetId);
			$commentsMoved = $this->commentMapper->moveToTicket($sourceId, $targetId);
			$this->ticketRelationService->transferOnMerge($sourceId, $targetId);

			if (!$this->ticketMapper->markMergedIfUnmerged($sourceId, $targetId)) {
				throw new \InvalidArgumentException('Source ticket is already merged');
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			if ($copiedFiles !== []) {
				$this->deleteAttachmentFiles($targetId, $copiedFiles);
			}
			if ($e instanceof \InvalidArgumentException) {
				throw $e;
			}
			$this->logger->error('Ticket merge failed; rolled back', [
				'source_id' => $sourceId,
				'target_id' => $targetId,
				'exception' => $e,
			]);
			throw new \InvalidArgumentException('Merge failed. Please try again.', 0, $e);
		}

		// DB committed: safe to remove source originals (copies remain at target).
		if ($copiedFiles !== []) {
			$this->deleteAttachmentFiles($sourceId, $copiedFiles);
			$this->removeEmptyAttachmentDir($sourceId);
		}

		$this->logger->info('Ticket merged', [
			'source_id' => $sourceId,
			'target_id' => $targetId,
			'comments_moved' => $commentsMoved,
			'attachments_moved' => $attachmentsMoved,
		]);

		return [
			'target_id' => $targetId,
			'comments_moved' => $commentsMoved,
			'attachments_moved' => $attachmentsMoved,
		];
	}

	/**
	 * Copy attachment files from source ticket dir to target (keep originals).
	 *
	 * @return list<string> Basenames successfully copied
	 */
	private function copyAttachmentFiles(int $fromTicketId, int $toTicketId): array
	{
		$dataDir = $this->config->getSystemValue('datadirectory', '');
		$sourceDir = rtrim($dataDir, '/') . '/helpdesk_attachments/' . $fromTicketId;
		$targetDir = rtrim($dataDir, '/') . '/helpdesk_attachments/' . $toTicketId;

		if (!is_dir($sourceDir)) {
			return [];
		}

		if (!is_dir($targetDir) && !mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
			throw new \RuntimeException('Could not create attachment directory for target ticket');
		}

		$copied = [];
		$files = scandir($sourceDir);
		if ($files === false) {
			return [];
		}

		foreach ($files as $file) {
			if ($file === '.' || $file === '..') {
				continue;
			}
			$src = $sourceDir . '/' . $file;
			$dst = $targetDir . '/' . $file;
			if (!is_file($src)) {
				continue;
			}
			if (file_exists($dst)) {
				if ($copied !== []) {
					$this->deleteAttachmentFiles($toTicketId, $copied);
				}
				throw new \RuntimeException('Attachment filename collision during merge; aborting to protect data integrity');
			}
			if (!copy($src, $dst)) {
				if ($copied !== []) {
					$this->deleteAttachmentFiles($toTicketId, $copied);
				}
				throw new \RuntimeException('Could not copy attachment file during merge');
			}
			$copied[] = $file;
		}

		return $copied;
	}

	/**
	 * @param list<string> $basenames
	 */
	private function deleteAttachmentFiles(int $ticketId, array $basenames): void
	{
		$dataDir = $this->config->getSystemValue('datadirectory', '');
		$dir = rtrim($dataDir, '/') . '/helpdesk_attachments/' . $ticketId;
		foreach ($basenames as $file) {
			$path = $dir . '/' . $file;
			if (is_file($path)) {
				@unlink($path);
			}
		}
	}

	private function removeEmptyAttachmentDir(int $ticketId): void
	{
		$dataDir = $this->config->getSystemValue('datadirectory', '');
		$dir = rtrim($dataDir, '/') . '/helpdesk_attachments/' . $ticketId;
		if (is_dir($dir)) {
			@rmdir($dir);
		}
	}
}

<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\Attachment;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Db\TicketWatcherMapper;
use OCA\Ticketcheck\Exception\CompanionConflictException;
use OCA\Ticketcheck\Exception\CompanionNotFoundException;
use OCA\Ticketcheck\Exception\CompanionValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Companion JSON surface over TicketService (agent inbox / detail / mutations /
 * watchers / create / bulk status / attachments).
 *
 * All ticket IDs entering this service pass the uniform not-found oracle:
 * missing and forbidden are indistinguishable (CompanionNotFoundException).
 */
class CompanionTicketService
{
	public const BULK_MAX = 25;

	private const OPEN_STATUSES = [
		Ticket::STATUS_NEW,
		Ticket::STATUS_IN_PROGRESS,
		Ticket::STATUS_WAITING,
	];

	private const QUEUES = ['open', 'mine', 'waiting', 'watching'];

	public function __construct(
		private readonly TicketMapper $ticketMapper,
		private readonly TicketService $ticketService,
		private readonly PermissionService $permissions,
		private readonly AttachmentMapper $attachmentMapper,
		private readonly ProjectService $projectService,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly CompanionNotificationService $notifications,
		private readonly TicketWatcherService $watcherService,
		private readonly TicketWatcherMapper $watcherMapper,
		private readonly EmailService $emailService,
		private readonly LoggerInterface $logger,
		private readonly TicketWorkflowLock $workflowLock,
	) {
	}

	/**
	 * @return array{items: list<array<string, mixed>>, nextCursor: ?string}
	 */
	public function inbox(
		string $uid,
		string $queue,
		?string $cursor,
		int $limit,
		?int $projectId = null,
		?string $priority = null,
	): array {
		$limit = max(1, min(50, $limit));
		if (!in_array($queue, self::QUEUES, true)) {
			throw new CompanionValidationException('invalid_queue', 'queue must be open|mine|waiting|watching');
		}
		if ($projectId !== null && $projectId < 1) {
			throw new CompanionValidationException('invalid_project', 'projectId must be a positive integer');
		}
		if ($priority !== null && !in_array($priority, Ticket::getPriorities(), true)) {
			throw new CompanionValidationException('invalid_priority', 'Unknown priority filter');
		}

		$statuses = match ($queue) {
			'waiting' => [Ticket::STATUS_WAITING],
			default => self::OPEN_STATUSES,
		};
		$assignedTo = $queue === 'mine' ? $uid : null;
		$watchedBy = $queue === 'watching' ? $uid : null;
		$decoded = $this->decodeCursor($cursor);

		// Fetch extra rows so permission filtering still fills a page.
		$fetch = min(200, ($limit + 1) * 4);
		$tickets = $this->ticketMapper->findCompanionInboxPage(
			$statuses,
			$assignedTo,
			$decoded['updatedAt'] ?? null,
			$decoded['id'] ?? null,
			$fetch,
			$projectId,
			$priority,
			$watchedBy,
		);

		$items = [];
		foreach ($tickets as $ticket) {
			if (!$this->permissions->canViewTicket($ticket)) {
				continue;
			}
			$items[] = $this->serializeInboxItem($ticket);
			if (count($items) > $limit) {
				break;
			}
		}

		$hasMore = count($items) > $limit;
		if ($hasMore) {
			array_pop($items);
		}

		$nextCursor = null;
		if ($hasMore && count($items) > 0) {
			$tail = $items[count($items) - 1];
			$nextCursor = $this->encodeCursorFromParts((string)$tail['updatedAtRaw'], (int)$tail['id']);
		}

		// Strip internal cursor helper field.
		$items = array_map(static function (array $row): array {
			unset($row['updatedAtRaw']);
			return $row;
		}, $items);

		return ['items' => array_values($items), 'nextCursor' => $nextCursor];
	}

	/**
	 * Queue counts for the Home screen. Companion users are agents/admins and
	 * see every ticket (role gate), so plain COUNT queries are accurate.
	 *
	 * @return array{open: int, mine: int, waiting: int, watching: int}
	 */
	public function queueCounts(string $uid): array
	{
		return [
			'open' => $this->ticketMapper->countCompanionQueue(self::OPEN_STATUSES, null, null),
			'mine' => $this->ticketMapper->countCompanionQueue(self::OPEN_STATUSES, $uid, null),
			'waiting' => $this->ticketMapper->countCompanionQueue([Ticket::STATUS_WAITING], null, null),
			'watching' => $this->ticketMapper->countCompanionQueue(self::OPEN_STATUSES, null, $uid),
		];
	}

	/**
	 * Visible projects + priorities + statuses for inbox filters and create form.
	 *
	 * @return array{projects: list<array{id: int, name: string}>, priorities: list<string>, statuses: list<string>}
	 */
	public function filterOptions(): array
	{
		$projects = [];
		try {
			foreach ($this->projectService->getAllProjects(500, 0, false) as $project) {
				if (!is_array($project)) {
					continue;
				}
				$id = (int)($project['id'] ?? 0);
				$name = (string)($project['name'] ?? '');
				if ($id > 0 && $name !== '') {
					$projects[] = ['id' => $id, 'name' => $name];
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Companion filter options: project list failed', ['exception' => $e]);
		}

		return [
			'projects' => $projects,
			'priorities' => Ticket::getPriorities(),
			'statuses' => Ticket::getStatuses(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function detail(int $id, string $uid): array
	{
		$ticket = $this->requireVisibleTicket($id);
		$canViewInternal = $this->permissions->canViewInternalNotes();
		$comments = $this->ticketService->getComments($id, $canViewInternal);

		// visibility of an attachment derives from its owning comment.
		$internalCommentIds = [];
		foreach ($this->ticketService->getComments($id, true) as $comment) {
			if ($comment->getIsInternal()) {
				$internalCommentIds[(int)$comment->getId()] = true;
			}
		}

		$attachments = [];
		foreach ($this->attachmentMapper->findByTicketId($id) as $attachment) {
			if (!$this->ticketService->canViewerAccessAttachment($attachment, $canViewInternal)) {
				continue;
			}
			$attachments[] = $this->serializeAttachment($attachment, $internalCommentIds);
		}

		return [
			'ticket' => $this->serializeTicket($ticket),
			'comments' => array_map(fn (Comment $c): array => $this->serializeComment($c), $comments),
			'attachments' => $attachments,
			'allowedTransitions' => $this->allowedTransitions($ticket->getStatus()),
			'canAssign' => $this->permissions->canEditTicket($ticket),
			'watching' => $this->watcherMapper->isWatching($id, $uid),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function addComment(int $id, string $uid, string $body, string $visibility, int $version): array
	{
		$body = trim($body);
		if ($body === '' || mb_strlen($body) > 10000) {
			throw new CompanionValidationException('invalid_body', 'Comment body must be 1…10000 characters');
		}
		if (!in_array($visibility, ['public', 'internal'], true)) {
			throw new CompanionValidationException('invalid_visibility', 'visibility must be public|internal');
		}

		$this->requireEditableTicket($id);
		// CAS must run inside TicketService's exclusive lock (assertAuthorized).

		// Web parity: agents without internal-note permission cannot force internal visibility.
		$isInternal = $visibility === 'internal' && $this->permissions->canCreateInternalNotes();
		$comment = $this->guardTicketWorkflow(fn (): Comment => $this->ticketService->addComment(
			$id,
			$body,
			$isInternal,
			false,
			function (Ticket $t) use ($version): void {
				$this->assertCanEdit($t);
				$this->assertVersion($t, $version);
			},
		));

		$fresh = $this->ticketService->getActiveTicket($id);

		return [
			'comment' => $this->serializeComment($comment),
			'ticket' => [
				'id' => $fresh->getId(),
				'version' => $this->ticketVersion($fresh),
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function changeStatus(int $id, string $uid, string $status, int $version): array
	{
		$ticket = $this->requireEditableTicket($id);
		$updated = $this->applyStatusTransition($ticket, $uid, $status, $version);

		return [
			'ticket' => $this->serializeTicket($updated),
		];
	}

	/**
	 * Bulk status change (max BULK_MAX tickets, per-item results).
	 * Every ticket id MUST have a CAS version in $versions (no silent no-CAS path).
	 *
	 * @param array<mixed> $ticketIds raw request values; each entry is validated
	 * @param array<int, int> $versions ticketId => version CAS map (required per id)
	 * @return array{results: list<array{id: int, ok: bool, error?: string}>}
	 */
	public function bulkStatus(string $uid, array $ticketIds, string $status, array $versions = []): array
	{
		$normalizedIds = [];
		foreach ($ticketIds as $rawId) {
			if (!is_int($rawId) && !(is_string($rawId) && ctype_digit($rawId))) {
				throw new CompanionValidationException('invalid_ticket_ids', 'ticketIds must be positive integers');
			}
			$intId = (int)$rawId;
			if ($intId < 1) {
				throw new CompanionValidationException('invalid_ticket_ids', 'ticketIds must be positive integers');
			}
			$normalizedIds[$intId] = true;
		}
		$ids = array_keys($normalizedIds);
		if ($ids === []) {
			throw new CompanionValidationException('invalid_ticket_ids', 'ticketIds must not be empty');
		}
		if (count($ids) > self::BULK_MAX) {
			throw new CompanionValidationException('too_many_tickets', 'At most ' . self::BULK_MAX . ' tickets per bulk request');
		}
		$normalizedStatus = Ticket::normalizeStatus($status);
		if ($normalizedStatus === null || !in_array($normalizedStatus, Ticket::getStatuses(), true)) {
			throw new CompanionValidationException('invalid_status', 'Unknown status');
		}

		$results = [];
		foreach ($ids as $ticketId) {
			try {
				if (!array_key_exists($ticketId, $versions)) {
					$results[] = ['id' => $ticketId, 'ok' => false, 'error' => 'version_required'];
					continue;
				}
				$ticket = $this->requireEditableTicket($ticketId);
				$this->applyStatusTransition($ticket, $uid, $normalizedStatus, $versions[$ticketId]);
				$results[] = ['id' => $ticketId, 'ok' => true];
			} catch (CompanionNotFoundException) {
				$results[] = ['id' => $ticketId, 'ok' => false, 'error' => 'ticket_not_found'];
			} catch (CompanionConflictException $e) {
				$results[] = ['id' => $ticketId, 'ok' => false, 'error' => $e->getErrorCode()];
			} catch (CompanionValidationException $e) {
				$results[] = ['id' => $ticketId, 'ok' => false, 'error' => $e->getErrorCode()];
			} catch (\Throwable $e) {
				$this->logger->error('Companion bulk status failed for ticket', [
					'ticket_id' => $ticketId,
					'exception' => $e,
				]);
				$results[] = ['id' => $ticketId, 'ok' => false, 'error' => 'operation_failed'];
			}
		}

		return ['results' => $results];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function assign(int $id, string $actorUid, ?string $assignee, int $version): array
	{
		$this->requireEditableTicket($id);

		$updated = $this->guardTicketWorkflow(fn (): Ticket => $this->ticketService->assignTicket(
			$id,
			$assignee,
			function (Ticket $t) use ($version): void {
				$this->assertCanEdit($t);
				$this->assertVersion($t, $version);
			},
		));

		return [
			'ticket' => $this->serializeTicket($updated),
		];
	}

	/**
	 * @return list<array{id: string, displayName: string}>
	 */
	public function assignableUsers(int $id, string $query, int $limit): array
	{
		$this->requireEditableTicket($id);
		$limit = max(5, min(50, $limit));
		$query = trim($query);
		$needle = $query !== '' ? mb_strtolower($query, 'UTF-8') : '';
		$out = [];
		$seen = [];

		foreach ([PermissionService::GROUP_HELPDESK_ADMINS, PermissionService::GROUP_HELPDESK_AGENTS] as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				continue;
			}
			foreach ($group->getUsers() as $user) {
				$uid = $user->getUID();
				if (isset($seen[$uid]) || $this->groupManager->isInGroup($uid, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {
					continue;
				}
				$display = $user->getDisplayName();
				if ($needle !== '') {
					$hay = mb_strtolower($uid . ' ' . $display, 'UTF-8');
					if (!str_contains($hay, $needle)) {
						continue;
					}
				}
				$seen[$uid] = true;
				$out[] = ['id' => $uid, 'displayName' => $display];
				if (count($out) >= $limit) {
					return $out;
				}
			}
		}

		return $out;
	}

	/**
	 * Watch or unwatch a ticket for the calling agent.
	 *
	 * CAS is asserted **inside** TicketWorkflowLock against a freshly loaded row so a
	 * concurrent comment/status cannot TOCTOU-bypass the version check. WatcherService
	 * re-enters the same lock (request-local nesting). The response always returns the
	 * live ticket version (never a stale pre-lock snapshot).
	 *
	 * @return array{watching: bool, ticket: array{id: int, version: int}}
	 */
	public function setWatching(int $id, string $uid, bool $watching, int $version): array
	{
		$this->requireEditableTicket($id);

		$this->guardTicketWorkflow(function () use ($id, $uid, $watching, $version): void {
			$this->workflowLock->withTicketLocks(
				[$id],
				function () use ($id, $uid, $watching, $version): void {
					$ticket = $this->ticketService->getActiveTicket($id);
					$this->assertCanEdit($ticket);
					$this->assertVersion($ticket, $version);

					if ($watching) {
						if (!$this->watcherMapper->isWatching($id, $uid)) {
							try {
								$this->watcherService->addWatcher($id, $uid);
							} catch (\InvalidArgumentException $e) {
								throw $this->mapWatcherException($e);
							}
						}
						return;
					}

					try {
						// deleteByTicketAndUser is idempotent: unwatching twice is a no-op.
						$this->watcherService->removeWatcher($id, $uid);
					} catch (\InvalidArgumentException $e) {
						throw $this->mapWatcherException($e);
					}
				},
				'TicketCheck companion watch',
			);
		});

		$fresh = $this->ticketService->getActiveTicket($id);
		return [
			'watching' => $this->watcherMapper->isWatching($id, $uid),
			'ticket' => [
				'id' => (int)$fresh->getId(),
				'version' => $this->ticketVersion($fresh),
			],
		];
	}

	/**
	 * Create a ticket from the companion (project-scoped, ACL parity with web store()).
	 *
	 * @return array<string, mixed> detail payload of the created ticket
	 */
	public function create(string $uid, ?int $projectId, string $title, string $description, string $priority): array
	{
		if ($projectId === null || $projectId < 1) {
			throw new CompanionValidationException('project_required', 'projectId is required');
		}

		$title = trim($title);
		$description = trim($description);
		if ($title === '' || mb_strlen($title) > 255) {
			throw new CompanionValidationException('invalid_title', 'Title must be 1…255 characters');
		}
		if ($description === '' || mb_strlen($description) > 50000) {
			throw new CompanionValidationException('invalid_description', 'Description must be 1…50000 characters');
		}
		if (!in_array($priority, Ticket::getPriorities(), true)) {
			throw new CompanionValidationException('invalid_priority', 'Unknown priority');
		}

		$data = [
			'title' => $title,
			'description' => $description,
			'priority' => $priority,
			'project_id' => $projectId,
			'created_by_guest' => false,
		];

		$project = null;
		try {
			$project = $this->projectService->getProject($projectId);
		} catch (\Throwable) {
			$project = null;
		}
		if ($project === null) {
			throw new CompanionValidationException('invalid_project', 'Project not found');
		}
		if (isset($project['active']) && (int)$project['active'] === 0) {
			throw new CompanionValidationException('project_inactive', 'Project is inactive');
		}
		// Web parity: project customer becomes the ticket customer.
		if (!empty($project['customer_id'])) {
			try {
				$customer = $this->projectService->getCustomer((int)$project['customer_id']);
				if ($customer !== null) {
					$data['customer_id'] = (int)$project['customer_id'];
					$data['customer_name'] = (string)($customer['name'] ?? '');
					$data['customer_email'] = (string)($customer['email'] ?? '');
				}
			} catch (\Throwable) {
				// fall back to creator identity below
			}
		}

		// Web parity: fall back to the creating agent's identity.
		if (empty($data['customer_email'])) {
			$email = trim((string)$this->permissions->getCurrentUserEmail());
			// Agents without a profile email must still be able to file tickets;
			// TicketService otherwise rejects with "Customer email is required".
			$data['customer_email'] = $email !== '' ? $email : ($uid . '@ticketcheck.invalid');
		}
		if (empty($data['customer_name'])) {
			$data['customer_name'] = $this->permissions->getCurrentUserDisplayName();
		}

		try {
			$ticket = $this->ticketService->createTicket($data);
		} catch (\Exception $e) {
			if (str_starts_with($e->getMessage(), 'Validation failed')) {
				throw new CompanionValidationException('validation_failed', $e->getMessage());
			}
			throw $e;
		}

		// Web parity: creation email is soft-fail.
		try {
			$this->emailService->sendTicketCreatedNotification($ticket);
		} catch (\Throwable $e) {
			$this->logger->warning('Companion ticket created email failed', ['exception' => $e]);
		}

		return $this->detail((int)$ticket->getId(), $uid);
	}

	/**
	 * Persist a validated companion upload as a ticket-level (public) attachment.
	 *
	 * @return array{attachment: array<string, mixed>, ticket: array{id: int, version: int}}
	 */
	public function addUploadedAttachment(
		int $id,
		string $tmpPath,
		string $originalName,
		int $fileSize,
		string $mimeType,
		int $version,
	): array {
		$this->requireEditableTicket($id);

		try {
			$attachment = $this->ticketService->addAttachmentFromUploadedFile(
				$id,
				$tmpPath,
				$originalName,
				$fileSize,
				$mimeType,
				null,
				false,
				function (Ticket $t) use ($version): void {
					$this->assertCanEdit($t);
					$this->assertVersion($t, $version);
				},
				false,
			);
		} catch (CompanionConflictException $e) {
			throw $e;
		} catch (CompanionNotFoundException $e) {
			throw $e;
		} catch (\Exception $e) {
			$this->logger->error('Companion attachment upload failed', [
				'ticket_id' => $id,
				'exception' => $e,
			]);
			throw new CompanionValidationException('attachment_upload_failed', 'Attachment could not be stored');
		}

		$fresh = $this->ticketService->getActiveTicket($id);

		return [
			'attachment' => $this->serializeAttachment($attachment, []),
			'ticket' => [
				'id' => (int)$fresh->getId(),
				'version' => $this->ticketVersion($fresh),
			],
		];
	}

	/**
	 * Resolve an attachment for download with the uniform not-found oracle.
	 */
	public function attachmentForDownload(int $ticketId, int $attachmentId): Attachment
	{
		$this->requireVisibleTicket($ticketId);

		$attachment = null;
		foreach ($this->attachmentMapper->findByTicketId($ticketId) as $candidate) {
			if ((int)$candidate->getId() === $attachmentId) {
				$attachment = $candidate;
				break;
			}
		}
		if ($attachment === null) {
			// AF24: uniform opacity with ticket IDOR (no attachment existence oracle).
			throw new CompanionNotFoundException();
		}
		if (!$this->ticketService->canViewerAccessAttachment($attachment, $this->permissions->canViewInternalNotes())) {
			throw new CompanionNotFoundException();
		}
		return $attachment;
	}

	private function applyStatusTransition(Ticket $ticket, string $uid, string $status, ?int $version = null): Ticket
	{
		// CAS before transition: a stale client must always get CONFLICT (409), never a
		// transition oracle (422) based on a ticket state they have not observed.
		if ($version !== null) {
			$this->assertVersion($ticket, $version);
		}

		$status = Ticket::normalizeStatus($status) ?? $status;
		$allowed = $this->allowedTransitions($ticket->getStatus());
		if (!in_array($status, $allowed, true)) {
			throw new CompanionValidationException('STATUS_TRANSITION_DENIED', 'Status transition not allowed');
		}

		$old = $ticket->getStatus();
		$updated = $this->guardTicketWorkflow(fn (): Ticket => $this->ticketService->changeStatus(
			(int)$ticket->getId(),
			$status,
			function (Ticket $t) use ($version): void {
				$this->assertCanEdit($t);
				// Re-check under the workflow lock — another writer may have won the race.
				if ($version !== null) {
					$this->assertVersion($t, $version);
				}
			},
		));
		$this->notifications->notifyStatusChanged($updated, $uid, $old, $status);

		return $updated;
	}

	private function mapWatcherException(\InvalidArgumentException $e): \Exception
	{
		if ($e->getMessage() === 'ticket_not_found') {
			return new CompanionNotFoundException();
		}
		if (str_contains($e->getMessage(), 'already in progress')) {
			return new CompanionConflictException('CONFLICT', 'Ticket was modified; reload and retry');
		}
		return new CompanionValidationException('watch_denied', $e->getMessage());
	}

	private function requireVisibleTicket(int $id): Ticket
	{
		try {
			$ticket = $this->ticketService->getActiveTicket($id);
		} catch (DoesNotExistException|\Exception) {
			throw new CompanionNotFoundException();
		}
		if (!$this->permissions->canViewTicket($ticket)) {
			throw new CompanionNotFoundException();
		}
		return $ticket;
	}

	private function requireEditableTicket(int $id): Ticket
	{
		$ticket = $this->requireVisibleTicket($id);
		$this->assertCanEdit($ticket);
		return $ticket;
	}

	private function assertCanEdit(Ticket $ticket): void
	{
		if (!$this->permissions->canEditTicket($ticket)) {
			throw new CompanionNotFoundException();
		}
	}

	private function assertVersion(Ticket $ticket, int $version): void
	{
		if ($version !== $this->ticketVersion($ticket)) {
			throw new CompanionConflictException('CONFLICT', 'Ticket was modified; reload and retry');
		}
	}

	/**
	 * TicketService wraps workflow-lock contention as a generic Exception — map to CAS CONFLICT for companion clients.
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	private function guardTicketWorkflow(callable $fn): mixed
	{
		try {
			return $fn();
		} catch (\Exception $e) {
			if (str_contains($e->getMessage(), 'already in progress')) {
				throw new CompanionConflictException('CONFLICT', 'Ticket was modified; reload and retry');
			}
			throw $e;
		}
	}

	public function ticketVersion(Ticket $ticket): int
	{
		return (int)$ticket->getUpdatedAt()->getTimestamp();
	}

	/**
	 * @return list<string>
	 */
	public function allowedTransitions(string $current): array
	{
		$current = Ticket::normalizeStatus($current) ?? $current;
		$all = Ticket::getStatuses();
		return array_values(array_filter($all, static fn (string $s): bool => $s !== $current));
	}

	/**
	 * SLA overdue flag consistent across inbox + detail:
	 * response dimension counts while the ticket is still new (no staff response
	 * in the normal workflow); resolution dimension counts until done.
	 */
	public function isOverdue(Ticket $ticket): bool
	{
		if (!$ticket->isOpen()) {
			return false;
		}
		if ($ticket->getStatus() === Ticket::STATUS_NEW && $ticket->isResponseOverdue()) {
			return true;
		}
		return $ticket->isResolutionOverdue();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function serializeInboxItem(Ticket $ticket): array
	{
		$updated = $ticket->getUpdatedAt();
		return [
			'id' => $ticket->getId(),
			'number' => $ticket->getTicketNumber(),
			'title' => $ticket->getTitle(),
			'status' => $ticket->getStatus(),
			'priority' => $ticket->getPriority(),
			'projectId' => $ticket->getProjectId(),
			'projectName' => $this->projectName($ticket->getProjectId()),
			'assignedTo' => $ticket->getAssignedTo(),
			'updatedAt' => $updated->format(\DateTimeInterface::ATOM),
			'updatedAtRaw' => $updated->format('Y-m-d H:i:s'),
			// Unread is client-local (seen markers + resume poll). Do not advertise a lying server flag.
			'slaResponseDue' => $ticket->getSlaResponseDue()?->format(\DateTimeInterface::ATOM),
			'slaResolutionDue' => $ticket->getSlaResolutionDue()?->format(\DateTimeInterface::ATOM),
			'overdue' => $this->isOverdue($ticket),
			'version' => $this->ticketVersion($ticket),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function serializeTicket(Ticket $ticket): array
	{
		return [
			'id' => $ticket->getId(),
			'number' => $ticket->getTicketNumber(),
			'title' => $ticket->getTitle(),
			'description' => $ticket->getDescription(),
			'status' => $ticket->getStatus(),
			'priority' => $ticket->getPriority(),
			'projectId' => $ticket->getProjectId(),
			'projectName' => $this->projectName($ticket->getProjectId()),
			'assignedTo' => $ticket->getAssignedTo(),
			'customerDisplay' => $ticket->getCustomerName() !== '' ? $ticket->getCustomerName() : $ticket->getCustomerEmail(),
			'createdAt' => $ticket->getCreatedAt()->format(\DateTimeInterface::ATOM),
			'updatedAt' => $ticket->getUpdatedAt()->format(\DateTimeInterface::ATOM),
			'slaResponseDue' => $ticket->getSlaResponseDue()?->format(\DateTimeInterface::ATOM),
			'slaResolutionDue' => $ticket->getSlaResolutionDue()?->format(\DateTimeInterface::ATOM),
			'overdue' => $this->isOverdue($ticket),
			'version' => $this->ticketVersion($ticket),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function serializeComment(Comment $comment): array
	{
		return [
			'id' => $comment->getId(),
			'body' => $comment->getContent(),
			'visibility' => $comment->getIsInternal() ? 'internal' : 'public',
			'authorId' => $comment->getUserId(),
			'authorDisplay' => $comment->getAuthorName(),
			'createdAt' => $comment->getCreatedAt()->format(\DateTimeInterface::ATOM),
		];
	}

	/**
	 * @param array<int, true> $internalCommentIds
	 * @return array<string, mixed>
	 */
	private function serializeAttachment(Attachment $attachment, array $internalCommentIds): array
	{
		$commentId = $attachment->getCommentId();
		$isInternal = $commentId !== null && $commentId > 0 && isset($internalCommentIds[(int)$commentId]);
		return [
			'id' => $attachment->getId(),
			'fileName' => $attachment->getFileName(),
			'mimeType' => $attachment->getMimeType(),
			'size' => $attachment->getFileSize(),
			'visibility' => $isInternal ? 'internal' : 'public',
		];
	}

	private function projectName(?int $projectId): ?string
	{
		if ($projectId === null || $projectId < 1) {
			return null;
		}
		try {
			$project = $this->projectService->getProject($projectId);
			return is_array($project) ? (string)($project['name'] ?? $project['title'] ?? null) : null;
		} catch (\Throwable) {
			return null;
		}
	}

	private function encodeCursorFromParts(string $updatedAtSql, int $id): string
	{
		$payload = $updatedAtSql . '|' . $id;
		return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
	}

	/**
	 * @return array{updatedAt: string, id: int}|null
	 */
	private function decodeCursor(?string $cursor): ?array
	{
		if ($cursor === null || $cursor === '') {
			return null;
		}
		$padded = strtr($cursor, '-_', '+/');
		$padLen = (4 - strlen($padded) % 4) % 4;
		$raw = base64_decode($padded . str_repeat('=', $padLen), true);
		if ($raw === false || !str_contains($raw, '|')) {
			throw new CompanionValidationException('invalid_cursor', 'Invalid inbox cursor');
		}
		[$updatedAt, $id] = explode('|', $raw, 2);
		if (!preg_match('/^\d{4}-\d{2}-\d{2} /', $updatedAt) || !ctype_digit($id)) {
			throw new CompanionValidationException('invalid_cursor', 'Invalid inbox cursor');
		}
		return ['updatedAt' => $updatedAt, 'id' => (int)$id];
	}
}

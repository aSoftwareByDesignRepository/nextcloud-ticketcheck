<?php

declare(strict_types=1);

/**
 * Co-assignee + persistent team management (spec: multi-assignee-2026-11-22).
 *
 * Contract:
 * - Primary/accountable assignee lives on helpdesk_tickets.assigned_to;
 *   this service owns the co-assignee set (cap {@see MAX_CO_ASSIGNEES}).
 * - Set semantics: add of an existing co-assignee and remove of a missing
 *   one are idempotent no-ops (EC-16); a (ticket_id, user_id) unique
 *   violation maps to success, never a 500.
 * - Every mutation re-validates the target user via
 *   UserValidationService::validateUserForAssignment() and bumps
 *   ticket.updated_at via Ticket::nextUpdatedAt() so companion CAS tokens
 *   change on any roster change.
 * - All mutations run under TicketWorkflowLock on the ticket.
 *
 * Error codes surfaced as \InvalidArgumentException messages:
 *   ticket_not_found, ticket_merged, not_assignable, already_primary,
 *   max_coassignees, no_assignable_members, group_not_assignable.
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\DbQueryGuard;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketAssignee;
use OCA\Ticketcheck\Db\TicketAssigneeMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Db\TicketTeam;
use OCA\Ticketcheck\Db\TicketTeamMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class TicketAssigneeService
{
	/** Maximum co-assignees per ticket (spec AC: cap 10). */
	public const MAX_CO_ASSIGNEES = 10;

	public function __construct(
		private readonly TicketAssigneeMapper $assigneeMapper,
		private readonly TicketTeamMapper $teamMapper,
		private readonly TicketMapper $ticketMapper,
		private readonly UserValidationService $userValidationService,
		private readonly PermissionService $permissionService,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly TicketWorkflowLock $workflowLock,
		private readonly ActivityEventService $activityEventService,
		private readonly LoggerInterface $logger,
		private readonly ?TicketNotificationDispatcher $notifier = null,
		private readonly ?CompanionNotificationService $companionNotifications = null,
		private readonly ?\OCP\IDBConnection $db = null,
	) {
	}

	/**
	 * Co-assignee uids for one ticket (serialization, fan-out).
	 *
	 * @return string[]
	 */
	public function getCoAssigneeUserIds(int $ticketId): array
	{
		if ($ticketId <= 0) {
			return [];
		}
		try {
			return $this->assigneeMapper->getUserIdsByTicketId($ticketId);
		} catch (\Throwable $e) {
			if (DbQueryGuard::isMissingSchemaObject($e)) {
				return [];
			}
			throw $e;
		}
	}

	/**
	 * Co-assignees with display names for the staff UI.
	 *
	 * @return array<int, array{user_id: string, display_name: string}>
	 */
	public function getCoAssigneesWithDisplay(int $ticketId): array
	{
		$out = [];
		foreach ($this->getCoAssigneeUserIds($ticketId) as $uid) {
			$user = $this->userManager->get($uid);
			$out[] = [
				'user_id' => $uid,
				'display_name' => $user !== null ? (string)$user->getDisplayName() : $uid,
			];
		}
		return $out;
	}

	/**
	 * Batch co-assignee uids keyed by ticket id (list pages / +N badges).
	 *
	 * @param int[] $ticketIds
	 * @return array<int, string[]>
	 */
	public function getCoAssigneeMapForTickets(array $ticketIds): array
	{
		try {
			return $this->assigneeMapper->getUserIdsForTickets($ticketIds);
		} catch (\Throwable $e) {
			if (DbQueryGuard::isMissingSchemaObject($e)) {
				return [];
			}
			throw $e;
		}
	}

	/**
	 * Batch with display names (list pages / avatar stacks).
	 *
	 * @param int[] $ticketIds
	 * @return array<int, array<int, array{user_id: string, display_name: string}>>
	 */
	public function getCoAssigneesWithDisplayForTickets(array $ticketIds): array
	{
		$map = $this->getCoAssigneeMapForTickets($ticketIds);
		$names = [];
		foreach ($map as $uids) {
			foreach ($uids as $uid) {
				if (!array_key_exists($uid, $names)) {
					$user = $this->userManager->get($uid);
					$names[$uid] = $user !== null ? (string)$user->getDisplayName() : $uid;
				}
			}
		}
		$out = [];
		foreach ($map as $ticketId => $uids) {
			foreach ($uids as $uid) {
				$out[$ticketId][] = ['user_id' => $uid, 'display_name' => $names[$uid]];
			}
		}
		return $out;
	}

	public function isCoAssignee(int $ticketId, string $userId): bool
	{
		try {
			return $this->assigneeMapper->isAssignee($ticketId, $userId);
		} catch (\Throwable $e) {
			if (DbQueryGuard::isMissingSchemaObject($e)) {
				return false;
			}
			throw $e;
		}
	}

	/**
	 * Team group ids assigned to a ticket.
	 *
	 * @return string[]
	 */
	public function getTeamGroupIds(int $ticketId): array
	{
		try {
			return $this->teamMapper->getGroupIdsByTicketId($ticketId);
		} catch (\Throwable $e) {
			if (DbQueryGuard::isMissingSchemaObject($e)) {
				return [];
			}
			throw $e;
		}
	}

	/**
	 * Add one co-assignee. Idempotent: already-a-co-assignee → success no-op
	 * (EC-16). Unique-index race → same idempotent success.
	 *
	 * @return bool true when a row was added, false on idempotent no-op
	 */
	public function addCoAssignee(int $ticketId, string $userId, string $actor, ?callable $assertAuthorized = null): bool
	{
		if ($userId === '') {
			throw new \InvalidArgumentException('not_assignable');
		}

		$added = $this->workflowLock->withTicketLocks(
			[$ticketId],
			function () use ($ticketId, $userId, $actor, $assertAuthorized): bool {
				$ticket = $this->findActiveTicket($ticketId);
				if ($assertAuthorized !== null) {
					$assertAuthorized($ticket);
				}

				if ($userId === (string)$ticket->getAssignedTo()) {
					throw new \InvalidArgumentException('already_primary');
				}
				if ($this->assigneeMapper->isAssignee($ticketId, $userId)) {
					return false;
				}
				$this->assertAssignable($userId, (int)$ticket->getProjectId());

				if ($this->assigneeMapper->countByTicketId($ticketId) >= self::MAX_CO_ASSIGNEES) {
					throw new \InvalidArgumentException('max_coassignees');
				}

				$row = new TicketAssignee();
				$row->setTicketId($ticketId);
				$row->setUserId($userId);
				$row->setAddedBy($actor);
				$row->setCreatedAt(new \DateTime());
				try {
					$this->assigneeMapper->insert($row);
				} catch (DbException $e) {
					if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
						return false; // concurrent add won; set semantics → success
					}
					throw $e;
				}

				$this->bumpUpdatedAt($ticket);
				$this->activityEventService->logCoAssigneeAdded($actor, $ticket, $userId);
				$this->logger->info('Co-assignee added', [
					'ticket_id' => $ticketId,
					'user_id' => $userId,
					'actor' => $actor,
				]);
				return true;
			},
			'TicketCheck co-assignee add'
		);
		if ($added) {
			$this->notifyAddedCoAssignee($ticketId, $userId, $actor);
		}
		return $added;
	}

	/**
	 * Remove one co-assignee. Idempotent: missing row → success no-op.
	 *
	 * @return bool true when a row was removed
	 */
	public function removeCoAssignee(int $ticketId, string $userId, string $actor, ?callable $assertAuthorized = null): bool
	{
		return $this->workflowLock->withTicketLocks(
			[$ticketId],
			function () use ($ticketId, $userId, $actor, $assertAuthorized): bool {
				$ticket = $this->findActiveTicket($ticketId);
				if ($assertAuthorized !== null) {
					$assertAuthorized($ticket);
				}
				$deleted = $this->assigneeMapper->deleteByTicketAndUser($ticketId, $userId);
				if ($deleted > 0) {
					$this->bumpUpdatedAt($ticket);
					$this->activityEventService->logCoAssigneeRemoved($actor, $ticket, $userId);
					$this->logger->info('Co-assignee removed', [
						'ticket_id' => $ticketId,
						'user_id' => $userId,
						'actor' => $actor,
					]);
				}
				return $deleted > 0;
			},
			'TicketCheck co-assignee remove'
		);
	}

	/**
	 * Replace the whole co-assignee set (web form `co_assignees` CSV path).
	 * Validate-all-then-write: any invalid uid fails before rows change.
	 * The current primary may not appear in the set.
	 *
	 * @param string[] $userIds
	 * @return array{added: int, removed: int}
	 */
	public function replaceCoAssignees(int $ticketId, array $userIds, string $actor): array
	{
		$target = [];
		foreach ($userIds as $uid) {
			$uid = trim((string)$uid);
			if ($uid !== '' && !in_array($uid, $target, true)) {
				$target[] = $uid;
			}
		}

		$notified = [];
		$result = $this->workflowLock->withTicketLocks(
			[$ticketId],
			function () use ($ticketId, $target, $actor, &$notified): array {
				$ticket = $this->findActiveTicket($ticketId);
				$primary = (string)$ticket->getAssignedTo();

				// EC-19: a CSV carrying the primary uid is silently deduped —
				// rejecting the whole submit for one duplicated chip is
				// hostile (contrast addCoAssignee, where the caller's intent
				// is specifically that uid → already_primary there).
				$target = array_values(array_filter($target, static fn (string $uid): bool => $uid !== $primary));

				// Validate the entire target set before touching rows.
				foreach ($target as $uid) {
					$this->assertAssignable($uid, (int)$ticket->getProjectId());
				}
				if (count($target) > self::MAX_CO_ASSIGNEES) {
					throw new \InvalidArgumentException('max_coassignees');
				}

				$current = $this->assigneeMapper->getUserIdsByTicketId($ticketId);
				$toAdd = array_values(array_diff($target, $current));
				$toRemove = array_values(array_diff($current, $target));

				// Set-replace is multi-row: wrap the mutation so a mid-loop
				// failure never leaves a half-applied co-assignee set.
				$ownTxn = $this->db !== null && !$this->db->inTransaction();
				if ($ownTxn) {
					$this->db->beginTransaction();
				}
				try {
					foreach ($toRemove as $uid) {
						$this->assigneeMapper->deleteByTicketAndUser($ticketId, $uid);
					}
					foreach ($toAdd as $uid) {
						$row = new TicketAssignee();
						$row->setTicketId($ticketId);
						$row->setUserId($uid);
						$row->setAddedBy($actor);
						$row->setCreatedAt(new \DateTime());
						try {
							$this->assigneeMapper->insert($row);
						} catch (DbException $e) {
							if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
								throw $e;
							}
						}
					}
					if ($ownTxn) {
						$this->db->commit();
					}
				} catch (\Throwable $e) {
					if ($ownTxn && $this->db->inTransaction()) {
						$this->db->rollBack();
					}
					throw $e;
				}

				if ($toAdd !== [] || $toRemove !== []) {
					$this->bumpUpdatedAt($ticket);
					foreach ($toAdd as $uid) {
						$this->activityEventService->logCoAssigneeAdded($actor, $ticket, $uid);
					}
					foreach ($toRemove as $uid) {
						$this->activityEventService->logCoAssigneeRemoved($actor, $ticket, $uid);
					}
				}
				$notified = $toAdd;

				return ['added' => count($toAdd), 'removed' => count($toRemove)];
			},
			'TicketCheck co-assignee replace'
		);
		foreach ($notified as $uid) {
			$this->notifyAddedCoAssignee($ticketId, $uid, $actor);
		}
		return $result;
	}

	/**
	 * Assign a team (group) to a ticket: persistent team row (NH-01) plus
	 * co-assignee fan-out for currently assignable members (cap applies).
	 * Non-admin actors may only assign groups they belong to.
	 *
	 * @return array{added: int, skipped: int}
	 */
	public function assignTeam(int $ticketId, string $groupId, string $actor, ?callable $assertAuthorized = null): array
	{
		$groupId = trim($groupId);
		if ($groupId === '') {
			throw new \InvalidArgumentException('group_not_assignable');
		}

		$addedUids = [];
		$result = $this->workflowLock->withTicketLocks(
			[$ticketId],
			function () use ($ticketId, $groupId, $actor, &$addedUids, $assertAuthorized): array {
				$ticket = $this->findActiveTicket($ticketId);
				if ($assertAuthorized !== null) {
					$assertAuthorized($ticket);
				}

				$group = $this->groupManager->get($groupId);
				if ($group === null) {
					throw new \InvalidArgumentException('group_not_assignable');
				}
				if (!$this->groupManager->isAdmin($actor)
					&& !$this->groupManager->isInGroup($actor, $groupId)) {
					throw new \InvalidArgumentException('group_not_assignable');
				}

				$primary = (string)$ticket->getAssignedTo();
				$members = $group->getUsers();
				$added = 0;
				$skipped = 0;
				$sawAssignable = false;
				foreach (is_iterable($members) ? $members : [] as $member) {
					$uid = $member->getUID();
					if ($uid === '' || $uid === $primary
						|| $this->assigneeMapper->isAssignee($ticketId, $uid)) {
						continue;
					}
					if ($this->assigneeMapper->countByTicketId($ticketId) >= self::MAX_CO_ASSIGNEES) {
						$skipped++;
						continue;
					}
					if (!$this->userValidationService
						->validateUserForAssignment($uid, (int)$ticket->getProjectId())['valid']) {
						continue;
					}
					$sawAssignable = true;
					$row = new TicketAssignee();
					$row->setTicketId($ticketId);
					$row->setUserId($uid);
					$row->setAddedBy($actor);
					$row->setCreatedAt(new \DateTime());
					try {
						$this->assigneeMapper->insert($row);
						$added++;
						$addedUids[] = $uid;
					} catch (DbException $e) {
						if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
							throw $e;
						}
					}
				}

				$alreadyTeamed = $this->teamMapper->hasTeam($ticketId, $groupId);
				if (!$alreadyTeamed && !$sawAssignable && $added === 0) {
					throw new \InvalidArgumentException('no_assignable_members');
				}

				if (!$alreadyTeamed) {
					$team = new TicketTeam();
					$team->setTicketId($ticketId);
					$team->setGroupId($groupId);
					$team->setAddedBy($actor);
					$team->setCreatedAt(new \DateTime());
					try {
						$this->teamMapper->insert($team);
					} catch (DbException $e) {
						if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
							throw $e;
						}
					}
				}

				if ($added > 0 || !$alreadyTeamed) {
					$this->bumpUpdatedAt($ticket);
					$this->activityEventService->logTeamAssigned($actor, $ticket, $groupId);
				}

				return ['added' => $added, 'skipped' => $skipped];
			},
			'TicketCheck team assign'
		);
		foreach ($addedUids as $uid) {
			$this->notifyAddedCoAssignee($ticketId, $uid, $actor);
		}
		return $result;
	}

	/**
	 * Remove a persistent team row (does not strip member co-assignees).
	 */
	public function unassignTeam(int $ticketId, string $groupId, string $actor, ?callable $assertAuthorized = null): bool
	{
		return $this->workflowLock->withTicketLocks(
			[$ticketId],
			function () use ($ticketId, $groupId, $actor, $assertAuthorized): bool {
				$ticket = $this->findActiveTicket($ticketId);
				if ($assertAuthorized !== null) {
					$assertAuthorized($ticket);
				}
				$deleted = $this->teamMapper->deleteByTicketAndGroup($ticketId, $groupId);
				if ($deleted > 0) {
					$this->bumpUpdatedAt($ticket);
					$this->logger->info('Team unassigned', [
						'ticket_id' => $ticketId,
						'group_id' => $groupId,
						'actor' => $actor,
					]);
				}
				return $deleted > 0;
			},
			'TicketCheck team unassign'
		);
	}

	/**
	 * Claim a team-queued ticket: actor (a live member of a queued group)
	 * becomes primary assignee; the actor's group rows are cleared and any
	 * co row for the actor is dropped. Co-assignees stay.
	 */
	public function claimTeamTicket(int $ticketId, string $actor, ?callable $assertAuthorized = null): Ticket
	{
		return $this->workflowLock->withTicketLocks(
			[$ticketId],
			function () use ($ticketId, $actor, $assertAuthorized): Ticket {
				$ticket = $this->findActiveTicket($ticketId);
				if ($assertAuthorized !== null) {
					$assertAuthorized($ticket);
				}

				$claimed = false;
				foreach ($this->teamMapper->findByTicketId($ticketId) as $team) {
					if ($this->groupManager->isInGroup($actor, (string)$team->getGroupId())) {
						$this->teamMapper->delete($team);
						$claimed = true;
					}
				}
				if (!$claimed) {
					throw new \InvalidArgumentException('not_assignable');
				}

				// Team-row delete + co-row delete + primary set must land
				// atomically — a partial claim would strand the ticket.
				$ownTxn = $this->db !== null && !$this->db->inTransaction();
				if ($ownTxn) {
					$this->db->beginTransaction();
				}
				try {
					$this->assigneeMapper->deleteByTicketAndUser($ticketId, $actor);
					$ticket->setAssignedTo($actor);
					$ticket->setUpdatedAt(Ticket::nextUpdatedAt($ticket->getUpdatedAt()));
					if ($ticket->getStatus() === Ticket::STATUS_NEW) {
						$ticket->setStatus(Ticket::STATUS_IN_PROGRESS);
					}
					$this->ticketMapper->update($ticket);
					if ($ownTxn) {
						$this->db->commit();
					}
				} catch (\Throwable $e) {
					if ($ownTxn && $this->db->inTransaction()) {
						$this->db->rollBack();
					}
					throw $e;
				}
				$this->activityEventService->logTeamClaimed($actor, $ticket);
				$this->logger->info('Team ticket claimed', [
					'ticket_id' => $ticketId,
					'actor' => $actor,
				]);
				return $ticket;
			},
			'TicketCheck team claim'
		);
	}

	/**
	 * Groups the actor may assign (NN-09): non-admin → actor's own groups;
	 * admin → all helpdesk staff groups. `fits` = member fan-out would not
	 * exceed the co cap (anti-enumeration: counts only, no member uids).
	 *
	 * @return array<int, array{group_id: string, display_name: string, member_count: int, fits: bool}>
	 */
	public function getAssignableGroups(int $ticketId, string $actor): array
	{
		$ticket = $this->findActiveTicket($ticketId);
		$projectId = (int)$ticket->getProjectId();

		if ($this->groupManager->isAdmin($actor)) {
			$gids = [
				PermissionService::GROUP_HELPDESK_AGENTS,
				PermissionService::GROUP_HELPDESK_ADMINS,
			];
		} else {
			// IGroupManager::getUserGroupIds takes IUser — a missing user
			// object means no groups (fail-closed, never a TypeError).
			$actorUser = $this->userManager->get($actor);
			$gids = $actorUser !== null ? $this->groupManager->getUserGroupIds($actorUser) : [];
		}

		$current = $this->assigneeMapper->countByTicketId($ticketId);
		$primary = (string)$ticket->getAssignedTo();
		$teamed = $this->teamMapper->getGroupIdsByTicketId($ticketId);

		$out = [];
		foreach ($gids as $gid) {
			$group = $this->groupManager->get($gid);
			if ($group === null) {
				continue;
			}
			$memberCount = 0;
			$assignable = 0;
			foreach ($group->getUsers() as $member) {
				$memberCount++;
				$uid = $member->getUID();
				if ($uid === $primary || $this->assigneeMapper->isAssignee($ticketId, $uid)) {
					continue;
				}
				if ($this->userValidationService->validateUserForAssignment($uid, $projectId)['valid']) {
					$assignable++;
				}
			}
			$out[] = [
				'group_id' => $gid,
				'display_name' => $group->getDisplayName(),
				'member_count' => $memberCount,
				'fits' => $assignable > 0 && ($current + $assignable) <= self::MAX_CO_ASSIGNEES,
				'assigned' => in_array($gid, $teamed, true),
			];
		}
		usort($out, fn (array $a, array $b): int => strcasecmp($a['display_name'], $b['display_name']));
		return $out;
	}

	/**
	 * Merge transfer (EC-10): union source co rows onto target and demote
	 * the source primary to a co-assignee on the target. Cap-exempt —
	 * system operations may exceed MAX_CO_ASSIGNEES.
	 *
	 * @return array{moved: int, dropped: int, primary_demoted: bool}
	 */
	public function transferOnMerge(int $sourceId, int $targetId): array
	{
		if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
			return ['moved' => 0, 'dropped' => 0, 'primary_demoted' => false];
		}

		$target = null;
		$source = null;
		try {
			$target = $this->ticketMapper->find($targetId);
			$source = $this->ticketMapper->find($sourceId);
		} catch (\Throwable) {
		}
		$targetPrimary = $target !== null ? (string)$target->getAssignedTo() : null;

		$stats = $this->assigneeMapper->moveToTicket($sourceId, $targetId, $targetPrimary);
		$primaryDemoted = false;

		$sourcePrimary = $source !== null ? (string)$source->getAssignedTo() : '';
		if ($sourcePrimary !== ''
			&& $sourcePrimary !== $targetPrimary
			&& !$this->assigneeMapper->isAssignee($targetId, $sourcePrimary)) {
			$row = new TicketAssignee();
			$row->setTicketId($targetId);
			$row->setUserId($sourcePrimary);
			$row->setAddedBy('');
			$row->setCreatedAt(new \DateTime());
			try {
				$this->assigneeMapper->insert($row);
				$primaryDemoted = true;
			} catch (DbException $e) {
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
			}
		}

		$teamStats = $this->teamMapper->moveToTicket($sourceId, $targetId);
		$stats['moved'] += $teamStats['moved'];
		$stats['dropped'] += $teamStats['dropped'];

		$this->logger->info('Transferred assignees on merge', [
			'source_id' => $sourceId,
			'target_id' => $targetId,
			'moved' => $stats['moved'],
			'dropped' => $stats['dropped'],
			'primary_demoted' => $primaryDemoted,
		]);

		return ['moved' => $stats['moved'], 'dropped' => $stats['dropped'], 'primary_demoted' => $primaryDemoted];
	}

	/**
	 * Called when a ticket's primary changes: the new primary must not keep
	 * a co row (a user cannot be primary and co-assignee at once).
	 * Caller must already hold the ticket lock.
	 */
	public function dropCoRowForPrimary(int $ticketId, string $userId): void
	{
		if ($userId === '') {
			return;
		}
		try {
			$this->assigneeMapper->deleteByTicketAndUser($ticketId, $userId);
		} catch (\Throwable $e) {
			if (!DbQueryGuard::isMissingSchemaObject($e)) {
				throw $e;
			}
		}
	}

	/**
	 * Delete all assignee + team rows for a ticket (purge path).
	 *
	 * @return array{assignees: int, teams: int}
	 */
	public function purgeForTicket(int $ticketId): array
	{
		try {
			return [
				'assignees' => $this->assigneeMapper->deleteByTicketId($ticketId),
				'teams' => $this->teamMapper->deleteByTicketId($ticketId),
			];
		} catch (\Throwable $e) {
			if (DbQueryGuard::isMissingSchemaObject($e)) {
				return ['assignees' => 0, 'teams' => 0];
			}
			throw $e;
		}
	}

	/**
	 * @return array{assignees: int, teams: int}
	 */
	public function countForTicket(int $ticketId): array
	{
		try {
			return [
				'assignees' => $this->assigneeMapper->countByTicketId($ticketId),
				'teams' => $this->teamMapper->countByTicketId($ticketId),
			];
		} catch (\Throwable $e) {
			if (DbQueryGuard::isMissingSchemaObject($e)) {
				return ['assignees' => 0, 'teams' => 0];
			}
			throw $e;
		}
	}

	/**
	 * Ticket ids queued for the actor's groups (NH-01 "my team" queue):
	 * open tickets with a team row for a group the actor belongs to.
	 *
	 * @param string[] $groupIds
	 * @return int[]
	 */
	public function getTeamTicketIdsForGroups(array $groupIds): array
	{
		try {
			return $this->teamMapper->getTicketIdsByGroupIds($groupIds);
		} catch (\Throwable $e) {
			if (DbQueryGuard::isMissingSchemaObject($e)) {
				return [];
			}
			throw $e;
		}
	}

	/**
	 * Group ids the actor can claim from on this ticket (live membership).
	 *
	 * @return string[]
	 */
	public function getClaimableGroupIds(int $ticketId, string $actor): array
	{
		$out = [];
		foreach ($this->getTeamGroupIds($ticketId) as $gid) {
			if ($this->groupManager->isInGroup($actor, $gid)) {
				$out[] = $gid;
			}
		}
		return $out;
	}

	/**
	 * Fan-out after commit (spec: notifications fire outside the ticket
	 * lock): in-app/mail dispatch + companion push, deduped against actor.
	 */
	private function notifyAddedCoAssignee(int $ticketId, string $userId, string $actor): void
	{
		if ($userId === $actor) {
			return;
		}
		try {
			$ticket = $this->ticketMapper->find($ticketId);
		} catch (\Throwable) {
			return;
		}
		try {
			$actorUser = $actor !== '' ? $this->userManager->get($actor) : null;
			$this->notifier?->assigned(
				$ticket,
				$userId,
				$actorUser !== null ? (string)$actorUser->getDisplayName() : null
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Co-assignee notification dispatch failed', [
				'ticket_id' => $ticketId,
				'user_id' => $userId,
				'exception' => $e,
			]);
		}
		try {
			$this->companionNotifications?->notifyCoAssigned($ticket, $userId, $actor);
		} catch (\Throwable $e) {
			$this->logger->warning('Co-assignee push dispatch failed', [
				'ticket_id' => $ticketId,
				'user_id' => $userId,
				'exception' => $e,
			]);
		}
	}

	private function assertAssignable(string $userId, int $projectId): void
	{
		$validation = $this->userValidationService->validateUserForAssignment(
			$userId,
			$projectId > 0 ? $projectId : null
		);
		if (!$validation['valid']) {
			throw new \InvalidArgumentException('not_assignable: ' . ($validation['reason'] ?? ''));
		}
	}

	private function findActiveTicket(int $ticketId): Ticket
	{
		try {
			$ticket = $this->ticketMapper->find($ticketId);
		} catch (DoesNotExistException) {
			throw new \InvalidArgumentException('ticket_not_found');
		}
		if ($ticket->getMergedIntoId() !== null) {
			throw new \InvalidArgumentException('ticket_merged');
		}
		return $ticket;
	}

	private function bumpUpdatedAt(Ticket $ticket): void
	{
		$ticket->setUpdatedAt(Ticket::nextUpdatedAt($ticket->getUpdatedAt()));
		$this->ticketMapper->update($ticket);
	}
}

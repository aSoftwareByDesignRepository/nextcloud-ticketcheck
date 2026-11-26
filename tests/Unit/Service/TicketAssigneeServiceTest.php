<?php

declare(strict_types=1);

/**
 * TicketAssigneeService — TC-MULTI-ASSIGN contract tests.
 *
 * Pins: already_primary vs. silent EC-19 dedupe, cap enforcement, check-
 * then-act unique-race handling, team-assign authorization (membership or
 * admin), claim semantics, and merge transfer.
 */

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketAssignee;
use OCA\Ticketcheck\Db\TicketAssigneeMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Db\TicketTeam;
use OCA\Ticketcheck\Db\TicketTeamMapper;
use OCA\Ticketcheck\Service\ActivityEventService;
use OCA\Ticketcheck\Service\CompanionNotificationService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\TicketAssigneeService;
use OCA\Ticketcheck\Service\TicketNotificationDispatcher;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCA\Ticketcheck\Service\UserValidationService;
use OCP\DB\Exception as DbException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TicketAssigneeServiceTest extends TestCase
{
	private TicketAssigneeMapper $assigneeMapper;
	private TicketTeamMapper $teamMapper;
	private TicketMapper $ticketMapper;
	private UserValidationService $userValidation;
	private PermissionService $permission;
	private IGroupManager $groupManager;
	private IUserManager $userManager;
	private ActivityEventService $activity;
	private TicketNotificationDispatcher $notifier;
	private CompanionNotificationService $companion;
	private TicketAssigneeService $service;

	protected function setUp(): void
	{
		$this->assigneeMapper = $this->createMock(TicketAssigneeMapper::class);
		$this->teamMapper = $this->createMock(TicketTeamMapper::class);
		$this->ticketMapper = $this->createMock(TicketMapper::class);
		$this->userValidation = $this->createMock(UserValidationService::class);
		$this->permission = $this->createMock(PermissionService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->activity = $this->createMock(ActivityEventService::class);
		$this->notifier = $this->createMock(TicketNotificationDispatcher::class);
		$this->companion = $this->createMock(CompanionNotificationService::class);

		$lock = $this->createMock(TicketWorkflowLock::class);
		$lock->method('withTicketLocks')->willReturnCallback(
			static fn (array $ids, callable $cb) => $cb()
		);

		$this->service = new TicketAssigneeService(
			$this->assigneeMapper,
			$this->teamMapper,
			$this->ticketMapper,
			$this->userValidation,
			$this->permission,
			$this->groupManager,
			$this->userManager,
			$lock,
			$this->activity,
			new NullLogger(),
			$this->notifier,
			$this->companion,
		);
	}

	private function ticket(?string $assignedTo = 'alice'): Ticket
	{
		$ticket = new Ticket();
		$ticket->setId(5);
		$ticket->setProjectId(1);
		$ticket->setAssignedTo($assignedTo);
		$this->ticketMapper->method('find')->with(5)->willReturn($ticket);
		return $ticket;
	}

	private function assignable(string $uid): void
	{
		$this->userValidation->method('validateUserForAssignment')
			->willReturnCallback(static fn (string $u) => [
				'valid' => $u === $uid,
				'reason' => 'not_in_scope',
			]);
	}

	public function testAddRejectsEmptyUid(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('not_assignable');
		$this->service->addCoAssignee(5, '', 'actor');
	}

	public function testAddRejectsPrimaryUid(): void
	{
		$this->ticket('alice');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('already_primary');
		$this->service->addCoAssignee(5, 'alice', 'actor');
	}

	public function testAddIsIdempotentForExistingRow(): void
	{
		$this->ticket();
		$this->assigneeMapper->method('isAssignee')->willReturn(true);
		$this->assigneeMapper->expects($this->never())->method('insert');
		$this->activity->expects($this->never())->method('logCoAssigneeAdded');
		$this->assertFalse($this->service->addCoAssignee(5, 'bob', 'actor'));
	}

	public function testAddEnforcesCap(): void
	{
		$this->ticket();
		$this->assigneeMapper->method('isAssignee')->willReturn(false);
		$this->assignable('bob');
		$this->assigneeMapper->method('countByTicketId')
			->willReturn(TicketAssigneeService::MAX_CO_ASSIGNEES);
		$this->assigneeMapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('max_coassignees');
		$this->service->addCoAssignee(5, 'bob', 'actor');
	}

	public function testAddRejectsNonAssignableUser(): void
	{
		$this->ticket();
		$this->assigneeMapper->method('isAssignee')->willReturn(false);
		$this->userValidation->method('validateUserForAssignment')
			->willReturn(['valid' => false, 'reason' => 'not_agent']);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('not_assignable');
		$this->service->addCoAssignee(5, 'mallory', 'actor');
	}

	public function testAddUniqueRaceIsIdempotentSuccess(): void
	{
		$this->ticket();
		$this->assigneeMapper->method('isAssignee')->willReturn(false);
		$this->assignable('bob');
		$this->assigneeMapper->method('countByTicketId')->willReturn(0);

		$ex = $this->createMock(DbException::class);
		$ex->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->assigneeMapper->method('insert')->willThrowException($ex);

		$this->assertFalse($this->service->addCoAssignee(5, 'bob', 'actor'));
	}

	public function testAddHappyPathInsertsLogsAndNotifies(): void
	{
		$this->ticket();
		$this->assigneeMapper->method('isAssignee')->willReturn(false);
		$this->assignable('bob');
		$this->assigneeMapper->method('countByTicketId')->willReturn(2);
		$this->assigneeMapper->expects($this->once())->method('insert')
			->with($this->callback(static fn (TicketAssignee $r): bool =>
				$r->getTicketId() === 5 && $r->getUserId() === 'bob' && $r->getAddedBy() === 'actor'));
		$this->activity->expects($this->once())->method('logCoAssigneeAdded')
			->with('actor', $this->anything(), 'bob');
		$this->ticketMapper->expects($this->once())->method('update');
		$this->assertTrue($this->service->addCoAssignee(5, 'bob', 'actor'));
	}

	public function testRemoveMissingRowIsNoOp(): void
	{
		$this->ticket();
		$this->assigneeMapper->method('deleteByTicketAndUser')->willReturn(0);
		$this->activity->expects($this->never())->method('logCoAssigneeRemoved');
		$this->assertFalse($this->service->removeCoAssignee(5, 'bob', 'actor'));
	}

	public function testRemoveRowDeletesLogsAndBumps(): void
	{
		$this->ticket();
		$this->assigneeMapper->method('deleteByTicketAndUser')->willReturn(1);
		$this->activity->expects($this->once())->method('logCoAssigneeRemoved')
			->with('actor', $this->anything(), 'bob');
		$this->ticketMapper->expects($this->once())->method('update');
		$this->assertTrue($this->service->removeCoAssignee(5, 'bob', 'actor'));
	}

	/** EC-19: CSV carrying the primary uid is silently dropped, not rejected. */
	public function testReplaceSilentlyDedupesPrimary(): void
	{
		$this->ticket('alice');
		$this->assignable('bob');
		$this->assigneeMapper->method('getUserIdsByTicketId')->willReturn([]);
		$this->assigneeMapper->expects($this->once())->method('insert')
			->with($this->callback(static fn (TicketAssignee $r): bool => $r->getUserId() === 'bob'));
		$result = $this->service->replaceCoAssignees(5, ['alice', 'bob'], 'actor');
		$this->assertSame(['added' => 1, 'removed' => 0], $result);
	}

	public function testReplaceValidatesWholeSetBeforeWriting(): void
	{
		$this->ticket();
		$this->userValidation->method('validateUserForAssignment')
			->willReturnCallback(static fn (string $u) => ['valid' => $u !== 'mallory', 'reason' => 'x']);
		$this->assigneeMapper->expects($this->never())->method('insert');
		$this->assigneeMapper->expects($this->never())->method('deleteByTicketAndUser');
		$this->expectException(\InvalidArgumentException::class);
		$this->service->replaceCoAssignees(5, ['bob', 'mallory'], 'actor');
	}

	public function testReplaceEnforcesCap(): void
	{
		$this->ticket();
		$this->userValidation->method('validateUserForAssignment')
			->willReturn(['valid' => true]);
		$this->assigneeMapper->method('getUserIdsByTicketId')->willReturn([]);
		$uids = array_map(static fn (int $i): string => "u{$i}", range(1, TicketAssigneeService::MAX_CO_ASSIGNEES + 1));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('max_coassignees');
		$this->service->replaceCoAssignees(5, $uids, 'actor');
	}

	public function testAssignTeamRejectsUnknownGroup(): void
	{
		$this->ticket();
		$this->groupManager->method('get')->willReturn(null);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('group_not_assignable');
		$this->service->assignTeam(5, 'ghosts', 'actor');
	}

	public function testAssignTeamRejectsNonMemberNonAdmin(): void
	{
		$this->ticket();
		$group = $this->createMock(IGroup::class);
		$this->groupManager->method('get')->willReturn($group);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('group_not_assignable');
		$this->service->assignTeam(5, 'team-a', 'actor');
	}

	public function testAssignTeamWithNoAssignableMembersFails(): void
	{
		$this->ticket('alice');
		$member = $this->createMock(IUser::class);
		$member->method('getUID')->willReturn('alice'); // primary → skipped
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$member]);
		$this->groupManager->method('get')->willReturn($group);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->teamMapper->method('hasTeam')->willReturn(false);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('no_assignable_members');
		$this->service->assignTeam(5, 'team-a', 'actor');
	}

	public function testAssignTeamHappyPath(): void
	{
		$this->ticket('alice');
		$member = $this->createMock(IUser::class);
		$member->method('getUID')->willReturn('bob');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$member]);
		$this->groupManager->method('get')->willReturn($group);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->teamMapper->method('hasTeam')->willReturn(false);
		$this->assigneeMapper->method('isAssignee')->willReturn(false);
		$this->assigneeMapper->method('countByTicketId')->willReturn(0);
		$this->assignable('bob');
		$this->teamMapper->expects($this->once())->method('insert')
			->with($this->callback(static fn (TicketTeam $t): bool =>
				$t->getTicketId() === 5 && $t->getGroupId() === 'team-a'));
		$this->activity->expects($this->once())->method('logTeamAssigned');
		$result = $this->service->assignTeam(5, 'team-a', 'actor');
		$this->assertSame(1, $result['added']);
	}

	public function testClaimRejectsNonMember(): void
	{
		$this->ticket();
		$team = new TicketTeam();
		$team->setGroupId('team-a');
		$this->teamMapper->method('findByTicketId')->willReturn([$team]);
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('not_assignable');
		$this->service->claimTeamTicket(5, 'actor');
	}

	public function testClaimMakesActorPrimaryAndClearsTeamRows(): void
	{
		$ticket = $this->ticket(null);
		$ticket->setStatus(Ticket::STATUS_NEW);
		$team = new TicketTeam();
		$team->setGroupId('team-a');
		$this->teamMapper->method('findByTicketId')->willReturn([$team]);
		$this->groupManager->method('isInGroup')->with('actor', 'team-a')->willReturn(true);
		$this->teamMapper->expects($this->once())->method('delete')->with($team);
		$this->assigneeMapper->expects($this->once())
			->method('deleteByTicketAndUser')->with(5, 'actor');
		$this->ticketMapper->expects($this->once())->method('update')
			->with($this->callback(static fn (Ticket $t): bool =>
				$t->getAssignedTo() === 'actor' && $t->getStatus() === Ticket::STATUS_IN_PROGRESS));
		$this->activity->expects($this->once())->method('logTeamClaimed');
		$this->service->claimTeamTicket(5, 'actor');
	}

	public function testMergedTicketIsRejected(): void
	{
		$ticket = new Ticket();
		$ticket->setId(5);
		$ticket->setMergedIntoId(9);
		$this->ticketMapper->method('find')->willReturn($ticket);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('ticket_merged');
		$this->service->addCoAssignee(5, 'bob', 'actor');
	}

	public function testTransferOnMergeDelegatesToMappers(): void
	{
		$target = new Ticket();
		$target->setId(9);
		$target->setAssignedTo('alice');
		$source = new Ticket();
		$source->setId(3);
		$this->ticketMapper->method('find')->willReturnMap([[9, $target], [3, $source]]);
		$this->assigneeMapper->expects($this->once())->method('moveToTicket')
			->with(3, 9, 'alice')->willReturn(['moved' => 2, 'dropped' => 1]);
		$this->teamMapper->expects($this->once())->method('moveToTicket')
			->with(3, 9)->willReturn(['moved' => 1, 'dropped' => 0]);
		$result = $this->service->transferOnMerge(3, 9);
		$this->assertSame(3, $result['moved']);
		$this->assertSame(1, $result['dropped']);
	}
}

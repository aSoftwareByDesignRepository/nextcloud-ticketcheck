<?php

declare(strict_types=1);

/**
 * TicketWatcherService::addWatcher — check-then-act race pin.
 *
 * isWatching() → insert() can lose the (ticket_id,user_id) unique-index race
 * to a concurrent add: the loser must surface the same
 * 'User is already watching this ticket' the pre-check throws, never the raw
 * DB exception (idem_check_then_act_race / unique_index_write_no_conflict_path).
 */

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Db\TicketWatcherMapper;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\TicketWatcherService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCP\DB\Exception as DbException;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TicketWatcherServiceConflictTest extends TestCase
{
	private function service(TicketWatcherMapper $watcherMapper, TicketMapper $ticketMapper, PermissionService $permission, IUserManager $userManager): TicketWatcherService
	{
		$lock = $this->createMock(TicketWorkflowLock::class);
		$lock->method('withTicketLocks')->willReturnCallback(
			static fn (array $ids, callable $cb) => $cb()
		);
		return new TicketWatcherService($watcherMapper, $ticketMapper, $permission, $userManager, $lock, new NullLogger());
	}

	private function happyPathDeps(): array
	{
		$ticket = new Ticket();
		$ticket->setId(5);
		$ticket->setProjectId(1);

		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->method('find')->with(5)->willReturn($ticket);

		$permission = $this->createMock(PermissionService::class);
		$permission->method('canEditTicket')->willReturn(true);
		$permission->method('isUserInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $group === PermissionService::GROUP_HELPDESK_AGENTS
		);
		$permission->method('isProjectMember')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('bob')->willReturn($user);

		return [$ticketMapper, $permission, $userManager];
	}

	public function testUniqueViolationMapsToAlreadyWatching(): void
	{
		[$ticketMapper, $permission, $userManager] = $this->happyPathDeps();

		$ex = $this->createMock(DbException::class);
		$ex->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);

		$watcherMapper = $this->createMock(TicketWatcherMapper::class);
		$watcherMapper->method('isWatching')->willReturn(false);
		$watcherMapper->method('insert')->willThrowException($ex);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('User is already watching this ticket');
		$this->service($watcherMapper, $ticketMapper, $permission, $userManager)->addWatcher(5, 'bob');
	}

	public function testNonUniqueDbExceptionPropagatesUnmasked(): void
	{
		[$ticketMapper, $permission, $userManager] = $this->happyPathDeps();

		$ex = $this->createMock(DbException::class);
		$ex->method('getReason')->willReturn(DbException::REASON_SERVER);

		$watcherMapper = $this->createMock(TicketWatcherMapper::class);
		$watcherMapper->method('isWatching')->willReturn(false);
		$watcherMapper->method('insert')->willThrowException($ex);

		$this->expectException(DbException::class);
		$this->service($watcherMapper, $ticketMapper, $permission, $userManager)->addWatcher(5, 'bob');
	}
}

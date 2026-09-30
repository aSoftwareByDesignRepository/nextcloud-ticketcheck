<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectMemberService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProjectMemberAvailableUsersScopeTest extends TestCase
{
	public function testGetAvailableUsersNeverBlankSearchesAllUsers(): void
	{
		$userManager = $this->createMock(IUserManager::class);
		$userManager->expects(self::never())->method('search');

		$agent = $this->createMock(IUser::class);
		$agent->method('getUID')->willReturn('agent1');
		$agent->method('getDisplayName')->willReturn('Agent One');
		$agent->method('getEMailAddress')->willReturn('agent@example.com');
		$agent->method('isEnabled')->willReturn(true);

		$agents = $this->createMock(IGroup::class);
		$agents->method('getUsers')->willReturn([$agent]);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(
			static function (string $gid) use ($agents): ?IGroup {
				return $gid === 'helpdesk_agents' ? $agents : null;
			}
		);
		$groupManager->method('isInGroup')->willReturn(false);

		$lock = $this->createMock(TicketWorkflowLock::class);
		$lock->method('withExclusiveKey')->willReturnCallback(
			static fn (string $key, callable $cb) => $cb()
		);

		$service = new ProjectMemberService(
			$this->createMock(IDBConnection::class),
			$userManager,
			$this->createMock(IUserSession::class),
			$groupManager,
			$this->createMock(PermissionService::class),
			$lock,
			$this->createMock(LoggerInterface::class),
		);

		$users = $service->getAvailableUsers(null);
		self::assertCount(1, $users);
		self::assertSame('agent1', $users[0]['user_id']);
		self::assertContains('helpdesk_agents', $users[0]['groups']);
	}
}

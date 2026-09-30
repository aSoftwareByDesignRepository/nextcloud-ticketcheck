<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\UserValidationService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserValidationServiceAssignableTest extends TestCase
{
	private IUserManager $userManager;
	private IGroupManager $groupManager;
	private PermissionService $permissionService;
	private UserValidationService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->service = new UserValidationService(
			$this->userManager,
			$this->groupManager,
			$this->createMock(IConfig::class),
			$this->permissionService,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testRejectsArbitraryNextcloudUserWithoutHelpdeskRole(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$this->userManager->method('get')->with('random.user')->willReturn($user);
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->permissionService->method('isUserInGroup')->willReturn(false);
		$this->permissionService->method('isProjectMember')->willReturn(false);

		$result = $this->service->validateUserForAssignment('random.user', 42);

		self::assertFalse($result['valid']);
		self::assertStringContainsString('assignable', (string) $result['reason']);
	}

	public function testAcceptsHelpdeskAgent(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$this->userManager->method('get')->with('agent1')->willReturn($user);
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->permissionService->method('isUserInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $uid === 'agent1'
				&& $group === PermissionService::GROUP_HELPDESK_AGENTS
		);

		$result = $this->service->validateUserForAssignment('agent1', 42);

		self::assertTrue($result['valid']);
	}

	public function testAcceptsProjectMemberWhenNotInHelpdeskGroups(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$this->userManager->method('get')->with('pm1')->willReturn($user);
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->permissionService->method('isUserInGroup')->willReturn(false);
		$this->permissionService->method('isProjectMember')->with(7, 'pm1')->willReturn(true);

		$result = $this->service->validateUserForAssignment('pm1', 7);

		self::assertTrue($result['valid']);
	}

	public function testRejectsProjectMemberForDifferentProject(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$this->userManager->method('get')->with('pm1')->willReturn($user);
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->permissionService->method('isUserInGroup')->willReturn(false);
		$this->permissionService->method('isProjectMember')->willReturn(false);

		$result = $this->service->validateUserForAssignment('pm1', 99);

		self::assertFalse($result['valid']);
	}
}

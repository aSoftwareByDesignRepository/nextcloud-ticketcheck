<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\CompanionGateService;
use OCA\Ticketcheck\Service\CompanionPushService;
use OCA\Ticketcheck\Service\LicenseService;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class CompanionGateServiceTest extends TestCase
{
	public function testGuestDeniedCompanionAccess(): void
	{
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('canViewHelpdeskOverview')->with('guest-1')->willReturn(false);

		$gate = new CompanionGateService(
			$permissions,
			$this->createMock(LicenseService::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IConfig::class),
			$this->createMock(IAppManager::class),
		);

		self::assertFalse($gate->canAccessCompanion('guest-1'));
		self::assertSame('denied', $gate->resolveRole('guest-1'));
	}

	public function testRequesterGetsDedicatedRoleEnum(): void
	{
		// NH-06: requester-scope uids resolve to 'requester' — the client maps
		// it to the denied wall with tailored copy + portal deep link. No
		// companion seat is implied either way (NN-11).
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('canViewHelpdeskOverview')->with('teacher-1')->willReturn(false);
		$permissions->method('isRequesterGroupMember')->with('teacher-1')->willReturn(true);

		$user = $this->createMock(\OCP\IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('teacher-1')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(false);

		$gate = new CompanionGateService(
			$permissions,
			$this->createMock(LicenseService::class),
			$userManager,
			$groupManager,
			$this->createMock(IConfig::class),
			$this->createMock(IAppManager::class),
		);

		self::assertFalse($gate->canAccessCompanion('teacher-1'));
		self::assertSame('requester', $gate->resolveRole('teacher-1'));
	}

	public function testGuestContainedRequesterStaysDenied(): void
	{
		// D-R04: guest containment wins — a customers+requester uid keeps the
		// plain denied wall, never the requester variant.
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('canViewHelpdeskOverview')->willReturn(false);
		$permissions->method('isRequesterGroupMember')->willReturn(true);

		$user = $this->createMock(\OCP\IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => $gid === PermissionService::GROUP_HELPDESK_CUSTOMERS);

		$gate = new CompanionGateService(
			$permissions,
			$this->createMock(LicenseService::class),
			$userManager,
			$groupManager,
			$this->createMock(IConfig::class),
			$this->createMock(IAppManager::class),
		);

		self::assertSame('denied', $gate->resolveRole('dual-1'));
	}

	public function testRequesterBootstrapNeverReportsStaffFeaturesOrSeat(): void
	{
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('isRequesterGroupMember')->willReturn(true);
		$permissions->method('canUserAccessAppByUid')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$license = $this->createMock(LicenseService::class);
		$license->method('gateState')->willReturn(['seatAssigned' => true, 'seatWithinLimit' => true, 'licenseValid' => true]);
		$license->method('status')->willReturn([]);
		$push = $this->createMock(CompanionPushService::class);
		$push->method('isFeatureEnabled')->willReturn(true);
		$gate = new CompanionGateService($permissions, $license, $users,
			$this->createMock(IGroupManager::class), $this->createMock(IConfig::class), null, $push);

		$payload = $gate->bootstrapPayload('teacher', 'Teacher', '2.6.0');

		self::assertSame('requester', $payload['user']['role']);
		self::assertFalse($payload['licensing']['seat']['assigned']);
		self::assertFalse($payload['capabilities']['pushAvailable']);
		self::assertSame(['requesterPortal'], $payload['capabilities']['features']);
		self::assertTrue($gate->canAccessRequesterCompanion('teacher'));
		self::assertFalse($gate->canAccessCompanion('teacher'));
	}

	public function testAgentAllowedRole(): void
	{
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('canViewHelpdeskOverview')->with('agent-1')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('agent-1')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with('agent-1')->willReturn(false);
		$groupManager->method('isInGroup')
			->willReturnCallback(static fn (string $uid, string $gid): bool => $uid === 'agent-1' && $gid === PermissionService::GROUP_HELPDESK_AGENTS);

		$gate = new CompanionGateService(
			$permissions,
			$this->createMock(LicenseService::class),
			$userManager,
			$groupManager,
			$this->createMock(IConfig::class),
			$this->createMock(IAppManager::class),
		);

		self::assertTrue($gate->canAccessCompanion('agent-1'));
		self::assertSame('agent', $gate->resolveRole('agent-1'));
	}

	public function testBootstrapAdvertisesFullFeatureSurface(): void
	{
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('canViewHelpdeskOverview')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$license = $this->createMock(LicenseService::class);
		$license->method('gateState')->willReturn([
			'seatAssigned' => true,
			'seatWithinLimit' => true,
			'licenseValid' => true,
		]);
		$license->method('status')->willReturn(['state' => ['validUntil' => '2027-01-01']]);
		$license->method('buildEnvelope')->willReturn(null);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn((string)(10 * 1024 * 1024));

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->with('notifications')->willReturn(true);

		$gate = new CompanionGateService(
			$permissions,
			$license,
			$userManager,
			$this->createMock(IGroupManager::class),
			$config,
			$appManager,
		);

		$payload = $gate->bootstrapPayload('agent-1', 'Agent One', '2.3.0');

		self::assertSame(CompanionGateService::COMPANION_MIN, $payload['capabilities']['companionMin']);
		foreach (['inbox', 'comments', 'status', 'assign', 'attachments', 'filters', 'sla', 'watchers', 'create', 'bulk', 'offlineRead'] as $feature) {
			self::assertContains($feature, $payload['capabilities']['features'], "feature $feature missing");
		}
		self::assertNotContains('push', $payload['capabilities']['features']);
		self::assertFalse($payload['capabilities']['pushAvailable']);
		self::assertTrue($payload['licensing']['seat']['assigned']);
		self::assertSame('2027-01-01', $payload['licensing']['seat']['validUntil']);
	}

	public function testBootstrapAdvertisesPushOnlyWhenDeliveryEnabled(): void
	{
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('canViewHelpdeskOverview')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$license = $this->createMock(LicenseService::class);
		$license->method('gateState')->willReturn([
			'seatAssigned' => true,
			'seatWithinLimit' => true,
			'licenseValid' => true,
		]);
		$license->method('status')->willReturn(['state' => []]);
		$license->method('buildEnvelope')->willReturn(null);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn((string)(10 * 1024 * 1024));

		$push = $this->createMock(CompanionPushService::class);
		$push->method('isFeatureEnabled')->willReturn(true);

		$gate = new CompanionGateService(
			$permissions,
			$license,
			$userManager,
			$this->createMock(IGroupManager::class),
			$config,
			$this->createMock(IAppManager::class),
			$push,
		);

		$payload = $gate->bootstrapPayload('agent-1', 'Agent One', '2.6.0');

		self::assertContains('push', $payload['capabilities']['features']);
		self::assertTrue($payload['capabilities']['pushAvailable']);
	}
}

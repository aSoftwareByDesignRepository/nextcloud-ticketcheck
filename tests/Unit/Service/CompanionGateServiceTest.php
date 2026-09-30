<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\CompanionGateService;
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
}

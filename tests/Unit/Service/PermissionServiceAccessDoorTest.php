<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class PermissionServiceAccessDoorTest extends TestCase
{
	public function testDedicatedAppAdminPassesDoorWithoutHelpdeskGroup(): void
	{
		$svc = $this->service(
			ncAdmin: false,
			groups: [],
			config: [
				'app_admin_user_ids' => json_encode(['delegated'], JSON_THROW_ON_ERROR),
				'access_restriction_enabled' => '1',
				'access_allowed_user_ids' => '[]',
				'app_access_extra_groups' => '',
				'app_access_helpdesk_admins' => 'yes',
				'app_access_helpdesk_agents' => 'yes',
				'app_access_helpdesk_customers' => 'yes',
			],
		);
		self::assertTrue($svc->isAppAdmin('delegated'));
		self::assertTrue($svc->canUserAccessAppByUid('delegated'));
	}

	public function testRestrictedEmptyAllowlistBlocksAgentButNotGuestWhenCustomersOn(): void
	{
		$svc = $this->service(
			ncAdmin: false,
			groups: ['helpdesk_agents'],
			config: [
				'app_admin_user_ids' => '[]',
				'access_restriction_enabled' => '1',
				'access_allowed_user_ids' => '[]',
				'app_access_extra_groups' => '',
				'app_access_helpdesk_admins' => 'yes',
				'app_access_helpdesk_agents' => 'yes',
				'app_access_helpdesk_customers' => 'yes',
			],
		);
		self::assertFalse($svc->canUserAccessAppByUid('agent1'));
		self::assertFalse($svc->hasEnabledHelpdeskScope('agent1') && $svc->canUserAccessAppByUid('agent1'));

		$guestSvc = $this->service(
			ncAdmin: false,
			groups: ['helpdesk_customers'],
			config: [
				'app_admin_user_ids' => '[]',
				'access_restriction_enabled' => '1',
				'access_allowed_user_ids' => '[]',
				'app_access_extra_groups' => '',
				'app_access_helpdesk_admins' => 'yes',
				'app_access_helpdesk_agents' => 'yes',
				'app_access_helpdesk_customers' => 'yes',
			],
		);
		self::assertTrue($guestSvc->canUserAccessAppByUid('guest1'));
	}

	public function testOpenModeAllowsRoleLessUserThroughDoorButNeedsEnrollment(): void
	{
		$svc = $this->service(
			ncAdmin: false,
			groups: [],
			config: [
				'app_admin_user_ids' => '[]',
				'access_restriction_enabled' => '0',
				'access_allowed_user_ids' => '[]',
				'app_access_extra_groups' => '',
				'app_access_helpdesk_admins' => 'yes',
				'app_access_helpdesk_agents' => 'yes',
				'app_access_helpdesk_customers' => 'yes',
			],
		);
		self::assertTrue($svc->canUserAccessAppByUid('nobody'));
		self::assertFalse($svc->hasEnabledHelpdeskScope('nobody'));
		self::assertTrue($svc->needsRoleEnrollment('nobody'));
	}

	public function testRestrictedAllowlistedUserWithAgentRolePasses(): void
	{
		$svc = $this->service(
			ncAdmin: false,
			groups: ['helpdesk_agents'],
			config: [
				'app_admin_user_ids' => '[]',
				'access_restriction_enabled' => '1',
				'access_allowed_user_ids' => json_encode(['agent1'], JSON_THROW_ON_ERROR),
				'app_access_extra_groups' => '',
				'app_access_helpdesk_admins' => 'yes',
				'app_access_helpdesk_agents' => 'yes',
				'app_access_helpdesk_customers' => 'yes',
			],
		);
		self::assertTrue($svc->canUserAccessAppByUid('agent1'));
	}

	/**
	 * @param list<string> $groups
	 * @param array<string, string> $config
	 */
	private function service(bool $ncAdmin, array $groups, array $config): PermissionService
	{
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $ncAdmin);
		$groupManager->method('isInGroup')->willReturnCallback(
			static function (string $uid, string $gid) use ($groups): bool {
				return in_array($gid, $groups, true);
			}
		);

		$cfg = $this->createMock(IConfig::class);
		$cfg->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return $config[$key] ?? $default;
			}
		);

		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetchOne')->willReturn(0);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$func = $this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class));
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('func')->willReturn($func);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new PermissionService(
			$session,
			$groupManager,
			$cfg,
			$this->createMock(GuestProjectAccessMapper::class),
			$db,
		);
	}
}

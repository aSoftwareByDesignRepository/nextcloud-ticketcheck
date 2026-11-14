<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Internal-requester scope (spec D-R01…D-R22): truth table, precedence,
 * restricted e-mail leg (D-R15), door admission (D-R08/D-R19), scope toggle
 * (D-R20), fail-closed group evaluation (D-R18), config parsing (§10).
 */
final class PermissionServiceRequesterScopeTest extends TestCase
{
	private const CFG_BASE = [
		'app_access_helpdesk_admins' => 'yes',
		'app_access_helpdesk_agents' => 'yes',
		'app_access_helpdesk_customers' => 'yes',
		'app_access_helpdesk_requesters' => 'yes',
		'app_access_requester_groups' => '[]',
		'app_access_extra_groups' => '',
		'access_restriction_enabled' => '0',
		'access_allowed_user_ids' => '[]',
		'app_admin_user_ids' => '[]',
	];

	// ── isRequester() truth table (§11) ─────────────────────────────────────

	public function testRequesterDedicatedGroupMemberIsRequester(): void
	{
		$svc = $this->service(user: $this->user('req1'), groupsOf: ['req1' => ['helpdesk_requesters']]);
		self::assertTrue($svc->isRequester());
	}

	public function testRequesterConfiguredGroupMemberIsRequester(): void
	{
		$svc = $this->service(
			user: $this->user('req1'),
			groupsOf: ['req1' => ['teachers']],
			config: ['app_access_requester_groups' => '["teachers","staff"]'],
		);
		self::assertTrue($svc->isRequester());
	}

	public function testGuestContainmentWinsOverRequesterGroup(): void
	{
		// E-04 / D-R04: helpdesk_customers membership beats requester scope.
		$svc = $this->service(
			user: $this->user('dual'),
			groupsOf: ['dual' => ['helpdesk_customers', 'helpdesk_requesters']],
		);
		self::assertFalse($svc->isRequester());
	}

	public function testAgentInRequesterGroupKeepsStaffScope(): void
	{
		$svc = $this->service(
			user: $this->user('dual'),
			groupsOf: ['dual' => ['helpdesk_agents', 'helpdesk_requesters']],
		);
		self::assertFalse($svc->isRequester());
		self::assertTrue($svc->isRequesterGroupMember('dual'));
	}

	public function testHelpdeskAdminInRequesterGroupKeepsStaffScope(): void
	{
		$svc = $this->service(
			user: $this->user('dual'),
			groupsOf: ['dual' => ['helpdesk_admins', 'helpdesk_requesters']],
		);
		self::assertFalse($svc->isRequester());
	}

	public function testNcAdminIsNeverRequester(): void
	{
		$svc = $this->service(
			user: $this->user('root'),
			groupsOf: ['root' => ['helpdesk_requesters']],
			ncAdmins: ['root'],
		);
		self::assertFalse($svc->isRequester());
	}

	public function testDedicatedAppAdminInRequesterGroupKeepsStaffScope(): void
	{
		$svc = $this->service(
			user: $this->user('delegated'),
			groupsOf: ['delegated' => ['helpdesk_requesters']],
			config: ['app_admin_user_ids' => '["delegated"]'],
		);
		self::assertFalse($svc->isRequester());
	}

	public function testProjectMemberInRequesterGroupKeepsStaffScope(): void
	{
		$svc = $this->service(
			user: $this->user('pm'),
			groupsOf: ['pm' => ['helpdesk_requesters']],
			projectMemberCount: 1,
		);
		self::assertFalse($svc->isRequester());
	}

	public function testNoScopeUserIsNotRequester(): void
	{
		$svc = $this->service(user: $this->user('nobody'));
		self::assertFalse($svc->isRequester());
	}

	public function testMasterToggleOffDisablesRequesterScope(): void
	{
		$svc = $this->service(
			user: $this->user('req1'),
			groupsOf: ['req1' => ['helpdesk_requesters']],
			config: ['app_access_helpdesk_requesters' => 'no'],
		);
		self::assertFalse($svc->isRequester());
		self::assertFalse($svc->isRequesterGroupMember('req1'));
	}

	public function testIdentityActorCanNeverBeRequester(): void
	{
		$svc = $this->service(user: null);
		self::assertFalse($svc->isRequesterGroupMember('portal:5'));
	}

	public function testAnonymousIsNotRequester(): void
	{
		$svc = $this->service(user: null);
		self::assertFalse($svc->isRequester());
	}

	// ── Fail-closed group evaluation (D-R18/E-17) ───────────────────────────

	public function testGroupBackendExceptionYieldsNoScopeNeverThrows(): void
	{
		$svc = $this->service(user: $this->user('req1'), groupBackendThrows: true);
		self::assertFalse($svc->isRequesterGroupMember('req1'));
		self::assertFalse($svc->isRequester());
	}

	// ── Scope + door (D-R08/D-R19/D-R20) ────────────────────────────────────

	public function testRequesterScopeSatisfiesEnabledHelpdeskScope(): void
	{
		$svc = $this->service(user: null, groupsOf: ['req1' => ['helpdesk_requesters']]);
		self::assertTrue($svc->hasEnabledHelpdeskScope('req1'));
		self::assertFalse($svc->needsRoleEnrollment('req1'));
	}

	public function testToggleOffLeavesRequesterNeedingEnrollment(): void
	{
		$svc = $this->service(
			user: null,
			groupsOf: ['req1' => ['helpdesk_requesters']],
			config: ['app_access_helpdesk_requesters' => 'no'],
		);
		self::assertFalse($svc->hasEnabledHelpdeskScope('req1'));
		self::assertTrue($svc->needsRoleEnrollment('req1'));
	}

	public function testRestrictedDoorAdmitsRequesterGroupMember(): void
	{
		// D-R08/D-R19: requester membership implies door admission, no
		// second allowlist entry — even with the customers toggle off (E-12).
		$svc = $this->service(
			user: null,
			groupsOf: ['req1' => ['teachers']],
			config: [
				'access_restriction_enabled' => '1',
				'app_access_requester_groups' => '["teachers"]',
				'app_access_helpdesk_customers' => 'no',
			],
		);
		self::assertTrue($svc->canUserAccessAppByUid('req1'));
	}

	public function testRestrictedDoorDeniesNonMember(): void
	{
		$svc = $this->service(
			user: null,
			groupsOf: ['req1' => ['teachers']],
			config: [
				'access_restriction_enabled' => '1',
				'app_access_requester_groups' => '["teachers"]',
			],
		);
		self::assertFalse($svc->canUserAccessAppByUid('outsider'));
	}

	// ── canPortalViewTicket requester rows (D-R05/D-R15) ──────────────────
// Portal-scope predicate: portal paths use canPortalViewTicket; the staff
// route (canViewTicketWithProjectAccess) must stay denied for requesters
// (US-R05 AC-1/NN-02).

	public function testRequesterSeesOwnTicket(): void
	{
		$svc = $this->service(user: $this->user('req1', 'req@x.io'), groupsOf: ['req1' => ['helpdesk_requesters']]);
		self::assertTrue($svc->canPortalViewTicket($this->ticket('req1', 'other@x.io')));
	}

	public function testRequesterSeesStaffFiledTicketByEmail(): void
	{
		$svc = $this->service(
			user: $this->user('req1', 'shared@x.io'),
			groupsOf: ['req1' => ['helpdesk_requesters'], 'agent1' => ['helpdesk_agents']],
		);
		self::assertTrue($svc->canPortalViewTicket($this->ticket('agent1', 'shared@x.io')));
	}

	public function testRequesterNeverSeesPeerAuthoredTicketViaSharedEmail(): void
	{
		// D-R15 / TH-R02: two requesters sharing an e-mail must not read each
		// other's tickets — the e-mail leg is restricted to non-requester
		// authors.
		$svc = $this->service(
			user: $this->user('reqA', 'shared@x.io'),
			groupsOf: ['reqA' => ['helpdesk_requesters'], 'reqB' => ['helpdesk_requesters']],
		);
		self::assertFalse($svc->canPortalViewTicket($this->ticket('reqB', 'shared@x.io')));
	}

	public function testRequesterSeesInboundEmailTicketByEmail(): void
	{
		$svc = $this->service(user: $this->user('req1', 'req@x.io'), groupsOf: ['req1' => ['helpdesk_requesters']]);
		self::assertTrue($svc->canPortalViewTicket($this->ticket('inbound-email', 'req@x.io')));
	}

	public function testRequesterSeesPortalAuthoredTicketByEmail(): void
	{
		$svc = $this->service(user: $this->user('req1', 'req@x.io'), groupsOf: ['req1' => ['helpdesk_requesters']]);
		self::assertTrue($svc->canPortalViewTicket($this->ticket('portal:7', 'req@x.io')));
	}

	public function testRequesterCannotSeeForeignTicket(): void
	{
		$svc = $this->service(user: $this->user('req1', 'req@x.io'), groupsOf: ['req1' => ['helpdesk_requesters']]);
		self::assertFalse($svc->canPortalViewTicket($this->ticket('req2', 'other@x.io')));
	}

	public function testRequesterCannotSeeProjectTicketWithoutAccess(): void
	{
		$svc = $this->service(
			user: $this->user('req1', 'req@x.io'),
			groupsOf: ['req1' => ['helpdesk_requesters'], 'agent1' => ['helpdesk_agents']],
		);
		self::assertFalse($svc->canPortalViewTicket($this->ticket('agent1', 'req@x.io', 7)));
	}

	public function testRequesterSeesOwnTicketAssignedToProjectByStaff(): void
	{
		// E-13: staff triage assigns a project; the requester still sees their
		// own ticket via created_by — but no other project tickets.
		$svc = $this->service(user: $this->user('req1', 'req@x.io'), groupsOf: ['req1' => ['helpdesk_requesters']]);
		$own = $this->ticket('req1', 'req@x.io', 7);
		self::assertTrue($svc->canPortalViewTicket($own));
		// US-R05 AC-1: the staff ticket route stays denied for requesters.
		self::assertFalse($svc->canViewTicketWithProjectAccess($own));
	}

	// ── Dual-role staff portal union (D-R10/D-R22) ────────────────────────
// Dual-role staff keep staff scope (isRequester()=false) but must still
// reach their own customer-side tickets on portal routes — the "My
// requests" deep-link and reply path must not dead-end.

	public function testDualRoleProjectMemberSeesOwnRequesterTicketViaPortal(): void
	{
		$svc = $this->service(
			user: $this->user('pm', 'pm@x.io'),
			groupsOf: ['pm' => ['helpdesk_requesters']],
			projectMemberCount: 1,
		);
		self::assertFalse($svc->isRequester()); // staff precedence
		$own = $this->ticket('pm', 'pm@x.io');
		// Staff visibility alone denies the no-project ticket…
		self::assertFalse($svc->canViewTicketWithProjectAccess($own));
		// …but the portal union keeps their own request reachable.
		self::assertTrue($svc->canPortalViewTicket($own));
	}

	public function testDualRoleAgentKeepsStaffVisibilityOnPortal(): void
	{
		// Union must never subtract: a dual-role agent still sees all tickets
		// via staff scope, even ones requester scoping would deny.
		$svc = $this->service(
			user: $this->user('dual', 'dual@x.io'),
			groupsOf: ['dual' => ['helpdesk_agents', 'helpdesk_requesters']],
		);
		self::assertFalse($svc->isRequester());
		self::assertTrue($svc->canPortalViewTicket($this->ticket('other', 'other@x.io')));
	}

	public function testDualRoleProjectMemberCannotSeeForeignTicketOnPortal(): void
	{
		$svc = $this->service(
			user: $this->user('pm', 'pm@x.io'),
			groupsOf: ['pm' => ['helpdesk_requesters']],
			projectMemberCount: 1,
		);
		self::assertFalse($svc->canPortalViewTicket($this->ticket('other', 'other@x.io')));
	}

	// ── Config parsing (§10/§11) ────────────────────────────────────────────

	public function testMalformedRequesterGroupsJsonEvaluatesEmpty(): void
	{
		$svc = $this->service(
			user: $this->user('req1'),
			groupsOf: ['req1' => ['teachers']],
			config: ['app_access_requester_groups' => '{not json'],
		);
		self::assertSame([], $svc->getAppAccessSettings()['requester_groups']);
		self::assertFalse($svc->isRequester());
	}

	public function testRequesterGroupsListIsCappedAtLimit(): void
	{
		$many = [];
		for ($i = 0; $i < 60; $i++) {
			$many[] = 'group_' . $i;
		}
		$svc = $this->service(
			user: null,
			config: ['app_access_requester_groups' => json_encode($many, JSON_THROW_ON_ERROR)],
		);
		self::assertCount(PermissionService::REQUESTER_GROUPS_LIMIT, $svc->getAppAccessSettings()['requester_groups']);
	}

	// ── Rate-limit parity (US-R02 AC-4) ─────────────────────────────────────

	public function testRequesterGetsGuestRateLimit(): void
	{
		$svc = $this->service(user: $this->user('req1'), groupsOf: ['req1' => ['helpdesk_requesters']]);
		self::assertFalse($svc->checkRateLimit(50));
		self::assertTrue($svc->checkRateLimit(49));
	}

	public function testStaffKeepsUnlimitedRateLimit(): void
	{
		$svc = $this->service(user: $this->user('agent1'), groupsOf: ['agent1' => ['helpdesk_agents']]);
		self::assertTrue($svc->checkRateLimit(5000));
	}

	// ── Helpers ─────────────────────────────────────────────────────────────

	private function user(string $uid, string $email = ''): IUser
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($email !== '' ? $email : null);
		return $user;
	}

	private function ticket(string $createdBy, string $customerEmail, ?int $projectId = null): object
	{
		return new class($createdBy, $customerEmail, $projectId) {
			public function __construct(
				private readonly string $createdBy,
				private readonly string $customerEmail,
				private readonly ?int $projectId,
			) {}
			public function getCreatedBy(): string { return $this->createdBy; }
			public function getCustomerEmail(): string { return $this->customerEmail; }
			public function getProjectId(): ?int { return $this->projectId; }
		};
	}

	/**
	 * @param array<string, list<string>> $groupsOf uid → list of gids
	 * @param array<string, string> $config app-value overrides on CFG_BASE
	 * @param list<string> $ncAdmins
	 */
	private function service(
		?IUser $user,
		array $groupsOf = [],
		array $config = [],
		array $ncAdmins = [],
		int $projectMemberCount = 0,
		bool $groupBackendThrows = false,
	): PermissionService {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(
			static fn (string $uid): bool => in_array($uid, $ncAdmins, true)
		);
		$groupManager->method('isInGroup')->willReturnCallback(
			static function (string $uid, string $gid) use ($groupsOf, $groupBackendThrows): bool {
				if ($groupBackendThrows) {
					throw new \RuntimeException('LDAP down');
				}
				return in_array($gid, $groupsOf[$uid] ?? [], true);
			}
		);

		$merged = $config + self::CFG_BASE;
		$cfg = $this->createMock(IConfig::class);
		$cfg->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($merged): string {
				return $merged[$key] ?? $default;
			}
		);

		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($projectMemberCount);
		$result->method('fetch')->willReturn(false);
		$expr = $this->createMock(IExpressionBuilder::class);
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$qb = $this->createMock(IQueryBuilder::class);
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

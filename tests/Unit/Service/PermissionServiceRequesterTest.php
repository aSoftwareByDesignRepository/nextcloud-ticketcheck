<?php

declare(strict_types=1);

/**
 * Internal-requester scope contract (spec D-R03…D-R20, US-R05).
 *
 * Covers: resolved-scope precedence (guest > staff > requester), the
 * restricted e-mail leg (D-R15 duplicate-address leak), door admission
 * (D-R08/D-R19), scope toggles (D-R20) and fail-closed group evaluation
 * (D-R18/E-17).
 */

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class PermissionServiceRequesterTest extends TestCase
{
	private const BASE_CONFIG = [
		'app_admin_user_ids' => '[]',
		'access_restriction_enabled' => '0',
		'access_allowed_user_ids' => '[]',
		'app_access_extra_groups' => '',
		'app_access_helpdesk_admins' => 'yes',
		'app_access_helpdesk_agents' => 'yes',
		'app_access_helpdesk_customers' => 'yes',
		'app_access_helpdesk_requesters' => 'yes',
		'app_access_requester_groups' => '[]',
	];

	private function ticket(string $createdBy, string $email = 'a@example.org', ?int $projectId = null): Ticket
	{
		// Real entity — Ticket getters are magic (@method on Entity), so they
		// cannot be configured on a PHPUnit mock.
		$t = new Ticket();
		$t->setCreatedBy($createdBy);
		$t->setCustomerEmail($email);
		$t->setProjectId($projectId);
		return $t;
	}

	/**
	 * @param list<string> $groups groups the uid belongs to
	 * @param array<string,string> $config
	 * @param list<string> $throwOnGroups gids whose isInGroup check throws (backend outage)
	 */
	private function service(string $uid, string $email, array $groups, array $config = [], array $throwOnGroups = []): PermissionService
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($email);
		$user->method('getDisplayName')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($uid === '' ? null : $user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(
			static fn (string $u): bool => $u === 'ncadmin');
		$groupManager->method('isInGroup')->willReturnCallback(
			static function (string $u, string $gid) use ($uid, $groups, $throwOnGroups): bool {
				if (in_array($gid, $throwOnGroups, true)) {
					throw new \RuntimeException('LDAP backend down');
				}
				return $u === $uid && in_array($gid, $groups, true);
			});

		$cfg = $this->createMock(IConfig::class);
		$merged = $config + self::BASE_CONFIG;
		$cfg->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $merged[$key] ?? $default);

		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetchOne')->willReturn(0);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$func = $this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class));
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
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

	// ── Resolved scope (D-R04) ────────────────────────────────────────────

	public function testDedicatedGroupMemberResolvesRequester(): void
	{
		$svc = $this->service('teacher1', 't@school.example', ['helpdesk_requesters']);
		self::assertTrue($svc->isRequester());
		self::assertTrue($svc->isRequesterGroupMember('teacher1'));
	}

	public function testConfiguredGroupMemberResolvesRequester(): void
	{
		$svc = $this->service('teacher1', 't@school.example', ['teachers'], [
			'app_access_requester_groups' => '["teachers"]',
		]);
		self::assertTrue($svc->isRequester());
	}

	public function testMasterToggleOffDisablesScope(): void
	{
		$svc = $this->service('teacher1', 't@school.example', ['helpdesk_requesters'], [
			'app_access_helpdesk_requesters' => 'no',
		]);
		self::assertFalse($svc->isRequester());
		self::assertFalse($svc->isRequesterGroupMember('teacher1'));
	}

	public function testGuestContainmentWinsOverRequester(): void
	{
		$svc = $this->service('dual1', 'd@x.example', ['helpdesk_customers', 'helpdesk_requesters']);
		self::assertTrue($svc->isGuest());
		self::assertFalse($svc->isRequester());
	}

	public function testStaffScopeWinsOverRequester(): void
	{
		$svc = $this->service('it1', 'it@x.example', ['helpdesk_agents', 'helpdesk_requesters']);
		self::assertFalse($svc->isRequester());
		self::assertTrue($svc->isRequesterGroupMember('it1'));
	}

	public function testNoMembershipNoScope(): void
	{
		$svc = $this->service('plain1', 'p@x.example', ['unrelated_group']);
		self::assertFalse($svc->isRequester());
		self::assertTrue($svc->needsRoleEnrollment('plain1'));
	}

	public function testRequesterDoesNotNeedEnrollment(): void
	{
		$svc = $this->service('teacher1', 't@x.example', ['helpdesk_requesters']);
		self::assertFalse($svc->needsRoleEnrollment('teacher1'));
	}

	public function testRequesterScopeFailClosedOnBackendError(): void
	{
		// E-17/D-R18: an LDAP hiccup during requester-group evaluation yields
		// no scope — never a grant, never a 500.
		$svc = $this->service('teacher1', 't@x.example', [], [], ['helpdesk_requesters', 'teachers']);
		self::assertFalse($svc->isRequesterGroupMember('teacher1'));
	}

	// ── Door admission (D-R08/D-R19) ──────────────────────────────────────

	public function testRestrictedDoorAdmitsRequesterMember(): void
	{
		$svc = $this->service('teacher1', 't@x.example', ['helpdesk_requesters'], [
			'access_restriction_enabled' => '1',
		]);
		self::assertTrue($svc->canUserAccessAppByUid('teacher1'));
	}

	public function testRestrictedDoorAdmitsConfiguredGroupMember(): void
	{
		$svc = $this->service('teacher1', 't@x.example', ['teachers'], [
			'access_restriction_enabled' => '1',
			'app_access_requester_groups' => '["teachers"]',
		]);
		self::assertTrue($svc->canUserAccessAppByUid('teacher1'));
	}

	public function testRestrictedDoorDeniesNonMember(): void
	{
		$svc = $this->service('plain1', 'p@x.example', [], [
			'access_restriction_enabled' => '1',
		]);
		self::assertFalse($svc->canUserAccessAppByUid('plain1'));
	}

	// ── Ticket visibility (D-R05/D-R15) ───────────────────────────────────

	public function testRequesterSeesOwnTicket(): void
	{
		$svc = $this->service('a@x.example', 'a@x.example', ['helpdesk_requesters']);
		self::assertTrue($svc->isRequester());
		self::assertTrue($svc->canPortalViewTicket($this->ticket('a@x.example', 'a@x.example')));
	}

	public function testRequesterSeesOwnTicketInProject(): void
	{
		$svc = $this->service('a@x.example', 'a@x.example', ['helpdesk_requesters']);
		self::assertTrue($svc->canPortalViewTicket($this->ticket('a@x.example', 'a@x.example', 7)));
	}

	public function testPeerTicketDeniedDespiteSharedEmail(): void
	{
		// TH-R02/D-R15: two requesters sharing one address — A's own ticket is
		// invisible to B even via the e-mail leg.
		$svc = $this->service('b@x.example', 'shared@x.example', ['helpdesk_requesters']);
		self::assertFalse($svc->canPortalViewTicket($this->ticket('a@x.example', 'shared@x.example')));
	}

	public function testPeerTicketDeniedOnEmailMatchWhenPeersEmailMatches(): void
	{
		$svc = $this->service('b@x.example', 'shared@x.example', ['helpdesk_requesters']);
		// Peer authored a ticket addressed to the shared mailbox → denied.
		self::assertFalse($svc->isRequesterScopedTicket($this->ticket('a@x.example', 'shared@x.example'), 'b@x.example'));
	}

	public function testStaffFiledTicketReachesRequesterViaEmail(): void
	{
		// created_by is a *staff* uid — the e-mail leg must allow it (D-R15):
		// staff-filed/inbound tickets addressed to the requester stay visible.
		$staffAware = new PermissionService(
			$this->sessionFor('b@x.example', 'shared@x.example'),
			$this->groupsFor(['b@x.example' => ['helpdesk_requesters'], 'agent1' => ['helpdesk_agents']]),
			$this->configFor([]),
			$this->createMock(GuestProjectAccessMapper::class),
			$this->dbStub(),
		);
		self::assertTrue($staffAware->isRequester());
		self::assertTrue($staffAware->canPortalViewTicket($this->ticket('agent1', 'shared@x.example')));
	}

	public function testInboundEmailTicketReachesRequesterViaEmail(): void
	{
		$svc = $this->service('b@x.example', 'shared@x.example', ['helpdesk_requesters']);
		self::assertTrue($svc->canPortalViewTicket($this->ticket('inbound-email', 'shared@x.example')));
	}

	public function testPortalAuthoredTicketReachesRequesterViaEmail(): void
	{
		$svc = $this->service('b@x.example', 'shared@x.example', ['helpdesk_requesters']);
		self::assertTrue($svc->canPortalViewTicket($this->ticket('portal:42', 'shared@x.example')));
	}

	public function testDifferentEmailDenied(): void
	{
		$svc = $this->service('b@x.example', 'b@x.example', ['helpdesk_requesters']);
		self::assertFalse($svc->canPortalViewTicket($this->ticket('agent1', 'other@x.example')));
	}

	public function testStaffRouteDeniesRequesterOwnTicket(): void
	{
		// US-R05 AC-1 / NN-02: the shared staff-route predicate stays closed for
		// requesters — even their own ticket must not render the staff UI.
		// Portal routes (canPortalViewTicket) carry the requester grant.
		$svc = $this->service('a@x.example', 'a@x.example', ['helpdesk_requesters']);
		$own = $this->ticket('a@x.example', 'a@x.example');
		self::assertTrue($svc->canPortalViewTicket($own));
		self::assertFalse($svc->canViewTicketWithProjectAccess($own));
	}

	// ── helpers for multi-uid group fixtures ──────────────────────────────

	private function sessionFor(string $uid, string $email): IUserSession
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($email);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}

	/** @param array<string,list<string>> $byUid */
	private function groupsFor(array $byUid): IGroupManager
	{
		$gm = $this->createMock(IGroupManager::class);
		$gm->method('isAdmin')->willReturn(false);
		$gm->method('isInGroup')->willReturnCallback(
			static fn (string $u, string $gid): bool => in_array($gid, $byUid[$u] ?? [], true));
		return $gm;
	}

	/** @param array<string,string> $config */
	private function configFor(array $config): IConfig
	{
		$merged = $config + self::BASE_CONFIG;
		$cfg = $this->createMock(IConfig::class);
		$cfg->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $merged[$key] ?? $default);
		return $cfg;
	}

	private function dbStub(): IDBConnection
	{
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetchOne')->willReturn(0);
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('func')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class));
		$qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	// ── NH-03 all-users toggle ────────────────────────────────────────────

	public function testAllUsersToggleGrantsRequesterScopeToAnyUid(): void
	{
		$svc = $this->service('anyone', 'a@x.example', [], [
			'app_access_requester_all_users' => 'yes',
		]);
		self::assertTrue($svc->isRequester());
		self::assertTrue($svc->isRequesterGroupMember('anyone'));
	}

	public function testAllUsersToggleOffLeavesUnscopedUserOut(): void
	{
		$svc = $this->service('anyone', 'a@x.example', [], [
			'app_access_requester_all_users' => 'no',
		]);
		self::assertFalse($svc->isRequester());
	}

	public function testAllUsersToggleMasterToggleStillWins(): void
	{
		$svc = $this->service('anyone', 'a@x.example', [], [
			'app_access_requester_all_users' => 'yes',
			'app_access_helpdesk_requesters' => 'no',
		]);
		self::assertFalse($svc->isRequester());
	}

	public function testAllUsersToggleDoesNotOverrideGuestContainment(): void
	{
		$svc = $this->service('dual', 'd@x.example', ['helpdesk_customers'], [
			'app_access_requester_all_users' => 'yes',
		]);
		self::assertTrue($svc->isGuest());
		self::assertFalse($svc->isRequester());
	}

	// ── NC-managed portal session (nav variant, B6) ───────────────────────

	public function testNcManagedPortalUserCoversRequesterAndDualRole(): void
	{
		$requester = $this->service('teacher', 't@x.example', ['helpdesk_requesters']);
		self::assertTrue($requester->isNcManagedPortalUser());

		$dualRole = $this->service('agentreq', 'a@x.example', ['helpdesk_agents', 'helpdesk_requesters']);
		self::assertFalse($dualRole->isRequester()); // staff precedence
		self::assertTrue($dualRole->isNcManagedPortalUser());
	}

	public function testNcManagedPortalUserExcludesContainedGuest(): void
	{
		$guest = $this->service('guest1', 'g@x.example', ['helpdesk_customers']);
		self::assertFalse($guest->isNcManagedPortalUser());
	}

	// ── NH-05 requester project access ────────────────────────────────────

	public function testRequesterProjectAccessDefaultOffDeniesGrantable(): void
	{
		$svc = $this->service('teacher', 't@x.example', ['helpdesk_requesters']);
		self::assertFalse($svc->isRequesterProjectAccessGrantable('teacher'));
	}

	public function testRequesterProjectAccessGrantableWhenToggled(): void
	{
		$svc = $this->service('teacher', 't@x.example', ['helpdesk_requesters'], [
			'app_access_requester_project_access' => 'yes',
		]);
		self::assertTrue($svc->isRequesterProjectAccessGrantable('teacher'));
		// Guests and staff are never "requester-grantable".
		self::assertFalse($svc->isRequesterProjectAccessGrantable('agent-x'));
	}

	// ── Requester notification recipients (MH-10) ─────────────────────────

	public function testRequesterRecipientUidsResolvesAuthorAndEmailMatch(): void
	{
		$teacher = $this->createMock(IUser::class);
		$teacher->method('getUID')->willReturn('teacher1');
		$teacher->method('getEMailAddress')->willReturn('t@x.example');
		$peer = $this->createMock(IUser::class);
		$peer->method('getUID')->willReturn('peer1');
		$peer->method('getEMailAddress')->willReturn('shared@x.example');

		$userManager = $this->createMock(\OCP\IUserManager::class);
		$userManager->method('getByEmail')->willReturnCallback(
			static fn (string $email): array => $email === 't@x.example' ? [$teacher] : []);
		$userManager->method('get')->willReturnCallback(
			static fn (string $uid) => ['teacher1' => $teacher, 'peer1' => $peer][$uid] ?? null);

		$svc = new PermissionService(
			$this->sessionFor('agent1', 'agent@x.example'),
			$this->groupsFor([
				'teacher1' => ['helpdesk_requesters'],
				'peer1' => ['helpdesk_requesters'],
				'agent1' => ['helpdesk_agents'],
			]),
			$this->configFor([]),
			$this->createMock(GuestProjectAccessMapper::class),
			$this->dbStub(),
			null,
			null,
			$userManager,
		);

		// Own ticket → author is the recipient.
		self::assertSame(['teacher1'], $svc->requesterRecipientUids($this->ticket('teacher1', 't@x.example')));
		// Peer-authored ticket → author only, never the e-mail-matched peer (D-R15).
		self::assertSame(['peer1'], $svc->requesterRecipientUids(
			$this->ticket('peer1', 'other@x.example')));
		// Non-requester author → no requester recipients.
		self::assertSame([], $svc->requesterRecipientUids($this->ticket('agent1', 'a@x.example')));
	}

	public function testRequesterRecipientUidsSkipsContainedGuests(): void
	{
		$guestUser = $this->createMock(IUser::class);
		$guestUser->method('getUID')->willReturn('guestreq');
		$guestUser->method('getEMailAddress')->willReturn('g@x.example');
		$userManager = $this->createMock(\OCP\IUserManager::class);
		$userManager->method('getByEmail')->willReturn([$guestUser]);
		$userManager->method('get')->willReturn($guestUser);

		$svc = new PermissionService(
			$this->sessionFor('agent1', 'a@x.example'),
			$this->groupsFor([
				'guestreq' => ['helpdesk_customers', 'helpdesk_requesters'],
				'agent1' => ['helpdesk_agents'],
			]),
			$this->configFor([]),
			$this->createMock(GuestProjectAccessMapper::class),
			$this->dbStub(),
			null,
			null,
			$userManager,
		);
		// Containment precedence: guest path covers them — no requester mail.
		self::assertSame([], $svc->requesterRecipientUids($this->ticket('agent1', 'g@x.example')));
	}
}

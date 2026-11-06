<?php

declare(strict_types=1);

/**
 * Canonicalized-actor guest detection (spec D-T10, §9.2).
 *
 * A migrated NC guest resolves to kind 'nc_guest' with actor 'portal:<id>'.
 * PermissionService::isGuest() must honour the resolver's guest kinds — a
 * group check on the canonical actor can never pass ('portal:<id>' is not an
 * NC uid), and without it every migrated NC guest logging in via the NC
 * session path was silently treated as staff: the portal index took the
 * staff else-branch, resolved zero accessible projects, and rendered an
 * empty portal with stats total=0.
 */

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\PortalActorResolver;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class PermissionServiceGuestActorTest extends TestCase
{
	private function service(PortalActorResolver $resolver, IGroupManager $groups, IUserSession $session): PermissionService
	{
		return new PermissionService(
			$session,
			$groups,
			$this->createMock(IConfig::class),
			$this->createMock(GuestProjectAccessMapper::class),
			$this->createMock(IDBConnection::class),
			$resolver,
		);
	}

	public function testMigratedNcGuestActorIsGuest(): void
	{
		$resolver = $this->createMock(PortalActorResolver::class);
		$resolver->method('isGuest')->willReturn(true);
		$resolver->method('kind')->willReturn('nc_guest');
		$resolver->method('currentActor')->willReturn('portal:3');

		// The canonical actor is not an NC uid — the group check must never
		// even be consulted for resolver-recognized guest kinds.
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects(self::never())->method('isInGroup');

		$session = $this->createMock(IUserSession::class);

		self::assertTrue($this->service($resolver, $groups, $session)->isGuest());
	}

	public function testIdentitySessionIsGuest(): void
	{
		$resolver = $this->createMock(PortalActorResolver::class);
		$resolver->method('isGuest')->willReturn(true);
		$resolver->method('kind')->willReturn('identity');
		$resolver->method('currentActor')->willReturn('portal:9');

		$groups = $this->createMock(IGroupManager::class);
		$groups->expects(self::never())->method('isInGroup');

		self::assertTrue($this->service($resolver, $groups, $this->createMock(IUserSession::class))->isGuest());
	}

	public function testStaffKindFallsBackToGroupCheck(): void
	{
		$resolver = $this->createMock(PortalActorResolver::class);
		$resolver->method('isGuest')->willReturn(false);
		$resolver->method('kind')->willReturn('staff');
		$resolver->method('currentActor')->willReturn('agent1');

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid) => $uid === 'agent1' && $gid === 'helpdesk_agents');

		self::assertFalse($this->service($resolver, $groups, $this->createMock(IUserSession::class))->isGuest());
	}

	public function testRefusedGuestKeepsLegacyUidSemantics(): void
	{
		$resolver = $this->createMock(PortalActorResolver::class);
		$resolver->method('isGuest')->willReturn(false);
		$resolver->method('kind')->willReturn('nc_guest_refused');
		$resolver->method('currentActor')->willReturn(null);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('e2e_guest');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid) => $uid === 'e2e_guest' && $gid === 'helpdesk_customers');

		self::assertTrue($this->service($resolver, $groups, $session)->isGuest());
	}
}

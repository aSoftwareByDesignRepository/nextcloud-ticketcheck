<?php

declare(strict_types=1);

/**
 * Bucket classification (spec US-T04 AC-1): migratable, blocked_no_email,
 * blocked_duplicate_email, skipped_staff, no_access, already_migrated,
 * nc_fallback — every guest lands in exactly one bucket.
 */

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\GuestAliasMapper;
use OCA\Ticketcheck\Db\GuestMiglogMapper;
use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Service\GuestAuthConfig;
use OCA\Ticketcheck\Service\GuestIdentityService;
use OCA\Ticketcheck\Service\GuestMigrationService;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GuestMigrationServiceTest extends TestCase
{
    private function svc(?IUser $user, int $accessCount = 1): GuestMigrationService
    {
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn($user);
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('get')->willReturn(null); // no staff groups → not staff
        $access = $this->createMock(GuestProjectAccessMapper::class);
        $access->method('countProjectsForUser')->willReturn($accessCount);
        return new GuestMigrationService(
            $groups,
            $userManager,
            $this->createMock(IConfig::class),
            $this->createMock(IDBConnection::class),
            $this->createMock(GuestIdentityService::class),
            $this->createMock(GuestAliasMapper::class),
            $this->createMock(GuestMiglogMapper::class),
            $access,
            $this->createMock(GuestAuthConfig::class),
            $this->createMock(ILockingProvider::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function user(string $email): IUser
    {
        $u = $this->createMock(IUser::class);
        $u->method('getEMailAddress')->willReturn($email);
        $u->method('getUID')->willReturn('guest_a');
        $u->method('getDisplayName')->willReturn('Guest A');
        return $u;
    }

    public function testMigratableWithEmailAndAccess(): void
    {
        $svc = $this->svc($this->user('a@example.com'), 1);
        self::assertSame('migratable',
            $svc->classify('guest_a', ['a@example.com' => 1], [], []));
    }

    public function testNoEmailBlocks(): void
    {
        $svc = $this->svc($this->user(''), 3);
        self::assertSame('blocked_no_email',
            $svc->classify('guest_a', [], [], []));
    }

    public function testDuplicateEmailBlocks(): void
    {
        $svc = $this->svc($this->user('a@example.com'), 1);
        self::assertSame('blocked_duplicate_email',
            $svc->classify('guest_a', ['a@example.com' => 2], [], []));
    }

    public function testAliasBeatsEverything(): void
    {
        $svc = $this->svc($this->user('a@example.com'), 0);
        // Even with no access rows, an existing alias = already_migrated.
        self::assertSame('already_migrated',
            $svc->classify('guest_a', ['a@example.com' => 1], ['guest_a'], []));
    }

    public function testNcFallbackBeatsMigration(): void
    {
        $svc = $this->svc($this->user('a@example.com'), 5);
        self::assertSame('nc_fallback',
            $svc->classify('guest_a', ['a@example.com' => 1], [], ['guest_a']));
    }

    public function testNoAccessSkips(): void
    {
        $svc = $this->svc($this->user('a@example.com'), 0);
        self::assertSame('no_access',
            $svc->classify('guest_a', ['a@example.com' => 1], [], []));
    }

    public function testMissingUserIsNoAccess(): void
    {
        $svc = $this->svc(null, 0);
        self::assertSame('no_access',
            $svc->classify('ghost_uid', [], [], []));
    }

    public function testStaffMembershipSkips(): void
    {
        // isInGroup path: a staff uid inside the guest group must land in
        // skipped_staff, never migratable.
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isInGroup')->willReturnCallback(
            static fn (string $uid, string $gid): bool => $uid === 'staff_a' && $gid === 'helpdesk_agents'
        );
        $groups->method('isAdmin')->willReturn(false);
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn($this->user('s@example.com'));
        $access = $this->createMock(GuestProjectAccessMapper::class);
        $access->method('countProjectsForUser')->willReturn(5);
        $svc = new GuestMigrationService(
            $groups, $userManager, $this->createMock(IConfig::class),
            $this->createMock(IDBConnection::class),
            $this->createMock(GuestIdentityService::class),
            $this->createMock(GuestAliasMapper::class),
            $this->createMock(GuestMiglogMapper::class), $access,
            $this->createMock(GuestAuthConfig::class),
            $this->createMock(ILockingProvider::class),
            $this->createMock(LoggerInterface::class),
        );
        self::assertSame('skipped_staff',
            $svc->classify('staff_a', ['s@example.com' => 1], [], []));
    }

    public function testAccessCheckIsConjunctive(): void
    {
        // && not ||: access on the real uid must keep the guest migratable
        // even when the portal:0 sentinel has no projects. An arg-insensitive
        // mock cannot distinguish the mutation.
        $user = $this->user('a@example.com');
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn($user);
        $access = $this->createMock(GuestProjectAccessMapper::class);
        $access->method('countProjectsForUser')->willReturnCallback(
            static fn (string $uid): int => $uid === 'portal:0' ? 0 : 3
        );
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('get')->willReturn(null);
        $groups->method('isInGroup')->willReturn(false);
        $groups->method('isAdmin')->willReturn(false);
        $svc = new GuestMigrationService(
            $groups, $userManager, $this->createMock(IConfig::class),
            $this->createMock(IDBConnection::class),
            $this->createMock(GuestIdentityService::class),
            $this->createMock(GuestAliasMapper::class),
            $this->createMock(GuestMiglogMapper::class), $access,
            $this->createMock(GuestAuthConfig::class),
            $this->createMock(ILockingProvider::class),
            $this->createMock(LoggerInterface::class),
        );
        self::assertSame('migratable',
            $svc->classify('guest_a', ['a@example.com' => 1], [], []));
    }

    public function testEmailCaseInsensitiveForDuplicates(): void
    {
        // Stored e-mail casing differs from the count map — must still dup-block.
        $svc = $this->svc($this->user('A@Example.com'), 1);
        self::assertSame('blocked_duplicate_email',
            $svc->classify('guest_a', ['a@example.com' => 2], [], []));
    }
}

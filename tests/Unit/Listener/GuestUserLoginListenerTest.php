<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Listener;

use OCA\Ticketcheck\Listener\GuestGroupRemovedListener;
use OCA\Ticketcheck\Listener\GuestUserLoginListener;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\User\Events\PostLoginEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Issue #5: pinning defaultapp=ticketcheck must never 500 a login, and the
 * pin must be cleared once the user leaves helpdesk_customers — but only
 * when the app provably wrote it. Stored-value equality is not provenance:
 * an admin-set '0 B' quota (upload-disabled account) or an admin-pinned
 * defaultapp must survive both the login self-heal and the group-removal
 * path (self_heal_write_outside_managed_domain).
 * GH #4: the marker-less pre-flag cohort additionally heals via the
 * '999.0.0' firstrunwizard sentinel — the app's own magic value — when the
 * one-shot upgrade repair never reached the user.
 */
class GuestUserLoginListenerTest extends TestCase
{
    private function user(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    private function loginEvent(IUser $user): PostLoginEvent
    {
        return new PostLoginEvent($user, 'login', 'secret', false);
    }

    /**
     * Deterministic per-key store: each getUserValue answers from $values
     * (default ''), and every deleteUserValue is recorded in $deletes.
     *
     * @param array<string, string> $values "app|key" => value
     * @return array{0: IConfig&\PHPUnit\Framework\MockObject\MockObject, 1: \ArrayObject<int, array{string,string,string}>}
     */
    private function configStore(string $uid, array $values): array
    {
        $config = $this->createMock(IConfig::class);
        $deletes = new \ArrayObject();
        $config->method('getUserValue')
            ->willReturnCallback(static function (string $u, string $app, string $key, string $default = '') use ($uid, $values): string {
                if ($u !== $uid) {
                    return $default;
                }
                return $values[$app . '|' . $key] ?? $default;
            });
        $config->method('deleteUserValue')
            ->willReturnCallback(static function (string $u, string $app, string $key) use ($deletes): void {
                $deletes->append([$u, $app, $key]);
            });
        return [$config, $deletes];
    }

    public function testGuestGetsDefaultAppPinnedOnLogin(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturn(true);

        $config = $this->createMock(IConfig::class);
        $writes = [];
        $config->expects($this->exactly(3))
            ->method('setUserValue')
            ->willReturnCallback(static function (string $uid, string $app, string $key, string $value) use (&$writes): void {
                $writes[] = [$uid, $app, $key, $value];
            });
        $config->expects($this->never())->method('deleteUserValue');

        $listener = new GuestUserLoginListener(
            $groupManager,
            $config,
            $this->createMock(LoggerInterface::class),
        );
        $listener->handle($this->loginEvent($this->user('alice')));

        $this->assertSame([
            ['alice', 'ticketcheck', 'portal_pins', '1'],
            ['alice', 'core', 'defaultapp', 'ticketcheck'],
            ['alice', 'firstrunwizard', 'show', '999.0.0'],
        ], $writes);
    }

    public function testFormerGuestPinsAreClearedOnLogin(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturn(false);

        // App-created ex-guest: creation marker proves the app wrote the
        // '0 B' quota + defaultapp pins; the login flag covers the portal pin
        // set. Everything is undone — including both markers.
        [$config, $deletes] = $this->configStore('bob', [
            'ticketcheck|guest_created_at' => '2026-10-01 12:00:00',
            'ticketcheck|portal_pins' => '1',
            'core|defaultapp' => 'ticketcheck',
            'firstrunwizard|show' => '999.0.0',
            'files|quota' => '0 B',
        ]);

        $listener = new GuestUserLoginListener(
            $groupManager,
            $config,
            $this->createMock(LoggerInterface::class),
        );
        $listener->handle($this->loginEvent($this->user('bob')));

        $this->assertSame([
            ['bob', 'core', 'defaultapp'],
            ['bob', 'firstrunwizard', 'show'],
            ['bob', 'files', 'quota'],
            ['bob', 'ticketcheck', 'portal_pins'],
            ['bob', 'ticketcheck', 'guest_created_at'],
        ], $deletes->getArrayCopy());
    }

    public function testPreFlagPinPairIsHealedOnLoginWithoutMarkers(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturn(false);

        // GH #4 cohort: pre-flag member pin (<=2.6.0 wrote the
        // defaultapp+firstrunwizard pair without provenance markers) on a
        // user who left the group and whose one-shot upgrade repair never
        // reached her. The '999.0.0' sentinel is ours alone, so the pair is
        // proven app state and both values go — the admin-set quota stays.
        [$config, $deletes] = $this->configStore('lisa', [
            'core|defaultapp' => 'ticketcheck',
            'firstrunwizard|show' => '999.0.0',
            'files|quota' => '5 GB',
        ]);

        $listener = new GuestUserLoginListener(
            $groupManager,
            $config,
            $this->createMock(LoggerInterface::class),
        );
        $listener->handle($this->loginEvent($this->user('lisa')));

        $this->assertSame([
            ['lisa', 'core', 'defaultapp'],
            ['lisa', 'firstrunwizard', 'show'],
        ], $deletes->getArrayCopy());
    }

    public function testLoneWizardSentinelIsClearedWithoutMarkers(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturn(false);

        // Only the sentinel survives (defaultapp already cleaned): '999.0.0'
        // is provably ours, so it is deleted — while a foreign defaultapp
        // pin for a staff user is left alone.
        [$config, $deletes] = $this->configStore('mia', [
            'core|defaultapp' => 'dashboard',
            'firstrunwizard|show' => '999.0.0',
        ]);

        $listener = new GuestUserLoginListener(
            $groupManager,
            $config,
            $this->createMock(LoggerInterface::class),
        );
        $listener->handle($this->loginEvent($this->user('mia')));

        $this->assertSame([
            ['mia', 'firstrunwizard', 'show'],
        ], $deletes->getArrayCopy());
    }

    public function testUnprovenancedStalePinIsPreservedOnLogin(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturn(false);

        // Never-guest user pinned to defaultapp=ticketcheck by an admin (or a
        // pre-flag app write): no marker, no portal_pins flag — the app cannot
        // prove authorship, so nothing is deleted.
        [$config, $deletes] = $this->configStore('carol', [
            'core|defaultapp' => 'ticketcheck',
            'files|quota' => '0 B',
        ]);

        $listener = new GuestUserLoginListener(
            $groupManager,
            $config,
            $this->createMock(LoggerInterface::class),
        );
        $listener->handle($this->loginEvent($this->user('carol')));

        $this->assertSame([], $deletes->getArrayCopy());
    }

    public function testFlaggedExMemberPinsAreClearedButQuotaStays(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturn(false);

        // Manually-added ex-member (no creation marker) who logged in while
        // in the group: portal_pins proves the app wrote defaultapp +
        // firstrunwizard — those are unpinned — but the admin-set '0 B'
        // quota is NOT app state and must survive.
        [$config, $deletes] = $this->configStore('dora', [
            'ticketcheck|portal_pins' => '1',
            'core|defaultapp' => 'ticketcheck',
            'firstrunwizard|show' => '999.0.0',
            'files|quota' => '0 B',
        ]);

        $listener = new GuestUserLoginListener(
            $groupManager,
            $config,
            $this->createMock(LoggerInterface::class),
        );
        $listener->handle($this->loginEvent($this->user('dora')));

        $this->assertSame([
            ['dora', 'core', 'defaultapp'],
            ['dora', 'firstrunwizard', 'show'],
            ['dora', 'ticketcheck', 'portal_pins'],
        ], $deletes->getArrayCopy());
    }

    public function testAdminSetZeroQuotaIsPreservedWithoutMarker(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturn(false);

        // The defect the r38 critic proved: '0 B' on a uid the app never
        // created is an admin choice (upload-disabled), not our pin.
        [$config, $deletes] = $this->configStore('elias', [
            'files|quota' => '0 B',
        ]);

        $listener = new GuestUserLoginListener(
            $groupManager,
            $config,
            $this->createMock(LoggerInterface::class),
        );
        $listener->handle($this->loginEvent($this->user('elias')));

        $this->assertSame([], $deletes->getArrayCopy());
    }

    public function testFormerGuestKeepsAdminChangedQuota(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturn(false);

        // App-created ex-guest whose quota an admin raised: '5 GB' is not the
        // app's pin value, so it stays — but the provenance marker is still
        // consumed (the account is fully the admin's from here on).
        [$config, $deletes] = $this->configStore('frida', [
            'ticketcheck|guest_created_at' => '2026-10-01 12:00:00',
            'core|defaultapp' => 'dashboard',
            'files|quota' => '5 GB',
        ]);

        $listener = new GuestUserLoginListener(
            $groupManager,
            $config,
            $this->createMock(LoggerInterface::class),
        );
        $listener->handle($this->loginEvent($this->user('frida')));

        $this->assertSame([
            ['frida', 'ticketcheck', 'guest_created_at'],
        ], $deletes->getArrayCopy());
    }

    public function testForeignDefaultAppIsUntouched(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturn(false);

        [$config, $deletes] = $this->configStore('carol', [
            'core|defaultapp' => 'dashboard',
        ]);

        $listener = new GuestUserLoginListener(
            $groupManager,
            $config,
            $this->createMock(LoggerInterface::class),
        );
        $listener->handle($this->loginEvent($this->user('carol')));

        $this->assertSame([], $deletes->getArrayCopy());
    }

    public function testListenerFailureNeverBreaksLogin(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')
            ->willThrowException(new \RuntimeException('group backend down'));

        $config = $this->createMock(IConfig::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $listener = new GuestUserLoginListener($groupManager, $config, $logger);
        // Must not throw — a thrown listener exception 500s the login POST.
        $listener->handle($this->loginEvent($this->user('dave')));
        $this->assertTrue(true);
    }

    public function testGroupRemovalClearsAppWrittenPinsImmediately(): void
    {
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn('helpdesk_customers');

        // Flagged ex-member: the app's login pin proves authorship of
        // defaultapp + firstrunwizard; admin-set '5 GB' quota stays.
        [$config, $deletes] = $this->configStore('erin', [
            'ticketcheck|portal_pins' => '1',
            'core|defaultapp' => 'ticketcheck',
            'firstrunwizard|show' => '999.0.0',
            'files|quota' => '5 GB',
        ]);

        $listener = new GuestGroupRemovedListener(
            $config,
            $this->createMock(LoggerInterface::class),
        );
        $listener->handle(new UserRemovedEvent($group, $this->user('erin')));

        $this->assertSame([
            ['erin', 'core', 'defaultapp'],
            ['erin', 'firstrunwizard', 'show'],
            ['erin', 'ticketcheck', 'portal_pins'],
        ], $deletes->getArrayCopy());
    }

    public function testGroupRemovalPreservesUnprovenancedMember(): void
    {
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn('helpdesk_customers');

        // Marker-less member with values the app cannot prove it wrote
        // (admin-set '0 B' quota + admin-pinned defaultapp, or a pre-flag pin
        // on a member who never logged in while flagged): preserve all.
        [$config, $deletes] = $this->configStore('erin2', [
            'core|defaultapp' => 'ticketcheck',
            'files|quota' => '0 B',
        ]);

        $listener = new GuestGroupRemovedListener(
            $config,
            $this->createMock(LoggerInterface::class),
        );
        $listener->handle(new UserRemovedEvent($group, $this->user('erin2')));

        $this->assertSame([], $deletes->getArrayCopy());
    }

    public function testGroupRemovalIgnoresOtherGroups(): void
    {
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn('helpdesk_agents');

        $config = $this->createMock(IConfig::class);
        $config->expects($this->never())->method('deleteUserValue');

        $listener = new GuestGroupRemovedListener(
            $config,
            $this->createMock(LoggerInterface::class),
        );
        $listener->handle(new UserRemovedEvent($group, $this->user('frank')));
    }
}

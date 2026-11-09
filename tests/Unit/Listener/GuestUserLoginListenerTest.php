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
 * pin must be cleared once the user leaves helpdesk_customers.
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

    public function testGuestGetsDefaultAppPinnedOnLogin(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturn(true);

        $config = $this->createMock(IConfig::class);
        $writes = [];
        $config->expects($this->exactly(2))
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
            ['alice', 'core', 'defaultapp', 'ticketcheck'],
            ['alice', 'firstrunwizard', 'show', '999.0.0'],
        ], $writes);
    }

    public function testStalePinIsClearedForNonGuestOnLogin(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturn(false);

        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')
            ->with('bob', 'core', 'defaultapp')
            ->willReturn('ticketcheck');
        $config->expects($this->once())
            ->method('deleteUserValue')
            ->with('bob', 'core', 'defaultapp');
        $config->expects($this->never())->method('setUserValue');

        $listener = new GuestUserLoginListener(
            $groupManager,
            $config,
            $this->createMock(LoggerInterface::class),
        );
        $listener->handle($this->loginEvent($this->user('bob')));
    }

    public function testForeignDefaultAppIsUntouched(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturn(false);

        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('dashboard');
        $config->expects($this->never())->method('deleteUserValue');

        $listener = new GuestUserLoginListener(
            $groupManager,
            $config,
            $this->createMock(LoggerInterface::class),
        );
        $listener->handle($this->loginEvent($this->user('carol')));
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

    public function testGroupRemovalClearsPinImmediately(): void
    {
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn('helpdesk_customers');

        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('ticketcheck');
        $config->expects($this->once())
            ->method('deleteUserValue')
            ->with('erin', 'core', 'defaultapp');

        $listener = new GuestGroupRemovedListener(
            $config,
            $this->createMock(LoggerInterface::class),
        );
        $listener->handle(new UserRemovedEvent($group, $this->user('erin')));
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

<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\GuestUserController;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class GuestUserControllerSecurityTest extends TestCase
{
    public function testResolveGuestAccountRejectsStaffAndNonGuests(): void
    {
        $guestUser = $this->createMock(IUser::class);
        $guestUser->method('getUID')->willReturn('guest1');

        $agentUser = $this->createMock(IUser::class);
        $agentUser->method('getUID')->willReturn('agent1');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturnMap([
            ['guest1', $guestUser],
            ['agent1', $agentUser],
            ['missing', null],
        ]);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturnCallback(
            static function (string $uid, string $gid): bool {
                if ($gid === 'helpdesk_customers') {
                    return $uid === 'guest1';
                }
                if ($gid === 'helpdesk_agents') {
                    return $uid === 'agent1';
                }
                if ($gid === 'helpdesk_admins') {
                    return false;
                }

                return false;
            }
        );

        $controller = $this->buildControllerForResolveGuest($userManager, $groupManager);

        $method = new \ReflectionMethod(GuestUserController::class, 'resolveGuestAccount');
        $method->setAccessible(true);

        $this->assertNotNull($method->invoke($controller, 'guest1'));
        $this->assertNull($method->invoke($controller, 'agent1'));
        $this->assertNull($method->invoke($controller, 'missing'));
        $this->assertNull($method->invoke($controller, ''));
    }

    private function buildControllerForResolveGuest(IUserManager $userManager, IGroupManager $groupManager): GuestUserController
    {
        $controller = $this->getMockBuilder(GuestUserController::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $ref = new \ReflectionClass(GuestUserController::class);
        foreach (['userManager' => $userManager, 'groupManager' => $groupManager] as $prop => $value) {
            $property = $ref->getProperty($prop);
            $property->setAccessible(true);
            $property->setValue($controller, $value);
        }

        return $controller;
    }
}

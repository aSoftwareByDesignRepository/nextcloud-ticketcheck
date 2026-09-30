<?php

declare(strict_types=1);

/**
 * Security tests for guest access control
 *
 * Covers M4-09: Token scopes, access restrictions, and rate limiting behavior.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Security tests for guest portal access
 */
class GuestAccessTest extends TestCase
{
    /** @var GuestProjectAccessMapper&\PHPUnit\Framework\MockObject\MockObject */
    private GuestProjectAccessMapper $guestAccessMapper;

    /** @var IUserSession&\PHPUnit\Framework\MockObject\MockObject */
    private IUserSession $userSession;

    /** @var IGroupManager&\PHPUnit\Framework\MockObject\MockObject */
    private IGroupManager $groupManager;

    /** @var IConfig&\PHPUnit\Framework\MockObject\MockObject */
    private IConfig $config;

    /** @var IDBConnection&\PHPUnit\Framework\MockObject\MockObject */
    private IDBConnection $db;

    private PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guestAccessMapper = $this->createMock(GuestProjectAccessMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->config = $this->createMock(IConfig::class);
        $this->db = $this->createMock(IDBConnection::class);

        $this->permissionService = new PermissionService(
            $this->userSession,
            $this->groupManager,
            $this->config,
            $this->guestAccessMapper,
            $this->db
        );
    }

    /**
     * Happy path: Guest with project access can get accessible project IDs
     */
    public function testGuestWithProjectAccessGetsAccessibleProjectIds(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('guest1');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')
            ->willReturnMap([
                ['guest1', PermissionService::GROUP_HELPDESK_CUSTOMERS, true],
                ['guest1', PermissionService::GROUP_HELPDESK_AGENTS, false],
                ['guest1', PermissionService::GROUP_HELPDESK_ADMINS, false],
            ]);

        $this->guestAccessMapper->expects(self::once())
            ->method('getProjectsForUser')
            ->with('guest1')
            ->willReturn([1, 2]);

        $ids = $this->permissionService->getAccessibleProjectIds();

        self::assertNotNull($ids);
        self::assertSame([1, 2], $ids);
    }

    /**
     * Forbidden path: Guest without project access gets empty list
     */
    public function testGuestWithoutProjectAccessGetsEmptyList(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('guest2');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')
            ->willReturnMap([
                ['guest2', PermissionService::GROUP_HELPDESK_CUSTOMERS, true],
                ['guest2', PermissionService::GROUP_HELPDESK_AGENTS, false],
                ['guest2', PermissionService::GROUP_HELPDESK_ADMINS, false],
            ]);

        $this->guestAccessMapper->expects(self::once())
            ->method('getProjectsForUser')
            ->with('guest2')
            ->willReturn([]);

        $ids = $this->permissionService->getAccessibleProjectIds();

        self::assertNotNull($ids);
        self::assertSame([], $ids);
    }

    /**
     * Rate limit: Guest under daily limit passes check
     */
    public function testGuestUnderRateLimitPasses(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')
            ->with(self::anything(), PermissionService::GROUP_HELPDESK_CUSTOMERS)
            ->willReturn(true);

        $passes = $this->permissionService->checkRateLimit(10, 50);
        self::assertTrue($passes);
    }

    /**
     * Rate limit: Guest at or over limit fails check
     */
    public function testGuestOverRateLimitFails(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('guest1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')
            ->with(self::anything(), PermissionService::GROUP_HELPDESK_CUSTOMERS)
            ->willReturn(true);

        $passes = $this->permissionService->checkRateLimit(50, 50);
        self::assertFalse($passes);

        $passes = $this->permissionService->checkRateLimit(60, 50);
        self::assertFalse($passes);
    }

    /**
     * Agent is not subject to rate limit
     */
    public function testAgentBypassesRateLimit(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent1');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')
            ->willReturnMap([
                ['agent1', PermissionService::GROUP_HELPDESK_CUSTOMERS, false],
                ['agent1', PermissionService::GROUP_HELPDESK_AGENTS, true],
            ]);

        // Guest mapper should not be called for agents
        $this->guestAccessMapper->expects(self::never())->method('getProjectsForUser');

        $passes = $this->permissionService->checkRateLimit(999, 50);
        self::assertTrue($passes);
    }
}

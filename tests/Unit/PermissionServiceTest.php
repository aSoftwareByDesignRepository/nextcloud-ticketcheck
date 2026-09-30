<?php

declare(strict_types=1);

/**
 * Unit tests for PermissionService — ticket view/edit/delete and role checks
 *
 * Security-critical: ensures guests only see their tickets, agents/admins have correct scope.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class PermissionServiceTest extends TestCase
{
    private IUserSession $userSession;
    private IGroupManager $groupManager;
    private IConfig $config;
    private GuestProjectAccessMapper $guestAccessMapper;
    private IDBConnection $db;

    private PermissionService $permissionService;

    /** @var int Passed to mock query builder for hd_proj_members COUNT (0 = no membership) */
    private int $mockProjectMemberCount = 0;
    /** @var string|null Role returned by hd_proj_members role lookup (null = not a member) */
    private ?string $mockProjectMemberRole = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->config = $this->createMock(IConfig::class);
        $this->guestAccessMapper = $this->createMock(GuestProjectAccessMapper::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->mockProjectMemberCount = 0;
        $this->mockProjectMemberRole = null;
        $this->db->method('getQueryBuilder')->willReturnCallback(function (): IQueryBuilder {
            if ($this->mockProjectMemberRole !== null) {
                return $this->mockProjectRoleQueryBuilder($this->mockProjectMemberRole);
            }

            return $this->mockProjectMembershipCountQueryBuilder($this->mockProjectMemberCount);
        });

        $this->permissionService = new PermissionService(
            $this->userSession,
            $this->groupManager,
            $this->config,
            $this->guestAccessMapper,
            $this->db
        );
    }

    /** Create a minimal ticket-like stub with getters for permission checks */
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

    public function testCanViewTicket_GuestSeesOwnByCreatedBy(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('guest1');
        $user->method('getEMailAddress')->willReturn('guest1@example.com');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['guest1', PermissionService::GROUP_HELPDESK_CUSTOMERS, true],
            ['guest1', PermissionService::GROUP_HELPDESK_AGENTS, false],
            ['guest1', PermissionService::GROUP_HELPDESK_ADMINS, false],
        ]);

        $ticket = $this->ticket('guest1', 'other@example.com', null);
        self::assertTrue($this->permissionService->canViewTicket($ticket));
    }

    public function testCanViewTicket_GuestSeesStaffTicketInGrantedProjectByEmail(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('guest2');
        $user->method('getEMailAddress')->willReturn('customer@example.com');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['guest2', PermissionService::GROUP_HELPDESK_CUSTOMERS, true],
            ['guest2', PermissionService::GROUP_HELPDESK_AGENTS, false],
            ['guest2', PermissionService::GROUP_HELPDESK_ADMINS, false],
        ]);
        $this->guestAccessMapper->method('hasAccess')->with('guest2', 5)->willReturn(true);

        $ticket = $this->ticket('agent1', 'customer@example.com', 5);
        self::assertTrue($this->permissionService->canViewTicket($ticket));
    }

    public function testCanViewTicket_GuestSeesStaffTicketInGrantedProjectByEmailCaseInsensitive(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('guest2');
        $user->method('getEMailAddress')->willReturn('Customer@Example.COM');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['guest2', PermissionService::GROUP_HELPDESK_CUSTOMERS, true],
            ['guest2', PermissionService::GROUP_HELPDESK_AGENTS, false],
            ['guest2', PermissionService::GROUP_HELPDESK_ADMINS, false],
        ]);
        $this->guestAccessMapper->method('hasAccess')->with('guest2', 5)->willReturn(true);

        $ticket = $this->ticket('agent1', 'customer@example.com', 5);
        self::assertTrue($this->permissionService->canViewTicket($ticket));
    }

    public function testCanViewTicket_GuestCannotViewEmailOnlyTicketWithoutProject(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('guest2');
        $user->method('getEMailAddress')->willReturn('customer@example.com');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['guest2', PermissionService::GROUP_HELPDESK_CUSTOMERS, true],
            ['guest2', PermissionService::GROUP_HELPDESK_AGENTS, false],
            ['guest2', PermissionService::GROUP_HELPDESK_ADMINS, false],
        ]);

        $ticket = $this->ticket('agent1', 'customer@example.com', null);
        self::assertFalse($this->permissionService->canViewTicket($ticket));
    }

    public function testCanViewTicket_GuestCannotViewOtherCustomersTicket(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('guest1');
        $user->method('getEMailAddress')->willReturn('guest1@example.com');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['guest1', PermissionService::GROUP_HELPDESK_CUSTOMERS, true],
            ['guest1', PermissionService::GROUP_HELPDESK_AGENTS, false],
            ['guest1', PermissionService::GROUP_HELPDESK_ADMINS, false],
        ]);

        $ticket = $this->ticket('agent1', 'other@example.com', null);
        self::assertFalse($this->permissionService->canViewTicket($ticket));
    }

    public function testCanViewTicket_AgentSeesAnyTicket(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent1');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['agent1', PermissionService::GROUP_HELPDESK_CUSTOMERS, false],
            ['agent1', PermissionService::GROUP_HELPDESK_AGENTS, true],
        ]);

        $ticket = $this->ticket('guest1', 'customer@example.com', 5);
        self::assertTrue($this->permissionService->canViewTicket($ticket));
    }

    public function testCanViewTicket_NoUserReturnsFalse(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $ticket = $this->ticket('guest1', 'a@b.com', null);
        self::assertFalse($this->permissionService->canViewTicket($ticket));
    }

    public function testCanEditTicket_AgentCanEdit(): void
    {
        $ticket = $this->ticket('guest1', 'a@b.com', null);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['agent1', PermissionService::GROUP_HELPDESK_AGENTS, true],
            ['agent1', PermissionService::GROUP_HELPDESK_ADMINS, false],
        ]);
        $this->groupManager->method('isAdmin')->willReturn(false);
        self::assertTrue($this->permissionService->canEditTicket($ticket));
    }

    public function testCanEditTicket_GuestCannotEdit(): void
    {
        $ticket = $this->ticket('guest1', 'a@b.com', null);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('guest1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['guest1', PermissionService::GROUP_HELPDESK_CUSTOMERS, true],
            ['guest1', PermissionService::GROUP_HELPDESK_AGENTS, false],
            ['guest1', PermissionService::GROUP_HELPDESK_ADMINS, false],
        ]);
        $this->groupManager->method('isAdmin')->willReturn(false);
        self::assertFalse($this->permissionService->canEditTicket($ticket));
    }

    public function testCanEditTicket_ProjectAdminCanEditInOwnProject(): void
    {
        $ticket = $this->ticket('guest1', 'a@b.com', 7);
        $this->mockProjectMemberRole = 'Admin';

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('projadmin1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['projadmin1', PermissionService::GROUP_HELPDESK_CUSTOMERS, false],
            ['projadmin1', PermissionService::GROUP_HELPDESK_AGENTS, false],
            ['projadmin1', PermissionService::GROUP_HELPDESK_ADMINS, false],
        ]);
        $this->groupManager->method('isAdmin')->willReturn(false);

        self::assertTrue($this->permissionService->canEditTicket($ticket));
        self::assertTrue($this->permissionService->canDeleteTicket($ticket));
    }

    public function testCanMoveTicketToProject_ProjectAdminCannotClearProject(): void
    {
        $ticket = $this->ticket('guest1', 'a@b.com', 7);
        $this->mockProjectMemberRole = 'Admin';

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('projadmin1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['projadmin1', PermissionService::GROUP_HELPDESK_CUSTOMERS, false],
            ['projadmin1', PermissionService::GROUP_HELPDESK_AGENTS, false],
            ['projadmin1', PermissionService::GROUP_HELPDESK_ADMINS, false],
        ]);
        $this->groupManager->method('isAdmin')->willReturn(false);

        self::assertTrue($this->permissionService->canMoveTicketToProject($ticket, 7));
        self::assertFalse($this->permissionService->canMoveTicketToProject($ticket, null));
    }

    public function testCanMoveTicketToProject_AgentCanMoveFreely(): void
    {
        $ticket = $this->ticket('guest1', 'a@b.com', 1);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['agent1', PermissionService::GROUP_HELPDESK_AGENTS, true],
            ['agent1', PermissionService::GROUP_HELPDESK_ADMINS, false],
            ['agent1', PermissionService::GROUP_HELPDESK_CUSTOMERS, false],
        ]);
        $this->groupManager->method('isAdmin')->willReturn(false);

        self::assertTrue($this->permissionService->canMoveTicketToProject($ticket, 99));
        self::assertTrue($this->permissionService->canMoveTicketToProject($ticket, null));
    }

    public function testCanBrowseGlobalProjectDirectory_AgentsOnly(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['agent1', PermissionService::GROUP_HELPDESK_AGENTS, true],
            ['agent1', PermissionService::GROUP_HELPDESK_ADMINS, false],
            ['agent1', PermissionService::GROUP_HELPDESK_CUSTOMERS, false],
        ]);
        $this->groupManager->method('isAdmin')->willReturn(false);
        self::assertTrue($this->permissionService->canBrowseGlobalProjectDirectory());
    }

    public function testCanBrowseGlobalProjectDirectory_ProjectAdminDenied(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('projadmin1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['projadmin1', PermissionService::GROUP_HELPDESK_CUSTOMERS, false],
            ['projadmin1', PermissionService::GROUP_HELPDESK_AGENTS, false],
            ['projadmin1', PermissionService::GROUP_HELPDESK_ADMINS, false],
        ]);
        $this->groupManager->method('isAdmin')->willReturn(false);
        self::assertFalse($this->permissionService->canBrowseGlobalProjectDirectory());
    }

    public function testCanDeleteTicket_AdminCanAlwaysDelete(): void
    {
        $ticket = $this->ticket('guest1', 'a@b.com', 1);

        $userAdmin = $this->createMock(IUser::class);
        $userAdmin->method('getUID')->willReturn('admin1');
        $this->userSession->method('getUser')->willReturn($userAdmin);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['admin1', PermissionService::GROUP_HELPDESK_ADMINS, true],
        ]);
        $this->groupManager->method('isAdmin')->willReturn(false);
        self::assertTrue($this->permissionService->canDeleteTicket($ticket));
    }

    public function testCanDeleteTicket_AgentWithoutProjectAdminCannotDelete(): void
    {
        $ticket = $this->ticket('guest1', 'a@b.com', 1);

        $userAgent = $this->createMock(IUser::class);
        $userAgent->method('getUID')->willReturn('agent1');
        $this->userSession->method('getUser')->willReturn($userAgent);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['agent1', PermissionService::GROUP_HELPDESK_AGENTS, true],
            ['agent1', PermissionService::GROUP_HELPDESK_ADMINS, false],
        ]);
        /* isProjectAdmin runs a DB query; when it reports missing schema, role is absent so agent cannot delete */
        $dbEx = new class ('no such table') extends \OCP\DB\Exception {
            public function getReason(): ?int
            {
                return self::REASON_DATABASE_OBJECT_NOT_FOUND;
            }
        };
        $this->db->method('getQueryBuilder')->willThrowException($dbEx);
        self::assertFalse($this->permissionService->canDeleteTicket($ticket));
    }

    public function testCanAccessApp_AllowsConfiguredAgentRole(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('agent1')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['agent1', PermissionService::GROUP_HELPDESK_ADMINS, false],
            ['agent1', PermissionService::GROUP_HELPDESK_AGENTS, true],
            ['agent1', PermissionService::GROUP_HELPDESK_CUSTOMERS, false],
        ]);
        $this->config->method('getAppValue')->willReturnMap([
            ['ticketcheck', 'app_access_helpdesk_admins', 'yes', 'no'],
            ['ticketcheck', 'app_access_helpdesk_agents', 'yes', 'yes'],
            ['ticketcheck', 'app_access_helpdesk_customers', 'yes', 'no'],
            ['ticketcheck', 'app_access_extra_groups', '', ''],
        ]);

        self::assertTrue($this->permissionService->canAccessApp());
        self::assertTrue($this->permissionService->hasEnabledHelpdeskScope('agent1'));
    }

    public function testCanAccessApp_DeniesUserOutsideConfiguredScopes(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('user1')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);
        $this->config->method('getAppValue')->willReturnMap([
            ['ticketcheck', 'app_access_helpdesk_admins', 'yes', 'no'],
            ['ticketcheck', 'app_access_helpdesk_agents', 'yes', 'no'],
            ['ticketcheck', 'app_access_helpdesk_customers', 'yes', 'no'],
            ['ticketcheck', 'app_access_extra_groups', '', 'qa_team'],
        ]);

        // Door ≠ role (access-door contract): an open door lets any logged-in
        // user in, but users outside the configured scopes get no helpdesk
        // scope — they land on the calm enrollment page instead.
        self::assertTrue($this->permissionService->canAccessApp());
        self::assertFalse($this->permissionService->hasEnabledHelpdeskScope('user1'));
    }

    public function testCanAccessApp_AllowsExtraGroupOnlyWhenFunctionalRoleExists(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent_extra');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('agent_extra')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['agent_extra', PermissionService::GROUP_HELPDESK_ADMINS, false],
            ['agent_extra', PermissionService::GROUP_HELPDESK_AGENTS, true],
            ['agent_extra', PermissionService::GROUP_HELPDESK_CUSTOMERS, false],
            ['agent_extra', 'qa_team', true],
        ]);
        $this->config->method('getAppValue')->willReturnMap([
            ['ticketcheck', 'app_access_helpdesk_admins', 'yes', 'no'],
            ['ticketcheck', 'app_access_helpdesk_agents', 'yes', 'no'],
            ['ticketcheck', 'app_access_helpdesk_customers', 'yes', 'no'],
            ['ticketcheck', 'app_access_extra_groups', '', 'qa_team'],
        ]);

        self::assertTrue($this->permissionService->canAccessApp());
        self::assertTrue($this->permissionService->hasEnabledHelpdeskScope('agent_extra'));
    }

    public function testCanAccessApp_AllowsProjectMemberWhenAgentScopeEnabled(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('proj_user');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('proj_user')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);
        $this->config->method('getAppValue')->willReturnMap([
            ['ticketcheck', 'app_access_helpdesk_admins', 'yes', 'no'],
            ['ticketcheck', 'app_access_helpdesk_agents', 'yes', 'yes'],
            ['ticketcheck', 'app_access_helpdesk_customers', 'yes', 'no'],
            ['ticketcheck', 'app_access_extra_groups', '', ''],
        ]);
        $this->mockProjectMemberCount = 1;
        self::assertTrue($this->permissionService->canAccessApp());
        self::assertTrue($this->permissionService->hasEnabledHelpdeskScope('proj_user'));
        $this->mockProjectMemberCount = 0;
    }

    public function testCanAccessApp_DeniesProjectMemberWhenAgentScopeDisabled(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('proj_user');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('proj_user')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);
        $this->config->method('getAppValue')->willReturnMap([
            ['ticketcheck', 'app_access_helpdesk_admins', 'yes', 'no'],
            ['ticketcheck', 'app_access_helpdesk_agents', 'yes', 'no'],
            ['ticketcheck', 'app_access_helpdesk_customers', 'yes', 'no'],
            ['ticketcheck', 'app_access_extra_groups', '', ''],
        ]);
        $this->mockProjectMemberCount = 1;
        // Open door: project members may enter the app, but with the agent
        // scope toggle off they get no helpdesk scope (enrollment page).
        self::assertTrue($this->permissionService->canAccessApp());
        self::assertFalse($this->permissionService->hasEnabledHelpdeskScope('proj_user'));
        $this->mockProjectMemberCount = 0;
    }

    public function testIsKnowledgeBaseIndexAvailable_ReturnsFalseWhenKbDisabled(): void
    {
        $this->config->method('getAppValue')->willReturnMap([
            ['ticketcheck', 'kb_enabled', 'yes', 'no'],
        ]);
        self::assertFalse($this->permissionService->isKnowledgeBaseIndexAvailable());
    }

    public function testIsKnowledgeBaseIndexAvailable_NonGuest_WhenKbEnabled(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['agent1', PermissionService::GROUP_HELPDESK_CUSTOMERS, false],
        ]);
        $this->config->method('getAppValue')->willReturnMap([
            ['ticketcheck', 'kb_enabled', 'yes', 'yes'],
        ]);
        self::assertTrue($this->permissionService->isKnowledgeBaseIndexAvailable());
    }

    public function testIsKnowledgeBaseIndexAvailable_GuestFalseWhenPortalDisabled(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('g1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['g1', PermissionService::GROUP_HELPDESK_CUSTOMERS, true],
        ]);
        $this->config->method('getAppValue')->willReturnMap([
            ['ticketcheck', 'kb_enabled', 'yes', 'yes'],
            ['ticketcheck', 'kb_portal_enabled', 'yes', 'no'],
        ]);
        self::assertFalse($this->permissionService->isKnowledgeBaseIndexAvailable());
    }

    public function testIsKnowledgeBaseIndexAvailable_GuestTrueWhenPortalEnabled(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('g1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['g1', PermissionService::GROUP_HELPDESK_CUSTOMERS, true],
        ]);
        $this->config->method('getAppValue')->willReturnMap([
            ['ticketcheck', 'kb_enabled', 'yes', 'yes'],
            ['ticketcheck', 'kb_portal_enabled', 'yes', 'yes'],
        ]);
        self::assertTrue($this->permissionService->isKnowledgeBaseIndexAvailable());
    }

    public function testIsKnowledgeBasePortalContentEnabled_RequiresBothAppToggles(): void
    {
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                if ($app !== 'ticketcheck') {
                    return $default;
                }
                return match ($key) {
                    'kb_enabled' => 'yes',
                    'kb_portal_enabled' => 'no',
                    default => $default,
                };
            }
        );
        self::assertFalse($this->permissionService->isKnowledgeBasePortalContentEnabled());
    }

    public function testIsKnowledgeBasePortalContentEnabled_TrueWhenBothTogglesOn(): void
    {
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                if ($app !== 'ticketcheck') {
                    return $default;
                }
                return match ($key) {
                    'kb_enabled' => 'yes',
                    'kb_portal_enabled' => 'yes',
                    default => $default,
                };
            }
        );
        self::assertTrue($this->permissionService->isKnowledgeBasePortalContentEnabled());
    }

    public function testIsKnowledgeBaseFeedbackEnabled_FollowsConfig(): void
    {
        $this->config->method('getAppValue')->willReturnMap([
            ['ticketcheck', 'kb_feedback_enabled', 'yes', 'no'],
        ]);
        self::assertFalse($this->permissionService->isKnowledgeBaseFeedbackEnabled());
    }

    public function testIsKnowledgeBaseCommentsEnabled_FollowsConfig(): void
    {
        $this->config->method('getAppValue')->willReturnMap([
            ['ticketcheck', 'kb_comments_enabled', 'yes', 'yes'],
        ]);
        self::assertTrue($this->permissionService->isKnowledgeBaseCommentsEnabled());
    }

    public function testAgentCannotAccessCustomerOrGuestAdministration(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['agent1', PermissionService::GROUP_HELPDESK_AGENTS, true],
            ['agent1', PermissionService::GROUP_HELPDESK_ADMINS, false],
            ['agent1', PermissionService::GROUP_HELPDESK_CUSTOMERS, false],
        ]);
        $this->groupManager->method('isAdmin')->willReturn(false);

        self::assertTrue($this->permissionService->isAgent());
        self::assertFalse($this->permissionService->canAccessCustomerAdministration());
        self::assertFalse($this->permissionService->canAccessGuestUserAdministration());
        self::assertFalse($this->permissionService->canExportData());
    }

    public function testHelpdeskAdminCanAccessCustomerAndGuestAdministration(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['admin1', PermissionService::GROUP_HELPDESK_ADMINS, true],
        ]);
        $this->groupManager->method('isAdmin')->willReturn(false);

        self::assertTrue($this->permissionService->canAccessCustomerAdministration());
        self::assertTrue($this->permissionService->canAccessGuestUserAdministration());
        self::assertTrue($this->permissionService->canExportData());
    }

    private function mockProjectRoleQueryBuilder(string $role): IQueryBuilder
    {
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetch')->willReturn(['role' => $role]);
        $result->method('closeCursor')->willReturn(true);

        $qb = $this->createMock(IQueryBuilder::class);
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('1=1');

        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturn('proj_user');
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('executeQuery')->willReturn($result);

        return $qb;
    }

    private function mockProjectMembershipCountQueryBuilder(int $count): IQueryBuilder
    {
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetchOne')->willReturn($count);
        $result->method('fetch')->willReturn(false);
        $result->method('closeCursor')->willReturn(true);

        $qb = $this->createMock(IQueryBuilder::class);
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('1=1');

        $func = new class {
            public function count(string $column): string
            {
                return 'COUNT(' . $column . ')';
            }
        };

        $qb->method('func')->willReturn($func);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturn('proj_user');
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('executeQuery')->willReturn($result);

        return $qb;
    }

    public function testDualRoleGuestPlusAgentUsesGuestTicketScoping(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('dual1');
        $user->method('getEMailAddress')->willReturn('dual1@example.com');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->willReturnMap([
            ['dual1', PermissionService::GROUP_HELPDESK_CUSTOMERS, true],
            ['dual1', PermissionService::GROUP_HELPDESK_AGENTS, true],
            ['dual1', PermissionService::GROUP_HELPDESK_ADMINS, false],
        ]);
        $this->groupManager->method('isAdmin')->with('dual1')->willReturn(false);

        // Foreign ticket: agent would see it, guest must not.
        $foreign = $this->ticket('someone-else', 'other@example.com', 99);
        self::assertFalse($this->permissionService->canViewTicket($foreign));
        self::assertFalse($this->permissionService->canEditTicket($foreign));
        self::assertFalse($this->permissionService->canViewInternalNotes());

        // Own ticket still visible.
        $own = $this->ticket('dual1', 'dual1@example.com', null);
        self::assertTrue($this->permissionService->canViewTicket($own));

        // Dual-role must not manage KB (upload / draft image bypass).
        self::assertFalse($this->permissionService->canManageKnowledgeBase());
        self::assertFalse($this->permissionService->canDeleteTicket($own));
        self::assertFalse($this->permissionService->canManageSettings());
        self::assertFalse($this->permissionService->canExportData());
        self::assertFalse($this->permissionService->canAccessCustomerAdministration());
        self::assertFalse($this->permissionService->canAccessGuestUserAdministration());
        self::assertFalse($this->permissionService->canViewHelpdeskOverview('dual1'));
    }
}

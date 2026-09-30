<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\TicketController;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\ActivityEventService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\DeletionService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\MergeService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCA\Ticketcheck\Service\SplitService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWatcherService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TicketControllerAssignableUsersTest extends TestCase
{
    /** @var IRequest&\PHPUnit\Framework\MockObject\MockObject */
    private IRequest $request;
    /** @var PermissionService&\PHPUnit\Framework\MockObject\MockObject */
    private PermissionService $permissionService;
    /** @var TicketMapper&\PHPUnit\Framework\MockObject\MockObject */
    private TicketMapper $ticketMapper;
    /** @var TicketService&\PHPUnit\Framework\MockObject\MockObject */
    private TicketService $ticketService;
    /** @var ProjectService&\PHPUnit\Framework\MockObject\MockObject */
    private ProjectService $projectService;
    /** @var IGroupManager&\PHPUnit\Framework\MockObject\MockObject */
    private IGroupManager $groupManager;
    private TicketController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->ticketService = $this->createMock(TicketService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $mergeService = $this->createMock(MergeService::class);
        $emailService = $this->createMock(EmailService::class);
        $this->projectService = $this->createMock(ProjectService::class);
        $deletionService = $this->createMock(DeletionService::class);
        $this->ticketMapper = $this->createMock(TicketMapper::class);
        $attachmentMapper = $this->createMock(AttachmentMapper::class);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $userSession = $this->createMock(IUserSession::class);
        $safeFilenameService = $this->createMock(SafeFilenameService::class);
        $l10nFactory = $this->createMock(IFactory::class);
        $config = $this->createMock(IConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $userManager = $this->createMock(IUserManager::class);
        $logger = $this->createMock(LoggerInterface::class);
        $ticketLinkService = $this->createMock(TicketLinkService::class);
        $ticketWatcherService = $this->createMock(TicketWatcherService::class);
        $splitService = $this->createMock(SplitService::class);
        $activityEventService = $this->createMock(ActivityEventService::class);
        $attachmentUploadService = $this->createMock(AttachmentUploadService::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $key): string => $key);
        $l10nFactory->method('get')->with('ticketcheck')->willReturn($l10n);

        $this->permissionService->method('isAgent')->willReturn(true);
        $this->permissionService->method('isHelpdeskAdmin')->willReturn(false);
        $this->permissionService->method('getCurrentUserId')->willReturn('agent1');
        $this->permissionService->method('canViewHelpdeskOverview')->willReturn(true);

        $this->controller = new TicketController(
            'ticketcheck',
            $this->request,
            $this->ticketService,
            $this->permissionService,
            $mergeService,
            $emailService,
            $this->projectService,
            $deletionService,
            $this->ticketMapper,
            $attachmentMapper,
            $urlGenerator,
            $userSession,
            $safeFilenameService,
            $l10nFactory,
            $config,
            $this->groupManager,
            $userManager,
            $logger,
            $ticketLinkService,
            $ticketWatcherService,
            $splitService,
            $activityEventService,
            $attachmentUploadService,
            $this->createMock(LocaleFormatService::class),
            $this->createMock(NavigationContextService::class),
            $this->createMock(FrontEndAssetService::class),
            new AttachmentDeliveryService($this->createMock(IConfig::class), new SafeFilenameService()),
        );
    }

    public function testSearchAssignableUsersReturnsForbiddenForGuests(): void
    {
        $this->permissionService->expects(self::atLeastOnce())->method('isGuest')->willReturn(true);

        $response = $this->controller->searchAssignableUsers(5);
        $data = $response->getData();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(403, $response->getStatus());
        self::assertFalse($data['success']);
    }

    public function testSearchAssignableUsersFiltersOutCustomerGroupAndAppliesQuery(): void
    {
        $this->permissionService->method('isGuest')->willReturn(false);
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'query' => 'project',
                    'limit' => 25,
                    default => $default,
                };
            }
        );

        $ticket = new Ticket();
        $ticket->setId(5);
        $ticket->setProjectId(42);
        $this->ticketService->method('getActiveTicket')->with(5)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(true);
        $this->permissionService->method('canEditTicket')->with($ticket)->willReturn(true);

        $this->projectService->method('getProjectMembers')->with(42)->willReturn([
            ['user_id' => 'team_a', 'user_name' => 'Project Alice'],
            ['user_id' => 'guest_like', 'user_name' => 'Guest Like'],
        ]);

        $this->groupManager->method('isInGroup')->willReturnCallback(
            static function (string $userId, string $group): bool {
                if ($group !== PermissionService::GROUP_HELPDESK_CUSTOMERS) {
                    return false;
                }
                return $userId === 'guest_like';
            }
        );

        $adminsGroup = $this->createMock(IGroup::class);
        $adminUser = $this->createConfiguredMock(IUser::class, [
            'getUID' => 'admin_john',
            'getDisplayName' => 'Admin John',
        ]);
        $adminsGroup->method('getUsers')->willReturn([$adminUser]);

        $agentsGroup = $this->createMock(IGroup::class);
        $agentUser = $this->createConfiguredMock(IUser::class, [
            'getUID' => 'agent_mark',
            'getDisplayName' => 'Agent Mark',
        ]);
        $agentsGroup->method('getUsers')->willReturn([$agentUser]);

        $this->groupManager->method('get')->willReturnCallback(
            static fn(string $groupName): ?IGroup => match ($groupName) {
                'helpdesk_admins' => $adminsGroup,
                'helpdesk_agents' => $agentsGroup,
                default => null,
            }
        );

        $response = $this->controller->searchAssignableUsers(5);
        $data = $response->getData();

        self::assertSame(200, $response->getStatus());
        self::assertTrue($data['success']);
        self::assertCount(1, $data['users'], 'Only project-matching non-customer users should remain');
        self::assertSame('team_a', $data['users'][0]['user_id']);
        self::assertTrue($data['users'][0]['is_project_member']);
    }
}

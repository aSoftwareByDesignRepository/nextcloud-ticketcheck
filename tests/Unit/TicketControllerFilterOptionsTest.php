<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\TicketController;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\ActivityEventService;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\DeletionService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\MergeService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCA\Ticketcheck\Service\SplitService;
use OCA\Ticketcheck\Service\TicketLinkService;
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

class TicketControllerFilterOptionsTest extends TestCase
{
    /** @var IRequest&\PHPUnit\Framework\MockObject\MockObject */
    private IRequest $request;
    /** @var PermissionService&\PHPUnit\Framework\MockObject\MockObject */
    private PermissionService $permissionService;
    /** @var ProjectService&\PHPUnit\Framework\MockObject\MockObject */
    private ProjectService $projectService;
    /** @var IGroupManager&\PHPUnit\Framework\MockObject\MockObject */
    private IGroupManager $groupManager;
    private TicketController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $ticketService = $this->createMock(TicketService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $mergeService = $this->createMock(MergeService::class);
        $this->projectService = $this->createMock(ProjectService::class);
        $deletionService = $this->createMock(DeletionService::class);
        $ticketMapper = $this->createMock(TicketMapper::class);
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

        $this->permissionService->method('getCurrentUserId')->willReturn('agent1');

        $this->controller = new TicketController(
            'ticketcheck',
            $this->request,
            $ticketService,
            $this->permissionService,
            $mergeService,
            $this->projectService,
            $deletionService,
            $ticketMapper,
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

    private function stubParams(array $params): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, mixed $default = null) use ($params): mixed {
                return $params[$key] ?? $default;
            }
        );
    }

    private function stubAgentRoster(): void
    {
        $adminsGroup = $this->createMock(IGroup::class);
        $adminsGroup->method('getUsers')->willReturn([
            $this->createConfiguredMock(IUser::class, [
                'getUID' => 'admin_john',
                'getDisplayName' => 'Admin John',
            ]),
        ]);
        $agentsGroup = $this->createMock(IGroup::class);
        $agentsGroup->method('getUsers')->willReturn([
            $this->createConfiguredMock(IUser::class, [
                'getUID' => 'agent_mark',
                'getDisplayName' => 'Agent Mark',
            ]),
            $this->createConfiguredMock(IUser::class, [
                'getUID' => 'customer_jane',
                'getDisplayName' => 'Customer Jane',
            ]),
        ]);
        $this->groupManager->method('get')->willReturnCallback(
            static fn(string $groupName): ?IGroup => match ($groupName) {
                'helpdesk_admins' => $adminsGroup,
                'helpdesk_agents' => $agentsGroup,
                default => null,
            }
        );
        $this->groupManager->method('isInGroup')->willReturnCallback(
            static fn(string $userId, string $group): bool =>
                $group === PermissionService::GROUP_HELPDESK_CUSTOMERS && $userId === 'customer_jane'
        );
    }

    public function testAssigneeOptionsForbiddenForGuests(): void
    {
        $this->permissionService->method('isGuest')->willReturn(true);
        $this->stubParams(['type' => 'assignee']);

        $response = $this->controller->searchFilterOptions();

        self::assertSame(403, $response->getStatus());
        self::assertFalse($response->getData()['success']);
    }

    public function testAssigneeOptionsForbiddenForNonStaff(): void
    {
        $this->permissionService->method('isGuest')->willReturn(false);
        $this->permissionService->method('canViewHelpdeskOverview')->willReturn(false);
        $this->stubParams(['type' => 'assignee']);

        $response = $this->controller->searchFilterOptions();

        self::assertSame(403, $response->getStatus());
        self::assertFalse($response->getData()['success']);
    }

    public function testAssigneeOptionsMapRosterAndExcludeCustomers(): void
    {
        $this->permissionService->method('isGuest')->willReturn(false);
        $this->permissionService->method('canViewHelpdeskOverview')->willReturn(true);
        $this->stubAgentRoster();
        $this->stubParams(['type' => 'assignee', 'query' => '', 'limit' => 25]);

        $response = $this->controller->searchFilterOptions();
        $data = $response->getData();

        self::assertSame(200, $response->getStatus());
        self::assertTrue($data['success']);
        self::assertSame('assignee', $data['type']);
        $values = array_column($data['options'], 'value');
        sort($values);
        self::assertSame(['admin_john', 'agent_mark'], $values, 'customer-group members must not appear');
        foreach ($data['options'] as $option) {
            self::assertArrayHasKey('value', $option);
            self::assertArrayHasKey('label', $option);
            self::assertNotSame('', $option['label']);
        }
    }

    public function testAssigneeOptionsApplyQueryFilter(): void
    {
        $this->permissionService->method('isGuest')->willReturn(false);
        $this->permissionService->method('canViewHelpdeskOverview')->willReturn(true);
        $this->stubAgentRoster();
        $this->stubParams(['type' => 'assignee', 'query' => 'mark', 'limit' => 25]);

        $data = $this->controller->searchFilterOptions()->getData();

        self::assertTrue($data['success']);
        self::assertSame([['value' => 'agent_mark', 'label' => 'Agent Mark']], $data['options']);
    }

    public function testAssigneeOptionsScopedByProject(): void
    {
        $this->permissionService->method('isGuest')->willReturn(false);
        $this->permissionService->method('canViewHelpdeskOverview')->willReturn(true);
        $this->projectService->expects(self::once())
            ->method('getProjectMembers')
            ->with(42)
            ->willReturn([
                ['user_id' => 'team_a', 'user_name' => 'Project Alice'],
            ]);
        $this->groupManager->method('get')->willReturn(null);
        $this->groupManager->method('isInGroup')->willReturn(false);
        $this->stubParams(['type' => 'assignee', 'query' => '', 'limit' => 25, 'project_id' => 42]);

        $data = $this->controller->searchFilterOptions()->getData();

        self::assertTrue($data['success']);
        self::assertCount(1, $data['options']);
        self::assertSame('team_a', $data['options'][0]['value']);
        self::assertSame('Project Alice (Project Team)', $data['options'][0]['label']);
    }

    public function testUnknownTypeReturns400(): void
    {
        $this->permissionService->method('isGuest')->willReturn(false);
        $this->permissionService->method('canViewHelpdeskOverview')->willReturn(true);
        $this->stubParams(['type' => 'bogus']);

        $response = $this->controller->searchFilterOptions();

        self::assertSame(400, $response->getStatus());
        self::assertFalse($response->getData()['success']);
    }
}

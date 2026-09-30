<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class NavigationContextServiceTest extends TestCase
{
    private NavigationContextService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $request = $this->createMock(IRequest::class);
        $request->method('getPathInfo')->willReturn('/apps/ticketcheck/tickets/kanban');

        $perm = $this->createMock(PermissionService::class);
        $this->service = $this->createService($perm, $request);
    }

    public function testResolvePageIdFromKanbanPath(): void
    {
        $this->assertSame('tickets-kanban', $this->service->resolvePageIdFromPath());
    }

    public function testResolvePageIdFromTicketDetailPath(): void
    {
        $this->assertSame('ticket-detail', $this->service->resolvePageIdFromPath('/apps/ticketcheck/tickets/99'));
    }

    public function testResolvePageIdFromPortalRateLimitPath(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getPathInfo')->willReturn('/apps/ticketcheck/portal/rate-limit');

        $perm = $this->createMock(PermissionService::class);
        $service = $this->createService($perm, $request);
        $this->assertSame('rate-limit', $service->resolvePageIdFromPath());
    }

    public function testResolvePageIdFromPortalCreatePath(): void
    {
        $this->assertSame('portal-create', $this->service->resolvePageIdFromPath('/apps/ticketcheck/portal/tickets/create'));
    }

    public function testBuildNavigationForAgentOmitsRestrictedDirectoryAndAdmin(): void
    {
        $service = $this->createNavigationServiceForRoles(isAgent: true, isHelpdeskAdmin: false);
        $ids = $this->collectNavigationItemIds($service->buildNavigation('tickets', 'staff', $this->mockL10n(), $this->mockUrlGenerator()));

        $this->assertContains('projects', $ids);
        $this->assertNotContains('customers', $ids);
        $this->assertNotContains('guests', $ids);
        $this->assertNotContains('settings', $ids);
    }

    public function testBuildNavigationForHelpdeskAdminIncludesDirectoryAndSettings(): void
    {
        $service = $this->createNavigationServiceForRoles(isAgent: false, isHelpdeskAdmin: true);
        $ids = $this->collectNavigationItemIds($service->buildNavigation('tickets', 'staff', $this->mockL10n(), $this->mockUrlGenerator()));

        $this->assertContains('customers', $ids);
        $this->assertContains('guests', $ids);
        $this->assertContains('export', $ids);
        $this->assertContains('settings', $ids);
    }

    public function testBuildNavigationForErrorPageShowsMinimalStaffRecovery(): void
    {
        $service = $this->createNavigationServiceForRoles(isAgent: true, isHelpdeskAdmin: false);
        $nav = $service->buildNavigation('error', 'staff', $this->mockL10n(), $this->mockUrlGenerator());
        $ids = $this->collectNavigationItemIds($nav);

        $this->assertSame(['dashboard'], $ids);
        $this->assertCount(1, $nav);
    }

    public function testBuildNavigationForErrorPageShowsMinimalGuestRecovery(): void
    {
        $perm = $this->createMock(PermissionService::class);
        $perm->method('isKnowledgeBaseIndexAvailable')->willReturn(false);
        $request = $this->createMock(IRequest::class);
        $request->method('getPathInfo')->willReturn('/apps/ticketcheck/error');
        $service = $this->createService($perm, $request);

        $ids = $this->collectNavigationItemIds(
            $service->buildNavigation('error', 'guest', $this->mockL10n(), $this->mockUrlGenerator())
        );

        $this->assertSame(['portal-home'], $ids);
    }

    public function testBuildSidebarFooterStatsForStaffAgent(): void
    {
        $perm = $this->createMock(PermissionService::class);
        $perm->method('getCurrentUserId')->willReturn('agent1');
        $perm->method('canViewHelpdeskOverview')->with('agent1')->willReturn(true);

        $mapper = $this->createMock(TicketMapper::class);
        $mapper->method('countAllTickets')->willReturn(42);
        $mapper->method('countOpenAllTickets')->willReturn(7);

        $service = $this->createService($perm, $this->createMock(IRequest::class), $mapper);
        $this->assertSame(['total' => 42, 'open' => 7], $service->buildSidebarFooterStats('staff'));
    }

    public function testBuildSidebarFooterStatsForGuestUsesGuestScope(): void
    {
        $perm = $this->createMock(PermissionService::class);
        $perm->method('isGuest')->willReturn(true);
        $perm->method('getCurrentUserId')->willReturn('guest1');
        $perm->method('getCurrentUserEmail')->willReturn('guest@example.com');
        $perm->method('getAccessibleProjectIds')->willReturn([10, 11]);

        $mapper = $this->createMock(TicketMapper::class);
        $mapper->expects(self::exactly(2))
            ->method('countGuestScope')
            ->willReturnCallback(function (string $uid, string $email, array $projectIds, bool $openOnly): int {
                TestCase::assertSame('guest1', $uid);
                TestCase::assertSame('guest@example.com', $email);
                TestCase::assertSame([10, 11], $projectIds);
                return $openOnly ? 1 : 3;
            });
        $mapper->expects(self::never())->method('countByCustomerEmail');
        $mapper->expects(self::never())->method('countOpenByCustomerEmail');

        $service = $this->createService($perm, $this->createMock(IRequest::class), $mapper);
        $this->assertSame(['total' => 3, 'open' => 1], $service->buildSidebarFooterStats('guest'));
    }

    public function testBuildSidebarFooterStatsForGuestWithoutUidReturnsNull(): void
    {
        $perm = $this->createMock(PermissionService::class);
        $perm->method('isGuest')->willReturn(true);
        $perm->method('getCurrentUserId')->willReturn(null);

        $mapper = $this->createMock(TicketMapper::class);
        $mapper->expects(self::never())->method('countGuestScope');

        $service = $this->createService($perm, $this->createMock(IRequest::class), $mapper);
        $this->assertNull($service->buildSidebarFooterStats('guest'));
    }

    public function testBuildScopeContextForGuestAddsOrganizationFromAccessibleProjects(): void
    {
        $perm = $this->createMock(PermissionService::class);
        $perm->method('isGuest')->willReturn(true);
        $perm->method('isHelpdeskAdmin')->willReturn(false);
        $perm->method('isAgent')->willReturn(false);
        $perm->method('getAccessibleProjectIds')->willReturn([10, 11]);

        $projectService = $this->createMock(ProjectService::class);
        $projectService->method('getProject')->willReturnMap([
            [10, ['customer_name' => 'Acme Corp']],
            [11, ['customer_name' => 'Acme Corp']],
        ]);

        $service = $this->createService($perm, $this->createMock(IRequest::class), null, $projectService);
        $scope = $service->buildScopeContext([], 'guest', $this->mockL10n());

        $this->assertSame('Acme Corp', $scope['organizationName']);
        $this->assertSame('scope_role_guest_portal', $scope['roleLabel']);
    }

    public function testGetActiveAccessibleProjectsForGuestUsesPerProjectLookup(): void
    {
        $perm = $this->createMock(PermissionService::class);
        $perm->method('isGuest')->willReturn(true);
        $perm->method('getAccessibleProjectIds')->willReturn([5001]);

        $projectService = $this->createMock(ProjectService::class);
        $projectService->expects(self::never())->method('getAllProjects');
        $projectService->method('getProject')->with(5001)->willReturn(['id' => 5001, 'name' => 'Edge', 'active' => 1]);

        $service = $this->createService($perm, $this->createMock(IRequest::class), null, $projectService);
        $projects = $service->getActiveAccessibleProjectsForGuest();

        $this->assertCount(1, $projects);
        $this->assertSame(5001, $projects[0]['id']);
    }

    public function testBuildGuestNavigationNeverIncludesCreateTicketInSidebar(): void
    {
        $perm = $this->createMock(PermissionService::class);
        $perm->method('isGuest')->willReturn(true);
        $perm->method('getAccessibleProjectIds')->willReturn([99]);
        $perm->method('isKnowledgeBasePortalContentEnabled')->willReturn(false);

        $projectService = $this->createMock(ProjectService::class);
        $projectService->method('getProject')->with(99)->willReturn(['id' => 99, 'active' => 0]);

        $service = $this->createService($perm, $this->createMock(IRequest::class), null, $projectService);
        $this->assertFalse($service->canGuestCreateTicket());

        $ids = $this->collectNavigationItemIds(
            $service->buildNavigation('portal-home', 'guest', $this->mockL10n(), $this->mockUrlGenerator())
        );
        $this->assertNotContains('portal-create', $ids);
        $this->assertContains('portal-home', $ids);
    }

    public function testBuildGuestNavigationOmitsCreateTicketEvenWhenGuestCanCreate(): void
    {
        $perm = $this->createMock(PermissionService::class);
        $perm->method('isGuest')->willReturn(true);
        $perm->method('getAccessibleProjectIds')->willReturn([42]);
        $perm->method('isKnowledgeBasePortalContentEnabled')->willReturn(false);

        $projectService = $this->createMock(ProjectService::class);
        $projectService->method('getProject')->with(42)->willReturn(['id' => 42, 'active' => 1]);

        $service = $this->createService($perm, $this->createMock(IRequest::class), null, $projectService);
        $this->assertTrue($service->canGuestCreateTicket());

        $ids = $this->collectNavigationItemIds(
            $service->buildNavigation('portal-home', 'guest', $this->mockL10n(), $this->mockUrlGenerator())
        );
        $this->assertNotContains('portal-create', $ids);
        $this->assertContains('portal-tickets', $ids);
    }

    public function testBuildScopeContextForGuestSkipsOrganizationWhenPageProvidesProject(): void
    {
        $perm = $this->createMock(PermissionService::class);
        $perm->method('isGuest')->willReturn(true);
        $perm->method('isHelpdeskAdmin')->willReturn(false);
        $perm->method('isAgent')->willReturn(false);

        $projectService = $this->createMock(ProjectService::class);
        $projectService->expects(self::never())->method('getProject');

        $service = $this->createService($perm, $this->createMock(IRequest::class), null, $projectService);
        $scope = $service->buildScopeContext(['projectName' => 'Support Portal'], 'guest', $this->mockL10n());

        $this->assertSame('Support Portal', $scope['projectName']);
        $this->assertSame('', $scope['organizationName']);
    }

    public function testBuildDefaultHeaderActionsForAdminOnDirectoryIndexPages(): void
    {
        $service = $this->createNavigationServiceForRoles(isAgent: false, isHelpdeskAdmin: true);
        $l = $this->mockL10n();
        $urlGenerator = $this->mockUrlGenerator();

        $customersHtml = $service->buildDefaultHeaderActions('customers', $l, $urlGenerator);
        $projectsHtml = $service->buildDefaultHeaderActions('projects', $l, $urlGenerator);
        $guestsHtml = $service->buildDefaultHeaderActions('guests', $l, $urlGenerator);
        $kbHtml = $service->buildDefaultHeaderActions('kb', $l, $urlGenerator);
        $kbSearchHtml = $service->buildDefaultHeaderActions('kb-search', $l, $urlGenerator);

        $this->assertStringContainsString('create_new_customer', $customersHtml);
        $this->assertStringContainsString('create_new_project', $projectsHtml);
        $this->assertStringContainsString('invite_guest_user', $guestsHtml);
        $this->assertStringContainsString('create_article', $kbHtml);
        $this->assertStringContainsString('create_article', $kbSearchHtml);
        foreach ([$customersHtml, $projectsHtml, $guestsHtml, $kbHtml, $kbSearchHtml] as $html) {
            $this->assertStringContainsString('helpdesk-btn--primary', $html);
            $this->assertStringContainsString('href="/mock"', $html);
        }
    }

    public function testBuildDefaultHeaderActionsForAgentOmitsAdminOnlyCreateButtons(): void
    {
        $service = $this->createNavigationServiceForRoles(isAgent: true, isHelpdeskAdmin: false);
        $l = $this->mockL10n();
        $urlGenerator = $this->mockUrlGenerator();

        $this->assertSame('', $service->buildDefaultHeaderActions('customers', $l, $urlGenerator));
        $this->assertSame('', $service->buildDefaultHeaderActions('projects', $l, $urlGenerator));
        $this->assertSame('', $service->buildDefaultHeaderActions('guests', $l, $urlGenerator));

        $kbHtml = $service->buildDefaultHeaderActions('kb', $l, $urlGenerator);
        $this->assertStringContainsString('create_article', $kbHtml);
        $this->assertStringContainsString('helpdesk-btn--primary', $kbHtml);
    }

    public function testBuildDefaultHeaderActionsEscapesUrlAndLabel(): void
    {
        $perm = $this->createMock(PermissionService::class);
        $perm->method('isHelpdeskAdmin')->willReturn(true);
        $perm->method('canAccessGuestUserAdministration')->willReturn(true);
        $service = $this->createService($perm, $this->createMock(IRequest::class));

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturn('Invite "guest" <user>');
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRoute')->willReturn('/mock?a=1&b="x"');
        $urlGenerator->method('linkToDefaultPageUrl')->willReturn('/home');

        $html = $service->buildDefaultHeaderActions('guests', $l, $urlGenerator);

        $this->assertStringContainsString('href="/mock?a=1&amp;b=&quot;x&quot;"', $html);
        $this->assertStringContainsString('Invite &quot;guest&quot; &lt;user&gt;', $html);
        $this->assertStringNotContainsString('<user>', $html);
    }

	public function testBuildDefaultHeaderActionsOmitsCreateArticleOnArticleDetail(): void
	{
		$service = $this->createNavigationServiceForRoles(isAgent: false, isHelpdeskAdmin: true);
		$l = $this->mockL10n();
		$urlGenerator = $this->mockUrlGenerator();

		$this->assertSame('', $service->buildDefaultHeaderActions('kb-article', $l, $urlGenerator));
	}

	public function testBuildScopeContextDualRoleGuestWinsOverAdminBadge(): void
	{
		$perm = $this->createMock(PermissionService::class);
		$perm->method('isGuest')->willReturn(true);
		$perm->method('isHelpdeskAdmin')->willReturn(true);
		$perm->method('isAgent')->willReturn(true);
		$perm->method('getAccessibleProjectIds')->willReturn([]);

		$service = $this->createService($perm, $this->createMock(IRequest::class));
		$scope = $service->buildScopeContext([], 'guest', $this->mockL10n());

		$this->assertSame('scope_role_guest_portal', $scope['roleLabel']);
		$this->assertSame('guest', $scope['roleBadge']);
	}

	public function testBuildStaffNavigationEmptyForDualRoleGuest(): void
	{
		$service = $this->createNavigationServiceForRoles(isAgent: true, isHelpdeskAdmin: true, isGuest: true);
		$navigation = $service->buildNavigation('tickets', 'staff', $this->mockL10n(), $this->mockUrlGenerator());

		$this->assertSame([], $navigation);
	}

	public function testBuildDefaultHeaderActionsOmitsProjectCreateForDualRoleGuestAdmin(): void
	{
		$service = $this->createNavigationServiceForRoles(isAgent: false, isHelpdeskAdmin: true, isGuest: true);
		$this->assertSame('', $service->buildDefaultHeaderActions('projects', $this->mockL10n(), $this->mockUrlGenerator()));
	}

	private function createService(
        PermissionService $perm,
        IRequest $request,
        ?TicketMapper $ticketMapper = null,
        ?ProjectService $projectService = null,
    ): NavigationContextService {
        $ticketMapper ??= $this->createMock(TicketMapper::class);
        $projectService ??= $this->createMock(ProjectService::class);
        return new NavigationContextService($perm, $request, $ticketMapper, $projectService);
    }

    private function createNavigationServiceForRoles(bool $isAgent, bool $isHelpdeskAdmin, bool $isGuest = false): NavigationContextService
    {
        $perm = $this->createMock(PermissionService::class);
        $perm->method('isGuest')->willReturn($isGuest);
        $perm->method('isHelpdeskAdmin')->willReturn($isHelpdeskAdmin);
        $perm->method('isAgent')->willReturn($isAgent);
        $perm->method('getCurrentUserId')->willReturn('user1');
        $perm->method('canViewHelpdeskOverview')->willReturn(!$isGuest && ($isAgent || $isHelpdeskAdmin));
        $perm->method('canManageSettings')->willReturn(!$isGuest && $isHelpdeskAdmin);
        $perm->method('canAccessCustomerAdministration')->willReturn(!$isGuest && $isHelpdeskAdmin);
        $perm->method('canAccessGuestUserAdministration')->willReturn(!$isGuest && $isHelpdeskAdmin);
        $perm->method('canManageKnowledgeBase')->willReturn(!$isGuest && ($isHelpdeskAdmin || $isAgent));
        $perm->method('isKnowledgeBaseIndexAvailable')->willReturn(false);

        $request = $this->createMock(IRequest::class);
        $request->method('getPathInfo')->willReturn('/apps/ticketcheck/tickets');

        return $this->createService($perm, $request);
    }

    /**
     * @param list<array{group:string,items:list<array{id:string}>}> $navigation
     * @return list<string>
     */
    private function collectNavigationItemIds(array $navigation): array
    {
        $ids = [];
        foreach ($navigation as $group) {
            foreach ($group['items'] as $item) {
                $ids[] = $item['id'];
            }
        }
        return $ids;
    }

    private function mockL10n(): IL10N
    {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);
        return $l;
    }

    private function mockUrlGenerator(): IURLGenerator
    {
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRoute')->willReturn('/mock');
        $urlGenerator->method('linkToDefaultPageUrl')->willReturn('/home');
        return $urlGenerator;
    }
}

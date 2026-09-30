<?php

declare(strict_types=1);

/**
 * Dashboard controller for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Service\ButtonHtml;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\DatabaseSetupService;
use OCA\Ticketcheck\Db\DbQueryGuard;
use OCA\Ticketcheck\Db\TicketMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IDBConnection;
use OCP\IDateTimeFormatter;
use OCP\L10N\IFactory;
use OCP\IUserSession;

/**
 * Controller for dashboard and statistics
 */
class DashboardController extends Controller
{
    use CSPTrait;
    use PageRenderTrait;

    private PermissionService $permissionService;
    private DatabaseSetupService $databaseSetupService;
    private TicketMapper $ticketMapper;
    private IURLGenerator $urlGenerator;
    private IDBConnection $db;
    private IFactory $l10nFactory;
    private IUserSession $userSession;
    private IDateTimeFormatter $dateTimeFormatter;
    private LocaleFormatService $localeFormat;
    private NavigationContextService $navigationContext;
    private FrontEndAssetService $frontEndAssets;

    public function __construct(
        string $appName,
        IRequest $request,
        PermissionService $permissionService,
        DatabaseSetupService $databaseSetupService,
        TicketMapper $ticketMapper,
        IURLGenerator $urlGenerator,
        IDBConnection $db,
        IFactory $l10nFactory,
        IUserSession $userSession,
        IDateTimeFormatter $dateTimeFormatter,
        LocaleFormatService $localeFormat,
        NavigationContextService $navigationContext,
        FrontEndAssetService $frontEndAssets,
    ) {
        parent::__construct($appName, $request);
        $this->permissionService = $permissionService;
        $this->databaseSetupService = $databaseSetupService;
        $this->ticketMapper = $ticketMapper;
        $this->urlGenerator = $urlGenerator;
        $this->db = $db;
        $this->l10nFactory = $l10nFactory;
        $this->userSession = $userSession;
        $this->dateTimeFormatter = $dateTimeFormatter;
        $this->localeFormat = $localeFormat;
        $this->navigationContext = $navigationContext;
        $this->frontEndAssets = $frontEndAssets;
    }

    protected function getFrontEndAssetService(): FrontEndAssetService
    {
        return $this->frontEndAssets;
    }

    protected function getPermissionService(): PermissionService
    {
        return $this->permissionService;
    }

    protected function getUrlGenerator(): IURLGenerator
    {
        return $this->urlGenerator;
    }

    protected function getLocaleFormatService(): LocaleFormatService
    {
        return $this->localeFormat;
    }

    protected function getPageL10n(): \OCP\IL10N
    {
        return $this->l10nFactory->get($this->appName);
    }

    protected function getNavigationContextService(): NavigationContextService
    {
        return $this->navigationContext;
    }

    /**
     * Show dashboard (agents and admins only)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse|RedirectResponse
    {
        if ($this->permissionService->needsRoleEnrollment()) {
            return new RedirectResponse($this->urlGenerator->linkToRoute('ticketcheck.page.needsRole'));
        }

        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))) {
            $l = $this->l10nFactory->get('ticketcheck');
            $denied = new TemplateResponse($this->appName, 'error', [
                'message' => $l->t('access_denied_dashboard_agent_required'),
                'error' => $l->t('access_denied'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $denied->setStatus(Http::STATUS_FORBIDDEN);
            return $denied;
        }

        // Check if database tables exist, log status for debugging
        $this->databaseSetupService->logDatabaseStatus();

        // Get basic statistics
        $stats = $this->ticketMapper->getStatistics();
        $byStatus = $stats['by_status'] ?? [];
        $stats['completed_total'] = (int)($byStatus['done'] ?? 0)
            + (int)($byStatus['resolved'] ?? 0)
            + (int)($byStatus['closed'] ?? 0);

        // Get current user
        $userId = $this->permissionService->getCurrentUserId();

        // Add my tickets stats (exclude done/resolved/closed so dashboard shows only active workload)
        $myTickets = $this->ticketMapper->findByAssignedTo($userId, 100, true);
        $stats['my_tickets'] = count($myTickets);
        $stats['my_urgent'] = 0;
        $stats['my_high'] = 0;
        foreach ($myTickets as $ticket) {
            if ($ticket->getPriority() === 'urgent') $stats['my_urgent']++;
            if ($ticket->getPriority() === 'high') $stats['my_high']++;
        }

        // Dashboard alerts: counts must match ticket list filters (see TicketMapper::ASSIGNED_TO_UNASSIGNED).
        $stats['new_unassigned'] = $this->ticketMapper->countNewUnassigned();
        $stats['unassigned_active'] = $this->ticketMapper->countActiveUnassigned();
        $stats['unassigned_in_progress'] = $this->ticketMapper->countActiveUnassignedExcludingStatusNew();
        // Backward compatibility for templates/tests that still read `unassigned`.
        $stats['unassigned'] = $stats['unassigned_active'];

        // Add project count (with error handling for missing tables)
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from('helpdesk_projects');
            $result = $qb->executeQuery();
            $stats['projects'] = (int)$result->fetchOne();
            $result->closeCursor();
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                $stats['projects'] = 0;
            } else {
                throw $e;
            }
        }

        // Add customer count (with error handling for missing tables)
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from('helpdesk_customers');
            $result = $qb->executeQuery();
            $stats['customers'] = (int)$result->fetchOne();
            $result->closeCursor();
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                $stats['customers'] = 0;
            } else {
                throw $e;
            }
        }

        // Get recent tickets (last 10, exclude done so list shows actionable activity)
        $recentTickets = $this->ticketMapper->findAll(10, null, true);

        // Get system analytics for performance insights
        $systemAnalytics = $this->ticketMapper->getSystemAnalytics();

        $l = $this->l10nFactory->get('ticketcheck');
        $dashboardDateLong = $this->localeFormat->formatDate(date('Y-m-d'), 'long', $l);
        $pageHelp = $l->t('welcome_back') . ' • ' . $dashboardDateLong;

        $hasCustomers = ((int)($stats['customers'] ?? 0)) > 0;
        $hasProjects = ((int)($stats['projects'] ?? 0)) > 0;
        $pageHeaderActionsHtml = '';
        if ($hasCustomers && $hasProjects) {
            $pageHeaderActionsHtml = ButtonHtml::link(
                $this->urlGenerator->linkToRoute('ticketcheck.ticket.create'),
                $l->t('create_new_ticket'),
                ButtonHtml::VARIANT_PRIMARY,
            );
        }

        $response = $this->renderAppPage(
            'dashboard',
            [
                'stats' => $stats,
                'recentTickets' => $recentTickets,
                'systemAnalytics' => $systemAnalytics,
                'isAgent' => !$this->permissionService->isGuest() && $this->permissionService->isAgent(),
                'isAdmin' => $this->permissionService->canManageSettings(),
                'currentUser' => $this->userSession->getUser(),
                'dashboardDateLong' => $dashboardDateLong,
                'dateTimeFormatter' => $this->dateTimeFormatter,
                'pageHeaderActionsHtml' => $pageHeaderActionsHtml,
            ],
            'dashboard',
            $l->t('dashboard'),
            $pageHelp,
            'dashboard',
            'staff',
            ['contextLine' => $l->t('scope_all_projects')],
        );

        return $this->configureCSPWithNonce($response, 'main');
    }

    /**
     * Get dashboard statistics (API, agents/admins only)
     */
    #[NoAdminRequired]
    public function getStats(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }
        $stats = $this->ticketMapper->getStatistics();
        return new JSONResponse($stats);
    }

    /**
     * Get customer analytics (API, agents/admins only)
     *
     * @param int $customerId
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function getCustomerAnalytics(int $customerId): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }
        $analytics = $this->ticketMapper->getCustomerAnalytics($customerId);
        return new JSONResponse($analytics);
    }

    /**
     * Get project analytics (API, agents/admins only)
     *
     * @param int $projectId
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function getProjectAnalytics(int $projectId): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }
        $analytics = $this->ticketMapper->getProjectAnalytics($projectId);
        return new JSONResponse($analytics);
    }

    /**
     * Get system analytics (API, agents/admins only)
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function getSystemAnalytics(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }
        $analytics = $this->ticketMapper->getSystemAnalytics();
        return new JSONResponse($analytics);
    }
}

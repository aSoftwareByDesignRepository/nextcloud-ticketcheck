<?php

declare(strict_types=1);

/**
 * Customer portal controller (guest-facing TicketCheck routes under /portal/*).
 *
 * **Rendering:** Actions return `TemplateResponse(..., 'guest')`, which wraps body HTML in
 * `templates/layout.guest.php`. That layout sets `#content` to `app-ticketcheck` (Nextcloud app id).
 * In-app staff pages use the default user layout with `PageRenderTrait` and `page-start.php` instead.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCA\Ticketcheck\Service\GuestLayoutParamsProvider;
use OCA\Ticketcheck\Service\GuestPasswordPolicyService;
use OCA\Ticketcheck\Service\GuestPortalPageService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\HtmlSanitizerService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCA\Ticketcheck\Service\SurveyService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\AttachmentDisplayHelper;
use OCA\Ticketcheck\Service\KbSearchTextHelper;
use OCA\Ticketcheck\Service\PortalTicketDisplay;
use OCA\Ticketcheck\Service\PortalTicketListFilterService;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Db\KBArticleMapper;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Theming\Service\ThemesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\Util;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Controller for customer portal operations
 */
class CustomerPortalController extends Controller
{
    use CSPTrait;
    private TicketService $ticketService;
    private PermissionService $permissionService;
    private ProjectService $projectService;
    private EmailService $emailService;
    private TicketMapper $ticketMapper;
    private KBArticleMapper $kbArticleMapper;
    private AttachmentMapper $attachmentMapper;
    private IURLGenerator $urlGenerator;
    private IUserSession $userSession;
    private IGroupManager $groupManager;
    private IUserManager $userManager;
    /** @phpstan-ignore-next-line */
    private ThemesService $themesService;
    private \OCP\ISession $session;
    private EmailPreferencesService $emailPreferences;
    private SafeFilenameService $safeFilenameService;
    private IConfig $config;
    private IFactory $l10nFactory;
    private HtmlSanitizerService $htmlSanitizerService;
    private IMailer $mailer;
    private LoggerInterface $logger;
    private GuestLayoutParamsProvider $guestLayoutParamsProvider;
    private GuestPortalPageService $portalPageService;
    private SurveyService $surveyService;
    private TicketLinkService $ticketLinkService;
    private AttachmentUploadService $attachmentUploadService;
    private NavigationContextService $navigationContext;
    private GuestPasswordPolicyService $guestPasswordPolicy;
    private PortalTicketListFilterService $portalTicketListFilter;
    private AttachmentDeliveryService $attachmentDelivery;

    /** Maximum tickets loaded for guest list pages (email-scoped). */
    private const PORTAL_TICKET_LIST_LIMIT = 500;

    public function __construct(
        string $appName,
        IRequest $request,
        TicketService $ticketService,
        PermissionService $permissionService,
        ProjectService $projectService,
        EmailService $emailService,
        TicketMapper $ticketMapper,
        KBArticleMapper $kbArticleMapper,
        AttachmentMapper $attachmentMapper,
        IURLGenerator $urlGenerator,
        IUserSession $userSession,
        IGroupManager $groupManager,
        IUserManager $userManager,
        ThemesService $themesService,
        \OCP\ISession $session,
        EmailPreferencesService $emailPreferences,
        SafeFilenameService $safeFilenameService,
        IConfig $config,
        IFactory $l10nFactory,
        HtmlSanitizerService $htmlSanitizerService,
        IMailer $mailer,
        LoggerInterface $logger,
        GuestLayoutParamsProvider $guestLayoutParamsProvider,
        SurveyService $surveyService,
        TicketLinkService $ticketLinkService,
        AttachmentUploadService $attachmentUploadService,
        NavigationContextService $navigationContext,
        GuestPortalPageService $portalPageService,
        GuestPasswordPolicyService $guestPasswordPolicy,
        PortalTicketListFilterService $portalTicketListFilter,
        AttachmentDeliveryService $attachmentDelivery,
    ) {
        parent::__construct($appName, $request);
        $this->ticketService = $ticketService;
        $this->permissionService = $permissionService;
        $this->projectService = $projectService;
        $this->emailService = $emailService;
        $this->ticketMapper = $ticketMapper;
        $this->kbArticleMapper = $kbArticleMapper;
        $this->attachmentMapper = $attachmentMapper;
        $this->urlGenerator = $urlGenerator;
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
        $this->userManager = $userManager;
        $this->themesService = $themesService;
        $this->session = $session;
        $this->emailPreferences = $emailPreferences;
        $this->safeFilenameService = $safeFilenameService;
        $this->config = $config;
        $this->l10nFactory = $l10nFactory;
        $this->htmlSanitizerService = $htmlSanitizerService;
        $this->mailer = $mailer;
        $this->logger = $logger;
        $this->guestLayoutParamsProvider = $guestLayoutParamsProvider;
        $this->surveyService = $surveyService;
        $this->ticketLinkService = $ticketLinkService;
        $this->attachmentUploadService = $attachmentUploadService;
        $this->navigationContext = $navigationContext;
        $this->portalPageService = $portalPageService;
        $this->guestPasswordPolicy = $guestPasswordPolicy;
        $this->portalTicketListFilter = $portalTicketListFilter;
        $this->attachmentDelivery = $attachmentDelivery;
    }

    /**
     * Render a guest portal page through the single canonical path.
     *
     * Wraps {@see GuestPortalPageService::render()} so every guest GET goes
     * through the same merge of layout params (theme, GDPR ack), shell
     * context (navigation, requesttoken, …) and page-specific body params.
     *
     * @param array<string,mixed> $bodyParams
     * @param array<string,mixed> $scopeContext
     */
    private function renderPortalPage(
        string $template,
        string $pageId,
        array $bodyParams = [],
        string $pageTitle = '',
        string $pageHelp = '',
        array $scopeContext = [],
    ): TemplateResponse {
        $kbEnabled = $this->config->getAppValue('ticketcheck', 'kb_enabled', 'yes') === 'yes';
        $kbPortalEnabled = $this->config->getAppValue('ticketcheck', 'kb_portal_enabled', 'yes') === 'yes';
        $bodyParams += [
            'kbEnabled' => $kbEnabled,
            'kbPortalEnabled' => $kbPortalEnabled,
        ];
        // SECURITY: guest CSP + nonce are applied once by CSPMiddleware
        // (scoped to /apps/ticketcheck). Do not call configureCSPWithNonce
        // here — a second nonce would desync NC core's layout meta from scripts.
        return $this->portalPageService->render(
            $template,
            $pageId,
            $bodyParams,
            $pageTitle,
            $pageHelp,
            $scopeContext,
        );
    }

    /**
     * Portal index (show tickets)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        // SECURITY: Guests only see tickets they may open (project + authz),
        // never other customers' titles in a shared project list.
        if ($this->permissionService->isGuest()) {
            $tickets = $this->loadGuestVisibleTickets();
            $projects = $this->buildAccessiblePortalProjects();
        } else {
            // Admins/agents see all tickets
            $accessibleProjectIds = $this->permissionService->getAccessibleProjectIds();

            if ($accessibleProjectIds === null) {
                // User can see all tickets
                $tickets = $this->ticketMapper->findAll(100, 0);
                $projects = $this->projectService->getAllProjects(100, 0);
            } elseif (empty($accessibleProjectIds)) {
                // No project access
                $tickets = [];
                $projects = [];
            } else {
                // Filter by accessible projects
                $normalizedProjectIds = $this->normalizeProjectIdList($accessibleProjectIds);
                $tickets = $normalizedProjectIds === [] ? [] : $this->ticketMapper->findByProjects($normalizedProjectIds, self::PORTAL_TICKET_LIST_LIMIT);
                // Get accessible projects for filter dropdown
                $projects = [];
                foreach ($normalizedProjectIds as $projectId) {
                    $normalizedProjectId = $this->normalizeOptionalPositiveInt($projectId);
                    if ($normalizedProjectId === false || $normalizedProjectId === null) {
                        continue;
                    }
                    $project = $this->projectService->getProject($normalizedProjectId);
                    if ($project) {
                        $projects[] = $project;
                    }
                }
            }
        }

        // Canonical status summary (sidebar `open` = not done; KPI buckets).
        $stats = $this->buildPortalStatusStats($tickets);

        // Build creator names per ticket (actual creator, not viewer)
        $creatorNames = [];
        foreach ($tickets as $t) {
            $creatorName = '';
            if ($t->getCreatedByGuest()) {
                // Guest created: use customer name
                $creatorName = $t->getCustomerName();
            } else {
                // Staff or registered user: resolve display name from user id
                $creatorId = $t->getCreatedBy();
                if ($creatorId) {
                    $user = $this->userManager->get($creatorId);
                    if ($user) {
                        $creatorName = $user->getDisplayName();
                    } else {
                        $creatorName = $creatorId;
                    }
                } else {
                    // Fallback to customer name if no creator id present
                    $creatorName = $t->getCustomerName();
                }
            }
            $creatorNames[$t->getId()] = $creatorName ?: 'Unknown';
        }

        return $this->renderPortalPage(
            'portal/index',
            'portal-home',
            [
                'tickets' => $tickets,
                'projects' => $projects,
                'stats' => $stats,
                'categories' => Ticket::getCategories(),
                'userName' => $this->permissionService->getCurrentUserDisplayName(),
                'creatorNames' => $creatorNames,
            ],
        );
    }

    /**
     * Show my tickets
     *
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function myTickets(): TemplateResponse
    {
        // Guests: same visibility as the support dashboard (project-scoped tickets).
        // Email-only lists looked empty while the dashboard showed project tickets —
        // that mismatch was confusing and contradicted the portal security model.
        if ($this->permissionService->isGuest()) {
            $tickets = $this->loadGuestVisibleTickets();
        } else {
            $userEmail = (string)($this->permissionService->getCurrentUserEmail() ?? '');
            $tickets = $this->ticketMapper->findByCustomerEmail($userEmail, self::PORTAL_TICKET_LIST_LIMIT);
        }
        $filterBaseUrl = $this->urlGenerator->linkToRoute('ticketcheck.customerPortal.myTickets');

        return $this->renderPortalPage(
            'portal/my-tickets',
            'portal-tickets',
            $this->buildPortalTicketListTemplateParams($tickets, null, $filterBaseUrl),
        );
    }

    /**
     * Show tickets for a specific project
     *
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function projectTickets(int $projectId): TemplateResponse
    {
        $l = $this->l10nFactory->get($this->appName);
        // Verify user has access to this project
        if (!$this->permissionService->canAccessProject($projectId)) {
            return $this->renderPortalPage(
                'portal/error',
                'error',
                ['message' => $l->t('no_project_access')],
            );
        }

        // Get project details
        $project = $this->projectService->getProject($projectId);
        if (!$project) {
            return $this->renderPortalPage(
                'portal/error',
                'error',
                ['message' => $l->t('project_not_found')],
            );
        }

        // Get tickets for this specific project (guests: all project tickets they can access)
        if ($this->permissionService->isGuest()) {
            $tickets = array_values(array_filter(
                $this->loadGuestVisibleTickets(),
                static function ($ticket) use ($projectId) {
                    return (int)$ticket->getProjectId() === $projectId;
                }
            ));
        } else {
            $userEmail = (string)($this->permissionService->getCurrentUserEmail() ?? '');
            $allUserTickets = $this->ticketMapper->findByCustomerEmail($userEmail, self::PORTAL_TICKET_LIST_LIMIT);
            $tickets = array_values(array_filter($allUserTickets, static function ($ticket) use ($projectId) {
                return (int)$ticket->getProjectId() === $projectId;
            }));
        }

        // Canonical status summary for this project (sidebar `open` = not done).
        $stats = $this->buildPortalStatusStats($tickets);

        $filterBaseUrl = $this->urlGenerator->linkToRoute('ticketcheck.customerPortal.projectTickets', ['projectId' => $projectId]);
        $listParams = $this->buildPortalTicketListTemplateParams($tickets, $projectId, $filterBaseUrl);

        return $this->renderPortalPage(
            'portal/my-tickets',
            'portal-tickets',
            array_merge($listParams, [
                'project' => $project,
                'projectId' => $projectId,
                'stats' => $stats,
            ]),
            scopeContext: [
                'projectName' => (string)($project['name'] ?? ''),
            ],
        );
    }

    /**
     * Show create ticket form
     *
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function createTicket(): TemplateResponse|RedirectResponse
    {
        // Staff reaching the guest-only portal create form get sent to the
        // staff create-ticket view instead of a guest template dead end.
        if (!$this->permissionService->isGuest()) {
            return new RedirectResponse(
                $this->urlGenerator->linkToRoute('ticketcheck.app.index', ['view' => 'ticket-new'])
            );
        }
        // Check rate limit (same unified ceiling as create/reply/attach POSTs)
        $userId = (string)($this->permissionService->getCurrentUserId() ?? '');
        $since = new \DateTime('-1 day');
        $recentCount = $this->ticketService->countRecentPortalActionsByUser($userId, $since);

        if (!$this->permissionService->checkRateLimit($recentCount)) {
            $l = $this->l10nFactory->get($this->appName);
            return $this->renderPortalPage(
                'portal/rate-limit',
                'rate-limit',
                ['limit' => 50],
                $l->t('please_slow_down'),
                $l->t('too_many_requests'),
            );
        }

        $accessibleProjectIds = $this->permissionService->getAccessibleProjectIds();
        $hasAccessibleProjects = $accessibleProjectIds !== null && $accessibleProjectIds !== [];

        try {
            $projects = $this->navigationContext->getActiveAccessibleProjectsForGuest();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load accessible projects for guest portal create-ticket', [
                'exception' => $e,
            ]);
            $projects = [];
        }

        $hasActiveProjects = $projects !== [];
        $canCreateTicket = $this->navigationContext->canGuestCreateTicket() && $hasActiveProjects;
        $l = $this->l10nFactory->get($this->appName);

        $defaultProjectId = null;
        $activeProjectRows = array_values(array_filter($projects, static function (array $project): bool {
            return !isset($project['active']) || (int)$project['active'] !== 0;
        }));
        if (count($activeProjectRows) === 1) {
            $defaultProjectId = (int)$activeProjectRows[0]['id'];
        }

        return $this->renderPortalPage(
            'portal/create-ticket',
            'portal-create',
            [
                'priorities' => Ticket::getPriorities(),
                'categories' => Ticket::getCategories(),
                'projects' => $projects,
                'defaultProjectId' => $defaultProjectId,
                'hasAccessibleProjects' => $hasAccessibleProjects,
                'hasActiveProjects' => $hasActiveProjects,
                'canCreateTicket' => $canCreateTicket,
            ],
            $l->t('create_new_ticket'),
            $l->t('portal_create_lead'),
        );
    }

    /**
     * Store new ticket
     */
    #[NoAdminRequired]
    public function storeTicket(): JSONResponse
    {
        try {
            $l = $this->l10nFactory->get($this->appName);
            $userId = (string)($this->permissionService->getCurrentUserId() ?? '');

            // The portal create endpoint is guest-only: a staff POST previously
            // fell through to a generic 400 after ticket validation rejected the
            // missing customer identity — a dead end with no next step.
            if (!$this->permissionService->isGuest()) {
                return new JSONResponse([
                    'error' => $l->t('portal_create_guest_accounts_only'),
                ], 403);
            }

            // @phpstan-ignore-next-line — guest-only by the gate above; kept explicit for readability.
            if ($this->permissionService->isGuest() && !$this->navigationContext->canGuestCreateTicket()) {
                return new JSONResponse([
                    'error' => $l->t('guest_no_active_projects_message'),
                ], 403);
            }

            $title = $this->request->getParam('title');
            $description = $this->request->getParam('description');
            $priority = $this->request->getParam('priority', 'normal');
            $category = $this->request->getParam('category', 'general');
            $projectId = $this->normalizeOptionalPositiveInt($this->request->getParam('project_id'));

            // Validate required fields
            if (empty($title) || trim($title) === '') {
                return new JSONResponse([
                    'error' => $l->t('title_is_required')
                ], 400);
            }

            if (empty($description) || trim($description) === '') {
                return new JSONResponse([
                    'error' => $l->t('description_is_required')
                ], 400);
            }

            if ($projectId === false) {
                return new JSONResponse([
                    'error' => $l->t('invalid_project_id')
                ], 400);
            }

            // When guest has accessible projects, project selection is required (no ticket without a project)
            // @phpstan-ignore-next-line — guest-only by the gate above; kept explicit for readability.
            if ($this->permissionService->isGuest()) {
                $accessibleProjectIds = $this->permissionService->getAccessibleProjectIds();
                if ($accessibleProjectIds !== null && !empty($accessibleProjectIds)) {
                    $normalizedAccessibleProjectIds = array_values(array_filter(array_map(function ($id) {
                        $normalized = $this->normalizeOptionalPositiveInt($id);
                        return $normalized === false || $normalized === null ? null : $normalized;
                    }, $accessibleProjectIds)));
                    $projectIdInt = $projectId;
                    if ($projectIdInt === null || $projectIdInt === 0) {
                        return new JSONResponse([
                            'error' => $l->t('project_required_for_ticket')
                        ], 400);
                    }
                    if (!in_array($projectIdInt, $normalizedAccessibleProjectIds, true)) {
                        return new JSONResponse([
                            'error' => $l->t('no_project_access')
                        ], 403);
                    }
                }
            }

            // Validate project access if project is specified (non-guest or guest with no forced list)
            if ($projectId !== null && !$this->permissionService->canAccessProject($projectId)) {
                return new JSONResponse([
                    'error' => $l->t('no_project_access')
                ], 403);
            }

            // Block creation for inactive projects
            if ($projectId !== null) {
                try {
                    $project = $this->projectService->getProject($projectId);
                    if ($project && isset($project['active']) && (int)$project['active'] === 0) {
                        return new JSONResponse([
                            'error' => $l->t('project_inactive_no_tickets')
                        ], 409);
                    }
                } catch (\Throwable $e) {
                    // ignore project fetch errors
                }
            }

            // Create ticket
            $ticketData = [
                'title' => $title,
                'description' => $description,
                'priority' => $priority,
                'category' => $category,
                'project_id' => $projectId,
                'customer_name' => $this->permissionService->getCurrentUserDisplayName(),
                'customer_email' => $this->permissionService->getCurrentUserEmail(),
                'created_by_guest' => $this->permissionService->isGuest(),
            ];

            $attachmentValidationError = $this->validatePendingAttachmentUploads();
            if ($attachmentValidationError !== null) {
                return $attachmentValidationError;
            }
            $pendingAttachmentCount = $this->countPendingAttachmentUploads();

            // Rate check + create (+ bundled uploads) under one exclusive gate so
            // parallel POSTs cannot exceed the daily ceiling via attachment fan-out.
            $rateLimited = false;
            $uploadedFiles = [];
            $projectDenied = false;
            $projectInactive = false;
            try {
                $ticket = $this->ticketService->withGuestActivityGate($userId, function () use ($userId, &$rateLimited, &$projectDenied, &$projectInactive, $ticketData, $pendingAttachmentCount, &$uploadedFiles) {
                    $since = new \DateTime('-24 hours');
                    $recentCount = $this->ticketService->countRecentPortalActionsByUser($userId, $since);
                    $slotsNeeded = 1 + $pendingAttachmentCount;
                    if (!$this->permissionService->checkRateLimit($recentCount + $slotsNeeded - 1)) {
                        $rateLimited = true;
                        return null;
                    }

                    // Re-check project access + active under the exclusive gate
                    // (membership revoke / deactivate between outer check and create).
                    $gateProjectId = $ticketData['project_id'] ?? null;
                    if ($gateProjectId !== null && $gateProjectId !== 0) {
                        $gateProjectId = (int) $gateProjectId;
                        if ($this->permissionService->isGuest()) {
                            $accessibleProjectIds = $this->permissionService->getAccessibleProjectIds();
                            if ($accessibleProjectIds !== null && $accessibleProjectIds !== []) {
                                $normalized = [];
                                foreach ($accessibleProjectIds as $id) {
                                    $n = $this->normalizeOptionalPositiveInt($id);
                                    if ($n !== false && $n !== null) {
                                        $normalized[] = $n;
                                    }
                                }
                                if (!in_array($gateProjectId, $normalized, true)) {
                                    $projectDenied = true;
                                    return null;
                                }
                            }
                        }
                        if (!$this->permissionService->canAccessProject($gateProjectId)) {
                            $projectDenied = true;
                            return null;
                        }
                        try {
                            $project = $this->projectService->getProject($gateProjectId);
                            if ($project && isset($project['active']) && (int) $project['active'] === 0) {
                                $projectInactive = true;
                                return null;
                            }
                        } catch (\Throwable $e) {
                            // ignore project fetch errors; createTicket will fail loudly if needed
                        }
                    }

                    $created = $this->ticketService->createTicket($ticketData);
                    if ($pendingAttachmentCount > 0) {
                        try {
                            $uploadedFiles = $this->handleMultipleFileUploads(
                                (int) $created->getId(),
                                $pendingAttachmentCount
                            );
                        } catch (\Throwable $e) {
                            // Compensate: never leave an orphan ticket after a failed attach batch
                            // (covers InvalidArgument, ticket_not_found, and Error).
                            try {
                                $this->ticketService->deleteTicket((int) $created->getId());
                            } catch (\Throwable $cleanup) {
                                $this->logger->error('Portal create attach failed; ticket compensation delete failed', [
                                    'ticket_id' => $created->getId(),
                                    'exception' => $cleanup,
                                ]);
                            }
                            throw $e;
                        }
                    }
                    return $created;
                });
            } catch (\InvalidArgumentException $e) {
                if ($e->getMessage() === 'file_upload_failed_try_again') {
                    return new JSONResponse([
                        'error' => $l->t('file_upload_failed_try_again'),
                        'message' => $l->t('file_upload_failed_try_again'),
                    ], 400);
                }
                return new JSONResponse([
                    'error' => $l->t('too_many_requests'),
                ], 429);
            }

            if ($projectDenied) {
                return new JSONResponse([
                    'error' => $l->t('no_project_access')
                ], 403);
            }
            if ($projectInactive) {
                return new JSONResponse([
                    'error' => $l->t('project_inactive_no_tickets')
                ], 409);
            }

            if ($rateLimited || $ticket === null) {
                return new JSONResponse([
                    'error' => $l->t('rate_limit_50_per_day')
                ], 429);
            }

            // Send email notification
            try {
                $this->emailService->sendTicketCreatedNotification($ticket);
            } catch (\Throwable $e) {
                // Soft-fail: never turn a successful portal create into a false 400.
                $this->logger->warning('Failed to send ticket created email: ' . $e->getMessage());
            }

            return new JSONResponse([
                'success' => true,
                'ticket' => $ticket,
                'uploaded_files' => $uploadedFiles,
                'redirect' => $this->urlGenerator->linkToRoute('ticketcheck.customerPortal.viewTicket', ['id' => $ticket->getId()])
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Portal store ticket failed', ['exception' => $e]);
            if ($this->isUniformTicketNotFound($e)) {
                return new JSONResponse([
                    'error' => $this->l10nFactory->get($this->appName)->t('ticket_not_found'),
                    'message' => $this->l10nFactory->get($this->appName)->t('ticket_not_found'),
                ], 404);
            }
            return new JSONResponse([
                'error' => $this->l10nFactory->get($this->appName)->t('an_error_occurred')
            ], 400);
        }
    }

    /**
     * Handle multiple file uploads during ticket creation.
     * Fails closed when any pending file does not persist.
     * Partial successes in this request are compensated before the exception
     * propagates (defense in depth alongside create-ticket delete compensation).
     *
     * @param int $expectedCount
     * @return list<array{id: int, fileName: string, fileSize: int}>
     */
    private function handleMultipleFileUploads(int $ticketId, int $expectedCount): array
    {
        $uploadedFiles = [];
        $files = $_FILES['attachments'];

        if (!is_array($files['name'] ?? null)) {
            throw new \InvalidArgumentException('file_upload_failed_try_again');
        }

        try {
            $fileCount = count($files['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                try {
                    $fileData = [
                        'name' => $files['name'][$i],
                        'type' => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error' => $files['error'][$i],
                        'size' => $files['size'][$i]
                    ];

                    $result = $this->processSingleFileUpload($ticketId, $fileData);
                    if (($result['success'] ?? false) !== true || !isset($result['attachment'])) {
                        throw new \InvalidArgumentException('file_upload_failed_try_again');
                    }
                    $uploadedFiles[] = $result['attachment'];
                } catch (\InvalidArgumentException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    if ($this->isUniformTicketNotFound($e)) {
                        throw $e;
                    }
                    $this->logger->warning('Portal file upload failed', [
                        'file' => $files['name'][$i] ?? 'unknown',
                        'exception' => $e
                    ]);
                    throw new \InvalidArgumentException('file_upload_failed_try_again', 0, $e);
                }
            }

            if (count($uploadedFiles) < $expectedCount) {
                throw new \InvalidArgumentException('file_upload_failed_try_again');
            }

            return $uploadedFiles;
        } catch (\Throwable $e) {
            $this->compensateUploadedAttachments($ticketId, $uploadedFiles);
            throw $e;
        }
    }

    /**
     * Best-effort delete of attachments created in the current request batch.
     *
     * @param list<array{id?: int, fileName?: string, fileSize?: int|string}> $uploadedFiles
     */
    private function compensateUploadedAttachments(int $ticketId, array $uploadedFiles): void
    {
        foreach ($uploadedFiles as $file) {
            $attachmentId = (int) ($file['id'] ?? 0);
            if ($attachmentId <= 0) {
                continue;
            }
            try {
                // Merge-safe: row may already live on the survivor.
                $this->ticketService->deleteAttachmentWherever($attachmentId);
            } catch (\Throwable $cleanup) {
                $this->logger->error('Portal attachment batch compensation delete failed', [
                    'ticket_id' => $ticketId,
                    'attachment_id' => $attachmentId,
                    'exception' => $cleanup,
                ]);
            }
        }
    }

    private function validatePendingAttachmentUploads(): ?JSONResponse
    {
        if (!isset($_FILES['attachments']) || !is_array($_FILES['attachments']['name'] ?? null)) {
            return null;
        }

        $validation = $this->attachmentUploadService->validateUploadedFilesBatch($_FILES['attachments']);
        if ($validation['success'] === true) {
            return null;
        }

        $message = (string)($validation['message'] ?? $this->l10nFactory->get('ticketcheck')->t('upload_limit_exceeded'));
        return new JSONResponse([
            'success' => false,
            'error' => $message,
            'message' => $message,
        ], 400);
    }

    /**
     * Count files already validated for this request (0 when none / invalid batch rejected earlier).
     */
    private function countPendingAttachmentUploads(): int
    {
        if (!isset($_FILES['attachments']) || !is_array($_FILES['attachments']['name'] ?? null)) {
            return 0;
        }

        $validation = $this->attachmentUploadService->validateUploadedFilesBatch($_FILES['attachments']);
        if (($validation['success'] ?? false) !== true) {
            return 0;
        }

        return max(0, (int) ($validation['fileCount'] ?? 0));
    }

    /**
     * Process a single file upload (extracted from uploadAttachment method)
     *
     * @param int $ticketId
     * @param array $fileData
     * @return array
     */
    /**
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $fileData
     * @return array{success: bool, message?: string, attachment?: array{id: int, fileName: string, fileSize: int}}
     */
    private function processSingleFileUpload(int $ticketId, array $fileData): array
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $validation = $this->validateGuestUploadedFile($fileData);
            if ($validation['success'] !== true) {
                return $validation;
            }
            $mimeType = (string)($validation['mimeType'] ?? '');
            $originalName = (string)($validation['originalName'] ?? '');
            if ($mimeType === '' || $originalName === '') {
                return ['success' => false, 'message' => $l->t('file_validation_failed')];
            }

            $attachment = $this->ticketService->addAttachmentFromUploadedFile(
                $ticketId,
                (string)$fileData['tmp_name'],
                $originalName,
                (int)$fileData['size'],
                $mimeType,
                null,
                true,
                $this->buildPortalTicketAuthzAssert(),
                true
            );

            return [
                'success' => true,
                'attachment' => [
                    'id' => $attachment->getId(),
                    'fileName' => $attachment->getFileName(),
                    'fileSize' => $attachment->getFileSize(),
                ]
            ];
        } catch (\Exception $e) {
            if ($this->isUniformTicketNotFound($e)) {
                throw $e;
            }
            return [
                'success' => false,
                'message' => $l->t('an_error_occurred')
            ];
        }
    }

    /**
     * View ticket
     *
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function viewTicket(int $id): TemplateResponse|RedirectResponse
    {
        try {
            $ticket = $this->ticketMapper->find($id);

            // SECURITY: Do NOT disclose ticket existence to unauthorised
            // guests. `ticket_not_found` is returned both for missing rows
            // (catch below) and for rows the caller cannot see, so an
            // attacker cannot enumerate ticket ids.
            if (!$this->permissionService->canViewTicketWithProjectAccess($ticket)) {
                $l = $this->l10nFactory->get($this->appName);
                $denied = $this->renderPortalPage(
                    'portal/error',
                    'error',
                    ['message' => $l->t('ticket_not_found')],
                );
                // Anti-enumeration stays: identical status+body to a missing row.
                $denied->setStatus(Http::STATUS_NOT_FOUND);
                return $denied;
            }

            // After merge, conversation lives on the survivor — follow it when allowed.
            if ($ticket->getMergedIntoId() !== null) {
                try {
                    $survivor = $this->ticketService->getActiveTicket($id);
                } catch (\Throwable $e) {
                    $this->logger->error('Portal merged ticket survivor lookup failed', [
                        'ticket_id' => $id,
                        'exception' => $e,
                    ]);
                    $l = $this->l10nFactory->get($this->appName);
                    return $this->renderPortalPage(
                        'portal/error',
                        'error',
                        ['message' => $l->t('an_error_occurred')],
                    );
                }
                $survivorId = (int) $survivor->getId();
                if ($survivorId !== $id
                    && $this->permissionService->canViewTicketWithProjectAccess($survivor)) {
                    return new RedirectResponse(
                        $this->urlGenerator->linkToRoute(
                            'ticketcheck.customerPortal.viewTicket',
                            ['id' => $survivorId]
                        )
                    );
                }
            }

            // Get comments (excluding internal notes for guests)
            $comments = $this->ticketService->getComments($id, false);

            // Get ticket-level attachments (not linked to specific comments)
            $ticketAttachments = $this->attachmentMapper->findByTicketId($id);
            $attachments = array_filter($ticketAttachments, function ($attachment) {
                return $attachment->getCommentId() === null;
            });

            // Get comment-level attachments for each comment
            $commentsWithAttachments = [];
            foreach ($comments as $comment) {
                $commentAttachments = $this->attachmentMapper->findByCommentId($comment->getId());
                $commentsWithAttachments[] = [
                    'comment' => $comment,
                    'attachments' => $commentAttachments,
                    'authorName' => $this->resolveCommentAuthorDisplayName($comment),
                ];
            }

            // Get assigned user display name if assigned
            $assignedUserName = null;
            if ($ticket->getAssignedTo()) {
                $assignedUser = $this->userManager->get($ticket->getAssignedTo());
                if ($assignedUser) {
                    $assignedUserName = $assignedUser->getDisplayName();
                    // Fallback to UID if display name is empty
                    if (empty($assignedUserName) || trim($assignedUserName) === '') {
                        $assignedUserName = $ticket->getAssignedTo();
                    }
                }
            }

            // Get creator name
            $creatorName = '';
            if ($ticket->getCreatedByGuest()) {
                // Guest created: use customer name
                $creatorName = $ticket->getCustomerName();
            } else {
                // Staff or registered user: resolve display name from user id
                $creatorId = $ticket->getCreatedBy();
                if ($creatorId) {
                    $user = $this->userManager->get($creatorId);
                    if ($user) {
                        $creatorName = $user->getDisplayName();
                        // Fallback to UID if display name is empty
                        if (empty($creatorName) || trim($creatorName) === '') {
                            $creatorName = $creatorId;
                        }
                    } else {
                        $creatorName = $creatorId;
                    }
                } else {
                    // Fallback to customer name if no creator id present
                    $creatorName = $ticket->getCustomerName();
                }
            }
            if (empty($creatorName) || trim($creatorName) === '') {
                $creatorName = 'Unknown';
            }

            $survey = $this->surveyService->getSurveyByTicket($id);
            $showSurveyForm = $ticket->getStatus() === Ticket::STATUS_DONE && $survey === null;
            $showReplyForm = $ticket->isOpen();
            $links = $this->ticketLinkService->getLinksForTicket($id);

            // Project for display (template expects projects array)
            $projects = [];
            if ($ticket->getProjectId()) {
                $project = $this->projectService->getProject($ticket->getProjectId());
                if ($project) {
                    $projects[] = $project;
                }
            }

            $l = $this->l10nFactory->get($this->appName);
            $projectName = '';
            if ($projects !== []) {
                $projectName = (string)($projects[0]['name'] ?? '');
            }
            $scopeContext = [
                'scopeTicketRef' => $l->t('ticket_number', [$ticket->getTicketNumber()]),
            ];
            if ($projectName !== '') {
                $scopeContext['projectName'] = $projectName;
            }

            return $this->renderPortalPage(
                'portal/ticket-detail',
                'portal-ticket-detail',
                [
                    'ticket' => $ticket,
                    'survey' => $survey,
                    'links' => $links,
                    'showSurveyForm' => $showSurveyForm,
                    'showReplyForm' => $showReplyForm,
                    'comments' => $comments,
                    'commentsWithAttachments' => $commentsWithAttachments,
                    'attachments' => $attachments,
                    'assignedUserName' => $assignedUserName,
                    'creatorName' => $creatorName,
                    'projects' => $projects,
                    'projectName' => $projectName,
                ],
                $ticket->getTitle(),
                '',
                scopeContext: $scopeContext,
            );
        } catch (\Exception $e) {
            $l = $this->l10nFactory->get($this->appName);
            $this->logger->warning('Portal viewTicket failed', ['exception' => $e]);
            $notFound = $this->renderPortalPage(
                'portal/error',
                'error',
                ['message' => $l->t('ticket_not_found')],
            );
            $notFound->setStatus(Http::STATUS_NOT_FOUND);
            return $notFound;
        }
    }

    /**
     * Submit satisfaction survey for a ticket
     */
    #[NoAdminRequired]
    public function submitSurvey(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get($this->appName);
        try {
            $ticket = $this->ticketMapper->find($id);

            // SECURITY: authorise FIRST so we can return a uniform 404 for
            // "missing or inaccessible" tickets. Otherwise the difference
            // between 400 (wrong status) and 403 (no permission) would let
            // an attacker enumerate ticket ids belonging to other guests.
            // CSAT is portal-only: staff must not pollute ratings.
            if (!$this->permissionService->isGuest()
                || !$this->permissionService->canViewTicketWithProjectAccess($ticket)) {
                return new JSONResponse([
                    'error' => $l->t('ticket_not_found'),
                ], 404);
            }

            // Surveys must land on the survivor (same hop as replies).
            try {
                $active = $this->ticketService->getActiveTicket($id);
            } catch (\Throwable $e) {
                return new JSONResponse([
                    'error' => $l->t('ticket_not_found'),
                ], 404);
            }
            if (!$this->permissionService->canViewTicketWithProjectAccess($active)) {
                return new JSONResponse([
                    'error' => $l->t('ticket_not_found'),
                ], 404);
            }
            $ticket = $active;
            $activeId = (int) $ticket->getId();

            if ($ticket->getStatus() !== Ticket::STATUS_DONE) {
                return new JSONResponse([
                    'error' => $l->t('satisfaction_survey_only_when_done'),
                ], 400);
            }

            $rating = (int) $this->request->getParam('rating', 0);
            $comment = trim((string) ($this->request->getParam('comment') ?? ''));

            $this->surveyService->submitSurvey(
                $activeId,
                $rating,
                $comment === '' ? null : $comment,
                function (\OCA\Ticketcheck\Db\Ticket $fresh): void {
                    // Uniform 404 for missing/inaccessible (no enumeration via 403).
                    if (!$this->permissionService->isGuest()
                        || !$this->permissionService->canViewTicketWithProjectAccess($fresh)) {
                        throw new \InvalidArgumentException('ticket_not_found');
                    }
                }
            );

            return new JSONResponse([
                'success' => true,
                'message' => $l->t('satisfaction_survey_thanks'),
            ]);
        } catch (\InvalidArgumentException $e) {
            $key = $e->getMessage();
            if ($key === 'ticket_not_found') {
                return new JSONResponse([
                    'error' => $l->t('ticket_not_found'),
                ], 404);
            }
            return new JSONResponse([
                'error' => $this->translateSurveyExceptionKey($l, $key),
            ], 400);
        } catch (DoesNotExistException $e) {
            return new JSONResponse([
                'error' => $l->t('ticket_not_found'),
            ], 404);
        } catch (\Exception $e) {
            $this->logger->error('Portal submit survey failed', ['exception' => $e]);
            return new JSONResponse([
                'error' => $l->t('an_error_occurred'),
            ], 400);
        }
    }

    /**
     * Map SurveyService InvalidArgumentException keys to translated strings.
     */
    private function translateSurveyExceptionKey(\OCP\IL10N $l, string $message): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $message) === 1) {
            return $l->t($message);
        }

        return $l->t('an_error_occurred');
    }

    /**
     * Get ticket links for portal (guests can view linked tickets they have access to)
     */
    #[NoAdminRequired]
    public function getTicketLinks(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $ticket = $this->ticketMapper->find($id);
            // SECURITY: collapse "missing" and "inaccessible" into a single
            // 404 to prevent ticket-id enumeration by unauthorised guests.
            if (!$this->permissionService->canViewTicketWithProjectAccess($ticket)) {
                return new JSONResponse([
                    'error' => $l->t('ticket_not_found'),
                ], 404);
            }
            try {
                $active = $this->ticketService->getActiveTicket($id);
            } catch (\Throwable $e) {
                return new JSONResponse([
                    'error' => $l->t('ticket_not_found'),
                ], 404);
            }
            if (!$this->permissionService->canViewTicketWithProjectAccess($active)) {
                return new JSONResponse([
                    'error' => $l->t('ticket_not_found'),
                ], 404);
            }
            $links = $this->ticketLinkService->getLinksForTicket((int) $active->getId());
            return new JSONResponse(['links' => $links]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse([
                'error' => $l->t('ticket_not_found'),
            ], 404);
        } catch (\Exception $e) {
            $this->logger->error('Get ticket links failed', ['exception' => $e]);
            return new JSONResponse([
                'error' => $l->t('an_error_occurred'),
            ], 400);
        }
    }

    /**
     * Add reply to ticket (guest portal — sole comment endpoint; route: POST /portal/tickets/{id}/reply).
     */
    #[NoAdminRequired]
    public function addReply(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $comment = $this->request->getParam('comment');

            // Validate comment is not empty
            if (empty($comment) || trim($comment) === '') {
                return new JSONResponse([
                    'error' => $l->t('comment_required')
                ], 400);
            }

            $ticket = $this->ticketMapper->find($id);

            // SECURITY: uniform 404 so missing vs forbidden ticket ids look
            // identical to unauthorised callers and cannot be enumerated.
            if (!$this->permissionService->canViewTicketWithProjectAccess($ticket)) {
                return new JSONResponse([
                    'error' => $l->t('ticket_not_found')
                ], 404);
            }

            // Replies must land on the survivor after authz on that ticket.
            try {
                $active = $this->ticketService->getActiveTicket($id);
            } catch (\Throwable $e) {
                return new JSONResponse([
                    'error' => $l->t('ticket_not_found'),
                ], 404);
            }
            if (!$this->permissionService->canViewTicketWithProjectAccess($active)) {
                return new JSONResponse([
                    'error' => $l->t('ticket_not_found'),
                ], 404);
            }
            $ticket = $active;
            $activeId = (int) $ticket->getId();

            if (!$ticket->isOpen()) {
                return new JSONResponse([
                    'error' => $l->t('portal_ticket_closed_no_reply'),
                ], 403);
            }

            $attachmentValidationError = $this->validatePendingAttachmentUploads();
            if ($attachmentValidationError !== null) {
                return $attachmentValidationError;
            }
            $pendingAttachmentCount = $this->countPendingAttachmentUploads();

            $uploadedFiles = [];
            $commentOrLimit = $this->runGuestPortalActionWithRateLimit(
                $l,
                function () use ($activeId, $comment, $pendingAttachmentCount, &$uploadedFiles) {
                    $commentObj = $this->ticketService->addComment(
                        $activeId,
                        trim($comment),
                        false, // Not an internal note
                        true,  // Guests cannot reply to closed tickets (re-check under lock)
                        $this->buildPortalTicketAuthzAssert()
                    );
                    if ($pendingAttachmentCount > 0) {
                        try {
                            $uploadedFiles = $this->handleMultipleReplyFileUploads(
                                $activeId,
                                (int) $commentObj->getId(),
                                $pendingAttachmentCount
                            );
                        } catch (\Throwable $e) {
                            // Compensate: never leave an orphan reply after a failed attach batch.
                            try {
                                $this->ticketService->deleteComment(
                                    $activeId,
                                    (int) $commentObj->getId()
                                );
                            } catch (\Throwable $cleanup) {
                                $this->logger->error('Portal reply attach failed; comment compensation delete failed', [
                                    'ticket_id' => $activeId,
                                    'comment_id' => $commentObj->getId(),
                                    'exception' => $cleanup,
                                ]);
                            }
                            throw $e;
                        }
                    }
                    return $commentObj;
                },
                $pendingAttachmentCount
            );
            if ($commentOrLimit instanceof JSONResponse) {
                return $commentOrLimit;
            }
            $commentObj = $commentOrLimit;

            // Send email notification
            try {
                $currentUser = $this->userSession->getUser();
                $currentUserId = $currentUser ? $currentUser->getUID() : null;

                $this->emailService->sendNewCommentNotification(
                    $ticket,
                    (string)($this->permissionService->getCurrentUserDisplayName() ?? ''),
                    $comment,
                    $currentUserId // Exclude comment author from notifications
                );
            } catch (\Throwable $e) {
                // Soft-fail: never turn a successful portal reply into a false 400.
                $this->logger->warning('Failed to send comment notification email: ' . $e->getMessage());
            }

            return new JSONResponse([
                'success' => true,
                'comment' => $commentObj,
                'uploaded_files' => $uploadedFiles
            ]);
        } catch (\InvalidArgumentException $e) {
            if ($e->getMessage() === 'file_upload_failed_try_again') {
                return new JSONResponse([
                    'success' => false,
                    'error' => $l->t('file_upload_failed_try_again'),
                    'message' => $l->t('file_upload_failed_try_again'),
                ], 400);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->isUniformTicketNotFound($e)) {
                // Identical payload as the early foreign-ticket return above —
                // same status AND same body so ids cannot be enumerated.
                return new JSONResponse([
                    'error' => $l->t('ticket_not_found'),
                ], 404);
            }
            if ($e->getMessage() === 'portal_ticket_closed_no_reply') {
                return new JSONResponse([
                    'success' => false,
                    'error' => $l->t('portal_ticket_closed_no_reply'),
                ], 403);
            }
            $this->logger->error('Error adding reply', ['exception' => $e]);
            return new JSONResponse([
                'success' => false,
                'error' => $l->t('an_error_occurred')
            ], 400);
        }
    }

    /**
     * Handle multiple attachment uploads linked to a specific reply comment.
     * Fails closed: every pending OK upload must persist (no silent success).
     *
     * @param int $expectedCount Number of UPLOAD_ERR_OK files already batch-validated
     * @return list<array{id: int, fileName: string, fileSize: int}>
     */
    private function handleMultipleReplyFileUploads(int $ticketId, int $commentId, int $expectedCount): array
    {
        $uploadedFiles = [];
        $files = $_FILES['attachments'];

        if (!is_array($files['name'] ?? null)) {
            throw new \InvalidArgumentException('file_upload_failed_try_again');
        }

        try {
            $fileCount = count($files['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                try {
                    $fileData = [
                        'name' => $files['name'][$i],
                        'type' => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error' => $files['error'][$i],
                        'size' => $files['size'][$i]
                    ];

                    $validation = $this->validateGuestUploadedFile($fileData);
                    if ($validation['success'] !== true) {
                        throw new \InvalidArgumentException('file_upload_failed_try_again');
                    }

                    $mimeType = (string)($validation['mimeType'] ?? '');
                    $originalName = (string)($validation['originalName'] ?? '');
                    if ($mimeType === '' || $originalName === '') {
                        throw new \InvalidArgumentException('file_upload_failed_try_again');
                    }

                    $attachment = $this->ticketService->addAttachmentFromUploadedFile(
                        $ticketId,
                        (string)$fileData['tmp_name'],
                        $originalName,
                        (int)$fileData['size'],
                        $mimeType,
                        $commentId,
                        true,
                        $this->buildPortalTicketAuthzAssert(),
                        true
                    );

                    $uploadedFiles[] = [
                        'id' => $attachment->getId(),
                        'fileName' => $attachment->getFileName(),
                        'fileSize' => $attachment->getFileSize(),
                    ];
                } catch (\InvalidArgumentException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    // Match portal create uploads: wrap Exception as upload failure, but let
                    // Error bubble so the reply-level Throwable compensate can delete the comment.
                    if ($this->isUniformTicketNotFound($e)) {
                        throw $e;
                    }
                    $this->logger->warning('Portal reply attachment upload failed', [
                        'ticket_id' => $ticketId,
                        'comment_id' => $commentId,
                        'file' => $files['name'][$i] ?? 'unknown',
                        'exception' => $e,
                    ]);
                    throw new \InvalidArgumentException('file_upload_failed_try_again', 0, $e);
                }
            }

            if (count($uploadedFiles) < $expectedCount) {
                throw new \InvalidArgumentException('file_upload_failed_try_again');
            }

            return $uploadedFiles;
        } catch (\Throwable $e) {
            $this->compensateUploadedAttachments($ticketId, $uploadedFiles);
            throw $e;
        }
    }

    /**
     * Upload attachment to ticket (guest portal)
     */
    #[NoAdminRequired]
    public function uploadAttachment(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $ticket = $this->ticketMapper->find($id);

            // SECURITY: uniform 404 so missing vs forbidden ticket ids look
            // identical to unauthorised callers and cannot be enumerated.
            if (!$this->permissionService->canViewTicketWithProjectAccess($ticket)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('ticket_not_found')
                ], 404);
            }

            // Attachments must land on the survivor after authz on that ticket.
            try {
                $active = $this->ticketService->getActiveTicket($id);
            } catch (\Throwable $e) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('ticket_not_found'),
                ], 404);
            }
            if (!$this->permissionService->canViewTicketWithProjectAccess($active)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('ticket_not_found'),
                ], 404);
            }
            $ticket = $active;
            $activeId = (int) $ticket->getId();

            if (!$ticket->isOpen()) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('portal_ticket_closed_no_reply'),
                ], 403);
            }

            // Check if file was uploaded
            if (!isset($_FILES['file'])) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('no_file_uploaded')
                ], 400);
            }

            $file = $_FILES['file'];

            // Validate file
            if ($file['error'] !== UPLOAD_ERR_OK) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('file_upload_failed_try_again')
                ], 400);
            }
            $validation = $this->validateGuestUploadedFile($file);
            if ($validation['success'] !== true) {
                return new JSONResponse($validation, 400);
            }
            $mimeType = (string)($validation['mimeType'] ?? '');
            $originalName = (string)($validation['originalName'] ?? '');
            if ($mimeType === '' || $originalName === '') {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('file_validation_failed')
                ], 400);
            }

            // Get comment ID if provided (for linking attachments to specific comments)
            $commentId = $this->normalizeOptionalPositiveInt($this->request->getParam('comment_id'));
            if ($commentId === false) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('invalid_parameters')
                ], 400);
            }
            if ($commentId !== null && !$this->commentBelongsToTicket($activeId, $commentId)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('invalid_parameters')
                ], 400);
            }

            $attachmentOrLimit = $this->runGuestPortalActionWithRateLimit($l, function () use ($activeId, $file, $originalName, $mimeType, $commentId) {
                return $this->ticketService->addAttachmentFromUploadedFile(
                    $activeId,
                    (string)$file['tmp_name'],
                    $originalName,
                    (int)$file['size'],
                    $mimeType,
                    $commentId,
                    true,
                    $this->buildPortalTicketAuthzAssert(),
                    true
                );
            });
            if ($attachmentOrLimit instanceof JSONResponse) {
                return $attachmentOrLimit;
            }
            $attachment = $attachmentOrLimit;

            // Send email notification if file is not linked to a comment
            // (comment attachments are notified via comment notification)
            if ($commentId === null) {
                try {
                    $this->emailService->sendTicketUpdatedNotification(
                        $ticket,
                            sprintf('Attachment added: %s', $originalName)
                    );
                } catch (\Throwable $e) {
                    // Soft-fail: never turn a successful portal upload into a false 400.
                    $this->logger->warning('Failed to send attachment notification email: ' . $e->getMessage());
                }
            }

            return new JSONResponse([
                'success' => true,
                'message' => $l->t('file_uploaded'),
                'attachment' => [
                    'id' => $attachment->getId(),
                    'fileName' => $attachment->getFileName(),
                    'fileSize' => $attachment->getFileSize(),
                ]
            ]);
        } catch (\Throwable $e) {
            if ($this->isUniformTicketNotFound($e)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('ticket_not_found'),
                ], 404);
            }
            if ($e->getMessage() === 'portal_ticket_closed_no_reply') {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('portal_ticket_closed_no_reply'),
                ], 403);
            }
            $this->logger->error('Portal upload attachment failed', ['exception' => $e]);
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('an_error_occurred')
            ], 400);
        }
    }

    /**
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @return array{success: bool, message?: string, mimeType?: string, originalName?: string}
     */
    private function validateGuestUploadedFile(array $file): array
    {
        return $this->attachmentUploadService->validateUploadedFile($file);
    }

    /**
     * Re-check portal ticket access under the workflow lock (uniform not-found).
     *
     * @return callable(\OCA\Ticketcheck\Db\Ticket):void
     */
    private function buildPortalTicketAuthzAssert(): callable
    {
        return function (Ticket $fresh): void {
            if (!$this->permissionService->canViewTicketWithProjectAccess($fresh)) {
                throw new \Exception('ticket_not_found');
            }
        };
    }

    /**
     * Uniform portal oracle: authz/missing must surface as ticket_not_found (never upload failure).
     * Accepts both the portal key and TicketService's legacy English message.
     *
     * Crucially also collapses mapper-level misses: ticketMapper->find() throws
     * DoesNotExistException (message "Did expect one result ..."), which must NOT
     * leak through as a generic 400 — that would let guests enumerate ticket ids
     * by diffing 400-vs-404 across missing vs foreign ids.
     */
    private function isUniformTicketNotFound(\Throwable $e): bool
    {
        if ($e instanceof DoesNotExistException || $e instanceof MultipleObjectsReturnedException) {
            return true;
        }
        $message = $e->getMessage();
        return $message === 'ticket_not_found' || $message === 'Ticket not found';
    }

    /**
     * Ensure a provided comment belongs to the same ticket.
     * Prevents cross-ticket attachment linkage when comment_id is supplied.
     */
    private function commentBelongsToTicket(int $ticketId, int $commentId): bool
    {
        try {
            $comments = $this->ticketService->getComments($ticketId, false);
            foreach ($comments as $comment) {
                if ($comment->getId() === $commentId) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to validate comment ownership for attachment upload', [
                'ticket_id' => $ticketId,
                'comment_id' => $commentId,
                'exception' => $e,
            ]);
        }

        return false;
    }

    /**
     * Whether the guest may still create/reply/upload (no exact counters — avoids quota probing).
     */
    #[NoAdminRequired]
    public function checkRateLimitStatus(): JSONResponse
    {
        $l = $this->l10nFactory->get($this->appName);
        if (!$this->permissionService->isGuest()) {
            return new JSONResponse([
                'success' => false,
                'error' => $l->t('access_denied'),
            ], 403);
        }

        try {
            $userId = (string)($this->permissionService->getCurrentUserId() ?? '');
            $since = new \DateTime('-24 hours');
            $recentCount = $this->ticketService->countRecentPortalActionsByUser($userId, $since);
            $allowed = $this->permissionService->checkRateLimit($recentCount);

            return new JSONResponse([
                'success' => true,
                'allowed' => $allowed,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Portal check rate limit failed', ['exception' => $e]);
            $l = $this->l10nFactory->get($this->appName);
            return new JSONResponse([
                'success' => false,
                'error' => $l->t('an_error_occurred')
            ], 500);
        }
    }

    /**
     * View knowledge base (with integrated search)
     *
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function knowledgeBase(): TemplateResponse
    {
        if (!$this->permissionService->isKnowledgeBasePortalContentEnabled()) {
            return $this->renderPortalPage(
                'portal/error',
                'error',
                ['message' => $this->l10nFactory->get('ticketcheck')->t('knowledge_base_disabled_message')],
            );
        }
        try {
            $query = KbSearchTextHelper::normalizeQuery((string)$this->request->getParam('q', ''));
            $categoryFilter = trim((string)$this->request->getParam('category', ''));
            $allPublished = $this->kbArticleMapper->findPublished();
            $searchActive = $query !== '';

            $articles = $searchActive
                ? $this->kbArticleMapper->search($query, true)
                : $allPublished;

            $categorySource = $searchActive ? $articles : $allPublished;
            $categories = [];
            foreach ($categorySource as $article) {
                $cat = $article->getCategory() ?: 'General';
                $categories[$cat] = ($categories[$cat] ?? 0) + 1;
            }
            if ($categoryFilter !== '' && !isset($categories[$categoryFilter])) {
                $categories[$categoryFilter] = 0;
            }

            if ($categoryFilter !== '') {
                $articles = array_values(array_filter($articles, static function ($article) use ($categoryFilter) {
                    $articleCategory = $article->getCategory() ?: 'General';
                    return $articleCategory === $categoryFilter;
                }));
            }

            return $this->renderPortalPage(
                'portal/kb',
                'portal-kb',
                [
                    'query' => $query,
                    'articles' => $articles,
                    'categories' => $categories,
                    'categoryFilter' => $categoryFilter,
                    'searchActive' => $searchActive,
                ],
            );
        } catch (\Exception $e) {
            return $this->renderPortalPage(
                'portal/error',
                'error',
                ['message' => $this->l10nFactory->get('ticketcheck')->t('failed_to_load_knowledge_base')],
            );
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function kpKnowledgeBase(): TemplateResponse
    {
        return $this->knowledgeBase();
    }


    /**
     * View knowledge base article
     *
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function kbArticle(int $id): TemplateResponse
    {
        if (!$this->permissionService->isKnowledgeBasePortalContentEnabled()) {
            return $this->renderPortalPage(
                'portal/error',
                'error',
                ['message' => $this->l10nFactory->get('ticketcheck')->t('knowledge_base_disabled_message')],
            );
        }
        try {
            $article = $this->kbArticleMapper->find($id);

            // Only show published articles to guests
            if (!$article->isPublished()) {
                return $this->renderPortalPage(
                    'portal/error',
                    'error',
                    ['message' => $this->l10nFactory->get('ticketcheck')->t('article_not_found')],
                );
            }

            // Atomic increment avoids lost updates under concurrent portal reads.
            $this->kbArticleMapper->incrementViewsAtomic($id);
            $article = $this->kbArticleMapper->find($id);

            return $this->renderPortalPage(
                'portal/kb-article',
                'portal-kb-article',
                [
                    'article' => $article,
                    'kbFeedbackEnabled' => $this->permissionService->isKnowledgeBaseFeedbackEnabled(),
                    'kbCommentsEnabled' => $this->permissionService->isKnowledgeBaseCommentsEnabled(),
                    'sanitizer' => $this->htmlSanitizerService,
                ],
            );
        } catch (\Exception $e) {
            return $this->renderPortalPage(
                'portal/error',
                'error',
                ['message' => $this->l10nFactory->get('ticketcheck')->t('article_not_found')],
            );
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function kpArticle(int $id): TemplateResponse
    {
        return $this->kbArticle($id);
    }

    /**
     * Show password change page
     *
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function changePassword(): TemplateResponse
    {
        return $this->renderPortalPage(
            'portal/change-password',
            'portal-password',
        );
    }

    /**
     * Email preferences page for guests (portal)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function emailPreferences(): TemplateResponse
    {
        $user = $this->userSession->getUser();
        $prefs = $user ? $this->emailPreferences->getUserPreferences($user->getUID(), true) : [];

        return $this->renderPortalPage(
            'portal/email-preferences',
            'portal-email-prefs',
            [
                'preferences' => $prefs,
                'saveUrl' => $this->urlGenerator->linkToRouteAbsolute('ticketcheck.userPreferences.saveEmailPreferences'),
                'accountDeletionUrl' => $this->urlGenerator->linkToRouteAbsolute('ticketcheck.customerPortal.requestAccountDeletion'),
            ],
        );
    }

    /**
     * Update password
     */
    #[NoAdminRequired]
    public function updatePassword(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->isGuest()) {
            return new JSONResponse(['success' => false, 'error' => $l->t('permission_denied')], 403);
        }
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['success' => false, 'error' => $l->t('not_logged_in')], 401);
            }

            $currentPassword = $this->request->getParam('current_password');
            $newPassword = $this->request->getParam('new_password');

            // SECURITY: Require current password to prevent session hijack account takeover
            if (empty($currentPassword) || trim($currentPassword) === '') {
                $l = $this->l10nFactory->get($this->appName);
                return new JSONResponse([
                    'success' => false,
                    'error' => $l->t('current_password_required')
                ], 400);
            }

            // SECURITY: Rate limit failed password attempts (5 per 15 min per user),
            // serialized so parallel wrong-password POSTs cannot exceed the ceiling.
            $userId = $user->getUID();
            $windowSeconds = 900; // 15 min
            try {
                $authGate = $this->ticketService->withGuestPasswordFailGate($userId, function () use ($userId, $currentPassword, $windowSeconds) {
                    $failCount = (int) $this->config->getUserValue($userId, 'ticketcheck', 'pw_fail_count', 0);
                    $failTime = (int) $this->config->getUserValue($userId, 'ticketcheck', 'pw_fail_time', 0);
                    if ($failCount >= 5 && (time() - $failTime) < $windowSeconds) {
                        return 'limited';
                    }
                    if ($failCount >= 5 && (time() - $failTime) >= $windowSeconds) {
                        $this->config->deleteUserValue($userId, 'ticketcheck', 'pw_fail_count');
                        $this->config->deleteUserValue($userId, 'ticketcheck', 'pw_fail_time');
                        $failCount = 0;
                    }

                    $verifiedUser = $this->userManager->checkPassword($userId, (string) $currentPassword);
                    if ($verifiedUser === false) {
                        $failCount++;
                        $this->config->setUserValue($userId, 'ticketcheck', 'pw_fail_count', (string) $failCount);
                        if ($failCount === 1) {
                            $this->config->setUserValue($userId, 'ticketcheck', 'pw_fail_time', (string) time());
                        }
                        return 'bad';
                    }

                    $this->config->deleteUserValue($userId, 'ticketcheck', 'pw_fail_count');
                    $this->config->deleteUserValue($userId, 'ticketcheck', 'pw_fail_time');
                    return 'ok';
                });
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse([
                    'success' => false,
                    'error' => $l->t('too_many_failed_attempts'),
                ], 429);
            }

            if ($authGate === 'limited') {
                return new JSONResponse([
                    'success' => false,
                    'error' => $l->t('too_many_failed_attempts'),
                ], 429);
            }
            if ($authGate === 'bad') {
                return new JSONResponse([
                    'success' => false,
                    'error' => $l->t('incorrect_current_password'),
                ], 400);
            }

            $policyCheck = $this->guestPasswordPolicy->validateNewPassword((string) $newPassword);
            if (!$policyCheck['valid']) {
                $errorKey = $policyCheck['errorKey'] ?? 'password_requirements';
                return new JSONResponse(['success' => false, 'error' => $l->t($errorKey)], 400);
            }

            if ($this->guestPasswordPolicy->isSameAsCurrent((string) $newPassword, (string) $currentPassword)) {
                return new JSONResponse(['success' => false, 'error' => $l->t('password_same_as_current')], 400);
            }

            // SECURITY: Log password change for audit trail
            $logger = $this->logger;
            $logger->info('Guest user password change', [
                'user_id' => $user->getUID(),
                'email' => $user->getEMailAddress(),
                'ip' => $this->request->getRemoteAddress(),
                'user_agent' => $this->request->getHeader('User-Agent'),
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            // Serialize with admin reset so last-write races cannot clobber.
            try {
                $this->ticketService->withGuestPasswordFailGate($userId, function () use ($user, $newPassword): void {
                    $user->setPassword($newPassword);
                });
            } catch (\InvalidArgumentException) {
                return new JSONResponse([
                    'success' => false,
                    'error' => $l->t('an_error_occurred'),
                ], 409);
            }

            // Session token refresh is handled by core auth/session flow.

            $l = $this->l10nFactory->get($this->appName);
            return new JSONResponse([
                'success' => true,
                'message' => $l->t('password_changed_successfully')
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Portal change password failed', ['exception' => $e]);
            return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 500);
        }
    }

    /**
     * Request account deletion (for guest users)
     */
    #[NoAdminRequired]
    public function requestAccountDeletion(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['success' => false, 'error' => $l->t('not_logged_in')], 401);
            }

            $userId = $user->getUID();
            $displayName = $user->getDisplayName();
            $email = $user->getEMailAddress();

            // Verify user is a guest
            if (!$this->groupManager->isInGroup($userId, 'helpdesk_customers')) {
                return new JSONResponse(['success' => false, 'error' => $l->t('only_guest_users_can_request_account_deletion')], 403);
            }

            // SECURITY: Log deletion request for audit trail
            $logger = $this->logger;
            $logger->warning('Guest user account deletion requested', [
                'user_id' => $userId,
                'display_name' => $displayName,
                'email' => $email,
                'ip' => $this->request->getRemoteAddress(),
                'user_agent' => $this->request->getHeader('User-Agent'),
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            // Try to send email to all helpdesk admins
            $adminGroup = $this->groupManager->get('helpdesk_admins');
            $admins = $adminGroup ? $adminGroup->getUsers() : [];

            if (empty($admins)) {
                // Fallback to system admins
                $adminGroup = $this->groupManager->get('admin');
                $admins = $adminGroup ? $adminGroup->getUsers() : [];
            }

            // Email is optional - deletion request is logged regardless
            $emailSent = false;

            if (!empty($admins)) {
                try {
                    $mailer = $this->mailer;
                    $message = $mailer->createMessage();

                    $deleteUrl = $this->urlGenerator->getAbsoluteURL(
                        $this->urlGenerator->linkToRoute('ticketcheck.guestUser.index')
                    );
                    $safeUserId = htmlspecialchars((string)$userId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $safeDisplayName = htmlspecialchars((string)$displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $safeEmail = htmlspecialchars((string)$email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $safeDeleteUrl = htmlspecialchars((string)$deleteUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $safeRequestDate = htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                    $subject = $l->t('account_deletion_request_email_subject', [$displayName]);

                    $htmlBody = "
                        <h2>Account Deletion Request</h2>
                        <p>A guest user has requested account deletion.</p>
                        
                        <h3>User Details:</h3>
                        <ul>
                            <li><strong>Username:</strong> {$safeUserId}</li>
                            <li><strong>Display Name:</strong> {$safeDisplayName}</li>
                            <li><strong>Email:</strong> {$safeEmail}</li>
                            <li><strong>Request Date:</strong> {$safeRequestDate}</li>
                        </ul>
                        
                        <p><strong>Action Required:</strong></p>
                        <p>Please review this request and delete the account if appropriate.</p>
                        
                        <p><a href=\"{$safeDeleteUrl}\" style=\"display: inline-block; padding: 12px 24px; background: #e74c3c; color: white; text-decoration: none; border-radius: 6px; font-weight: bold;\">
                            Manage Guest Users
                        </a></p>
                        
                        <hr style=\"margin: 2rem 0; border: none; border-top: 1px solid #ddd;\">
                        <p style=\"color: #666; font-size: 0.9rem;\">
                            This is an automated message from the Helpdesk system.
                        </p>
                    ";

                    $message->setSubject($subject);
                    $message->setHtmlBody($htmlBody);

                    // Get configured sender name from settings
                    $config = $this->config;
                    $fromName = $config->getAppValue('ticketcheck', 'email_from_name', 'Helpdesk');
                    $fromAddress = $config->getSystemValue('mail_from_address', 'ticketcheck');
                    $fromDomain = $config->getSystemValue('mail_domain', 'localhost');
                    $message->setFrom([$fromAddress . '@' . $fromDomain => $fromName]);

                    // Send to all admins
                    foreach ($admins as $admin) {
                        $adminEmail = $admin->getEMailAddress();
                        if ($adminEmail) {
                            try {
                                $message->setTo([$adminEmail]);
                                $mailer->send($message);
                                $emailSent = true;
                            } catch (\Exception $e) {
                                // Log but continue to try other admins
                                $logger->error('Failed to send deletion request email', [
                                    'admin_email' => $adminEmail,
                                    'exception' => $e
                                ]);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Email sending failed, but that's okay - request is logged
                    $logger->error('Failed to send any deletion request emails', ['exception' => $e]);
                }
            }

            // SUCCESS - the request is logged and email sent to admins
            $successMessage = $l->t('account_deletion_request_submitted_successfully') . ' ';

            if ($emailSent) {
                $successMessage .= $l->t('administrator_notified_review_request');
            } else {
                $successMessage .= $l->t('administrator_will_be_notified_review_request');
            }

            return new JSONResponse([
                'success' => true,
                'message' => $successMessage
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Portal request account deletion failed', ['exception' => $e]);
            return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 500);
        }
    }

    /**
     * Persist portal privacy notice acknowledgement (guest API; same storage as staff route).
     */
    #[NoAdminRequired]
    public function dismissPortalPrivacyNotice(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['success' => false, 'error' => $l->t('not_authenticated')], 401);
        }

        $this->config->setUserValue(
            $user->getUID(),
            $this->appName,
            GuestLayoutParamsProvider::USER_CONFIG_GDPR_PORTAL_ACK,
            (string)time(),
        );

        return new JSONResponse(['success' => true]);
    }

    /**
     * Set guest user language preference
     *
     * @UseSession
     */
    #[NoAdminRequired]
    public function setLanguage(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $language = $this->request->getParam('language', 'en');

            // Validate language code
            if (!in_array($language, ['en', 'de'])) {
                return new JSONResponse(['success' => false, 'error' => $l->t('invalid_language_code')], 400);
            }

            // Store in session for guest users
            $this->session->set('guest_language', $language);

            // Also try to set it in user config if user is logged in
            $user = $this->userSession->getUser();
            if ($user) {
                $config = $this->config;
                $config->setUserValue($user->getUID(), 'core', 'lang', $language);
            }

            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('Portal save preferences failed', ['exception' => $e]);
            return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 500);
        }
    }

    /**
     * Download attachment (portal) – guests use this so the request stays under /portal/
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function downloadAttachment(int $id, int $attachmentId): \OCP\AppFramework\Http\Response
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $ticket = $this->ticketMapper->find($id);
            // SECURITY: do not disclose ticket existence to unauthorised
            // guests — same 404 as for missing ticket ids prevents
            // enumeration through the attachment download endpoint.
            if (!$this->permissionService->canViewTicketWithProjectAccess($ticket)) {
                return new JSONResponse(['error' => $l->t('ticket_not_found')], 404);
            }

            // After merge, bytes live on the survivor — follow when the guest may view it.
            try {
                $activeTicket = $this->ticketService->getActiveTicket($id);
            } catch (\Throwable $e) {
                return new JSONResponse(['error' => $l->t('ticket_not_found')], 404);
            }
            $activeId = (int) $activeTicket->getId();
            if ($activeId !== $id
                && !$this->permissionService->canViewTicketWithProjectAccess($activeTicket)) {
                return new JSONResponse(['error' => $l->t('ticket_not_found')], 404);
            }

            $attachment = $this->findAttachmentForTicket($activeId, $attachmentId);
            if ($attachment === null) {
                return new JSONResponse(['error' => $l->t('attachment_not_found')], 404);
            }

            if (!$this->ticketService->canViewerAccessAttachment(
                $attachment,
                $this->permissionService->canViewInternalNotes()
            )) {
                return new JSONResponse(['error' => $l->t('attachment_not_found')], 404);
            }

            $requestInline = $this->request->getParam(AttachmentDisplayHelper::INLINE_QUERY_PARAM) === '1';
            $result = $this->attachmentDelivery->buildStreamResponse($attachment, $activeId, $requestInline);
            if (!$result['ok']) {
                return new JSONResponse(['error' => $l->t($result['error'])], $result['status']);
            }

            return $result['response'];
        } catch (DoesNotExistException $e) {
            // Uniform with foreign-project deny — no missing-vs-forbidden body oracle.
            return new JSONResponse(['error' => $l->t('ticket_not_found')], 404);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $l->t('an_error_occurred')], 400);
        }
    }

    private function findAttachmentForTicket(int $ticketId, int $attachmentId): ?\OCA\Ticketcheck\Db\Attachment
    {
        foreach ($this->ticketService->getAttachments($ticketId) as $att) {
            if ($att->getId() === $attachmentId) {
                return $att;
            }
        }

        return null;
    }

    /**
     * Guest portal logout (POST only — CSRF token is required and verified by the framework)
     * Logs out the user and redirects to the Nextcloud login page.
     *
     * @UseSession
     */
    #[NoAdminRequired]
    public function logout(): \OCP\AppFramework\Http\RedirectResponse
    {
        // Log the guest user logout
        $logger = $this->logger;
        $user = $this->userSession->getUser();

        if ($user) {
            $logger->info('Guest user logging out', [
                'user_id' => $user->getUID(),
                'email' => $user->getEMailAddress()
            ]);
        }

        // Log the user out
        $this->userSession->logout();

        // Redirect to login page with clear parameter
        $response = new \OCP\AppFramework\Http\RedirectResponse(
            $this->urlGenerator->linkToRouteAbsolute('core.login.showLoginForm', ['clear' => true])
        );

        return $response;
    }

    /**
     * Tickets a guest may see in the portal.
     *
     * Loads candidates from accessible projects (plus the guest's own
     * no-project tickets), then keeps only rows that pass
     * canViewTicketWithProjectAccess. That matches detail/reply authz so the
     * list never leaks titles/metadata for tickets the guest cannot open
     * (e.g. another customer's ticket in a shared project).
     *
     * @return list<Ticket>
     */
    private function loadGuestVisibleTickets(): array
    {
        $accessibleProjectIds = $this->normalizeProjectIdList($this->permissionService->getAccessibleProjectIds());
        // Over-fetch slightly so permission filtering still fills the portal page.
        $fetchLimit = self::PORTAL_TICKET_LIST_LIMIT * 2;
        $ticketsByProject = $accessibleProjectIds === []
            ? []
            : $this->ticketMapper->findByProjects($accessibleProjectIds, $fetchLimit);

        $userEmail = (string)($this->permissionService->getCurrentUserEmail() ?? '');
        $myTicketsNoProject = [];
        if ($userEmail !== '') {
            $myTickets = $this->ticketMapper->findByCustomerEmail($userEmail, self::PORTAL_TICKET_LIST_LIMIT);
            $existingIds = array_map(static function ($t) {
                return $t->getId();
            }, $ticketsByProject);
            foreach ($myTickets as $t) {
                if ($t->getProjectId() === null && !in_array($t->getId(), $existingIds, true)) {
                    $myTicketsNoProject[] = $t;
                    $existingIds[] = $t->getId();
                }
            }
        }

        $tickets = array_merge($ticketsByProject, $myTicketsNoProject);
        $tickets = array_values(array_filter(
            $tickets,
            function ($ticket): bool {
                return $this->permissionService->canViewTicketWithProjectAccess($ticket);
            }
        ));

        usort($tickets, static function ($a, $b) {
            // created_at is NOT NULL in schema; compare newest-first.
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        if (count($tickets) > self::PORTAL_TICKET_LIST_LIMIT) {
            return array_slice($tickets, 0, self::PORTAL_TICKET_LIST_LIMIT);
        }

        return $tickets;
    }

    /**
     * Status summary for portal KPIs + the sidebar stats card.
     *
     * Keys are canonical statuses ({@see Ticket::getStatuses()}) plus `total`
     * and `open` — `open` always means "not done" so the sidebar shows the
     * same value on every portal page.
     *
     * @param array<Ticket> $tickets
     * @return array{total:int,open:int,new:int,in_progress:int,waiting:int,done:int}
     */
    private function buildPortalStatusStats(array $tickets): array
    {
        return PortalTicketDisplay::statusSummary(array_map(
            static function (Ticket $ticket): string {
                return $ticket->getStatus();
            },
            $tickets
        ));
    }

    /**
     * @param array<int, Ticket> $tickets
     * @return array<string, mixed>
     */
    private function buildPortalTicketListTemplateParams(array $tickets, ?int $scopedProjectId, string $filterBaseUrl): array
    {
        $portalProjects = $this->buildAccessiblePortalProjects();
        $allowedProjectIds = [];
        $portalProjectNames = [];
        foreach ($portalProjects as $project) {
            $id = (int)($project['id'] ?? 0);
            if ($id > 0) {
                $allowedProjectIds[] = $id;
                $portalProjectNames[$id] = (string)($project['name'] ?? '');
            }
        }

        $resolved = $this->portalTicketListFilter->resolveFromRequest(
            $this->request,
            $scopedProjectId,
            $allowedProjectIds,
        );
        $tickets = $this->portalTicketListFilter->apply($tickets, $resolved);

        return [
            'tickets' => $tickets,
            'currentFilters' => $resolved['currentFilters'],
            'statuses' => Ticket::getStatuses(),
            'priorities' => Ticket::getPriorities(),
            'categories' => Ticket::getCategories(),
            'portalProjects' => $portalProjects,
            'portalProjectNames' => $portalProjectNames,
            'portalShowProjectColumn' => $scopedProjectId === null && count($portalProjects) > 1,
            'scopedProjectId' => $scopedProjectId,
            'ticketsListHasActiveFilters' => $this->portalTicketListFilter->hasActiveFilters($resolved['currentFilters']),
            'filterBaseUrl' => $filterBaseUrl,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildAccessiblePortalProjects(): array
    {
        $accessibleProjectIds = $this->permissionService->getAccessibleProjectIds();
        if ($accessibleProjectIds === null || $accessibleProjectIds === []) {
            return [];
        }

        $projects = [];
        foreach ($accessibleProjectIds as $projectId) {
            $id = $this->normalizeOptionalPositiveInt($projectId);
            if ($id === false || $id === null) {
                continue;
            }
            $project = $this->projectService->getProject($id);
            if ($project !== null && (!isset($project['active']) || (int)$project['active'] === 1)) {
                $projects[] = $project;
            }
        }

        return $projects;
    }

    /**
     * @return int|null|false int for valid positive IDs, null when empty, false when invalid
     */
    private function normalizeOptionalPositiveInt(mixed $value): int|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : false;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            if (!ctype_digit($trimmed)) {
                return false;
            }
            $parsed = (int)$trimmed;
            return $parsed > 0 ? $parsed : false;
        }

        return false;
    }

    /**
     * @param mixed $projectIds
     * @return list<int>
     */
    private function normalizeProjectIdList(mixed $projectIds): array
    {
        if (!is_array($projectIds)) {
            return [];
        }

        $normalized = [];
        foreach ($projectIds as $projectId) {
            $id = $this->normalizeOptionalPositiveInt($projectId);
            if ($id !== null && $id !== false) {
                $normalized[] = $id;
            }
        }
        return $normalized;
    }

    /**
     * Resolve comment author label for portal templates (ARCH-21: no IUserManager in views).
     */
    private function resolveCommentAuthorDisplayName(\OCA\Ticketcheck\Db\Comment $comment): string
    {
        $authorName = (string)($comment->getAuthorName() ?? '');
        if (trim($authorName) !== '') {
            return $authorName;
        }

        $userId = $comment->getUserId();
        if ($userId) {
            $user = $this->userManager->get($userId);
            if ($user !== null) {
                $display = (string)($user->getDisplayName() ?? '');
                if (trim($display) !== '') {
                    return $display;
                }

                return $userId;
            }
        }

        return $this->l10nFactory->get($this->appName)->t('unknown');
    }

    /**
     * Enforce the same daily activity ceiling as ticket creation (tickets + replies).
     * Callers that mutate under withGuestActivityGate should re-check inside the gate;
     * this helper is a fast-fail for early returns before acquiring the gate.
     */
    /**
     * Re-check the portal activity ceiling under the exclusive guest gate, then run $action.
     *
     * $extraSlots reserves capacity for bundled side-effects (e.g. attachments on the
     * same request) so create/reply + N files cannot exceed the daily ceiling.
     *
     * @template T
     * @param callable(): T $action
     * @return T|JSONResponse
     */
    private function runGuestPortalActionWithRateLimit(\OCP\IL10N $l, callable $action, int $extraSlots = 0): mixed
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $action();
        }

        $userId = $user->getUID();
        $rateLimited = false;
        $extraSlots = max(0, $extraSlots);
        try {
            $result = $this->ticketService->withGuestActivityGate($userId, function () use ($userId, &$rateLimited, $action, $extraSlots) {
                $since = new \DateTime('-24 hours');
                $recentCount = $this->ticketService->countRecentPortalActionsByUser($userId, $since);
                $slotsNeeded = 1 + $extraSlots;
                if (!$this->permissionService->checkRateLimit($recentCount + $slotsNeeded - 1)) {
                    $rateLimited = true;
                    return null;
                }
                return $action();
            });
        } catch (\InvalidArgumentException $e) {
            // Lock contention → rate-limit response. Upload failures must not look like 429.
            if ($e->getMessage() === 'file_upload_failed_try_again') {
                return new JSONResponse([
                    'success' => false,
                    'error' => $l->t('file_upload_failed_try_again'),
                    'message' => $l->t('file_upload_failed_try_again'),
                ], 400);
            }
            return new JSONResponse([
                'success' => false,
                'error' => $l->t('too_many_requests'),
                'message' => $l->t('too_many_requests'),
            ], 429);
        }

        if ($rateLimited) {
            return new JSONResponse([
                'success' => false,
                'error' => $l->t('too_many_requests'),
                'message' => $l->t('too_many_requests'),
            ], 429);
        }

        if ($result === null) {
            // The gate closure's only null path is the rate-limit branch above —
            // anything else means the action produced no response; fail loudly
            // instead of leaking a null where a response is contractually due.
            return new JSONResponse([
                'success' => false,
                'error' => $l->t('an_error_occurred'),
                'message' => $l->t('an_error_occurred'),
            ], 500);
        }

        return $result;
    }

}

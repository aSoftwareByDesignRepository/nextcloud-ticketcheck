<?php

declare(strict_types=1);

/**
 * Ticket controller for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\MergeService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\DeletionService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCA\Ticketcheck\Service\TicketWatcherService;
use OCA\Ticketcheck\Service\SplitService;
use OCA\Ticketcheck\Service\ActivityEventService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\AttachmentDisplayHelper;
use OCA\Ticketcheck\Service\Export\ExportQueryHelper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Exception\AppAccessDeniedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Controller for ticket operations
 */
class TicketController extends Controller
{
    use CSPTrait;
    use PageRenderTrait;
    private TicketService $ticketService;
    private PermissionService $permissionService;
    private MergeService $mergeService;
    private EmailService $emailService;
    private ProjectService $projectService;
    private DeletionService $deletionService;
    private TicketMapper $ticketMapper;
    private AttachmentMapper $attachmentMapper;
    private IURLGenerator $urlGenerator;
    private IUserSession $userSession;
    private SafeFilenameService $safeFilenameService;
    private IFactory $l10nFactory;
    private IConfig $config;
    private IGroupManager $groupManager;
    private IUserManager $userManager;
    private LoggerInterface $logger;
    private TicketLinkService $ticketLinkService;
    private TicketWatcherService $ticketWatcherService;
    private SplitService $splitService;
    private ActivityEventService $activityEventService;
    private AttachmentUploadService $attachmentUploadService;
    private LocaleFormatService $localeFormat;
    private NavigationContextService $navigationContext;
    private FrontEndAssetService $frontEndAssets;
    private AttachmentDeliveryService $attachmentDelivery;

    public function __construct(
        string $appName,
        IRequest $request,
        TicketService $ticketService,
        PermissionService $permissionService,
        MergeService $mergeService,
        EmailService $emailService,
        ProjectService $projectService,
        DeletionService $deletionService,
        TicketMapper $ticketMapper,
        AttachmentMapper $attachmentMapper,
        IURLGenerator $urlGenerator,
        IUserSession $userSession,
        SafeFilenameService $safeFilenameService,
        IFactory $l10nFactory,
        IConfig $config,
        IGroupManager $groupManager,
        IUserManager $userManager,
        LoggerInterface $logger,
        TicketLinkService $ticketLinkService,
        TicketWatcherService $ticketWatcherService,
        SplitService $splitService,
        ActivityEventService $activityEventService,
        AttachmentUploadService $attachmentUploadService,
        LocaleFormatService $localeFormat,
        NavigationContextService $navigationContext,
        FrontEndAssetService $frontEndAssets,
        AttachmentDeliveryService $attachmentDelivery,
    ) {
        parent::__construct($appName, $request);
        $this->ticketService = $ticketService;
        $this->permissionService = $permissionService;
        $this->mergeService = $mergeService;
        $this->emailService = $emailService;
        $this->projectService = $projectService;
        $this->deletionService = $deletionService;
        $this->ticketMapper = $ticketMapper;
        $this->attachmentMapper = $attachmentMapper;
        $this->urlGenerator = $urlGenerator;
        $this->userSession = $userSession;
        $this->safeFilenameService = $safeFilenameService;
        $this->l10nFactory = $l10nFactory;
        $this->config = $config;
        $this->groupManager = $groupManager;
        $this->userManager = $userManager;
        $this->logger = $logger;
        $this->ticketLinkService = $ticketLinkService;
        $this->ticketWatcherService = $ticketWatcherService;
        $this->splitService = $splitService;
        $this->activityEventService = $activityEventService;
        $this->attachmentUploadService = $attachmentUploadService;
        $this->localeFormat = $localeFormat;
        $this->navigationContext = $navigationContext;
        $this->frontEndAssets = $frontEndAssets;
        $this->attachmentDelivery = $attachmentDelivery;
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
     * @return array{
     *   dbFilters: array<string, mixed>,
     *   currentFilters: array{
     *     status: string,
     *     priority: string,
     *     category: string,
     *     assigned_to: string,
     *     project_id: string,
     *     customer_id: string,
     *     search: string,
     *     hide_done: string
     *   },
     *   status: string,
     *   search: string
     * }
     */
    private function resolveTicketListFiltersFromRequest(): array
    {
        $status = (string) $this->request->getParam('status', '');
        $priority = (string) $this->request->getParam('priority', '');
        $category = (string) $this->request->getParam('category', '');
        $assignedTo = (string) $this->request->getParam('assigned_to', '');
        $projectId = (string) $this->request->getParam('project_id', '');
        $customerId = (string) $this->request->getParam('customer_id', '');
        $search = (string) $this->request->getParam('search', '');
        $hideDone = (string) $this->request->getParam('hide_done', '1');

        $filters = [];
        if ($status !== '') {
            if (strpos($status, ',') !== false) {
                $statusArray = array_map('trim', explode(',', $status));
                $normalizedStatuses = [];
                $map = [
                    'open' => 'new',
                    'in_progress' => 'in_progress',
                    'inprogress' => 'in_progress',
                    'working' => 'in_progress',
                    'waiting_customer' => 'waiting',
                    'resolved' => 'done',
                    'closed' => 'done',
                ];
                foreach ($statusArray as $s) {
                    $normalizedStatuses[] = $map[$s] ?? $s;
                }
                $filters['status_in'] = $normalizedStatuses;
            } elseif ($status === 'open') {
                $filters['status_in'] = ['new', 'in_progress', 'waiting'];
            } else {
                $map = [
                    'open' => 'new',
                    'in_progress' => 'in_progress',
                    'inprogress' => 'in_progress',
                    'working' => 'in_progress',
                    'waiting_customer' => 'waiting',
                    'resolved' => 'done',
                    'closed' => 'done',
                ];
                $filters['status'] = $map[$status] ?? $status;
            }
        }
        if ($priority !== '') {
            $filters['priority'] = $priority;
        }
        if ($category !== '') {
            $filters['category'] = $category;
        }
        if ($assignedTo !== '') {
            $filters['assigned_to'] = $assignedTo;
        }
        if ($projectId !== '') {
            $filters['project_id'] = $projectId;
        }
        if ($customerId !== '') {
            $filters['customer_id'] = $customerId;
        }

        $doneStatusAliases = ['done', 'resolved', 'closed'];
        $isFilteringByDone = in_array($status, $doneStatusAliases, true)
            || (isset($filters['status']) && $filters['status'] === 'done')
            || (isset($filters['status_in']) && in_array('done', $filters['status_in'], true));

        if ($hideDone === '1' && !$isFilteringByDone) {
            if (isset($filters['status_in'])) {
                $filters['status_in'] = array_values(array_filter($filters['status_in'], static function ($s) {
                    return $s !== 'done';
                }));
                if ($filters['status_in'] === []) {
                    unset($filters['status_in']);
                }
            } else {
                $filters['status_not'] = 'done';
            }
        }

        return [
            'dbFilters' => $filters,
            'currentFilters' => [
                'status' => $status,
                'priority' => $priority,
                'category' => $category,
                'assigned_to' => $assignedTo,
                'project_id' => $projectId,
                'customer_id' => $customerId,
                'search' => $search,
                'hide_done' => $hideDone,
            ],
            'status' => $status,
            'search' => $search,
        ];
    }

    /**
     * @param array<string, mixed> $dbFilters
     * @return array<int, Ticket>
     */
    private function fetchTicketsForListFilters(array $dbFilters, string $search, int $limit = 500): array
    {
        if ($search !== '') {
            return $this->ticketMapper->search($search, $dbFilters);
        }
        if ($dbFilters !== []) {
            return $this->ticketMapper->search('', $dbFilters);
        }

        return $this->ticketMapper->findAll($limit);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTicketListTemplateParams(string $ticketsFilterRoute, int $ticketLimit = 100): array
    {
        $resolved = $this->resolveTicketListFiltersFromRequest();
        $tickets = $this->fetchTicketsForListFilters($resolved['dbFilters'], $resolved['search'], $ticketLimit);
        $projectsAndCustomers = $this->resolveEditableProjectsAndCustomers();

        $params = [
            'tickets' => $tickets,
            'statuses' => Ticket::getStatuses(),
            'priorities' => Ticket::getPriorities(),
            'categories' => Ticket::getCategories(),
            'projects' => $projectsAndCustomers['projects'],
            'customers' => $projectsAndCustomers['customers'],
            'currentUser' => $this->userSession->getUser(),
            'isAdmin' => $this->permissionService->canManageSettings(),
            'canBulkDelete' => $this->permissionService->canBulkDeleteTickets(),
            'currentFilters' => $resolved['currentFilters'],
            'ticketsFilterRoute' => $ticketsFilterRoute,
        ];

        return array_merge($params, $this->buildTicketExportUrls($resolved['currentFilters']));
    }

    /**
     * @param array<string,mixed> $currentFilters
     * @return array<string,mixed>
     */
    private function buildTicketExportUrls(array $currentFilters): array
    {
        if (!$this->permissionService->canExportData()) {
            return [];
        }

        $helper = new ExportQueryHelper();
        $exportIndex = $this->urlGenerator->linkToRoute('ticketcheck.export.index');

        return [
            'exportIndexUrl' => $exportIndex,
            'exportAllTicketsUrl' => $exportIndex . '?entity=tickets&scope=all',
            'exportFilteredTicketsUrl' => $exportIndex . $helper->buildExportIndexQuery(
                $helper->ticketListFiltersToExportParams($currentFilters)
            ),
        ];
    }

    /**
     * List all tickets
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        if ($this->permissionService->isGuest()) {
            return new TemplateResponse($this->appName, 'redirect', [
                'url' => $this->urlGenerator->linkToRoute('ticketcheck.customerPortal.index'),
                'l' => $this->l10nFactory->get('ticketcheck'),
            ]);
        }
        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))) {
            $l = $this->l10nFactory->get('ticketcheck');
            $denied = new TemplateResponse($this->appName, 'error', [
                'message' => $l->t('access_denied_agent_admin_required'),
                'error' => $l->t('access_denied'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $denied->setStatus(Http::STATUS_FORBIDDEN);
            return $denied;
        }

        $l = $this->l10nFactory->get('ticketcheck');

        $response = $this->renderAppPage(
            'tickets',
            $this->buildTicketListTemplateParams('ticketcheck.ticket.index', 100),
            'tickets',
            $l->t('all_tickets'),
            $l->t('view_and_manage_requests'),
            'tickets',
            'staff',
            ['contextLine' => $l->t('scope_all_projects')],
        );

        return $this->configureCSPWithNonce($response, 'main');
    }

    /**
     * Show Kanban board view
     *
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function kanban(): TemplateResponse
    {
        if ($this->permissionService->isGuest()) {
            return new TemplateResponse($this->appName, 'redirect', [
                'url' => $this->urlGenerator->linkToRoute('ticketcheck.customerPortal.index'),
                'l' => $this->l10nFactory->get('ticketcheck'),
            ]);
        }
        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))) {
            $l = $this->l10nFactory->get('ticketcheck');
            $denied = new TemplateResponse($this->appName, 'error', [
                'message' => $l->t('access_denied_kanban_agent_required'),
                'error' => $l->t('access_denied'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $denied->setStatus(Http::STATUS_FORBIDDEN);
            return $denied;
        }

        $l = $this->getPageL10n();

        $response = $this->renderAppPage(
            'tickets-kanban',
            $this->buildTicketListTemplateParams('ticketcheck.ticket.kanban', 500),
            'tickets-kanban',
            $l->t('kanban_board'),
            $l->t('view_and_manage_requests'),
            'tickets-kanban',
            'staff',
            ['contextLine' => $l->t('scope_all_projects')],
        );

        return $this->configureCSPWithNonce($response, 'main');
    }

    /**
     * Show ticket detail
     *
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(int $id): TemplateResponse|RedirectResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $ticket = $this->ticketMapper->find($id);
        } catch (\OCP\AppFramework\Db\DoesNotExistException|\OCP\AppFramework\Db\MultipleObjectsReturnedException) {
            $notFound = new TemplateResponse($this->appName, 'error', [
                'message' => $l->t('ticket_not_found'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
                'isAdmin' => $this->permissionService->canManageSettings(),
            ]);
            $notFound->setStatus(Http::STATUS_NOT_FOUND);
            return $notFound;
        } catch (\Throwable $e) {
            $this->logger->error('Ticket detail lookup failed', ['ticket_id' => $id, 'exception' => $e]);
            $error = new TemplateResponse($this->appName, 'error', [
                'message' => $l->t('an_error_occurred'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
                'isAdmin' => $this->permissionService->canManageSettings(),
            ]);
            $error->setStatus(Http::STATUS_INTERNAL_SERVER_ERROR);
            return $error;
        }

        try {
            // Check permissions (project-aware for agents with limited project access)
            if (!$this->permissionService->canViewTicketWithProjectAccess($ticket)) {
                $denied = new TemplateResponse($this->appName, 'error', [
                    'message' => $l->t('access_denied'),
                    'l' => $l,
                    'urlGenerator' => $this->urlGenerator,
                    'isAdmin' => $this->permissionService->canManageSettings(),
                ]);
                $denied->setStatus(Http::STATUS_FORBIDDEN);
                return $denied;
            }

            // Comments/attachments live on the survivor after merge — send the user there.
            if ($ticket->getMergedIntoId() !== null) {
                try {
                    $survivor = $this->ticketService->getActiveTicket($id);
                } catch (\Throwable $e) {
                    $this->logger->error('Merged ticket survivor lookup failed', [
                        'ticket_id' => $id,
                        'exception' => $e,
                    ]);
                    $error = new TemplateResponse($this->appName, 'error', [
                        'message' => $l->t('an_error_occurred'),
                        'l' => $l,
                        'urlGenerator' => $this->urlGenerator,
                        'isAdmin' => $this->permissionService->canManageSettings(),
                    ]);
                    $error->setStatus(Http::STATUS_INTERNAL_SERVER_ERROR);
                    return $error;
                }
                $survivorId = (int) $survivor->getId();
                if ($survivorId !== $id
                    && $this->permissionService->canViewTicketWithProjectAccess($survivor)) {
                    return new RedirectResponse(
                        $this->urlGenerator->linkToRoute('ticketcheck.ticket.show', ['id' => $survivorId])
                    );
                }
            }

            // Get comments
            $includeInternal = $this->permissionService->canViewInternalNotes();
            $comments = $this->ticketService->getComments($id, $includeInternal);

            // Get ticket-level attachments (not linked to specific comments)
            $ticketAttachments = $this->ticketService->getAttachments($id);
            $attachments = array_filter($ticketAttachments, function ($attachment) {
                return $attachment->getCommentId() === null;
            });

            // Get comment-level attachments for each comment
            $commentsWithAttachments = [];
            foreach ($comments as $comment) {
                $commentAttachments = $this->attachmentMapper->findByCommentId($comment->getId());
                $commentsWithAttachments[] = [
                    'comment' => $comment,
                    'attachments' => $commentAttachments
                ];
            }

            // Get project info if linked
            $project = null;
            if ($ticket->getProjectId()) {
                try {
                    $project = $this->projectService->getProject($ticket->getProjectId());
                } catch (\Throwable $e) {
                    // Do not break ticket detail rendering if related project record is missing.
                    $this->logger->warning('Ticket detail project lookup failed', [
                        'ticket_id' => $id,
                        'project_id' => $ticket->getProjectId(),
                        'exception' => $e,
                    ]);
                    $project = null;
                }
            }

            // Get available agents for assignment
            $availableAgents = [];
            $groupManager = $this->groupManager;
            $userManager = $this->userManager;

                // First: Get project team members (if ticket has a project)
                if ($ticket->getProjectId()) {
                    try {
                        $projectMembers = $this->projectService->getProjectMembers($ticket->getProjectId());
                        foreach ($projectMembers as $member) {
                            $availableAgents[$member['user_id']] = [
                                'user_id' => $member['user_id'],
                                'user_name' => $member['user_name'] . ' (Project Team)',
                                'is_project_member' => true,
                            ];
                        }
                    } catch (\Exception $e) {
                        // Project not found or no members
                    }
                }

                // Second: Add all helpdesk admins and agents
                $helpdeskGroups = ['helpdesk_admins', 'helpdesk_agents'];
                foreach ($helpdeskGroups as $groupName) {
                    $group = $groupManager->get($groupName);
                    if ($group) {
                        $groupUsers = $group->getUsers();
                        if (!is_iterable($groupUsers)) {
                            continue;
                        }
                        foreach ($groupUsers as $user) {
                            $userId = $user->getUID();
                            if (!isset($availableAgents[$userId])) {
                                $availableAgents[$userId] = [
                                    'user_id' => $userId,
                                    'user_name' => $user->getDisplayName(),
                                    'is_project_member' => false,
                                ];
                            }
                        }
                    }
                }

                // Fail closed: never fall back to searching all Nextcloud users.
                // Assignees must be helpdesk staff or project members (API allowlist).

                // Sort: Project members first, then others alphabetically
            $availableAgents = array_values($availableAgents);
            usort($availableAgents, function ($a, $b) {
                if ($a['is_project_member'] && !$b['is_project_member']) return -1;
                if (!$a['is_project_member'] && $b['is_project_member']) return 1;
                return strcmp($a['user_name'], $b['user_name']);
            });

            $links = $this->ticketLinkService->getLinksForTicket($id);
            $watchers = $this->ticketWatcherService->getWatchersWithDisplay($id);

            $scopeContext = [
                'projectName' => is_array($project) ? (string)($project['name'] ?? '') : '',
                'scopeTicketRef' => $l->t('ticket_number', [(string)($ticket->getTicketNumber() ?? '')]),
            ];

            $invoicingCheckReceivablesUrl = null;
            $pcCustomerId = 0;
            if (is_array($project) && !empty($project['customer_id'])) {
                $pcCustomerId = (int) $project['customer_id'];
            } elseif ($ticket->getCustomerId()) {
                $pcCustomerId = (int) $ticket->getCustomerId();
            }
            if ($pcCustomerId > 0) {
                try {
                    $appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
                    if ($appManager->isEnabledForUser('invoicecheck')) {
                        $invoicingCheckReceivablesUrl = $this->urlGenerator->linkToRoute(
                            'invoicecheck.page.receivables',
                            ['customerId' => $pcCustomerId],
                        );
                    }
                } catch (\Throwable) {
                    $invoicingCheckReceivablesUrl = null;
                }
            }

            $response = $this->renderAppPage(
                'ticket-detail',
                [
                    'ticket' => $ticket,
                    'priorities' => Ticket::getPriorities(),
                    'comments' => $comments,
                    'commentsWithAttachments' => $commentsWithAttachments,
                    'attachments' => $attachments,
                    'project' => $project,
                    'availableAgents' => $availableAgents,
                    'links' => $links,
                    'watchers' => $watchers,
                    'canEdit' => $this->permissionService->canEditTicket($ticket),
                    'canDelete' => $this->permissionService->canDeleteTicket($ticket),
                    'canComment' => $this->permissionService->canCommentOnTicket($ticket),
                    'canViewInternal' => $this->permissionService->canViewInternalNotes(),
                    'canCreateInternal' => $this->permissionService->canCreateInternalNotes(),
                    'currentUser' => $this->userSession->getUser(),
                    'invoicingCheckReceivablesUrl' => $invoicingCheckReceivablesUrl,
                    // Merge redirect flash: count of related records (watchers,
                    // links, surveys) that could not be transferred to this
                    // survivor ticket. Strict digit-parse — anything else is 0.
                    'mergeDropped' => (function (): int {
                        $raw = (string) $this->request->getParam('merge_dropped', '');
                        return ($raw !== '' && ctype_digit($raw)) ? (int) $raw : 0;
                    })(),
                ],
                'ticket-detail',
                (string)($ticket->getTitle() ?? ''),
                '',
                'ticket-detail',
                'staff',
                $scopeContext,
            );

            // Apply CSP policy for main app context (required for file uploads and inline styles)
            return $this->configureCSPWithNonce($response, 'main');
        } catch (\Throwable $e) {
            $this->logger->error('Ticket detail render failed', ['ticket_id' => $id, 'exception' => $e]);
            $error = new TemplateResponse($this->appName, 'error', [
                'message' => $l->t('an_error_occurred'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
                'isAdmin' => $this->permissionService->canManageSettings(),
            ]);
            $error->setStatus(Http::STATUS_INTERNAL_SERVER_ERROR);
            return $error;
        }
    }

    /**
     * Show create ticket form
     *
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(): TemplateResponse
    {
        // Redirect guests to portal
        if ($this->permissionService->isGuest()) {
            return new TemplateResponse($this->appName, 'redirect', [
                'url' => $this->urlGenerator->linkToRoute('ticketcheck.customerPortal.createTicket'),
                'l' => $this->l10nFactory->get('ticketcheck'),
            ]);
        }
        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))) {
            $l = $this->l10nFactory->get('ticketcheck');
            $denied = new TemplateResponse($this->appName, 'error', [
                'message' => $l->t('access_denied_agent_admin_required'),
                'error' => $l->t('access_denied'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $denied->setStatus(Http::STATUS_FORBIDDEN);
            return $denied;
        }

        // Get projects and customers with pagination to avoid loading too many
        $projectsAndCustomers = $this->resolveEditableProjectsAndCustomers();
        $projects = $projectsAndCustomers['projects'];
        $customers = $projectsAndCustomers['customers'];

        // Users will be loaded dynamically based on selected project
        $availableAgents = [];

        $l = $this->l10nFactory->get('ticketcheck');

        $response = $this->renderAppPage(
            'ticket-form',
            [
                'ticket' => null,
                'projects' => $projects,
                'customers' => $customers,
                'priorities' => Ticket::getPriorities(),
                'categories' => Ticket::getCategories(),
                'availableAgents' => $availableAgents,
                'isEdit' => false,
            ],
            'ticket-form',
            $l->t('create_ticket'),
            '',
            'ticket-form',
            'staff',
            ['contextLine' => $l->t('scope_all_projects')],
        );

        // Apply CSP policy for main app context
        return $this->configureCSPWithNonce($response, 'main');
    }

    /**
     * Show edit ticket form
     *
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function edit(int $id): TemplateResponse|RedirectResponse
    {
        // Redirect guests to portal
        if ($this->permissionService->isGuest()) {
            return new RedirectResponse($this->urlGenerator->linkToRoute('ticketcheck.customerPortal.index'));
        }

        try {
            $ticket = $this->ticketMapper->find($id);
            $l = $this->l10nFactory->get('ticketcheck');

            if (!$this->permissionService->canViewTicketWithProjectAccess($ticket)) {
                $denied = new TemplateResponse($this->appName, 'error', [
                    'message' => $l->t('access_denied'),
                    'l' => $l,
                    'urlGenerator' => $this->urlGenerator,
                ]);
                $denied->setStatus(Http::STATUS_FORBIDDEN);
                return $denied;
            }
            if (!$this->permissionService->canEditTicket($ticket)) {
                $denied = new TemplateResponse($this->appName, 'error', [
                    'message' => $l->t('access_denied'),
                    'l' => $l,
                    'urlGenerator' => $this->urlGenerator,
                ]);
                $denied->setStatus(Http::STATUS_FORBIDDEN);
                return $denied;
            }

            // Edit the survivor after merge — editing a merged shell would save nowhere useful.
            if ($ticket->getMergedIntoId() !== null) {
                try {
                    $survivor = $this->ticketService->getActiveTicket($id);
                } catch (\Throwable $e) {
                    $this->logger->error('Merged ticket survivor lookup failed on edit', [
                        'ticket_id' => $id,
                        'exception' => $e,
                    ]);
                    $error = new TemplateResponse($this->appName, 'error', [
                        'message' => $l->t('an_error_occurred'),
                        'l' => $l,
                        'urlGenerator' => $this->urlGenerator,
                    ]);
                    $error->setStatus(Http::STATUS_INTERNAL_SERVER_ERROR);
                    return $error;
                }
                $survivorId = (int) $survivor->getId();
                if ($survivorId !== $id && $this->permissionService->canEditTicket($survivor)) {
                    return new RedirectResponse(
                        $this->urlGenerator->linkToRoute('ticketcheck.ticket.edit', ['id' => $survivorId])
                    );
                }
            }

            $projectsAndCustomers = $this->resolveEditableProjectsAndCustomers();
            $projects = $projectsAndCustomers['projects'];
            $customers = $projectsAndCustomers['customers'];

            $projectForScope = null;
            if ($ticket->getProjectId()) {
                try {
                    $projectForScope = $this->projectService->getProject($ticket->getProjectId());
                } catch (\Throwable $e) {
                    $this->logger->warning('Ticket form project lookup failed', [
                        'ticket_id' => $id,
                        'project_id' => $ticket->getProjectId(),
                        'exception' => $e,
                    ]);
                    $projectForScope = null;
                }
            }
            // Build available agents list similar to show()
            $availableAgents = [];
            $groupManager = $this->groupManager;
            $userManager = $this->userManager;

                if ($ticket->getProjectId()) {
                    try {
                        $projectMembers = $this->projectService->getProjectMembers($ticket->getProjectId());
                        foreach ($projectMembers as $member) {
                            $availableAgents[$member['user_id']] = [
                                'user_id' => $member['user_id'],
                                'user_name' => $member['user_name'] . ' (Project Team)',
                                'is_project_member' => true,
                            ];
                        }
                    } catch (\Exception $e) {
                        // ignore
                    }
                }

                $helpdeskGroups = ['helpdesk_admins', 'helpdesk_agents'];
                foreach ($helpdeskGroups as $groupName) {
                    $group = $groupManager->get($groupName);
                    if ($group) {
                        $groupUsers = $group->getUsers();
                        if (!is_iterable($groupUsers)) {
                            continue;
                        }
                        foreach ($groupUsers as $user) {
                            $userId = $user->getUID();
                            if (!isset($availableAgents[$userId])) {
                                $availableAgents[$userId] = [
                                    'user_id' => $userId,
                                    'user_name' => $user->getDisplayName(),
                                    'is_project_member' => false,
                                ];
                            }
                        }
                    }
                }

                // Fail closed: never fall back to searching all Nextcloud users.
                $availableAgents = array_values($availableAgents);
                usort($availableAgents, function ($a, $b) {
                    if ($a['is_project_member'] && !$b['is_project_member']) return -1;
                    if (!$a['is_project_member'] && $b['is_project_member']) return 1;
                    return strcmp($a['user_name'], $b['user_name']);
                });
            // Get existing attachments
            $attachments = $this->ticketService->getAttachments($id);
            // Filter out comment attachments, keep only ticket-level attachments
            $ticketAttachments = array_filter($attachments, function ($attachment) {
                return $attachment->getCommentId() === null;
            });

            $scopeContext = [
                'projectName' => is_array($projectForScope) ? (string)($projectForScope['name'] ?? '') : '',
                'scopeTicketRef' => $l->t('ticket_number', [(string)($ticket->getTicketNumber() ?? '')]),
            ];

            $response = $this->renderAppPage(
                'ticket-form',
                [
                    'ticket' => $ticket,
                    'projects' => $projects,
                    'customers' => $customers,
                    'priorities' => Ticket::getPriorities(),
                    'categories' => Ticket::getCategories(),
                    'availableAgents' => $availableAgents,
                    'attachments' => $ticketAttachments,
                    'isEdit' => true,
                ],
                'ticket-form',
                (string)($ticket->getTitle() ?? ''),
                '',
                'ticket-form',
                'staff',
                $scopeContext,
            );

            // Apply CSP policy for main app context (required for file uploads)
            return $this->configureCSPWithNonce($response, 'main');
        } catch (\Exception $e) {
            $notFound = new TemplateResponse($this->appName, 'error', [
                'message' => $this->l10nFactory->get('ticketcheck')->t('ticket_not_found'),
                'l' => $this->l10nFactory->get('ticketcheck'),
                'urlGenerator' => $this->urlGenerator,
            ]);
            $notFound->setStatus(Http::STATUS_NOT_FOUND);
            return $notFound;
        }
    }

    /**
     * Legacy alias for store() (unrouted). Kept so any external caller cannot
     * drift from the live create path's compensation / soft-fail contract.
     */
    #[NoAdminRequired]
    public function createWithAttachments(): JSONResponse
    {
        return $this->store();
    }

    /**
     * Handle multiple file uploads during ticket create/update.
     * Fails closed when any pending file does not persist.
     * Partial successes in this request are compensated (row + bytes deleted)
     * before the exception propagates — no orphan attaches from a failed batch.
     *
     * @return list<array{id: int, fileName: string, fileSize: int|string}>
     */
    private function handleMultipleFileUploads(int $ticketId, int $expectedCount): array
    {
        $uploadedFiles = [];
        $files = $_FILES['attachments'] ?? null;
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
                } catch (AppAccessDeniedException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    $this->logger->warning('File upload failed during ticket create/update', [
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
            // Match portal: compensate on any failure mode (Error included), not only
            // InvalidArgumentException / AppAccessDeniedException.
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
                $this->logger->error('Attachment batch compensation delete failed', [
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

        return new JSONResponse([
            'success' => false,
            'message' => $validation['message'] ?? $this->l10nFactory->get('ticketcheck')->t('upload_limit_exceeded'),
        ], 400);
    }

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
     * Process a single file upload
     * (Copied from CustomerPortalController for consistency)
     *
     * @param int $ticketId
     * @param array $fileData
     * @return array
     */
    /**
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $fileData
     * @return array{success: bool, message?: string, attachment?: array{id: int, fileName: string, fileSize: int|string}}
     */
    private function processSingleFileUpload(int $ticketId, array $fileData): array
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $validation = $this->attachmentUploadService->validateUploadedFile($fileData);
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
                false,
                $this->buildTicketCommentAuthzAssert(),
                false
            );

            return [
                'success' => true,
                'attachment' => [
                    'id' => $attachment->getId(),
                    'fileName' => $attachment->getFileName(),
                    'fileSize' => $attachment->getFileSize(),
                ]
            ];
        } catch (AppAccessDeniedException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Ticket upload single file failed', ['exception' => $e]);
            return ['success' => false, 'message' => $this->l10nFactory->get('ticketcheck')->t('an_error_occurred')];
        }
    }

    /**
     * Store new ticket (handles both JSON and multipart/form-data with files)
     */
    #[NoAdminRequired]
    public function store(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if ($this->permissionService->isGuest()) {
            return new JSONResponse(['success' => false, 'message' => $l->t('access_denied_use_customer_portal')], 403);
        }
        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))) {
            return new JSONResponse(['success' => false, 'message' => $l->t('access_denied')], 403);
        }
        try {
            // If a project is specified, block creation when inactive
            $projectId = $this->normalizeOptionalPositiveInt($this->request->getParam('project_id'));
            if ($projectId === false) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('invalid_project_id')
                ], 400);
            }
            if ($projectId !== null) {
                try {
                    $project = $this->projectService->getProject($projectId);
                    if ($project && isset($project['active']) && (int)$project['active'] === 0) {
                        return new JSONResponse([
                            'success' => false,
                            'message' => $l->t('project_inactive_ticket_creation_disabled')
                        ], 409);
                    }
                } catch (\Throwable $e) {
                    // If project fetch fails, fall through to normal validation
                }
            }
            $title = $this->request->getParam('title');
            $description = $this->request->getParam('description');

            $missingFields = [];
            if (empty($title) || trim($title) === '') {
                $missingFields[] = 'title';
            }
            if (empty($description) || trim($description) === '') {
                $missingFields[] = 'description';
            }
            if (!empty($missingFields)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('please_check_required_fields'),
                    'missing_fields' => $missingFields
                ], 400);
            }

            $data = [
                'title' => trim($title),
                'description' => trim($description),
                'customer_id' => $this->request->getParam('customer_id'),
                'customer_email' => $this->request->getParam('customer_email'),
                'customer_name' => $this->request->getParam('customer_name'),
                'project_id' => $projectId,
                'category' => $this->request->getParam('category', Ticket::CATEGORY_GENERAL),
                'priority' => $this->request->getParam('priority', Ticket::PRIORITY_NORMAL),
                'assigned_to' => $this->request->getParam('assigned_to'),
                'status' => $this->request->getParam('status', 'new'),
                'created_by_guest' => $this->permissionService->isGuest(),
            ];

            // If project_id is provided, get customer info from project
            if (!empty($data['project_id'])) {
                try {
                    $project = $this->projectService->getProject((int)$data['project_id']);
                    if ($project && !empty($project['customer_id'])) {
                        $data['customer_id'] = $project['customer_id'];
                        // Get customer details
                        $customer = $this->projectService->getCustomer($project['customer_id']);
                        if ($customer) {
                            $data['customer_name'] = $customer['name'];
                            $data['customer_email'] = $customer['email'] ?? '';
                        }
                    }
                } catch (\Exception $e) {
                    // If project not found or no customer, continue with provided data
                }
            }

            // Auto-fill customer info if still not provided
            if (empty($data['customer_email'])) {
                $email = trim((string) $this->permissionService->getCurrentUserEmail());
                // Companion parity: agents without a profile email must still be
                // able to file tickets — synthesize the same placeholder.
                $uid = (string) ($this->permissionService->getCurrentUserId() ?? '');
                $data['customer_email'] = $email !== '' ? $email : ($uid . '@ticketcheck.invalid');
            }
            if (empty($data['customer_name'])) {
                $data['customer_name'] = $this->permissionService->getCurrentUserDisplayName();
            }

            $attachmentValidationError = $this->validatePendingAttachmentUploads();
            if ($attachmentValidationError !== null) {
                return $attachmentValidationError;
            }
            $pendingAttachmentCount = $this->countPendingAttachmentUploads();

            $ticket = $this->ticketService->createTicket($data);

            $uploadedFiles = [];
            if ($pendingAttachmentCount > 0) {
                try {
                    $uploadedFiles = $this->handleMultipleFileUploads(
                        (int) $ticket->getId(),
                        $pendingAttachmentCount
                    );
                } catch (\Throwable $e) {
                    try {
                        $this->ticketService->deleteTicket((int) $ticket->getId());
                    } catch (\Throwable $cleanup) {
                        $this->logger->error('Staff store attach failed; ticket compensation delete failed', [
                            'ticket_id' => $ticket->getId(),
                            'exception' => $cleanup,
                        ]);
                    }
                    if ($e instanceof AppAccessDeniedException) {
                        return new JSONResponse([
                            'success' => false,
                            'message' => $l->t('access_denied'),
                        ], 403);
                    }
                    if ($e instanceof \InvalidArgumentException) {
                        return new JSONResponse([
                            'success' => false,
                            'message' => $l->t('file_upload_failed_try_again'),
                        ], 400);
                    }
                    $this->logger->error('Staff store attach failed with unexpected error', [
                        'ticket_id' => $ticket->getId(),
                        'exception' => $e,
                    ]);
                    return new JSONResponse([
                        'success' => false,
                        'message' => $l->t('server_error_try_again_later'),
                    ], 400);
                }
            }

            // Soft-fail notifications (same contract as createWithAttachments).
            try {
                $this->emailService->sendTicketCreatedNotification($ticket);
            } catch (\Throwable $e) {
                $this->logger->warning('Ticket created email failed', ['exception' => $e]);
            }

            return new JSONResponse([
                'success' => true,
                'message' => $l->t('ticket_created'),
                'ticket' => [
                    'id' => $ticket->getId(),
                    'ticket_number' => $ticket->getTicketNumber(),
                ],
                'uploaded_files' => $uploadedFiles,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Ticket store failed', ['exception' => $e]);
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('server_error_try_again_later')
            ], 400);
        }
    }

    /**
     * Update ticket (PUT request - without file uploads)
     */
    #[NoAdminRequired]
    public function update(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $resolved = $this->resolveActiveTicketForStaffMutate($id);
            if ($resolved instanceof JSONResponse) {
                return $resolved;
            }
            $ticket = $resolved;

            if (!$this->permissionService->canEditTicket($ticket)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('access_denied')
                ], 403);
            }

            $activeId = (int) $ticket->getId();
            $projectId = $this->normalizeOptionalPositiveInt($this->request->getParam('project_id'));
            if ($projectId === false) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('invalid_project_id')
                ], 400);
            }

            $projectChange = $this->prepareTicketProjectChange($ticket, $projectId);
            if ($projectChange['error'] !== null) {
                return $projectChange['error'];
            }

            $data = [
                'title' => $this->request->getParam('title'),
                'description' => $this->request->getParam('description'),
                'category' => $this->request->getParam('category'),
                'priority' => $this->request->getParam('priority'),
                'project_id' => $projectId,
            ];
            $data = array_merge($data, $projectChange['fields']);

            // Optional fields
            $status = $this->request->getParam('status');
            if ($status !== null && $status !== '') {
                $map = [
                    'open' => 'new',
                    'in_progress' => 'in_progress',
                    'inprogress' => 'in_progress',
                    'working' => 'in_progress',
                    'waiting_customer' => 'waiting',
                    'resolved' => 'done',
                    'closed' => 'done',
                ];
                $data['status'] = $map[$status] ?? $status;
            }

            $assignedTo = $this->request->getParam('assigned_to');
            // Allow empty string to unassign
            if ($assignedTo !== null) {
                $data['assigned_to'] = $assignedTo === '' ? null : $assignedTo;
            }

            $attachmentValidationError = $this->validatePendingAttachmentUploads();
            if ($attachmentValidationError !== null) {
                return $attachmentValidationError;
            }

            $updatedTicket = $this->ticketService->updateTicket(
                $activeId,
                $data,
                $this->buildTicketUpdateAuthzAssert($projectId)
            );

            // Soft-fail notifications: never turn a successful update into a false 400.
            try {
                $this->emailService->sendTicketUpdatedNotification($updatedTicket, 'Ticket details updated');
            } catch (\Throwable $e) {
                $this->logger->warning('Ticket updated email failed', ['exception' => $e]);
            }
            $currentUser = $this->userSession->getUser();
            if ($currentUser !== null) {
                try {
                    $this->activityEventService->logTicketUpdated($currentUser->getUID(), $updatedTicket);
                } catch (\Throwable $e) {
                    $this->logger->warning('Ticket updated activity failed', ['exception' => $e]);
                }
            }

            return new JSONResponse([
                'success' => true,
                'message' => $l->t('ticket_updated'),
                'ticket_id' => $updatedTicket->getId(),
            ]);
        } catch (AppAccessDeniedException $e) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('access_denied')
            ], 403);
        } catch (\Exception $e) {
            $this->logger->error('Ticket update failed', ['exception' => $e]);
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('server_error_try_again_later')
            ], 400);
        }
    }

    /**
     * Update ticket with file uploads (POST request for multipart/form-data)
     */
    #[NoAdminRequired]
    public function updatePost(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $resolved = $this->resolveActiveTicketForStaffMutate($id);
            if ($resolved instanceof JSONResponse) {
                return $resolved;
            }
            $ticket = $resolved;

            if (!$this->permissionService->canEditTicket($ticket)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('access_denied')
                ], 403);
            }

            $activeId = (int) $ticket->getId();
            $projectId = $this->normalizeOptionalPositiveInt($this->request->getParam('project_id'));
            if ($projectId === false) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('invalid_project_id')
                ], 400);
            }

            $projectChange = $this->prepareTicketProjectChange($ticket, $projectId);
            if ($projectChange['error'] !== null) {
                return $projectChange['error'];
            }

            $data = [
                'title' => $this->request->getParam('title'),
                'description' => $this->request->getParam('description'),
                'category' => $this->request->getParam('category'),
                'priority' => $this->request->getParam('priority'),
                'project_id' => $projectId,
            ];
            $data = array_merge($data, $projectChange['fields']);

            // Optional fields
            $status = $this->request->getParam('status');
            if ($status !== null && $status !== '') {
                $map = [
                    'open' => 'new',
                    'in_progress' => 'in_progress',
                    'inprogress' => 'in_progress',
                    'working' => 'in_progress',
                    'waiting_customer' => 'waiting',
                    'resolved' => 'done',
                    'closed' => 'done',
                ];
                $data['status'] = $map[$status] ?? $status;
            }

            $assignedTo = $this->request->getParam('assigned_to');
            // Allow empty string to unassign
            if ($assignedTo !== null) {
                $data['assigned_to'] = $assignedTo === '' ? null : $assignedTo;
            }

            $attachmentValidationError = $this->validatePendingAttachmentUploads();
            if ($attachmentValidationError !== null) {
                return $attachmentValidationError;
            }
            $pendingAttachmentCount = $this->countPendingAttachmentUploads();

            // Attach-first when this request includes files: never mutate ticket
            // fields if the attach batch fails (create-style all-or-nothing UX).
            // Partial attaches are compensated inside handleMultipleFileUploads.
            // If field update fails after a successful batch, compensate those
            // new attachment ids so the ticket is left unchanged.
            $uploadedFiles = [];
            try {
                if ($pendingAttachmentCount > 0) {
                    $uploadedFiles = $this->handleMultipleFileUploads(
                        $activeId,
                        $pendingAttachmentCount
                    );
                }

                $updatedTicket = $this->ticketService->updateTicket(
                    $activeId,
                    $data,
                    $this->buildTicketUpdateAuthzAssert($projectId)
                );
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('file_upload_failed_try_again'),
                    'ticket_id' => $activeId,
                ], 400);
            } catch (AppAccessDeniedException $e) {
                $this->compensateUploadedAttachments($activeId, $uploadedFiles);
                throw $e;
            } catch (\Throwable $e) {
                $this->compensateUploadedAttachments($activeId, $uploadedFiles);
                throw $e;
            }

            // Soft-fail notifications: ticket fields + attaches already saved.
            try {
                $this->emailService->sendTicketUpdatedNotification($updatedTicket, 'Ticket details updated');
            } catch (\Throwable $e) {
                $this->logger->warning('Ticket updated email failed', ['exception' => $e]);
            }
            $currentUser = $this->userSession->getUser();
            if ($currentUser !== null) {
                try {
                    $this->activityEventService->logTicketUpdated($currentUser->getUID(), $updatedTicket);
                } catch (\Throwable $e) {
                    $this->logger->warning('Ticket updated activity failed', ['exception' => $e]);
                }
            }

            return new JSONResponse([
                'success' => true,
                'message' => $l->t('ticket_updated'),
                'ticket_id' => $updatedTicket->getId(),
                'uploaded_files' => $uploadedFiles,
            ]);
        } catch (AppAccessDeniedException $e) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('access_denied')
            ], 403);
        } catch (\Exception $e) {
            $this->logger->error('Ticket update with uploads failed', ['exception' => $e]);
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('an_error_occurred')
            ], 400);
        }
    }

    /**
     * Delete ticket
     */
    #[NoAdminRequired]
    public function delete(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $resolved = $this->resolveActiveTicketForStaffMutate($id);
            if ($resolved instanceof JSONResponse) {
                return $resolved;
            }
            $ticket = $resolved;

            if (!$this->permissionService->canDeleteTicket($ticket)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('access_denied')
                ], 403);
            }

            $activeId = (int) $ticket->getId();

            // Get cascade parameter
            $cascade = $this->request->getParam('cascade', false);
            $cascade = filter_var($cascade, FILTER_VALIDATE_BOOLEAN);

            // Use DeletionService for enhanced deletion (survivor id after merge hop).
            // Re-assert delete authz under the workflow lock (demotion/move TOCTOU).
            $result = $this->deletionService->deleteEntity(
                'ticket',
                $activeId,
                $cascade,
                $this->buildTicketDeleteAuthzAssert()
            );

            return new JSONResponse([
                'success' => true,
                'message' => $result['message'],
                'deleted_counts' => $result['deleted_counts'] ?? []
            ]);
        } catch (AppAccessDeniedException $e) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('access_denied')
            ], 403);
        } catch (\Exception $e) {
            $this->logger->error('Ticket delete failed', ['exception' => $e]);
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('an_error_occurred')
            ], 400);
        }
    }

    /**
     * Delete ticket (API endpoint)
     */
    #[NoAdminRequired]
    public function apiDelete(int $id): JSONResponse
    {
        return $this->delete($id);
    }

    /**
     * Create ticket (API endpoint) – delegates to store() so same permission checks apply
     */
    #[NoAdminRequired]
    public function apiStore(): JSONResponse
    {
        return $this->store();
    }

    /**
     * Update ticket (API endpoint) – delegates to update() so same permission checks apply
     */
    #[NoAdminRequired]
    public function apiUpdate(int $id): JSONResponse
    {
        return $this->update($id);
    }

    /**
     * Change ticket status
     */
    #[NoAdminRequired]
    public function changeStatus(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $resolved = $this->resolveActiveTicketForStaffMutate($id);
            if ($resolved instanceof JSONResponse) {
                return $resolved;
            }
            $ticket = $resolved;

            if (!$this->permissionService->canEditTicket($ticket)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('access_denied')
                ], 403);
            }

            $activeId = (int) $ticket->getId();
            $oldStatus = $ticket->getStatus();
            $newStatus = $this->request->getParam('status');
            if ($newStatus !== null && $newStatus !== '') {
                $map = [
                    'open' => 'new',
                    'in_progress' => 'in_progress',
                    'inprogress' => 'in_progress',
                    'working' => 'in_progress',
                    'waiting_customer' => 'waiting',
                    'resolved' => 'done',
                    'closed' => 'done',
                ];
                $newStatus = $map[$newStatus] ?? $newStatus;
            }

            $updatedTicket = $this->ticketService->changeStatus(
                $activeId,
                $newStatus,
                $this->buildTicketEditAuthzAssert()
            );

            $currentUser = $this->userSession->getUser();
            $changedByName = $currentUser !== null ? $currentUser->getDisplayName() : null;
            // Soft-fail notifications: never turn a successful status change into a false 400.
            try {
                $this->emailService->sendStatusChangedNotification($updatedTicket, $oldStatus, $newStatus, $changedByName);
            } catch (\Throwable $e) {
                $this->logger->warning('Status changed email failed', ['exception' => $e]);
            }
            if ($currentUser !== null) {
                try {
                    $this->activityEventService->logTicketStatusChanged($currentUser->getUID(), $updatedTicket, $oldStatus, (string)$newStatus);
                } catch (\Throwable $e) {
                    $this->logger->warning('Status changed activity failed', ['exception' => $e]);
                }
            }

            return new JSONResponse([
                'success' => true,
                'message' => $l->t('status_updated')
            ]);
        } catch (AppAccessDeniedException $e) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('access_denied')
            ], 403);
        } catch (\Exception $e) {
            $this->logger->error('Ticket change status failed', ['exception' => $e]);
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('server_error_try_again_later')
            ], 400);
        }
    }

    /**
     * Merge ticket into another ticket
     * POST body: { target_id: N } or { target_ticket_number: "HD-..." }
     */
    #[NoAdminRequired]
    public function merge(int $id): JSONResponse
    {
        try {
            $targetId = $this->normalizeOptionalPositiveInt($this->request->getParam('target_id'));
            $targetTicketNumber = trim((string)$this->request->getParam('target_ticket_number', ''));
            $l = $this->l10nFactory->get('ticketcheck');

            if ($targetId === false) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('invalid_parameters'),
                ], 400);
            }

            if ($targetTicketNumber === '') {
                $targetTicketNumber = null;
            }

            if ($targetId === null && $targetTicketNumber === null) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('target_ticket_required'),
                ], 400);
            }

            $result = $this->mergeService->mergeTickets(
                $id,
                $targetId,
                $targetTicketNumber
            );

            return new JSONResponse([
                'success' => true,
                'message' => $l->t('ticket_merged_successfully'),
                'target_id' => $result['target_id'],
                'comments_moved' => $result['comments_moved'],
                'attachments_moved' => $result['attachments_moved'],
                'relations_moved' => $result['relations_moved'],
                'relations_dropped' => $result['relations_dropped'],
            ]);
        } catch (\InvalidArgumentException $e) {
            $l = $this->l10nFactory->get('ticketcheck');
            $mapped = $this->mapMergeExceptionMessage($l, $e);
            $status = ($mapped === $l->t('ticket_not_found')) ? 404 : 400;
            return new JSONResponse([
                'success' => false,
                'message' => $mapped,
            ], $status);
        } catch (\OCP\AppFramework\Db\DoesNotExistException|\OCP\AppFramework\Db\MultipleObjectsReturnedException) {
            $l = $this->l10nFactory->get('ticketcheck');
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('ticket_not_found'),
            ], 404);
        } catch (\Exception $e) {
            $this->logger->error('Ticket merge failed', ['exception' => $e]);
            $l = $this->l10nFactory->get('ticketcheck');
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('merge_failed'),
            ], 400);
        }
    }

    /**
     * Split ticket into multiple new tickets
     * POST body: { tickets: [{title, description}, ...] }
     */
    #[NoAdminRequired]
    public function split(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $tickets = $this->request->getParam('tickets', []);

            if (!is_array($tickets) || count($tickets) < 2) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('split_need_two_or_more'),
                ], 400);
            }

            $result = $this->splitService->splitTicket($id, $tickets);
            $created = array_map(fn ($t) => [
                'id' => $t->getId(),
                'ticket_number' => $t->getTicketNumber(),
                'title' => $t->getTitle(),
            ], $result['created']);

            return new JSONResponse([
                'success' => true,
                'message' => $l->t('ticket_split_successfully'),
                'created' => $created,
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['success' => false, 'message' => $this->mapWorkflowClientMessage($e, $l)], 400);
        } catch (\Exception $e) {
            $this->logger->error('Ticket split failed', ['exception' => $e]);
            $l = $this->l10nFactory->get('ticketcheck');
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('split_failed'),
            ], 400);
        }
    }

    /**
     * Get ticket links (related, blocks, blocked_by)
     */
    #[NoAdminRequired]
    public function getLinks(int $id): JSONResponse
    {
        $resolved = $this->resolveTicketForApiRead($id);
        if ($resolved instanceof JSONResponse) {
            return $resolved;
        }

        try {
            $links = $this->ticketLinkService->getLinksForTicket((int) $resolved->getId());
            return new JSONResponse(['links' => $links]);
        } catch (\Throwable $e) {
            $this->logger->error('Get links failed', ['exception' => $e]);
            return $this->ticketNotFoundJsonResponse();
        }
    }

    /**
     * Add ticket link
     * POST body: { linked_ticket_id: int, link_type: "related"|"blocks"|"blocked_by" }
     */
    #[NoAdminRequired]
    public function addLink(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $linkedId = $this->normalizeOptionalPositiveInt($this->request->getParam('linked_ticket_id'));
            $linkedNumber = $this->request->getParam('linked_ticket_number');
            $linkType = (string)($this->request->getParam('link_type') ?: 'related');
            $linkedTicketId = null;
            if ($linkedId === false) {
                return new JSONResponse(['success' => false, 'message' => $l->t('invalid_parameters')], 400);
            }
            if ($linkedId !== null) {
                $linkedTicketId = $linkedId;
            }
            if ($linkedTicketId === null && $linkedNumber !== null && $linkedNumber !== '') {
                try {
                    $linked = $this->ticketMapper->findByTicketNumber((string)$linkedNumber);
                    $linkedTicketId = $linked->getId();
                } catch (\OCP\AppFramework\Db\DoesNotExistException) {
                    return new JSONResponse(['success' => false, 'message' => $l->t('ticket_not_found')], 404);
                }
            }
            if ($linkedTicketId === null) {
                return new JSONResponse(['success' => false, 'message' => $l->t('linked_ticket_required')], 400);
            }
            $resolved = $this->resolveActiveTicketForStaffMutate($id);
            if ($resolved instanceof JSONResponse) {
                return $resolved;
            }
            if (!$this->permissionService->canEditTicket($resolved)) {
                return new JSONResponse(['success' => false, 'message' => $l->t('access_denied')], 403);
            }
            $linkedResolved = $this->resolveActiveTicketForStaffMutate($linkedTicketId);
            if ($linkedResolved instanceof JSONResponse) {
                return $linkedResolved;
            }
            if (!$this->permissionService->canEditTicket($linkedResolved)) {
                return new JSONResponse(['success' => false, 'message' => $l->t('access_denied')], 403);
            }
            $link = $this->ticketLinkService->addLink(
                (int) $resolved->getId(),
                (int) $linkedResolved->getId(),
                $linkType
            );
            return new JSONResponse([
                'success' => true,
                'link' => [
                    'id' => $link->getId(),
                    'ticket_id' => $link->getTicketId(),
                    'linked_ticket_id' => $link->getLinkedTicketId(),
                    'link_type' => $link->getLinkType(),
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            $mapped = $this->mapWorkflowClientMessage($e, $l);
            $status = ($mapped === $l->t('ticket_not_found')) ? 404 : 400;
            return new JSONResponse(['success' => false, 'message' => $mapped], $status);
        }
    }

    /**
     * Remove ticket link
     * DELETE with query: link_id OR ticket_id+linked_ticket_id+link_type
     */
    #[NoAdminRequired]
    public function removeLink(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $linkId = $this->normalizeOptionalPositiveInt($this->request->getParam('link_id'));
            $linkedId = $this->normalizeOptionalPositiveInt($this->request->getParam('linked_ticket_id'));
            $linkType = $this->request->getParam('link_type');

            if ($linkId === false || $linkedId === false) {
                return new JSONResponse(['success' => false, 'message' => $l->t('invalid_parameters')], 400);
            }

            // Authz before any link lookup — closes missing-vs-foreign + link-id probe.
            $resolved = $this->resolveActiveTicketForStaffMutate($id);
            if ($resolved instanceof JSONResponse) {
                return $resolved;
            }
            if (!$this->permissionService->canEditTicket($resolved)) {
                return new JSONResponse(['success' => false, 'message' => $l->t('access_denied')], 403);
            }
            $activeId = (int) $resolved->getId();

            if ($linkId !== null) {
                $this->ticketLinkService->removeLinkForTicket($activeId, $linkId);
            } elseif ($linkedId !== null && $linkType !== null) {
                // Mirror addLink: resolve+edit both sides before pair remove (no linked-id oracle).
                $linkedResolved = $this->resolveActiveTicketForStaffMutate($linkedId);
                if ($linkedResolved instanceof JSONResponse) {
                    return $linkedResolved;
                }
                if (!$this->permissionService->canEditTicket($linkedResolved)) {
                    return new JSONResponse(['success' => false, 'message' => $l->t('access_denied')], 403);
                }
                $this->ticketLinkService->removeLinkByPair(
                    $activeId,
                    (int) $linkedResolved->getId(),
                    (string)$linkType
                );
            } else {
                return new JSONResponse(['success' => false, 'message' => $l->t('link_identifier_required')], 400);
            }

            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException|MultipleObjectsReturnedException $e) {
            // Missing link row after ticket authz — not a ticket-existence signal.
            return new JSONResponse(['success' => false, 'message' => $l->t('invalid_parameters')], 400);
        } catch (\InvalidArgumentException $e) {
            $mapped = $this->mapWorkflowClientMessage($e, $l);
            $status = ($mapped === $l->t('ticket_not_found')) ? 404 : 400;
            return new JSONResponse(['success' => false, 'message' => $mapped], $status);
        }
    }

    /**
     * Get ticket watchers
     */
    #[NoAdminRequired]
    public function getWatchers(int $id): JSONResponse
    {
        $resolved = $this->resolveTicketForApiRead($id);
        if ($resolved instanceof JSONResponse) {
            return $resolved;
        }

        try {
            $watchers = $this->ticketWatcherService->getWatchersWithDisplay((int) $resolved->getId());
            return new JSONResponse(['watchers' => $watchers]);
        } catch (\Throwable $e) {
            $this->logger->error('Get watchers failed', ['exception' => $e]);
            return $this->ticketNotFoundJsonResponse();
        }
    }

    /**
     * Add watcher
     * POST body: { user_id: string }
     */
    #[NoAdminRequired]
    public function addWatcher(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $userId = (string)$this->request->getParam('user_id');
            if ($userId === '') {
                return new JSONResponse(['success' => false, 'message' => $l->t('user_id_required')], 400);
            }
            $resolved = $this->resolveActiveTicketForStaffMutate($id);
            if ($resolved instanceof JSONResponse) {
                return $resolved;
            }
            if (!$this->permissionService->canEditTicket($resolved)) {
                return new JSONResponse(['success' => false, 'message' => $l->t('access_denied')], 403);
            }
            $this->ticketWatcherService->addWatcher((int) $resolved->getId(), $userId);
            return new JSONResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            $mapped = $this->mapWorkflowClientMessage($e, $l);
            $status = ($mapped === $l->t('ticket_not_found')) ? 404 : 400;
            return new JSONResponse(['success' => false, 'message' => $mapped], $status);
        }
    }

    /**
     * Remove watcher
     */
    #[NoAdminRequired]
    public function removeWatcher(int $id, string $userId): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $resolved = $this->resolveActiveTicketForStaffMutate($id);
            if ($resolved instanceof JSONResponse) {
                return $resolved;
            }
            if (!$this->permissionService->canEditTicket($resolved)) {
                return new JSONResponse(['success' => false, 'message' => $l->t('access_denied')], 403);
            }
            $this->ticketWatcherService->removeWatcher((int) $resolved->getId(), $userId);
            return new JSONResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            $mapped = $this->mapWorkflowClientMessage($e, $l);
            $status = ($mapped === $l->t('ticket_not_found')) ? 404 : 400;
            return new JSONResponse(['success' => false, 'message' => $mapped], $status);
        }
    }

    /**
     * Assign ticket to user
     */
    #[NoAdminRequired]
    public function assign(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            // Authorize the surviving ticket (same merge hop as addComment).
            $resolved = $this->resolveActiveTicketForStaffMutate($id);
            if ($resolved instanceof JSONResponse) {
                return $resolved;
            }
            $ticket = $resolved;

            if (!$this->permissionService->canEditTicket($ticket)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('access_denied')
                ], 403);
            }

            $activeId = (int) $ticket->getId();
            $userId = $this->request->getParam('user_id');
            $updatedTicket = $this->ticketService->assignTicket(
                $activeId,
                $userId,
                $this->buildTicketEditAuthzAssert()
            );

            $currentUser = $this->userSession->getUser();
            $assignedByName = $currentUser !== null ? $currentUser->getDisplayName() : null;

            // Send assignment notification to agent
            if ($userId) {
                try {
                    $this->emailService->sendTicketAssignedNotification($updatedTicket, $userId, $assignedByName);
                } catch (\Throwable $e) {
                    // Soft-fail: never turn a successful assign into a false failure.
                    try {
                        $this->logger->warning('Failed to send assignment email: ' . $e->getMessage());
                    } catch (\Throwable $logEx) {
                        // ignore logging failures
                    }
                }
            }
            if ($currentUser !== null) {
                try {
                    $this->activityEventService->logTicketAssigned($currentUser->getUID(), $updatedTicket, $userId !== null ? (string)$userId : null);
                } catch (\Throwable $e) {
                    $this->logger->warning('Ticket assigned activity failed', ['exception' => $e]);
                }
            }

            return new JSONResponse([
                'success' => true,
                'message' => $l->t('ticket_assigned_successfully')
            ]);
        } catch (AppAccessDeniedException $e) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('access_denied')
            ], 403);
        } catch (\Exception $e) {
            $this->logger->error('Ticket assign failed', ['exception' => $e]);
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('an_error_occurred')
            ], 400);
        }
    }

    /**
     * Add comment to ticket
     */
    #[NoAdminRequired]
    public function addComment(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            // Authorize the surviving ticket. Authz on a merged source while the
            // comment lands on another project would be an IDOR.
            $resolved = $this->resolveActiveTicketForStaffMutate($id);
            if ($resolved instanceof JSONResponse) {
                return $resolved;
            }
            $ticket = $resolved;

            if (!$this->permissionService->canCommentOnTicket($ticket)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('access_denied')
                ], 403);
            }

            $activeId = (int) $ticket->getId();
            $content = $this->request->getParam('content');
            // Coerce JSON/form truthy values; never trust client-supplied strings as PHP truthiness.
            $isInternal = filter_var($this->request->getParam('is_internal', false), FILTER_VALIDATE_BOOLEAN);

            // Validate content is not empty
            if (!is_string($content) || trim($content) === '') {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('comment_content_required')
                ], 400);
            }

            $content = trim($content);

            // Only agents/admins may create private notes — hide UI is not enough (API bypass).
            if (!$this->permissionService->canCreateInternalNotes()) {
                $isInternal = false;
            }

            $comment = $this->ticketService->addComment(
                $activeId,
                $content,
                $isInternal,
                false,
                $this->buildTicketCommentAuthzAssert()
            );

            // Soft-fail notifications: never turn a successful comment into a false 400 (retry → duplicates).
            if (!$isInternal) {
                $currentUser = $this->userSession->getUser();
                $currentUserId = $currentUser ? $currentUser->getUID() : null;
                try {
                    $this->emailService->sendNewCommentNotification(
                        $ticket,
                        (string)($this->permissionService->getCurrentUserDisplayName() ?? ''),
                        $content,
                        $currentUserId // Exclude comment author from notifications
                    );
                } catch (\Throwable $e) {
                    $this->logger->warning('New comment email failed', ['exception' => $e]);
                }
            }
            $currentUser = $this->userSession->getUser();
            if ($currentUser !== null) {
                try {
                    $this->activityEventService->logTicketCommentAdded($currentUser->getUID(), $ticket, (bool)$isInternal);
                } catch (\Throwable $e) {
                    $this->logger->warning('Comment activity failed', ['exception' => $e]);
                }
            }

            $payload = [
                'success' => true,
                'message' => $l->t('comment_added'),
                'comment' => [
                    'id' => $comment->getId(),
                    'author' => $comment->getAuthorName(),
                    'content' => $comment->getContent(),
                    'created_at' => $comment->getCreatedAt()->format('Y-m-d H:i:s'),
                ],
            ];
            if ($activeId !== $id) {
                $payload['redirected_from'] = $id;
                $payload['ticket_id'] = $activeId;
            }

            return new JSONResponse($payload);
        } catch (AppAccessDeniedException $e) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('access_denied')
            ], 403);
        } catch (\Exception $e) {
            $this->logger->error('Ticket add comment failed', ['exception' => $e]);
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('an_error_occurred')
            ], 400);
        }
    }

    /**
     * API: Get all tickets (agents/admins only; guests must use portal)
     */
    #[NoAdminRequired]
    public function apiIndex(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if ($this->permissionService->isGuest()) {
            return new JSONResponse([
                'error' => $l->t('access_denied_use_customer_portal')
            ], 403);
        }
        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }

        // Limit API response to avoid loading too many tickets at once
        $tickets = $this->ticketMapper->findAll(1000, 0);
        return new JSONResponse($tickets);
    }

    /**
     * API: Search editable tickets that can be used as merge targets.
     */
    #[NoAdminRequired]
    public function searchMergeTargets(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if ($this->permissionService->isGuest()) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('access_denied_use_customer_portal')
            ], 403);
        }
        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('access_denied')
            ], 403);
        }

        $sourceId = $this->normalizeOptionalPositiveInt($this->request->getParam('source_id'));
        if ($sourceId === false || $sourceId === null) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('invalid_parameters')
            ], 400);
        }

        $query = trim((string)$this->request->getParam('query', ''));
        if (strlen($query) < 2) {
            return new JSONResponse([
                'success' => true,
                'tickets' => [],
                'message' => $l->t('merge_search_min_chars')
            ]);
        }

        try {
            $source = $this->ticketMapper->find($sourceId);
            if (!$this->permissionService->canEditTicket($source)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('access_denied')
                ], 403);
            }

            $tickets = [];
            foreach ($this->ticketMapper->searchMergeTargets($query, $sourceId, 20) as $ticket) {
                if (!$this->permissionService->canEditTicket($ticket)) {
                    continue;
                }

                $tickets[] = $this->formatMergeTargetTicket($ticket);
                if (count($tickets) >= 8) {
                    break;
                }
            }

            return new JSONResponse([
                'success' => true,
                'tickets' => $tickets
            ]);
        } catch (\OCP\AppFramework\Db\DoesNotExistException|\OCP\AppFramework\Db\MultipleObjectsReturnedException) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('ticket_not_found')
            ], 404);
        } catch (\Throwable $e) {
            $this->logger->error('Merge target search failed', ['exception' => $e]);
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('an_error_occurred')
            ], 500);
        }
    }

    /**
     * API: Search project/customer options for ticket list filters.
     */
    #[NoAdminRequired]
    public function searchFilterOptions(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if ($this->permissionService->isGuest()) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('access_denied_use_customer_portal')
            ], 403);
        }
        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('access_denied')
            ], 403);
        }

        $type = strtolower(trim((string)$this->request->getParam('type', '')));
        $query = trim((string)$this->request->getParam('query', ''));
        $limit = $this->normalizeOptionalPositiveInt($this->request->getParam('limit'));
        if ($limit === false || $limit === null) {
            $limit = 25;
        }
        $limit = max(5, min($limit, 50));

        try {
            if ($type === 'project') {
                if ($this->permissionService->canBrowseGlobalProjectDirectory()) {
                    $projects = $this->projectService->getAllProjects($limit, 0, false, $query);
                } else {
                    $userId = (string) ($this->permissionService->getCurrentUserId() ?? '');
                    $projects = $this->projectService->getAdministeredProjectsDirectory($userId, $limit)['projects'];
                    if ($query !== '') {
                        $needle = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
                        $projects = array_values(array_filter(
                            $projects,
                            static function (array $project) use ($needle): bool {
                                $name = (string) ($project['name'] ?? '');
                                $customer = (string) ($project['customer_name'] ?? '');
                                $hay = function_exists('mb_strtolower')
                                    ? mb_strtolower($name . ' ' . $customer, 'UTF-8')
                                    : strtolower($name . ' ' . $customer);
                                return str_contains($hay, $needle);
                            }
                        ));
                    }
                }
                $options = array_map(static function (array $project): array {
                    return [
                        'value' => (string)$project['id'],
                        'label' => !empty($project['customer_name'])
                            ? ($project['name'] . ' (' . $project['customer_name'] . ')')
                            : (string)$project['name'],
                    ];
                }, $projects);

                return new JSONResponse([
                    'success' => true,
                    'type' => 'project',
                    'options' => $options,
                ]);
            }

            if ($type === 'customer') {
                if ($this->permissionService->canBrowseGlobalProjectDirectory()) {
                    $customers = $this->projectService->getAllCustomers(500, 0, $query !== '' ? $query : null);
                } else {
                    $userId = (string) ($this->permissionService->getCurrentUserId() ?? '');
                    $customers = $this->projectService->getAdministeredProjectsDirectory($userId, 500)['customers'];
                    if ($query !== '') {
                        $needle = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
                        $customers = array_values(array_filter($customers, static function (array $customer) use ($needle): bool {
                            $name = function_exists('mb_strtolower') ? mb_strtolower((string)($customer['name'] ?? ''), 'UTF-8') : strtolower((string)($customer['name'] ?? ''));
                            $email = function_exists('mb_strtolower') ? mb_strtolower((string)($customer['email'] ?? ''), 'UTF-8') : strtolower((string)($customer['email'] ?? ''));
                            return str_contains($name, $needle) || str_contains($email, $needle);
                        }));
                    }
                }
                $customers = array_slice($customers, 0, $limit);
                $options = array_map(static function (array $customer): array {
                    return [
                        'value' => (string)$customer['id'],
                        'label' => (string)$customer['name'],
                    ];
                }, $customers);

                return new JSONResponse([
                    'success' => true,
                    'type' => 'customer',
                    'options' => $options,
                ]);
            }

            return new JSONResponse([
                'success' => false,
                'message' => $l->t('invalid_parameters')
            ], 400);
        } catch (\Throwable $e) {
            $this->logger->error('Filter option search failed', ['exception' => $e]);
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('an_error_occurred')
            ], 500);
        }
    }

    /**
     * API: Get single ticket
     */
    #[NoAdminRequired]
    public function apiShow(int $id): JSONResponse
    {
        $resolved = $this->resolveTicketForApiRead($id);
        if ($resolved instanceof JSONResponse) {
            return $resolved;
        }

        $ticket = $resolved;
        return new JSONResponse([
            'id' => $ticket->getId(),
            'ticket_number' => $ticket->getTicketNumber(),
            'title' => $ticket->getTitle(),
            'description' => $ticket->getDescription(),
            'status' => $ticket->getStatus(),
            'priority' => $ticket->getPriority(),
            'category' => $ticket->getCategory(),
            'customer_name' => $ticket->getCustomerName(),
            'customer_email' => $ticket->getCustomerEmail(),
            'assigned_to' => $ticket->getAssignedTo(),
        ]);
    }

    /**
     * Upload attachment to ticket
     */
    #[NoAdminRequired]
    public function uploadAttachment(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            // Authorize the surviving ticket (same merge hop as addComment).
            $resolved = $this->resolveActiveTicketForStaffMutate($id);
            if ($resolved instanceof JSONResponse) {
                return $resolved;
            }
            $ticket = $resolved;

            // Align with addComment: anyone who may reply may attach files to that reply.
            // canEditTicket is agent/admin-only and blocked project members from reply uploads.
            if (!$this->permissionService->canCommentOnTicket($ticket)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('access_denied')
                ], 403);
            }

            $activeId = (int) $ticket->getId();

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
            $validation = $this->attachmentUploadService->validateUploadedFile($file);
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

            $attachment = $this->ticketService->addAttachmentFromUploadedFile(
                $activeId,
                (string)$file['tmp_name'],
                $originalName,
                (int)$file['size'],
                $mimeType,
                $commentId,
                false,
                $this->buildTicketCommentAuthzAssert(),
                false
            );

            $payload = [
                'success' => true,
                'message' => $l->t('file_uploaded'),
                'attachment' => [
                    'id' => $attachment->getId(),
                    'fileName' => $attachment->getFileName(),
                    'fileSize' => $attachment->getFormattedFileSize(),
                ],
            ];
            if ($activeId !== $id) {
                $payload['redirected_from'] = $id;
            }

            return new JSONResponse($payload);
        } catch (AppAccessDeniedException $e) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('access_denied')
            ], 403);
        } catch (\Throwable $e) {
            $this->logger->error('Ticket upload comment attachment failed', ['exception' => $e]);
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('an_error_occurred')
            ], 400);
        }
    }

    /**
     * Download attachment
     *
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function downloadAttachment(int $ticketId, int $attachmentId): \OCP\AppFramework\Http\Response
    {
        $resolved = $this->resolveTicketForApiRead($ticketId);
        if ($resolved instanceof JSONResponse) {
            return $resolved;
        }

        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $ticketId = $resolved->getId();

            $attachment = $this->findAttachmentForTicket($ticketId, $attachmentId);
            if ($attachment === null) {
                return new JSONResponse([
                    'error' => $l->t('attachment_not_found')
                ], 404);
            }

            if (!$this->ticketService->canViewerAccessAttachment(
                $attachment,
                $this->permissionService->canViewInternalNotes()
            )) {
                return new JSONResponse([
                    'error' => $l->t('attachment_not_found')
                ], 404);
            }

            $requestInline = $this->request->getParam(AttachmentDisplayHelper::INLINE_QUERY_PARAM) === '1';
            $result = $this->attachmentDelivery->buildStreamResponse($attachment, $ticketId, $requestInline);
            if (!$result['ok']) {
                return new JSONResponse(['error' => $l->t($result['error'])], $result['status']);
            }

            return $result['response'];
        } catch (\Exception $e) {
            $this->logger->error('Ticket download attachment failed', ['exception' => $e]);
            return new JSONResponse([
                'error' => $l->t('an_error_occurred')
            ], 400);
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
     * Delete attachment
     */
    #[NoAdminRequired]
    public function deleteAttachment(int $ticketId, int $attachmentId): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            // Authorize the surviving ticket under the shared workflow lock path.
            $resolved = $this->resolveActiveTicketForStaffMutate($ticketId);
            if ($resolved instanceof JSONResponse) {
                return $resolved;
            }
            $ticket = $resolved;

            if (!$this->permissionService->canEditTicket($ticket)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('access_denied')
                ], 403);
            }

            $activeId = (int) $ticket->getId();

            // Row + file removal under the ticket workflow lock; re-check edit
            // authz on the locked entity (closes demotion/move TOCTOU).
            $this->ticketService->deleteAttachment(
                $activeId,
                $attachmentId,
                $this->buildTicketEditAuthzAssert()
            );

            return new JSONResponse([
                'success' => true,
                'message' => $l->t('attachment_deleted_successfully')
            ]);
        } catch (AppAccessDeniedException $e) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('access_denied')
            ], 403);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Attachment not found') {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('attachment_not_found')
                ], 404);
            }
            $this->logger->error('Ticket delete attachment failed', ['exception' => $e]);
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('an_error_occurred')
            ], 400);
        }
    }

    /**
     * Ensure a provided comment belongs to the same ticket.
     * Staff may bind attachments to internal notes; guests never see those ids.
     */
    private function commentBelongsToTicket(int $ticketId, int $commentId): bool
    {
        try {
            $includeInternal = $this->permissionService->canViewInternalNotes();
            $comments = $this->ticketService->getComments($ticketId, $includeInternal);
            foreach ($comments as $comment) {
                if ((int) $comment->getId() === $commentId) {
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
     * Legacy CSV export — GET removed (CSRF session-exfil). Use ExportController POST.
     */
    #[NoAdminRequired]
    public function exportCsv(): \OCP\AppFramework\Http\Response
    {
        $l = $this->l10nFactory->get('ticketcheck');
        return new \OCP\AppFramework\Http\JSONResponse([
            'error' => $l->t('use_export_ui'),
            'message' => $l->t('use_export_ui'),
        ], 410);
    }

    /**
     * Legacy single-ticket HTML export — GET removed (CSRF session-exfil,
     * same class as exportCsv). Prefer the Export UI / CSRF-protected POSTs.
     */
    #[NoAdminRequired]
    public function exportPdf(int $id): \OCP\AppFramework\Http\Response
    {
        $l = $this->l10nFactory->get('ticketcheck');
        return new \OCP\AppFramework\Http\JSONResponse([
            'error' => $l->t('use_export_ui'),
            'message' => $l->t('use_export_ui'),
        ], 410);
    }

    /**
     * Bulk action on multiple tickets
     */
    #[NoAdminRequired]
    public function bulkAction(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if ($this->permissionService->isGuest()) {
            return new JSONResponse(['error' => $l->t('access_denied_use_customer_portal')], 403);
        }
        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }
        try {
            $action = (string) $this->request->getParam('action', '');
            $ticketIds = $this->request->getParam('ticket_ids', []);
            $data = $this->request->getParam('data', []);
            if (!is_array($data)) {
                $data = [];
            }

            if (!is_array($ticketIds) || $ticketIds === []) {
                return new JSONResponse(['error' => $l->t('ticket_ids_array_required')], 400);
            }

            // Hard cap prevents accidental DoS / timeout from huge selections.
            $maxBulk = 100;
            if (count($ticketIds) > $maxBulk) {
                return new JSONResponse([
                    'error' => $l->t('too_many_requests'),
                ], 400);
            }

            // An unknown action must fail the whole request up front (400) —
            // never per-item "unknown_action" successes-status-200 noise.
            if (!in_array($action, ['assign', 'priority', 'status', 'delete'], true)) {
                return new JSONResponse(['error' => $l->t('unknown_action')], 400);
            }

            $statusMap = [
                'open' => 'new',
                'in_progress' => 'in_progress',
                'inprogress' => 'in_progress',
                'working' => 'in_progress',
                'waiting_customer' => 'waiting',
                'resolved' => 'done',
                'closed' => 'done',
            ];

            $results = [];
            $successCount = 0;

            foreach ($ticketIds as $ticketIdRaw) {
                $ticketId = (int) $ticketIdRaw;
                if ($ticketId <= 0) {
                    $results[] = ['id' => $ticketIdRaw, 'success' => false, 'error' => $l->t('invalid_parameters')];
                    continue;
                }

                try {
                    // Resolve via getActiveTicket only — never ticketMapper->find first
                    // (DoesNotExist used to fall through to operation_failed vs foreign ticket_not_found).
                    try {
                        $active = $this->ticketService->getActiveTicket($ticketId);
                    } catch (\Throwable $e) {
                        $results[] = ['id' => $ticketId, 'success' => false, 'error' => $l->t('ticket_not_found')];
                        continue;
                    }
                    $activeId = (int) $active->getId();
                    if ($action === 'delete' && $activeId !== $ticketId) {
                        $results[] = ['id' => $ticketId, 'success' => false, 'error' => $l->t('operation_failed')];
                        continue;
                    }
                    $ticket = $active;
                    $ticketId = $activeId;

                    // Uniform not-found for no project view; capability deny stays access_denied.
                    if (!$this->permissionService->canViewTicketWithProjectAccess($ticket)) {
                        $results[] = ['id' => $ticketId, 'success' => false, 'error' => $l->t('ticket_not_found')];
                        continue;
                    }
                    if (!$this->permissionService->canEditTicket($ticket)) {
                        $results[] = ['id' => $ticketId, 'success' => false, 'error' => $l->t('access_denied')];
                        continue;
                    }

                    switch ($action) {
                        case 'assign':
                            if (!array_key_exists('assigned_to', $data)) {
                                $results[] = ['id' => $ticketId, 'success' => false, 'error' => $l->t('invalid_parameters')];
                                break;
                            }
                            $assignee = $data['assigned_to'];
                            $assigneeId = ($assignee === null || $assignee === '') ? null : (string) $assignee;
                            $this->ticketService->assignTicket(
                                $ticketId,
                                $assigneeId,
                                $this->buildTicketEditAuthzAssert()
                            );
                            $successCount++;
                            $results[] = ['id' => $ticketId, 'success' => true];
                            break;

                        case 'priority':
                            if (!isset($data['priority']) || $data['priority'] === '') {
                                $results[] = ['id' => $ticketId, 'success' => false, 'error' => $l->t('invalid_parameters')];
                                break;
                            }
                            $this->ticketService->updateTicket(
                                $ticketId,
                                ['priority' => (string) $data['priority']],
                                $this->buildTicketEditAuthzAssert()
                            );
                            $successCount++;
                            $results[] = ['id' => $ticketId, 'success' => true];
                            break;

                        case 'status':
                            if (!isset($data['status']) || $data['status'] === '') {
                                $results[] = ['id' => $ticketId, 'success' => false, 'error' => $l->t('invalid_parameters')];
                                break;
                            }
                            $normalized = $statusMap[$data['status']] ?? (string) $data['status'];
                            if (!in_array($normalized, Ticket::getStatuses(), true)) {
                                $results[] = ['id' => $ticketId, 'success' => false, 'error' => $l->t('invalid_parameters')];
                                break;
                            }
                            $this->ticketService->changeStatus(
                                $ticketId,
                                $normalized,
                                $this->buildTicketEditAuthzAssert()
                            );
                            $successCount++;
                            $results[] = ['id' => $ticketId, 'success' => true];
                            break;

                        case 'delete':
                            if (!$this->permissionService->canDeleteTicket($ticket)) {
                                $results[] = ['id' => $ticketId, 'success' => false, 'error' => $l->t('access_denied')];
                                break;
                            }
                            // Full cascade under workflow lock; re-assert delete authz on locked entity.
                            $this->ticketService->deleteTicket(
                                $ticketId,
                                $this->buildTicketDeleteAuthzAssert()
                            );
                            $successCount++;
                            $results[] = ['id' => $ticketId, 'success' => true];
                            break;

                        default:
                            $results[] = ['id' => $ticketId, 'success' => false, 'error' => $l->t('unknown_action')];
                    }
                } catch (AppAccessDeniedException $e) {
                    $results[] = ['id' => $ticketId, 'success' => false, 'error' => $l->t('access_denied')];
                } catch (DoesNotExistException|MultipleObjectsReturnedException $e) {
                    // Uniform with foreign-project deny (no missing-vs-inaccessible oracle).
                    $results[] = ['id' => $ticketId, 'success' => false, 'error' => $l->t('ticket_not_found')];
                } catch (\Throwable $e) {
                    $message = $e->getMessage();
                    if ($message === 'Cannot modify a ticket that has been merged into another ticket'
                        || str_contains($message, 'already in progress')) {
                        $results[] = ['id' => $ticketId, 'success' => false, 'error' => $l->t('operation_failed')];
                    } else {
                        $results[] = ['id' => $ticketId, 'success' => false, 'error' => $l->t('operation_failed')];
                    }
                }
            }

            return new JSONResponse([
                'success' => true,
                'processed' => count($results),
                'succeeded' => $successCount,
                'failed' => count($results) - $successCount,
                'results' => $results
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $l->t('request_failed')], 400);
        }
    }

    /**
     * Get available users for project assignment (agents/admins only)
     */
    #[NoAdminRequired]
    public function getProjectUsers(int $projectId): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if ($this->permissionService->isGuest()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }
        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }
        try {
            return new JSONResponse([
                'success' => true,
                'users' => $this->collectAssignableUsers($projectId, null, 50)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Ticket get available agents failed', ['exception' => $e]);
            $l = $this->l10nFactory->get('ticketcheck');
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('an_error_occurred')
            ], 400);
        }
    }

    /**
     * Search assignable users for a specific ticket (assignment + watchers).
     */
    #[NoAdminRequired]
    public function searchAssignableUsers(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if ($this->permissionService->isGuest()) {
            return new JSONResponse(['success' => false, 'message' => $l->t('access_denied')], 403);
        }
        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))) {
            return new JSONResponse(['success' => false, 'message' => $l->t('access_denied')], 403);
        }

        $query = trim((string)$this->request->getParam('query', ''));
        $limit = $this->normalizeOptionalPositiveInt($this->request->getParam('limit'));
        if ($limit === false || $limit === null) {
            $limit = 25;
        }
        $limit = max(5, min($limit, 50));

        try {
            $resolved = $this->resolveActiveTicketForStaffMutate($id);
            if ($resolved instanceof JSONResponse) {
                return $resolved;
            }
            $ticket = $resolved;
            if (!$this->permissionService->canEditTicket($ticket)) {
                return new JSONResponse(['success' => false, 'message' => $l->t('access_denied')], 403);
            }

            return new JSONResponse([
                'success' => true,
                'users' => $this->collectAssignableUsers($ticket->getProjectId(), $query, $limit)
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Assignable users search failed', ['ticket_id' => $id, 'exception' => $e]);
            return new JSONResponse(['success' => false, 'message' => $l->t('an_error_occurred')], 500);
        }
    }

    /**
     * Directory search for bulk-assign picker (helpdesk agents/admins roster).
     * Never ask operators to type raw UIDs — pick from this list only.
     */
    #[NoAdminRequired]
    public function searchBulkAssignableUsers(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if ($this->permissionService->isGuest()) {
            return new JSONResponse(['success' => false, 'message' => $l->t('access_denied')], 403);
        }
        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))) {
            return new JSONResponse(['success' => false, 'message' => $l->t('access_denied')], 403);
        }

        $query = trim((string)$this->request->getParam('query', ''));
        $limit = $this->normalizeOptionalPositiveInt($this->request->getParam('limit'));
        if ($limit === false || $limit === null) {
            $limit = 25;
        }
        $limit = max(5, min($limit, 50));
        $projectId = $this->normalizeOptionalPositiveInt($this->request->getParam('project_id'));

        try {
            return new JSONResponse([
                'success' => true,
                'users' => $this->collectAssignableUsers(
                    ($projectId === false || $projectId === null) ? null : $projectId,
                    $query,
                    $limit
                ),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Bulk assignable users search failed', ['exception' => $e]);
            return new JSONResponse(['success' => false, 'message' => $l->t('an_error_occurred')], 500);
        }
    }

    /**
     * Re-assert delete authz on the locked fresh ticket entity.
     *
     * @return callable(Ticket):void
     */
    private function buildTicketDeleteAuthzAssert(): callable
    {
        return function (Ticket $fresh): void {
            if (!$this->permissionService->canDeleteTicket($fresh)) {
                throw new AppAccessDeniedException('access_denied');
            }
        };
    }

    /**
     * Re-assert edit authz on the locked fresh ticket entity.
     *
     * @return callable(Ticket):void
     */
    private function buildTicketEditAuthzAssert(): callable
    {
        return function (Ticket $fresh): void {
            if (!$this->permissionService->canEditTicket($fresh)) {
                throw new AppAccessDeniedException('access_denied');
            }
        };
    }

    /**
     * Re-assert comment authz on the locked fresh ticket entity.
     *
     * @return callable(Ticket):void
     */
    private function buildTicketCommentAuthzAssert(): callable
    {
        return function (Ticket $fresh): void {
            if (!$this->permissionService->canCommentOnTicket($fresh)) {
                throw new AppAccessDeniedException('access_denied');
            }
        };
    }

    /**
     * Re-assert edit + project-move authz on the locked fresh ticket entity.
     *
     * @return callable(Ticket):void
     */
    private function buildTicketUpdateAuthzAssert(?int $targetProjectId): callable
    {
        return function (Ticket $fresh) use ($targetProjectId): void {
            if (!$this->permissionService->canEditTicket($fresh)) {
                throw new AppAccessDeniedException('access_denied');
            }
            if (!$this->permissionService->canMoveTicketToProject($fresh, $targetProjectId)) {
                throw new AppAccessDeniedException('access_denied');
            }
        };
    }

    /**
     * Projects/customers the current user may pick on create/edit forms.
     *
     * @return array{projects: list<array<string, mixed>>, customers: list<array<string, mixed>>}
     */
    private function resolveEditableProjectsAndCustomers(): array
    {
        if ($this->permissionService->canBrowseGlobalProjectDirectory()) {
            return [
                'projects' => $this->projectService->getAllProjects(500, 0),
                'customers' => $this->projectService->getAllCustomers(500, 0),
            ];
        }

        $userId = (string) ($this->permissionService->getCurrentUserId() ?? '');
        return $this->projectService->getAdministeredProjectsDirectory($userId, 500);
    }

    /**
     * Validate a project_id change and sync customer fields when the project actually moves.
     *
     * @return array{error: ?JSONResponse, fields: array<string, mixed>}
     */
    private function prepareTicketProjectChange(Ticket $ticket, ?int $projectId): array
    {
        $l = $this->l10nFactory->get('ticketcheck');
        $currentRaw = $ticket->getProjectId();
        $currentId = $currentRaw !== null && $currentRaw !== '' ? (int) $currentRaw : null;

        if (!$this->permissionService->canMoveTicketToProject($ticket, $projectId)) {
            return [
                'error' => new JSONResponse([
                    'success' => false,
                    'message' => $l->t('access_denied'),
                ], 403),
                'fields' => [],
            ];
        }

        if ($projectId === $currentId) {
            return ['error' => null, 'fields' => []];
        }

        if ($projectId === null) {
            return [
                'error' => null,
                'fields' => [
                    'customer_id' => null,
                ],
            ];
        }

        $project = $this->projectService->getProject($projectId);
        if ($project === null) {
            return [
                'error' => new JSONResponse([
                    'success' => false,
                    'message' => $l->t('invalid_project_id'),
                ], 400),
                'fields' => [],
            ];
        }

        $fields = [
            'customer_id' => isset($project['customer_id']) && $project['customer_id'] !== ''
                ? (int) $project['customer_id']
                : null,
        ];
        if (!empty($project['customer_email'])) {
            $fields['customer_email'] = (string) $project['customer_email'];
        }
        if (!empty($project['customer_name'])) {
            $fields['customer_name'] = (string) $project['customer_name'];
        }

        return ['error' => null, 'fields' => $fields];
    }

    /**
     * @return int|null|false int for valid positive IDs, null when empty, false when invalid
     */
    private function normalizeOptionalPositiveInt(mixed $projectIdParam): int|null|false
    {
        if ($projectIdParam === null || $projectIdParam === '') {
            return null;
        }

        if (is_int($projectIdParam)) {
            return $projectIdParam > 0 ? $projectIdParam : false;
        }

        if (is_string($projectIdParam)) {
            $trimmed = trim($projectIdParam);
            if ($trimmed === '') {
                return null;
            }
            if (!ctype_digit($trimmed)) {
                return false;
            }
            $projectId = (int)$trimmed;
            return $projectId > 0 ? $projectId : false;
        }

        return false;
    }

    /**
     * @return array{id: int, ticket_number: string, title: string, customer_name: string, customer_email: string, status: string, priority: string}
     */
    private function formatMergeTargetTicket(Ticket $ticket): array
    {
        return [
            'id' => $ticket->getId(),
            'ticket_number' => $ticket->getTicketNumber(),
            'title' => $ticket->getTitle(),
            'customer_name' => $ticket->getCustomerName(),
            'customer_email' => $ticket->getCustomerEmail(),
            'status' => $ticket->getStatus(),
            'priority' => $ticket->getPriority(),
        ];
    }

    /**
     * Collect assignable users with optional text filter.
     *
     * @return array<int, array{user_id: string, user_name: string, is_project_member: bool}>
     */
    private function collectAssignableUsers(?int $projectId, ?string $query, int $limit): array
    {
        $query = $query !== null ? trim($query) : '';
        $normalizedNeedle = $query !== '' ? (function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query)) : '';
        $availableAgents = [];

        if ($projectId !== null) {
            try {
                $projectMembers = $this->projectService->getProjectMembers($projectId);
                foreach ($projectMembers as $member) {
                    if ($this->groupManager->isInGroup((string)$member['user_id'], PermissionService::GROUP_HELPDESK_CUSTOMERS)) {
                        continue;
                    }
                    $availableAgents[$member['user_id']] = [
                        'user_id' => $member['user_id'],
                        'user_name' => $member['user_name'] . ' (Project Team)',
                        'is_project_member' => true,
                    ];
                }
            } catch (\Throwable $e) {
                // project lookup failures should not break assignment search
            }
        }

        $helpdeskGroups = ['helpdesk_admins', 'helpdesk_agents'];
        foreach ($helpdeskGroups as $groupName) {
            $group = $this->groupManager->get($groupName);
            if ($group === null) {
                continue;
            }
            $groupUsers = $group->getUsers();
            if (!is_iterable($groupUsers)) {
                continue;
            }
            foreach ($groupUsers as $user) {
                $userId = $user->getUID();
                if (isset($availableAgents[$userId])) {
                    continue;
                }
                if ($this->groupManager->isInGroup($userId, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {
                    continue;
                }
                $availableAgents[$userId] = [
                    'user_id' => $userId,
                    'user_name' => $user->getDisplayName(),
                    'is_project_member' => false,
                ];
            }
        }

        // Fail closed: never fall back to searching all Nextcloud users.
        $availableAgents = array_values($availableAgents);
        if ($normalizedNeedle !== '') {
            $availableAgents = array_values(array_filter($availableAgents, static function (array $user) use ($normalizedNeedle): bool {
                $hayName = function_exists('mb_strtolower') ? mb_strtolower($user['user_name'], 'UTF-8') : strtolower($user['user_name']);
                $hayId = function_exists('mb_strtolower') ? mb_strtolower($user['user_id'], 'UTF-8') : strtolower($user['user_id']);
                return str_contains($hayName, $normalizedNeedle) || str_contains($hayId, $normalizedNeedle);
            }));
        }

        usort($availableAgents, static function (array $a, array $b): int {
            if ($a['is_project_member'] && !$b['is_project_member']) return -1;
            if (!$a['is_project_member'] && $b['is_project_member']) return 1;
            return strcmp($a['user_name'], $b['user_name']);
        });

        return array_slice($availableAgents, 0, $limit);
    }

    private function mapMergeExceptionMessage(\OCP\IL10N $l, \InvalidArgumentException $e): string
    {
        $message = trim($e->getMessage());
        // Uniform not-found for missing and non-editable targets (no existence oracle).
        if ($message === 'ticket_not_found'
            || str_starts_with($message, 'Permission denied')) {
            return $l->t('ticket_not_found');
        }

        return match ($message) {
            'Either target_id or target_ticket_number is required' => $l->t('target_ticket_required'),
            default => $l->t('merge_failed'),
        };
    }

    /**
     * Map workflow InvalidArgumentException text to stable l10n keys (no internal leak).
     */
    private function mapWorkflowClientMessage(\InvalidArgumentException $e, \OCP\IL10N $l): string
    {
        $message = trim($e->getMessage());
        if ($message === 'ticket_not_found'
            || $message === ''
            || str_starts_with($message, 'Permission denied')) {
            return $l->t('ticket_not_found');
        }
        if (str_contains($message, 'already in progress') || str_contains($message, 'expand ticket locks')) {
            return $l->t('workflow_busy_try_again');
        }
        if (str_contains($message, 'merged')) {
            return $l->t('ticket_already_merged');
        }
        if (str_contains($message, 'Link does not belong')) {
            return $l->t('invalid_parameters');
        }
        if (str_contains($message, 'Link already exists') || str_contains($message, 'already watching')) {
            return $l->t('already_exists');
        }
        if (str_contains($message, 'Cannot link a ticket to itself') || str_contains($message, 'Invalid link type')) {
            return $l->t('invalid_parameters');
        }
        if (str_contains($message, 'User not found') || str_contains($message, 'not an assignable') || str_contains($message, 'Guests cannot')) {
            return $l->t('invalid_parameters');
        }
        if (str_contains($message, 'At least 2') || str_contains($message, 'title and description') || str_contains($message, '255')) {
            return $l->t('invalid_parameters');
        }
        if (str_contains($message, 'closed ticket') || str_contains($message, 'Cannot split')) {
            return $l->t('split_failed');
        }

        return $l->t('an_error_occurred');
    }


    /**
     * Surviving ticket for staff mutations.
     * Missing → 404 ticket_not_found; AuthZ deny → 403 access_denied (never 404-as-deny).
     *
     * @return Ticket|JSONResponse
     */
    private function resolveActiveTicketForStaffMutate(int $id)
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $ticket = $this->ticketService->getActiveTicket($id);
        } catch (DoesNotExistException|MultipleObjectsReturnedException) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('ticket_not_found'),
            ], Http::STATUS_NOT_FOUND);
        }

        if (!$this->permissionService->canViewTicketWithProjectAccess($ticket)) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('access_denied'),
            ], Http::STATUS_FORBIDDEN);
        }

        return $ticket;
    }

    private function ticketNotFoundJsonResponse(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');

        return new JSONResponse([
            'error' => $l->t('ticket_not_found'),
        ], Http::STATUS_NOT_FOUND);
    }

    private function ticketAccessDeniedJsonResponse(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');

        return new JSONResponse([
            'error' => $l->t('access_denied'),
            'ok' => false,
        ], Http::STATUS_FORBIDDEN);
    }

    /**
     * @return Ticket|JSONResponse
     */
    private function resolveTicketForApiRead(int $id)
    {
        try {
            $ticket = $this->ticketMapper->find($id);
        } catch (DoesNotExistException|MultipleObjectsReturnedException) {
            return $this->ticketNotFoundJsonResponse();
        }

        if (!$this->permissionService->canViewTicketWithProjectAccess($ticket)) {
            return $this->ticketAccessDeniedJsonResponse();
        }

        // After merge, conversation/attachments live on the survivor.
        try {
            $active = $this->ticketService->getActiveTicket($id);
        } catch (\Throwable) {
            return $this->ticketNotFoundJsonResponse();
        }

        if ((int) $active->getId() !== $id
            && !$this->permissionService->canViewTicketWithProjectAccess($active)) {
            return $this->ticketAccessDeniedJsonResponse();
        }

        return $active;
    }
}

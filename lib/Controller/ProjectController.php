<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2025 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\ProjectMemberService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\SafeInternalRedirect;
use OCA\Ticketcheck\Service\DeletionService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Db\TicketMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Project Management Controller
 * Manages projects and customer relationships
 */
class ProjectController extends Controller
{
    use CSPTrait;
    use PageRenderTrait;
    private ProjectService $projectService;
    private ProjectMemberService $projectMemberService;
    private PermissionService $permissionService;
    private DeletionService $deletionService;
    private TicketMapper $ticketMapper;
    private IURLGenerator $urlGenerator;
    private IDBConnection $db;
    private IFactory $l10nFactory;
    private IUserManager $userManager;
    private IGroupManager $groupManager;
    private IUserSession $userSession;
    private LoggerInterface $logger;
    private LocaleFormatService $localeFormat;
    private NavigationContextService $navigationContext;
    private FrontEndAssetService $frontEndAssets;

    public function __construct(
        string $appName,
        IRequest $request,
        ProjectService $projectService,
        ProjectMemberService $projectMemberService,
        PermissionService $permissionService,
        DeletionService $deletionService,
        TicketMapper $ticketMapper,
        IURLGenerator $urlGenerator,
        IDBConnection $db,
        IFactory $l10nFactory,
        IUserManager $userManager,
        IGroupManager $groupManager,
        IUserSession $userSession,
        LoggerInterface $logger,
        LocaleFormatService $localeFormat,
        NavigationContextService $navigationContext,
        FrontEndAssetService $frontEndAssets,
    ) {
        parent::__construct($appName, $request);
        $this->projectService = $projectService;
        $this->projectMemberService = $projectMemberService;
        $this->permissionService = $permissionService;
        $this->deletionService = $deletionService;
        $this->ticketMapper = $ticketMapper;
        $this->urlGenerator = $urlGenerator;
        $this->db = $db;
        $this->l10nFactory = $l10nFactory;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->userSession = $userSession;
        $this->logger = $logger;
        $this->localeFormat = $localeFormat;
        $this->navigationContext = $navigationContext;
        $this->frontEndAssets = $frontEndAssets;
    }

    /**
     * List all projects
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        // Redirect guests
        if ($this->permissionService->isGuest()) {
            return new TemplateResponse($this->appName, 'redirect', [
                'url' => $this->urlGenerator->linkToRoute('ticketcheck.customerPortal.index'),
                'l' => $this->l10nFactory->get('ticketcheck'),
            ]);
        }

        // Use pagination to avoid loading too many at once
        $includeInactive = (bool)$this->request->getParam('includeInactive', false);
        $search = (string)($this->request->getParam('search', ''));
        $customerIdParsed = $this->normalizeOptionalPositiveInt($this->request->getParam('customer_id'));
        $customerId = ($customerIdParsed === false) ? null : $customerIdParsed;

        $projects = $this->projectService->getAllProjects(500, 0, $includeInactive, $search, $customerId);
        $projects = array_values(array_filter($projects, static function (array $project): bool {
            $projectId = isset($project['id']) ? (int)$project['id'] : 0;

            return $projectId > 0;
        }));
        $projects = array_values(array_filter($projects, function (array $project): bool {
            $projectId = (int)$project['id'];

            return $this->permissionService->canAccessProject($projectId);
        }));
        foreach ($projects as &$project) {
            $projectId = isset($project['id']) ? (int)$project['id'] : 0;
            $project['can_manage'] = $projectId > 0
                ? $this->permissionService->canManageProjectMembers($projectId)
                : false;
        }
        unset($project);

        $customers = $this->projectService->getAllCustomers(500, 0);
        $overviewUid = (string) ($this->permissionService->getCurrentUserId() ?? '');
        if (!$this->permissionService->canViewHelpdeskOverview($overviewUid)) {
            $allowedCustomerIds = [];
            foreach ($projects as $project) {
                if (!empty($project['customer_id'])) {
                    $allowedCustomerIds[(int)$project['customer_id']] = true;
                }
            }
            $customers = array_values(array_filter($customers, static function (array $customer) use ($allowedCustomerIds): bool {
                return isset($allowedCustomerIds[(int)($customer['id'] ?? 0)]);
            }));
        }

        $l = $this->l10nFactory->get('ticketcheck');

        $response = $this->renderAppPage(
            'projects/index',
            [
                'projects' => $projects,
                'customers' => $customers,
                'canManage' => $this->permissionService->canManageSettings(),
                'includeInactive' => $includeInactive,
                'search' => $search,
                'customer_id_filter' => $customerId,
            ],
            'projects',
            $l->t('projects'),
            '',
            null,
            'staff',
            ['contextLine' => $l->t('scope_all_projects')],
        );

        return $this->configureCSPWithNonce($response, 'main');
    }

    /**
     * Show project detail with team members
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(int $id): TemplateResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $project = $this->projectService->getProject($id);
            if (!$project) {
                $notFound = new TemplateResponse($this->appName, 'error', [
                    'error' => $l->t('project_not_found'),
                    'l' => $l,
                    'urlGenerator' => $this->urlGenerator,
                ]);
                $notFound->setStatus(Http::STATUS_NOT_FOUND);
                return $notFound;
            }
            if (!$this->permissionService->canAccessProject($id)) {
                $denied = new TemplateResponse($this->appName, 'error', [
                    'error' => $l->t('access_denied'),
                    'l' => $l,
                    'urlGenerator' => $this->urlGenerator,
                ]);
                $denied->setStatus(Http::STATUS_FORBIDDEN);
                return $denied;
            }
            $canManage = $this->permissionService->canManageProjectMembers($id);
            $customer = $project['customer_id'] ?
                $this->projectService->getCustomer($project['customer_id']) : null;

            // Get team members for this project
            $members = $this->projectMemberService->getProjectMembers($id);

            // Get available users to add (excluding existing members)
            $availableUsers = $canManage ? $this->projectMemberService->getAvailableUsers($id) : [];

            // Get guest users with access to this project
            $guests = [];
            if ($canManage) {
                try {
                    $qb = $this->db->getQueryBuilder();
                    $qb->select('ga.user_id', 'ga.created_at')
                        ->from('helpdesk_guest_access', 'ga')
                        ->where($qb->expr()->eq('ga.project_id', $qb->createNamedParameter($id)))
                        ->orderBy('ga.created_at', 'DESC');
                    $result = $qb->executeQuery();

                    while ($row = $result->fetch()) {
                        // Get user details from Nextcloud user manager
                        $user = $this->userManager->get($row['user_id']);
                        if ($user) {
                            $guests[] = [
                                'user_id' => $row['user_id'],
                                'display_name' => $user->getDisplayName(),
                                'email' => $user->getEMailAddress() ?? '',
                                'granted_at' => $row['created_at'],
                            ];
                        }
                    }
                    $result->closeCursor();
                } catch (\Throwable $e) {
                    // Table might not exist yet, log and continue
                    $this->logger->warning('Failed to load guest users for project: ' . $e->getMessage());
                }
            }

            // Get all guest users for granting access
            $allGuests = [];
            $guestGroup = $canManage ? $this->groupManager->get('helpdesk_customers') : null;
            if ($guestGroup) {
                foreach ($guestGroup->getUsers() as $user) {
                    $userId = $user->getUID();

                    // Check if already has access to this project
                    $hasAccess = false;
                    foreach ($guests as $guest) {
                        if ($guest['user_id'] === $userId) {
                            $hasAccess = true;
                            break;
                        }
                    }

                    if (!$hasAccess) {
                        $allGuests[] = [
                            'user_id' => $userId,
                            'display_name' => $user->getDisplayName(),
                            'email' => $user->getEMailAddress() ?? '',
                        ];
                    }
                }
            }

            // Get project analytics
            $projectAnalytics = $this->ticketMapper->getProjectAnalytics($id);
            $recentTickets = $this->ticketMapper->findByProjectId($id, 12);

            $pageTitle = (string)($project['name'] ?? $l->t('project_details'));
            $customerName = is_array($customer) ? (string)($customer['name'] ?? '') : '';
            $projectStatusScope = (isset($project['active']) && (int)$project['active'] === 0)
                ? $l->t('inactive_status')
                : $l->t('active_status');

            $adminCount = 0;
            foreach ($members as $memberRow) {
                if ((string)($memberRow['role'] ?? '') === 'Admin') {
                    $adminCount++;
                }
            }
            $membersForView = [];
            foreach ($members as $member) {
                $memberRole = (string)($member['role'] ?? '');
                $isLastAdmin = $memberRole === 'Admin' && $adminCount <= 1;
                $membersForView[] = array_merge($member, [
                    'can_remove' => $canManage
                        && !$isLastAdmin
                        && $this->projectMemberService->canActorRemoveMember($id, $memberRole),
                ]);
            }

            $response = $this->renderAppPage(
                'projects/detail',
                [
                    'project' => $project,
                    'customer' => $customer,
                    'members' => $membersForView,
                    'availableUsers' => $availableUsers,
                    'guests' => $guests,
                    'allGuests' => $allGuests,
                    'projectAnalytics' => $projectAnalytics,
                    'recentTickets' => $recentTickets,
                    'canManage' => $canManage,
                    'canManageGuests' => $this->permissionService->canAccessGuestUserAdministration(),
                ],
                'project-detail',
                $pageTitle,
                '',
                'project-detail',
                'staff',
                [
                    'projectName' => $pageTitle,
                    'customerName' => $customerName,
                    'projectStatusScope' => $projectStatusScope,
                ],
            );

            return $this->configureCSPWithNonce($response, 'main');
        } catch (\Throwable $e) {
            $notFound = new TemplateResponse($this->appName, 'error', [
                'error' => $l->t('project_not_found'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $notFound->setStatus(Http::STATUS_NOT_FOUND);
            return $notFound;
        }
    }

    /**
     * Show create project form
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(): TemplateResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            $denied = new TemplateResponse($this->appName, 'error', [
                'error' => $l->t('access_denied'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $denied->setStatus(Http::STATUS_FORBIDDEN);
            return $denied;
        }

        // Use pagination for customer dropdown
        $customers = $this->projectService->getAllCustomers(500, 0);
        $selectedCustomerIdRaw = $this->normalizeOptionalPositiveInt($this->request->getParam('customer_id', null));
        $selectedCustomerId = is_int($selectedCustomerIdRaw) ? $selectedCustomerIdRaw : 0;
        $returnTo = SafeInternalRedirect::sanitize((string) $this->request->getParam('return_to', ''));

        return $this->configureCSPWithNonce(
            $this->renderAppPage(
                'projects/form',
                [
                    'project' => null,
                    'customers' => $customers,
                    'selectedCustomerId' => $selectedCustomerId > 0 ? $selectedCustomerId : null,
                    'returnTo' => $returnTo,
                    'mode' => 'create',
                    'availableUsers' => [],
                ],
                'project-form',
                $l->t('create_project'),
                $l->t('project_form_scope_create_lead'),
                'project-form',
                'staff',
                ['contextLine' => $l->t('project_form_scope_create_lead')],
            ),
            'main',
        );
    }

    /**
     * Show edit project form
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function edit(int $id): TemplateResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageProjectMembers($id)) {
            $denied = new TemplateResponse($this->appName, 'error', [
                'error' => $l->t('access_denied'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $denied->setStatus(Http::STATUS_FORBIDDEN);
            return $denied;
        }

        $project = $this->projectService->getProject($id);
        if (!$project) {
            $notFound = new TemplateResponse($this->appName, 'error', [
                'error' => $l->t('project_not_found'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $notFound->setStatus(Http::STATUS_NOT_FOUND);
            return $notFound;
        }

        $customers = $this->projectService->getAllCustomers(500, 0);
        $availableUsers = $this->projectMemberService->getAvailableUsers($id);

        $customerName = '';
        if (!empty($project['customer_id'])) {
            try {
                $cust = $this->projectService->getCustomer((int)$project['customer_id']);
                $customerName = is_array($cust) ? (string)($cust['name'] ?? '') : '';
            } catch (\Throwable $e) {
                $customerName = '';
            }
        }

        $pageTitle = (string)($project['name'] ?? $l->t('edit_project'));

        return $this->configureCSPWithNonce(
            $this->renderAppPage(
                'projects/form',
                [
                    'project' => $project,
                    'customers' => $customers,
                    'mode' => 'edit',
                    'availableUsers' => $availableUsers,
                    'returnTo' => '',
                ],
                'project-form',
                $l->t('edit_project'),
                '',
                'project-form',
                'staff',
                [
                    'projectName' => $pageTitle,
                    'customerName' => $customerName,
                ],
            ),
            'main',
        );
    }

    /**
     * Store new project
     */
    #[NoAdminRequired]
    public function store(): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new DataResponse(['error' => $l->t('access_denied')], 403);
        }

        try {
            $name = $this->request->getParam('name');
            $customerId = $this->normalizeOptionalPositiveInt($this->request->getParam('customer_id'));
            if ($customerId === false) {
                return new DataResponse(['error' => $l->t('invalid_customer_id')], 400);
            }
            $description = $this->request->getParam('description', '');
            $activeParam = $this->request->getParam('active');
            $active = $activeParam !== null
                ? filter_var($activeParam, FILTER_VALIDATE_BOOLEAN)
                : true;

            // Whitespace-only names must not pass the emptiness check —
            // trim at the request boundary before validating/persisting.
            $name = trim((string)$name);
            if ($name === '') {
                return new DataResponse(['error' => $l->t('project_name_required')], 400);
            }

            // Get current user
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse(['error' => $l->t('user_not_authenticated')], 401);
            }

            $projectId = $this->projectService->createProject($name, $description, $customerId, $user->getUID(), $active);

            return new DataResponse([
                'success' => true,
                'project_id' => $projectId,
                'message' => $l->t('project_created_successfully'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Project operation failed', ['exception' => $e]);
            return new DataResponse(['error' => $l->t('an_error_occurred')], 400);
        }
    }

    /**
     * Update project
     */
    #[NoAdminRequired]
    public function update(int $id): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageProjectMembers($id)) {
            return new DataResponse(['error' => $l->t('access_denied')], 403);
        }

        try {
            // Uniform 404: a zero-row UPDATE must not masquerade as success.
            if ($this->projectService->getProject($id) === null) {
                return new DataResponse(['error' => $l->t('project_not_found')], 404);
            }
            $name = trim((string)$this->request->getParam('name'));
            if ($name === '') {
                return new DataResponse(['error' => $l->t('project_name_required')], 400);
            }
            $customerId = $this->normalizeOptionalPositiveInt($this->request->getParam('customer_id'));
            if ($customerId === false) {
                return new DataResponse(['error' => $l->t('invalid_customer_id')], 400);
            }
            $description = $this->request->getParam('description', '');
            $active = $this->request->getParam('active');
            $activeBool = $active !== null ? filter_var($active, FILTER_VALIDATE_BOOLEAN) : null;

            $this->projectService->updateProject($id, $name, $customerId, $description, $activeBool);

            return new DataResponse([
                'success' => true,
                'message' => $l->t('project_updated_successfully'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Project operation failed', ['exception' => $e]);
            return new DataResponse(['error' => $l->t('an_error_occurred')], 400);
        }
    }

    /**
     * Delete project
     */
    #[NoAdminRequired]
    public function delete(int $id): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageProjectMembers($id)) {
            return new DataResponse(['error' => $l->t('access_denied')], 403);
        }

        try {
            // Uniform 404: deleting a missing project is a not-found, not a generic error.
            if ($this->projectService->getProject($id) === null) {
                return new DataResponse(['error' => $l->t('project_not_found')], 404);
            }
            // Get cascade parameter
            $cascade = $this->request->getParam('cascade', false);
            $cascade = filter_var($cascade, FILTER_VALIDATE_BOOLEAN);

            // Use DeletionService for enhanced deletion
            $result = $this->deletionService->deleteEntity('project', $id, $cascade);

            return new DataResponse([
                'success' => true,
                'message' => $result['message'],
                'deleted_counts' => $result['deleted_counts']
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Project operation failed', ['exception' => $e]);
            return new DataResponse(['error' => $l->t('an_error_occurred')], 400);
        }
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

    protected function getPageL10n(): IL10N
    {
        return $this->l10nFactory->get($this->appName);
    }

    protected function getNavigationContextService(): NavigationContextService
    {
        return $this->navigationContext;
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
}

<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2025 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\DeletionService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Db\TicketMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Customer Management Controller
 * Manages customers and their guest users
 */
class CustomerController extends Controller
{
    use CSPTrait;
    use PageRenderTrait;

    private ProjectService $projectService;
    private PermissionService $permissionService;
    private DeletionService $deletionService;
    private TicketMapper $ticketMapper;
    private IURLGenerator $urlGenerator;
    /** @phpstan-ignore-next-line */
    private IGroupManager $groupManager;
    private IUserManager $userManager;
    private IDBConnection $db;
    private IFactory $l10nFactory;
    private IUserSession $userSession;
    private LoggerInterface $logger;
    private LocaleFormatService $localeFormat;
    private NavigationContextService $navigationContext;
    private FrontEndAssetService $frontEndAssets;
    private IAppManager $appManager;

    public function __construct(
        string $appName,
        IRequest $request,
        ProjectService $projectService,
        PermissionService $permissionService,
        DeletionService $deletionService,
        TicketMapper $ticketMapper,
        IURLGenerator $urlGenerator,
        IGroupManager $groupManager,
        IUserManager $userManager,
        IDBConnection $db,
        IFactory $l10nFactory,
        IUserSession $userSession,
        LoggerInterface $logger,
        LocaleFormatService $localeFormat,
        NavigationContextService $navigationContext,
        FrontEndAssetService $frontEndAssets,
        IAppManager $appManager,
    ) {
        parent::__construct($appName, $request);
        $this->projectService = $projectService;
        $this->permissionService = $permissionService;
        $this->deletionService = $deletionService;
        $this->ticketMapper = $ticketMapper;
        $this->urlGenerator = $urlGenerator;
        $this->groupManager = $groupManager;
        $this->userManager = $userManager;
        $this->db = $db;
        $this->l10nFactory = $l10nFactory;
        $this->userSession = $userSession;
        $this->logger = $logger;
        $this->localeFormat = $localeFormat;
        $this->navigationContext = $navigationContext;
        $this->frontEndAssets = $frontEndAssets;
        $this->appManager = $appManager;
    }

    /**
     * List all customers
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canAccessCustomerAdministration()) {
            $denied = new TemplateResponse($this->appName, 'error', [
                'error' => $l->t('access_denied'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $denied->setStatus(Http::STATUS_FORBIDDEN);
            return $denied;
        }

        $search = trim((string)$this->request->getParam('search', ''));
        $customers = $this->projectService->getAllCustomers(500, 0, $search !== '' ? $search : null);

        foreach ($customers as &$customer) {
            $customerId = (int)($customer['id'] ?? 0);
            $projects = $customerId > 0 ? $this->projectService->getProjectsByCustomer($customerId) : [];
            $customer['project_count'] = count($projects);
            $guestUsers = $this->getGuestUsersForCustomer($customer);
            $customer['guest_count'] = count($guestUsers);
        }
        unset($customer);

        $l = $this->l10nFactory->get('ticketcheck');

        $response = $this->renderAppPage(
            'customers/index',
            [
                'customers' => $customers,
                'search' => $search,
            ],
            'customers',
            $l->t('customers_page_title'),
            $l->t('customers_subtitle'),
            'customers',
            'staff',
            ['contextLine' => $l->t('customers_subtitle')],
        );

        return $this->configureCSPWithNonce($response, 'main');
    }

    /**
     * Show create customer form
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(): TemplateResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canAccessCustomerAdministration()) {
            $denied = new TemplateResponse($this->appName, 'error', [
                'error' => $l->t('access_denied'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $denied->setStatus(Http::STATUS_FORBIDDEN);
            return $denied;
        }

        $projects = $this->projectService->getAllProjects(1000, 0, true);
        $assignableProjects = array_values(array_filter($projects, static function (array $project): bool {
            return empty($project['customer_id']);
        }));

        return $this->configureCSPWithNonce(
            $this->renderAppPage(
                'customers/form',
                [
                    'customer' => null,
                    'isEdit' => false,
                    'assignableProjects' => $assignableProjects,
                    'selectedProjectIds' => [],
                ],
                'customer-form',
                $l->t('create_customer'),
                $l->t('customer_form_scope_create_lead'),
                'customer-form',
                'staff',
                ['contextLine' => $l->t('customer_form_scope_create_lead')],
            ),
            'main',
        );
    }

    /**
     * Show customer detail
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(int $id): TemplateResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canAccessCustomerAdministration()) {
            $denied = new TemplateResponse($this->appName, 'error', [
                'error' => $l->t('access_denied'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $denied->setStatus(Http::STATUS_FORBIDDEN);
            return $denied;
        }

        try {
            $customer = $this->projectService->getCustomer($id);
            if ($customer === null) {
                $notFound = new TemplateResponse($this->appName, 'error', [
                    'error' => $l->t('customer_not_found'),
                    'l' => $l,
                    'urlGenerator' => $this->urlGenerator,
                ]);
                $notFound->setStatus(Http::STATUS_NOT_FOUND);
                return $notFound;
            }
            $projects = $this->projectService->getProjectsByCustomer($id);
            foreach ($projects as &$project) {
                $projectId = isset($project['id']) ? (int)$project['id'] : 0;
                $project['can_manage'] = $projectId > 0
                    ? $this->permissionService->canManageProjectMembers($projectId)
                    : false;
            }
            unset($project);
            $guestUsers = is_array($customer) ? $this->getGuestUsersForCustomer($customer) : [];
            $customerAnalytics = $this->ticketMapper->getCustomerAnalytics($id);
            $recentTickets = $this->ticketMapper->findByCustomerId($id, 12);

            $pageTitle = (string)($customer['name'] ?? $l->t('customer_details'));

            $invoicingCheckUrl = null;
            if ($this->appManager->isEnabledForUser('invoicecheck')) {
                // TicketCheck customer ids are ProjectCheck customer ids — deep-link
                // to IC receivables filtered by pc_customer_id (never invent amounts).
                $invoicingCheckUrl = $this->urlGenerator->linkToRoute('invoicecheck.page.receivables', [
                    'customerId' => $id,
                ]);
            }

            $response = $this->renderAppPage(
                'customers/detail',
                [
                    'customer' => $customer,
                    'projects' => $projects,
                    'guestUsers' => $guestUsers,
                    'customerAnalytics' => $customerAnalytics,
                    'recentTickets' => $recentTickets,
                    'invoicingCheckUrl' => $invoicingCheckUrl,
                ],
                'customer-detail',
                $pageTitle,
                $l->t('customer_details_and_projects'),
                'customer-detail',
                'staff',
                ['customerName' => $pageTitle],
            );

            return $this->configureCSPWithNonce($response, 'main');
        } catch (\Throwable $e) {
            $notFound = new TemplateResponse($this->appName, 'error', [
                'error' => $l->t('customer_not_found'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $notFound->setStatus(Http::STATUS_NOT_FOUND);
            return $notFound;
        }
    }

    /**
     * Show edit customer form
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function edit(int $id): TemplateResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canAccessCustomerAdministration()) {
            $denied = new TemplateResponse($this->appName, 'error', [
                'error' => $l->t('access_denied'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $denied->setStatus(Http::STATUS_FORBIDDEN);
            return $denied;
        }

        try {
            $customer = $this->projectService->getCustomer($id);
            if ($customer === null) {
                $notFound = new TemplateResponse($this->appName, 'error', [
                    'error' => $l->t('customer_not_found'),
                    'l' => $l,
                    'urlGenerator' => $this->urlGenerator,
                ]);
                $notFound->setStatus(Http::STATUS_NOT_FOUND);
                return $notFound;
            }
            $projects = $this->projectService->getAllProjects(1000, 0, true);
            $assignableProjects = array_values(array_filter($projects, static function (array $project) use ($id): bool {
                return empty($project['customer_id']) || (int) $project['customer_id'] === $id;
            }));
            $selectedProjectIds = array_map(static fn(array $p): int => (int) $p['id'], $this->projectService->getProjectsByCustomer($id));

            $pageTitle = (string)($customer['name'] ?? $l->t('edit_customer'));

            $response = $this->renderAppPage(
                'customers/form',
                [
                    'customer' => $customer,
                    'isEdit' => true,
                    'assignableProjects' => $assignableProjects,
                    'selectedProjectIds' => $selectedProjectIds,
                ],
                'customer-form',
                $l->t('edit_customer'),
                '',
                'customer-form',
                'staff',
                ['customerName' => $pageTitle],
            );

            return $this->configureCSPWithNonce($response, 'main');
        } catch (\Throwable $e) {
            $notFound = new TemplateResponse($this->appName, 'error', [
                'error' => $l->t('customer_not_found'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $notFound->setStatus(Http::STATUS_NOT_FOUND);
            return $notFound;
        }
    }

    /**
     * Store new customer
     */
    #[NoAdminRequired]
    public function store(): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canAccessCustomerAdministration()) {
            return new DataResponse(['error' => $l->t('access_denied')], 403);
        }

        try {
            $name = trim((string)$this->request->getParam('name', ''));
            $email = trim((string)$this->request->getParam('email', ''));
            $phone = $this->normalizeCustomerOptionalField($this->request->getParam('phone', ''), 50);
            $notes = $this->normalizeCustomerOptionalField($this->request->getParam('notes', ''), 65535);

            if ($name === '') {
                return new DataResponse(['error' => $l->t('customer_name_required')], 400);
            }
            if ($email === '') {
                return new DataResponse(['error' => $l->t('email_address_required')], 400);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return new DataResponse(['error' => $l->t('invalid_email_address')], 400);
            }

            // Get current user
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse(['error' => $l->t('user_not_authenticated')], 401);
            }

            $customerId = $this->projectService->createCustomer($name, $email, null, $phone, $notes, $user->getUID());
            $projectIds = $this->parseProjectIds();
            if (!empty($projectIds)) {
                $this->syncCustomerProjects($customerId, $projectIds, false);
            }

            return new DataResponse([
                'success' => true,
                'customer_id' => $customerId,
                'message' => $l->t('customer_created_successfully'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Customer operation failed', ['exception' => $e]);
            return new DataResponse(['error' => $l->t('an_error_occurred')], 400);
        }
    }

    /**
     * Update customer
     */
    #[NoAdminRequired]
    public function update(int $id): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canAccessCustomerAdministration()) {
            return new DataResponse(['error' => $l->t('access_denied')], 403);
        }

        try {
            $name = trim((string)$this->request->getParam('name', ''));
            $email = trim((string)$this->request->getParam('email', ''));
            $phone = $this->normalizeCustomerOptionalField($this->request->getParam('phone', ''), 50);
            $notes = $this->normalizeCustomerOptionalField($this->request->getParam('notes', ''), 65535);

            if ($name === '') {
                return new DataResponse(['error' => $l->t('customer_name_required')], 400);
            }
            if ($email === '') {
                return new DataResponse(['error' => $l->t('email_address_required')], 400);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return new DataResponse(['error' => $l->t('invalid_email_address')], 400);
            }

            // Uniform 404: a zero-row UPDATE must not masquerade as success.
            if ($this->projectService->getCustomer($id) === null) {
                return new DataResponse(['error' => $l->t('customer_not_found')], 404);
            }

            $this->projectService->updateCustomer($id, $name, $email, null, $phone, $notes);
            $projectIds = $this->parseProjectIds();
            if ($projectIds !== null) {
                $this->syncCustomerProjects($id, $projectIds, true);
            }

            return new DataResponse([
                'success' => true,
                'message' => $l->t('customer_updated_successfully'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Customer operation failed', ['exception' => $e]);
            return new DataResponse(['error' => $l->t('an_error_occurred')], 400);
        }
    }

    /**
     * Delete customer
     */
    #[NoAdminRequired]
    public function delete(int $id): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canAccessCustomerAdministration()) {
            return new DataResponse(['error' => $l->t('access_denied')], 403);
        }

        try {
            // Uniform 404: deleting a missing customer is a not-found, not a generic error.
            if ($this->projectService->getCustomer($id) === null) {
                return new DataResponse(['error' => $l->t('customer_not_found')], 404);
            }
            // Get cascade parameter
            $cascade = $this->request->getParam('cascade', false);
            $cascade = filter_var($cascade, FILTER_VALIDATE_BOOLEAN);

            // Use DeletionService for enhanced deletion
            $result = $this->deletionService->deleteEntity('customer', $id, $cascade);

            return new DataResponse([
                'success' => true,
                'message' => $result['message'],
                'deleted_counts' => $result['deleted_counts'] ?? []
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Customer operation failed', ['exception' => $e]);
            return new DataResponse(['error' => $l->t('an_error_occurred')], 400);
        }
    }

    /**
     * Get guest users that have access to any of the customer's projects
     */
    /**
     * @param array{id?: int|string} $customer
     * @return list<array{user_id: string, display_name: string, email: string, created_at: string}>
     */
    private function getGuestUsersForCustomer(array $customer): array
    {
        $guests = [];
        $customerIdRaw = $customer['id'] ?? null;
        $customerId = is_numeric($customerIdRaw) ? (int)$customerIdRaw : 0;
        if ($customerId <= 0) {
            return [];
        }

        try {
            // Get all project IDs for this customer
            $projects = $this->projectService->getProjectsByCustomer($customerId);

            if (empty($projects)) {
                return [];
            }

            $projectIds = array_column($projects, 'id');

            // Filter out any invalid project IDs (in case of data inconsistency)
            $projectIds = array_filter($projectIds, function ($id) {
                return is_numeric($id) && $id > 0;
            });

            if (empty($projectIds)) {
                return [];
            }

            // Simple approach: check each project individually to avoid complex queries
            $allUserIds = [];
            foreach ($projectIds as $projectId) {
                try {
                    $qb = $this->db->getQueryBuilder();
                    $qb->select('user_id')
                        ->from('helpdesk_guest_access')
                        ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)));

                    $result = $qb->executeQuery();
                    while ($row = $result->fetch()) {
                        $allUserIds[] = $row['user_id'];
                    }
                    $result->closeCursor();
                } catch (\Throwable $e) {
                    // Log error for this specific project but continue
                    $this->logger->warning('Error fetching guest users for project', [
                        'project_id' => $projectId,
                        'exception' => $e,
                    ]);
                    continue;
                }
            }

            // Remove duplicates
            $uniqueUserIds = array_unique($allUserIds);

            // Get user details for each guest user
            foreach ($uniqueUserIds as $userId) {
                try {
                    $user = $this->userManager->get($userId);
                    if ($user) {
                        // Get the creation date from guest_project_access table
                        $qb = $this->db->getQueryBuilder();
                        $qb->select('created_at')
                            ->from('helpdesk_guest_access')
                            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                            ->orderBy('created_at', 'ASC')
                            ->setMaxResults(1);
                        $result = $qb->executeQuery();
                        $createdAt = $result->fetchOne();
                        $result->closeCursor();

                        $guests[] = [
                            'user_id' => $userId,
                            'display_name' => $user->getDisplayName(),
                            'email' => $user->getEMailAddress() ?? '',
                            'created_at' => $createdAt ?: date('Y-m-d H:i:s'),
                        ];
                    } else {
                        $this->logger->info('Guest user not found — may have been deleted', [
                            'user_id' => $userId,
                        ]);
                    }
                } catch (\Throwable $e) {
                    // Log error for this specific user but continue
                    $this->logger->warning('Error fetching details for guest user', [
                        'user_id' => $userId,
                        'exception' => $e,
                    ]);
                    continue;
                }
            }
        } catch (\Throwable $e) {
            // Log error but don't break the page
            $this->logger->error('Error fetching guest users for customer', [
                'customer_id' => $customerId,
                'exception' => $e,
            ]);
        }

        return $guests;
    }

    /**
     * @return list<int>|null
     */
    private function parseProjectIds(): ?array
    {
        $projectIds = $this->request->getParam('project_ids');
        if ($projectIds === null) {
            return null;
        }
        if (!is_array($projectIds)) {
            return [];
        }

        $normalized = [];
        foreach ($projectIds as $projectId) {
            $id = (int) $projectId;
            if ($id > 0) {
                $normalized[] = $id;
            }
        }
        return array_values(array_unique($normalized));
    }

    /**
     * @param list<int> $projectIds
     */
    private function syncCustomerProjects(int $customerId, array $projectIds, bool $isEdit): void
    {
        $allowedToAssign = [];
        foreach ($projectIds as $projectId) {
            $project = $this->projectService->getProject($projectId);
            if (!$project) {
                continue;
            }
            $currentCustomerId = isset($project['customer_id']) ? (int) $project['customer_id'] : 0;
            if ($currentCustomerId === 0 || $currentCustomerId === $customerId) {
                $allowedToAssign[$projectId] = $project;
            }
        }

        foreach ($allowedToAssign as $projectId => $project) {
            $this->projectService->updateProject(
                (int) $projectId,
                (string) ($project['name'] ?? ''),
                $customerId,
                isset($project['description']) ? (string) $project['description'] : null,
                null
            );
        }

        if (!$isEdit) {
            return;
        }

        $selected = array_keys($allowedToAssign);
        $currentProjects = $this->projectService->getProjectsByCustomer($customerId);
        foreach ($currentProjects as $currentProject) {
            $currentProjectId = (int) ($currentProject['id'] ?? 0);
            if ($currentProjectId <= 0 || in_array($currentProjectId, $selected, true)) {
                continue;
            }
            $this->projectService->updateProject(
                $currentProjectId,
                (string) ($currentProject['name'] ?? ''),
                null,
                isset($currentProject['description']) ? (string) $currentProject['description'] : null,
                null
            );
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

    private function normalizeCustomerOptionalField(mixed $value, int $maxLength): string
    {
        $text = trim((string)$value);
        if ($text === '' || $maxLength <= 0) {
            return '';
        }
        if (strlen($text) > $maxLength) {
            return substr($text, 0, $maxLength);
        }

        return $text;
    }
}

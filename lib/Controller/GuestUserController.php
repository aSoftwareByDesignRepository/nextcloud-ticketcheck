<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Db\DbQueryGuard;
use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\GuestPasswordPolicyService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

class GuestUserController extends Controller
{
    use CSPTrait;
    use PageRenderTrait;

    private PermissionService $permissionService;
    private ProjectService $projectService;
    private IURLGenerator $urlGenerator;
    private IUserManager $userManager;
    private IUserSession $userSession;
    private IGroupManager $groupManager;
    private IDBConnection $db;
    private GuestProjectAccessMapper $guestProjectAccessMapper;
    private IFactory $l10nFactory;
    private IConfig $config;
    private IMailer $mailer;
    private LoggerInterface $logger;
    private LocaleFormatService $localeFormat;
    private NavigationContextService $navigationContext;
    private FrontEndAssetService $frontEndAssets;
    private GuestPasswordPolicyService $guestPasswordPolicy;
    private TicketWorkflowLock $workflowLock;

    public function __construct(
        string $appName,
        IRequest $request,
        PermissionService $permissionService,
        ProjectService $projectService,
        IURLGenerator $urlGenerator,
        IUserManager $userManager,
        IUserSession $userSession,
        IGroupManager $groupManager,
        IDBConnection $db,
        GuestProjectAccessMapper $guestProjectAccessMapper,
        IFactory $l10nFactory,
        IConfig $config,
        IMailer $mailer,
        LoggerInterface $logger,
        LocaleFormatService $localeFormat,
        NavigationContextService $navigationContext,
        FrontEndAssetService $frontEndAssets,
        GuestPasswordPolicyService $guestPasswordPolicy,
        TicketWorkflowLock $workflowLock,
    ) {
        parent::__construct($appName, $request);
        $this->permissionService = $permissionService;
        $this->projectService = $projectService;
        $this->urlGenerator = $urlGenerator;
        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
        $this->db = $db;
        $this->guestProjectAccessMapper = $guestProjectAccessMapper;
        $this->l10nFactory = $l10nFactory;
        $this->config = $config;
        $this->mailer = $mailer;
        $this->logger = $logger;
        $this->localeFormat = $localeFormat;
        $this->navigationContext = $navigationContext;
        $this->frontEndAssets = $frontEndAssets;
        $this->guestPasswordPolicy = $guestPasswordPolicy;
        $this->workflowLock = $workflowLock;
    }

    /**
     * Show guest users list
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        if (!$this->permissionService->canAccessGuestUserAdministration()) {
            $l = $this->l10nFactory->get('ticketcheck');
            $denied = new TemplateResponse($this->appName, 'error', [
                'error' => $l->t('access_denied_admin_only'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $denied->setStatus(Http::STATUS_FORBIDDEN);
            return $denied;
        }

        // Get all guest users
        $guestGroup = $this->groupManager->get('helpdesk_customers');
        $guests = [];

        $projectAccessMap = $this->loadGuestProjectAccessMap();
        $guestLifecycleMap = $this->loadGuestLifecycleMap();
        $projectNameById = [];
        foreach ($this->projectService->getAllProjects(1000, 0) as $project) {
            $pid = (int)($project['id'] ?? 0);
            if ($pid > 0) {
                $projectNameById[$pid] = (string)($project['name'] ?? '');
            }
        }
        if ($guestGroup) {
            foreach ($guestGroup->getUsers() as $user) {
                $userId = $user->getUID();
                $projectIds = $projectAccessMap[$userId] ?? [];
                $lifecycle = $guestLifecycleMap[$userId] ?? [];
                $lastLoginRaw = $user->getLastLogin();
                $lastLogin = null;
                if ($lastLoginRaw > 0) {
                    $lastLogin = $lastLoginRaw > 9999999999 ? (int) floor($lastLoginRaw / 1000) : $lastLoginRaw;
                }
                $projectNames = [];
                foreach ($projectIds as $projectId) {
                    $name = $projectNameById[(int)$projectId] ?? '';
                    if ($name !== '') {
                        $projectNames[] = $name;
                    }
                }
                sort($projectNames, SORT_NATURAL | SORT_FLAG_CASE);

                $guests[] = [
                    'user_id' => $userId,
                    'display_name' => $user->getDisplayName(),
                    'email' => $user->getEMailAddress() ?? '',
                    'enabled' => $user->isEnabled(),
                    'project_count' => count($projectIds),
                    'project_ids' => $projectIds,
                    'project_names' => $projectNames,
                    'created_at' => $lifecycle['created_at'] ?? null,
                    'last_login' => $lastLogin,
                ];
            }
        }

        $l = $this->l10nFactory->get('ticketcheck');
        $response = $this->renderAppPage(
            'guests/index',
            ['guests' => $guests],
            'guests',
            $l->t('guest_users_page_title'),
            $l->t('guest_users_subtitle'),
            'guests',
            'staff',
            ['contextLine' => $l->t('guest_users_subtitle')],
        );

        return $this->configureCSPWithNonce($response, 'main');
    }

    /**
     * Show create guest form
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(): TemplateResponse
    {
        if (!$this->permissionService->canAccessGuestUserAdministration()) {
            $l = $this->l10nFactory->get('ticketcheck');
            $denied = new TemplateResponse($this->appName, 'error', [
                'error' => $l->t('access_denied_admin_only'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $denied->setStatus(Http::STATUS_FORBIDDEN);
            return $denied;
        }

        $projects = $this->projectService->getAllProjects(1000, 0);
        $l = $this->l10nFactory->get('ticketcheck');

        $response = $this->renderAppPage(
            'guests/form',
            ['projects' => $projects],
            'guest-form',
            $l->t('invite_guest_user'),
            $l->t('give_customer_portal_access'),
            'guest-form',
            'staff',
            ['contextLine' => $l->t('give_customer_portal_access')],
        );

        return $this->configureCSPWithNonce($response, 'main');
    }

    /**
     * Show edit guest form
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function edit(string $userId): TemplateResponse
    {
        if (!$this->permissionService->canAccessGuestUserAdministration()) {
            $l = $this->l10nFactory->get('ticketcheck');
            $denied = new TemplateResponse($this->appName, 'error', [
                'error' => $l->t('access_denied_admin_only'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $denied->setStatus(Http::STATUS_FORBIDDEN);
            return $denied;
        }

        $user = $this->userManager->get($userId);
        if (!$user) {
            $notFound = new TemplateResponse($this->appName, 'error', [
                'error' => $this->l10nFactory->get('ticketcheck')->t('guest_user_not_found'),
                'l' => $this->l10nFactory->get('ticketcheck'),
                'urlGenerator' => $this->urlGenerator,
            ]);
            $notFound->setStatus(Http::STATUS_NOT_FOUND);
            return $notFound;
        }

        // Get all projects for assignment
        $projects = $this->projectService->getAllProjects(1000, 0);

        // Get current project access for this guest. The table is created by
        // migrations; a failure here must surface — rendering an empty
        // selection would let a re-save silently wipe the guest's access.
        $qb = $this->db->getQueryBuilder();
        $qb->select('project_id')
            ->from('helpdesk_guest_access')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $assignedProjectIds = [];
        while ($row = $result->fetch()) {
            $assignedProjectIds[] = (int)$row['project_id'];
        }
        $result->closeCursor();

        $l = $this->l10nFactory->get('ticketcheck');
        $displayName = (string)$user->getDisplayName();

        $response = $this->renderAppPage(
            'guests/edit',
            [
                'guest' => [
                    'user_id' => $userId,
                    'display_name' => $displayName,
                    'email' => $user->getEMailAddress() ?? '',
                ],
                'projects' => $projects,
                'assigned_project_ids' => $assignedProjectIds,
            ],
            'guest-edit',
            $l->t('edit_guest_user'),
            $l->t('update_guest_info'),
            'guest-edit',
            'staff',
            ['contextLine' => $displayName],
        );

        return $this->configureCSPWithNonce($response, 'main');
    }

    /**
     * Create guest user
     */
    #[NoAdminRequired]
    public function store(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canAccessGuestUserAdministration()) {
            return new JSONResponse(['success' => false, 'error' => $l->t('access_denied')], 403);
        }

        try {
            $email = $this->request->getParam('email');
            $displayName = $this->request->getParam('display_name');
            $projectIds = $this->request->getParam('project_ids', []);
            $jsonPayload = $this->readJsonPayload();

            [$email, $displayName, $projectIds] = $this->mergeGuestPayload($email, $displayName, $projectIds, $jsonPayload);

            // Validate
            if (empty($email) || empty($displayName)) {
                return new JSONResponse(['success' => false, 'error' => $l->t('email_display_name_required')], 400);
            }

            try {
                [$email, $displayName, $projectIds] = $this->validateAndNormalizeGuestPayload($email, $displayName, $projectIds);
            } catch (\InvalidArgumentException $e) {
                $key = $e->getMessage();
                if ($key === 'invalid_email') {
                    return new JSONResponse(['success' => false, 'error' => $l->t('invalid_email_address')], 422);
                }
                if ($key === 'invalid_display_name') {
                    return new JSONResponse(['success' => false, 'error' => $l->t('email_display_name_required')], 422);
                }
                return new JSONResponse(['success' => false, 'error' => $l->t('email_display_name_required')], 422);
            }
            $invalidProjectIds = $this->getInvalidProjectIds($projectIds);
            if ($invalidProjectIds !== []) {
                return new JSONResponse(['success' => false, 'error' => $l->t('invalid_project_id')], 422);
            }

            $emailLockKey = $this->guestEmailLockKey($email);
            try {
                $created = $this->workflowLock->withExclusiveKey(
                    $emailLockKey,
                    function () use ($email, $displayName, $projectIds, $l): array {
                        if ($this->findConflictingGuestByEmail($email) !== null) {
                            return ['conflict' => true];
                        }

                        $username = $this->generateUniqueGuestUsername($email);
                        try {
                            [$user, $password] = $this->createGuestAccountWithCompliantPassword($username);
                        } catch (\RuntimeException) {
                            return ['create_failed' => true];
                        }

                        $user->setDisplayName($displayName);
                        $user->setEMailAddress($email);
                        $user->setQuota('0 B');

                        $guestGroup = $this->groupManager->get('helpdesk_customers');
                        if (!$guestGroup) {
                            $guestGroup = $this->groupManager->createGroup('helpdesk_customers');
                        }
                        if ($guestGroup !== null) {
                            $guestGroup->addUser($user);
                        }
                        $this->enforceGuestRoleIsolation($user);

                        $config = $this->config;
                        $config->setUserValue($username, 'core', 'enabled', 'true');
                        $config->setUserValue($username, 'files', 'quota', '0 B');
                        $config->setUserValue($username, 'ticketcheck', 'guest_created_at', (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
                        $preferredLanguage = $this->resolvePreferredGuestLanguage($l);
                        if ($preferredLanguage !== '') {
                            $config->setUserValue($username, 'core', 'lang', $preferredLanguage);
                            $config->setUserValue($username, 'ticketcheck', 'guest_language', $preferredLanguage);
                        }
                        $config->setUserValue($username, 'core', 'defaultapp', 'ticketcheck');

                        if (!empty($projectIds)) {
                            try {
                                $this->replaceGuestProjectAccess($username, $projectIds, 'guest creation');
                            } catch (\Throwable $grantError) {
                                // Compensate: never leave a half-provisioned guest
                                // account behind that reports "created" without the
                                // project access the admin selected.
                                try {
                                    $user->delete();
                                } catch (\Throwable $deleteError) {
                                    // best-effort: compensation rollback failed — primary error is still returned below
                                    $this->logger->error('Failed to roll back guest account after project grant failure', [
                                        'user_id' => $username,
                                        'exception' => $deleteError,
                                    ]);
                                }
                                $this->logger->error('Guest project access grant failed during creation', [
                                    'user_id' => $username,
                                    'exception' => $grantError,
                                ]);
                                return ['grant_failed' => true];
                            }
                        }

                        return [
                            'username' => $username,
                            'password' => $password,
                            'email' => $email,
                            'displayName' => $displayName,
                            'projectIds' => $projectIds,
                        ];
                    },
                    'Guest create by email'
                );
            } catch (\InvalidArgumentException) {
                return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 409);
            }

            if (!empty($created['conflict'])) {
                return new JSONResponse(['success' => false, 'error' => $l->t('guest_email_already_in_use')], 409);
            }
            if (!empty($created['create_failed'])) {
                return new JSONResponse(['success' => false, 'error' => $l->t('failed_to_create_guest_user')], 500);
            }
            if (!empty($created['grant_failed'])) {
                return new JSONResponse(['success' => false, 'error' => $l->t('guest_project_access_failed')], 500);
            }

            $emailSent = $this->sendWelcomeEmail(
                (string)$created['email'],
                (string)$created['displayName'],
                (string)$created['username'],
                (string)$created['password'],
                is_array($created['projectIds'] ?? null) ? $created['projectIds'] : []
            );

            return new JSONResponse([
                'success' => true,
                'message' => $emailSent
                    ? $l->t('guest_user_created_email_sent', [$email])
                    : $l->t('guest_user_created_email_failed', [$email]),
                'user_id' => (string)$created['username'],
                'email_sent' => $emailSent,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Guest user operation failed', ['exception' => $e]);
            return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 500);
        }
    }

    /**
     * @return list<int>
     */
    private function normalizePositiveIntList(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        $normalized = [];
        foreach ($ids as $id) {
            if (is_int($id) && $id > 0) {
                $normalized[$id] = $id;
                continue;
            }
            if (is_string($id)) {
                $trimmed = trim($id);
                if ($trimmed !== '' && ctype_digit($trimmed)) {
                    $parsed = (int)$trimmed;
                    if ($parsed > 0) {
                        $normalized[$parsed] = $parsed;
                    }
                }
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonPayload(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '' || strlen($raw) > 16384) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $jsonPayload
     * @return array{0: mixed, 1: mixed, 2: mixed}
     */
    private function mergeGuestPayload(
        mixed $email,
        mixed $displayName,
        mixed $projectIds,
        array $jsonPayload
    ): array {
        if (empty($jsonPayload)) {
            return [$email, $displayName, $projectIds];
        }

        $email = $jsonPayload['email'] ?? $email;
        $displayName = $jsonPayload['display_name'] ?? $displayName;
        if (array_key_exists('project_ids', $jsonPayload)) {
            $projectIds = $jsonPayload['project_ids'];
        } elseif (array_key_exists('project_ids[]', $jsonPayload)) {
            $projectIds = $jsonPayload['project_ids[]'];
        }

        return [$email, $displayName, $projectIds];
    }

    /**
     * Generate a password that satisfies Nextcloud password_policy and guest portal rules.
     */
    private function generateSecurePassword(): string
    {
        return $this->guestPasswordPolicy->generateCompliantPassword(16);
    }

    /**
     * @return array{0: \OCP\IUser, 1: string}
     */
    private function createGuestAccountWithCompliantPassword(string $username): array
    {
        $lastError = null;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $password = $this->generateSecurePassword();
            try {
                $user = $this->userManager->createUser($username, $password);
                if ($user instanceof \OCP\IUser) {
                    return [$user, $password];
                }
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        $this->logger->error('Guest user creation failed after password retries', [
            'username' => $username,
            'exception' => $lastError,
        ]);

        throw new \RuntimeException('Unable to create guest account with compliant password', 0, $lastError);
    }

    /**
     * Apply a new compliant password; retries when server policy rejects the candidate.
     */
    private function applyCompliantPassword(\OCP\IUser $user): string
    {
        $lastError = null;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $password = $this->generateSecurePassword();
            try {
                $user->setPassword($password);

                return $password;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        throw new \RuntimeException('Unable to set compliant guest password', 0, $lastError);
    }

    /**
     * Language for outbound guest emails (inviter UI locale, then guest preference, then system default).
     */
    private function resolveInviterLanguage(): string
    {
        $user = $this->userSession->getUser();
        if ($user !== null) {
            $lang = trim((string)$this->config->getUserValue($user->getUID(), 'core', 'lang', ''));
            if ($lang !== '') {
                return $lang;
            }
        }
        $l = $this->l10nFactory->get('ticketcheck');
        $preferred = $this->resolvePreferredGuestLanguage($l);
        return $preferred !== '' ? $preferred : $this->resolveGuestLanguage('');
    }

    /**
     * Resolve preferred language for a guest user.
     */
    private function resolveGuestLanguage(string $username): string
    {
        if ($username !== '') {
            $language = trim((string)$this->config->getUserValue($username, 'core', 'lang', ''));
            if ($language === '') {
                $language = trim((string)$this->config->getUserValue($username, 'ticketcheck', 'guest_language', ''));
            }
            if ($language !== '') {
                return $language;
            }
        }
        return trim((string)$this->config->getSystemValue('default_language', 'en')) ?: 'en';
    }

    /**
     * Send welcome email to new guest user.
     *
     * @param array<int> $projectIds
     * @return bool true when the message was handed to the mailer, false on failure
     */
    private function sendWelcomeEmail(string $email, string $displayName, string $username, string $password, array $projectIds): bool
    {
        try {
            $mailer = $this->mailer;
            $message = $mailer->createMessage();
            $config = $this->config;
            $inviteLanguage = $this->resolveInviterLanguage();
            $l = $this->l10nFactory->get('ticketcheck', $inviteLanguage);

            $portalUrl = $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute('ticketcheck.customerPortal.index')
            );

            $projectNames = [];
            if (!empty($projectIds)) {
                foreach ($projectIds as $projectId) {
                    $project = $this->projectService->getProject($projectId);
                    if ($project) {
                        $projectNames[] = $project['name'];
                    }
                }
            }

            $safeUsername = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safePassword = htmlspecialchars($password, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safePortalUrl = htmlspecialchars($portalUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $subject = $l->t('guest_welcome_email_subject');
            $projectListHtml = '';
            if (!empty($projectNames)) {
                $projectListItems = array_map(
                    static fn(string $name): string => '<li>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>',
                    $projectNames
                );
                $projectListHtml = '
                <h3>' . htmlspecialchars($l->t('guest_welcome_email_projects_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>
                <ul>' . implode('', $projectListItems) . '</ul>';
            }

            $htmlBody = "
                <h2>" . htmlspecialchars($l->t('guest_welcome_email_heading'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</h2>
                <p>" . htmlspecialchars($l->t('guest_welcome_email_greeting', [$displayName]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>
                <p>" . htmlspecialchars($l->t('guest_welcome_email_intro'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>
                
                <h3>" . htmlspecialchars($l->t('guest_welcome_email_login_details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</h3>
                <ul>
                    <li><strong>" . htmlspecialchars($l->t('guest_welcome_email_portal_url'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ":</strong> <a href='{$safePortalUrl}'>{$safePortalUrl}</a></li>
                    <li><strong>" . htmlspecialchars($l->t('guest_welcome_email_username'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ":</strong> {$safeUsername}</li>
                    <li><strong>" . htmlspecialchars($l->t('guest_welcome_email_password'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ":</strong> {$safePassword}</li>
                </ul>
                
                {$projectListHtml}
                
                <p><strong>" . htmlspecialchars($l->t('guest_welcome_email_important'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ":</strong> " . htmlspecialchars($l->t('guest_welcome_email_change_password_notice'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>
                
                <p>" . htmlspecialchars($l->t('guest_welcome_email_questions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>
                
                <p>" . nl2br(htmlspecialchars($l->t('guest_welcome_email_closing'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . "</p>
            ";

            $textBody = "
" . $l->t('guest_welcome_email_heading') . "

" . $l->t('guest_welcome_email_greeting', [$displayName]) . "

" . $l->t('guest_welcome_email_intro') . "

" . $l->t('guest_welcome_email_login_details') . "
- " . $l->t('guest_welcome_email_portal_url') . ": {$portalUrl}
- " . $l->t('guest_welcome_email_username') . ": {$username}
- " . $l->t('guest_welcome_email_password') . ": {$password}

" . (!empty($projectNames) ? "
" . $l->t('guest_welcome_email_projects_title') . "
" . implode("\n", array_map(fn($name) => "- {$name}", $projectNames)) . "
" : "") . "

" . $l->t('guest_welcome_email_important') . ": " . $l->t('guest_welcome_email_change_password_notice') . "

" . $l->t('guest_welcome_email_questions') . "

" . str_replace('<br>', "\n", $l->t('guest_welcome_email_closing')) . "
            ";

            // Get configured sender name from settings
            $fromName = $config->getAppValue('ticketcheck', 'email_from_name', 'Helpdesk');
            $fromEmail = $config->getSystemValue('mail_from_address', 'ticketcheck');
            $fromDomain = $config->getSystemValue('mail_domain', 'localhost');

            $message->setTo([$email => $displayName]);
            $message->setSubject($subject);
            $message->setHtmlBody($htmlBody);
            $message->setPlainBody($textBody);
            $message->setFrom([$fromEmail . '@' . $fromDomain => $fromName]);

            $mailer->send($message);

            return true;
        } catch (\Throwable $e) {
            // The guest account exists either way — callers must surface the
            // delivery failure so admins know the invite never reached the guest.
            $this->logger->warning('Failed to send welcome email to guest: ' . $e->getMessage());

            return false;
        }
    }

    private function resolvePreferredGuestLanguage(\OCP\IL10N $l10n): string
    {
        $languageCode = strtolower(trim((string)$l10n->getLanguageCode()));
        if ($languageCode === '') {
            $languageCode = strtolower(trim((string)$this->config->getSystemValue('default_language', '')));
        }
        if ($languageCode === '') {
            return '';
        }
        return str_contains($languageCode, '_') ? substr($languageCode, 0, 2) : $languageCode;
    }

    /**
     * Send password reset email to guest user.
     *
     * @return bool true when the message was handed to the mailer, false on failure
     */
    private function sendPasswordResetEmail(string $email, string $displayName, string $username, string $newPassword): bool
    {
        try {
            $mailer = $this->mailer;
            $message = $mailer->createMessage();
            $config = $this->config;
            $l = $this->l10nFactory->get('ticketcheck', $this->resolveInviterLanguage());

            $portalUrl = $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute('ticketcheck.customerPortal.index')
            );

            $safeUsername = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safePassword = htmlspecialchars($newPassword, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safePortalUrl = htmlspecialchars($portalUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $subject = $l->t('guest_password_reset_email_subject');

            $htmlBody = "
                <h2>" . htmlspecialchars($l->t('guest_password_reset_email_heading'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</h2>
                <p>" . htmlspecialchars($l->t('guest_welcome_email_greeting', [$displayName]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>
                <p>" . htmlspecialchars($l->t('guest_password_reset_email_intro'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>
                
                <h3>" . htmlspecialchars($l->t('guest_password_reset_email_login_details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</h3>
                <ul>
                    <li><strong>" . htmlspecialchars($l->t('guest_welcome_email_portal_url'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ":</strong> <a href='{$safePortalUrl}'>{$safePortalUrl}</a></li>
                    <li><strong>" . htmlspecialchars($l->t('guest_welcome_email_username'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ":</strong> {$safeUsername}</li>
                    <li><strong>" . htmlspecialchars($l->t('guest_password_reset_email_new_password'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ":</strong> {$safePassword}</li>
                </ul>
                
                <p><strong>" . htmlspecialchars($l->t('guest_welcome_email_important'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ":</strong> " . htmlspecialchars($l->t('guest_welcome_email_change_password_notice'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>
                
                <p>" . htmlspecialchars($l->t('guest_welcome_email_questions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>
                
                <p>" . nl2br(htmlspecialchars($l->t('guest_welcome_email_closing'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . "</p>
            ";

            $textBody = "
" . $l->t('guest_password_reset_email_heading') . "

" . $l->t('guest_welcome_email_greeting', [$displayName]) . "

" . $l->t('guest_password_reset_email_intro') . "

" . $l->t('guest_password_reset_email_login_details') . "
- " . $l->t('guest_welcome_email_portal_url') . ": {$portalUrl}
- " . $l->t('guest_welcome_email_username') . ": {$username}
- " . $l->t('guest_password_reset_email_new_password') . ": {$newPassword}

" . $l->t('guest_welcome_email_important') . ": " . $l->t('guest_welcome_email_change_password_notice') . "

" . $l->t('guest_welcome_email_questions') . "

" . str_replace('<br>', "\n", $l->t('guest_welcome_email_closing')) . "
            ";

            // Get configured sender name from settings
            $fromName = $config->getAppValue('ticketcheck', 'email_from_name', 'Helpdesk');
            $fromEmail = $config->getSystemValue('mail_from_address', 'ticketcheck');
            $fromDomain = $config->getSystemValue('mail_domain', 'localhost');

            $message->setTo([$email => $displayName]);
            $message->setSubject($subject);
            $message->setHtmlBody($htmlBody);
            $message->setPlainBody($textBody);
            $message->setFrom([$fromEmail . '@' . $fromDomain => $fromName]);

            $mailer->send($message);

            return true;
        } catch (\Throwable $e) {
            // The password was changed either way — callers must surface the
            // delivery failure so admins know the new credentials never arrived.
            $this->logger->warning('Failed to send password reset email to guest: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Update guest user
     */
    #[NoAdminRequired]
    public function update(string $userId): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');

        if (!$this->permissionService->canAccessGuestUserAdministration()) {
            return new JSONResponse(['success' => false, 'error' => $l->t('access_denied')], 403);
        }

        try {
            $user = $this->resolveGuestAccount($userId);
            if ($user === null) {
                return new JSONResponse(['success' => false, 'error' => $l->t('guest_user_not_found')], 404);
            }

            $email = $this->request->getParam('email');
            $displayName = $this->request->getParam('display_name');
            $projectIds = $this->request->getParam('project_ids', []);
            $jsonPayload = $this->readJsonPayload();

            [$email, $displayName, $projectIds] = $this->mergeGuestPayload($email, $displayName, $projectIds, $jsonPayload);

            // Validate
            if (empty($email) || empty($displayName)) {
                return new JSONResponse(['success' => false, 'error' => $l->t('email_display_name_required')], 400);
            }

            try {
                [$email, $displayName, $projectIds] = $this->validateAndNormalizeGuestPayload($email, $displayName, $projectIds);
            } catch (\InvalidArgumentException $e) {
                $key = $e->getMessage();
                if ($key === 'invalid_email') {
                    return new JSONResponse(['success' => false, 'error' => $l->t('invalid_email_address')], 422);
                }
                if ($key === 'invalid_display_name') {
                    return new JSONResponse(['success' => false, 'error' => $l->t('email_display_name_required')], 422);
                }
                return new JSONResponse(['success' => false, 'error' => $l->t('email_display_name_required')], 422);
            }
            $invalidProjectIds = $this->getInvalidProjectIds($projectIds);
            if ($invalidProjectIds !== []) {
                return new JSONResponse(['success' => false, 'error' => $l->t('invalid_project_id')], 422);
            }

            try {
                $result = $this->workflowLock->withExclusiveKey(
                    $this->guestAccessLockKey($userId),
                    function () use ($user, $userId, $email, $displayName, $projectIds): array {
                        if ($this->findConflictingGuestByEmail($email, $userId) !== null) {
                            return ['conflict' => true];
                        }

                        $user->setDisplayName($displayName);
                        $user->setEMailAddress($email);
                        $this->ensureGuestGroupMembership($user);
                        $this->enforceGuestRoleIsolation($user);
                        try {
                            $this->replaceGuestProjectAccess($userId, $projectIds, 'guest update');
                        } catch (\Throwable $grantError) {
                            $this->logger->error('Guest project access grant failed during update', [
                                'user_id' => $userId,
                                'exception' => $grantError,
                            ]);
                            return ['grant_failed' => true];
                        }

                        return ['ok' => true];
                    },
                    'Guest access rewrite'
                );
            } catch (\InvalidArgumentException) {
                return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 409);
            }

            if (!empty($result['grant_failed'])) {
                return new JSONResponse(['success' => false, 'error' => $l->t('guest_project_access_failed')], 500);
            }

            if (!empty($result['conflict'])) {
                return new JSONResponse(['success' => false, 'error' => $l->t('guest_email_already_in_use')], 409);
            }

            return new JSONResponse([
                'success' => true,
                'message' => $l->t('guest_user_updated_successfully')
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Guest user operation failed', ['exception' => $e]);
            return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 500);
        }
    }

    /**
     * Reset guest user password
     */
    #[NoAdminRequired]
    public function resetPassword(string $userId): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canAccessGuestUserAdministration()) {
            return new JSONResponse(['success' => false, 'error' => $l->t('access_denied')], 403);
        }

        try {
            $user = $this->resolveGuestAccount($userId);
            if ($user === null) {
                return new JSONResponse(['success' => false, 'error' => $l->t('guest_user_not_found')], 404);
            }

            try {
                $newPassword = $this->workflowLock->withExclusiveKey(
                    $this->guestPasswordLockKey($userId),
                    fn (): string => $this->applyCompliantPassword($user),
                    'Guest password reset'
                );
            } catch (\InvalidArgumentException) {
                return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 409);
            } catch (\RuntimeException) {
                return new JSONResponse(['success' => false, 'error' => $l->t('failed_to_reset_password')], 500);
            }

            $emailSent = $this->sendPasswordResetEmail((string)($user->getEMailAddress() ?? ''), $user->getDisplayName(), $user->getUID(), $newPassword);

            return new JSONResponse([
                'success' => true,
                'message' => $emailSent
                    ? $l->t('new_password_sent')
                    : $l->t('guest_password_reset_email_failed'),
                'email_sent' => $emailSent,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Guest user operation failed', ['exception' => $e]);
            return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 500);
        }
    }

    /**
     * Delete guest user
     */
    #[NoAdminRequired]
    public function delete(string $userId): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canAccessGuestUserAdministration()) {
            return new JSONResponse(['success' => false, 'error' => $l->t('access_denied')], 403);
        }

        try {
            $user = $this->resolveGuestAccount($userId);
            if ($user === null) {
                return new JSONResponse(['success' => false, 'error' => $l->t('guest_user_not_found')], 404);
            }

            try {
                $this->workflowLock->withExclusiveKey(
                    $this->guestAccessLockKey($userId),
                    function () use ($user, $userId): void {
                        // Access rows must go first: if $user->delete() fails we
                        // keep a clean guest, not an orphaned access row.
                        $qb = $this->db->getQueryBuilder();
                        $qb->delete('helpdesk_guest_access')
                            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
                        $qb->executeStatement();
                        $user->delete();
                    },
                    'Guest delete'
                );
            } catch (\InvalidArgumentException) {
                return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 409);
            }

            return new JSONResponse(['success' => true, 'message' => $l->t('guest_user_deleted_successfully')]);
        } catch (\Throwable $e) {
            $this->logger->error('Guest user operation failed', ['exception' => $e]);
            return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 500);
        }
    }

    /**
     * Grant project access to guest
     */
    #[NoAdminRequired]
    public function grantProjectAccess(string $userId, int $projectId): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canAccessGuestUserAdministration()) {
            return new JSONResponse(['success' => false, 'error' => $l->t('access_denied')], 403);
        }

        try {
            if ($this->resolveGuestAccount($userId) === null) {
                return new JSONResponse(['success' => false, 'error' => $l->t('guest_user_not_found')], 404);
            }
            if ($this->getInvalidProjectIds([$projectId]) !== []) {
                return new JSONResponse(['success' => false, 'error' => $l->t('invalid_project_id')], 422);
            }
            $actingUser = $this->userSession->getUser()?->getUID() ?? 'system';
            try {
                $this->workflowLock->withExclusiveKey(
                    $this->guestAccessLockKey($userId),
                    function () use ($userId, $projectId, $actingUser): void {
                        $this->guestProjectAccessMapper->grantAccess($userId, $projectId, null, $actingUser);
                    },
                    'Guest grant access'
                );
            } catch (\InvalidArgumentException) {
                return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 409);
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), 'already has access')) {
                    return new JSONResponse(['success' => true, 'message' => $l->t('guest_access_granted_successfully')]);
                }
                $this->logger->warning('Failed to grant project access', [
                    'project_id' => $projectId,
                    'user_id' => $userId,
                    'exception' => $e,
                ]);
                return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 500);
            }

            return new JSONResponse(['success' => true, 'message' => $l->t('guest_access_granted_successfully')]);
        } catch (\Throwable $e) {
            $this->logger->error('Guest user operation failed', ['exception' => $e]);
            return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 500);
        }
    }

    /**
     * Revoke project access from guest
     */
    #[NoAdminRequired]
    public function revokeProjectAccess(string $userId, int $projectId): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canAccessGuestUserAdministration()) {
            return new JSONResponse(['success' => false, 'error' => $l->t('access_denied')], 403);
        }

        try {
            if ($this->resolveGuestAccount($userId) === null) {
                return new JSONResponse(['success' => false, 'error' => $l->t('guest_user_not_found')], 404);
            }
            try {
                $this->workflowLock->withExclusiveKey(
                    $this->guestAccessLockKey($userId),
                    function () use ($userId, $projectId): void {
                        $qb = $this->db->getQueryBuilder();
                        $qb->delete('helpdesk_guest_access')
                            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                            ->andWhere($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)));
                        $qb->executeStatement();
                    },
                    'Guest revoke access'
                );
            } catch (\InvalidArgumentException) {
                return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 409);
            }

            return new JSONResponse(['success' => true, 'message' => $l->t('guest_access_revoked_successfully')]);
        } catch (\Throwable $e) {
            $this->logger->error('Guest user operation failed', ['exception' => $e]);
            return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 500);
        }
    }

    /**
     * @return array<string, int[]>
     */
    private function loadGuestProjectAccessMap(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'project_id')
            ->from('helpdesk_guest_access');
        $result = $qb->executeQuery();
        $map = [];
        while ($row = $result->fetch()) {
            $uid = (string)($row['user_id'] ?? '');
            if ($uid === '') {
                continue;
            }
            $map[$uid][] = (int)$row['project_id'];
        }
        $result->closeCursor();
        return $map;
    }

    /**
     * @return array<string, array{created_at?: string}>
     */
    private function loadGuestLifecycleMap(): array
    {
        $map = [];

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('userid', 'configvalue')
                ->from('preferences')
                ->where($qb->expr()->eq('appid', $qb->createNamedParameter('ticketcheck')))
                ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('guest_created_at')));
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $userId = (string)($row['userid'] ?? '');
                $createdAt = trim((string)($row['configvalue'] ?? ''));
                if ($userId !== '' && $createdAt !== '') {
                    $map[$userId]['created_at'] = $createdAt;
                }
            }
            $result->closeCursor();
        } catch (\Throwable $e) {
            // ignore and continue with fallback source
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('user_id', 'guest_user_id', 'created_at')
                ->from('helpdesk_guest_access');
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $userId = trim((string)($row['user_id'] ?? ''));
                if ($userId === '') {
                    $userId = trim((string)($row['guest_user_id'] ?? ''));
                }
                if ($userId === '' || isset($map[$userId]['created_at'])) {
                    continue;
                }
                $createdAt = trim((string)($row['created_at'] ?? ''));
                if ($createdAt === '') {
                    continue;
                }
                if (!isset($map[$userId]['created_at'])
                    || strcmp($createdAt, (string)$map[$userId]['created_at']) < 0) {
                    $map[$userId]['created_at'] = $createdAt;
                }
            }
            $result->closeCursor();
        } catch (\Throwable $e) {
            // fallback exhausted
        }

        return $map;
    }

    /**
     * @return array{0: string, 1: string, 2: int[]}
     */
    private function validateAndNormalizeGuestPayload(mixed $email, mixed $displayName, mixed $projectIds): array
    {
        $email = trim((string)$email);
        $displayName = trim((string)$displayName);
        $projectIds = $this->normalizeFlexibleProjectIds($projectIds);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('invalid_email');
        }
        if ($displayName === '' || mb_strlen($displayName) > 255) {
            throw new \InvalidArgumentException('invalid_display_name');
        }

        return [$email, $displayName, $projectIds];
    }

    /**
     * @param int[] $projectIds
     * @return int[]
     */
    private function getInvalidProjectIds(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('helpdesk_projects')
            ->where($qb->expr()->in('id', $qb->createNamedParameter($projectIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        $result = $qb->executeQuery();
        $existing = [];
        while ($row = $result->fetch()) {
            $existing[(int)$row['id']] = true;
        }
        $result->closeCursor();
        return array_values(array_filter($projectIds, static fn(int $id): bool => !isset($existing[$id])));
    }

    private function generateUniqueGuestUsername(string $email): string
    {
        $parts = explode('@', strtolower($email), 2);
        $local = preg_replace('/[^a-z0-9_]/', '_', $parts[0]) ?: 'guest';
        $domain = preg_replace('/[^a-z0-9_]/', '_', $parts[1] ?? 'user') ?: 'user';
        $base = substr('guest_' . $local . '_' . $domain, 0, 58);
        $candidate = $base;
        $i = 1;
        while ($this->userManager->get($candidate) !== null) {
            $suffix = '_' . $i;
            $candidate = substr($base, 0, 64 - strlen($suffix)) . $suffix;
            $i++;
        }
        return $candidate;
    }

    /**
     * Accepts project IDs from HTML forms, JSON arrays and comma-separated fallback.
     * @return int[]
     */
    private function normalizeFlexibleProjectIds(mixed $projectIds): array
    {
        if (is_string($projectIds)) {
            $projectIds = preg_split('/\s*,\s*/', trim($projectIds), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        return $this->normalizePositiveIntList($projectIds);
    }

    /**
     * Case-insensitive guest email collision (enabled helpdesk_customers only).
     * Portal ticket visibility keys on email — duplicates would cross-leak tickets.
     */
    private function findConflictingGuestByEmail(string $email, ?string $excludeUserId = null): ?string
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $matches = $this->userManager->getByEmail($email);
        if (!is_array($matches)) {
            return null;
        }

        foreach ($matches as $candidate) {
            if (!$candidate instanceof \OCP\IUser) {
                continue;
            }
            $uid = $candidate->getUID();
            if ($excludeUserId !== null && $uid === $excludeUserId) {
                continue;
            }
            if (!$candidate->isEnabled()) {
                continue;
            }
            if (!$this->groupManager->isInGroup($uid, 'helpdesk_customers')) {
                continue;
            }
            return $uid;
        }

        return null;
    }

    private function guestEmailLockKey(string $email): string
    {
        return 'ticketcheck/guest/email/' . strtolower(trim($email));
    }

    private function guestAccessLockKey(string $userId): string
    {
        return 'ticketcheck/guest/access/' . $userId;
    }

    private function guestPasswordLockKey(string $userId): string
    {
        // Same namespace as TicketService::withGuestPasswordFailGate so portal
        // change and admin reset cannot race last-write on setPassword.
        return 'ticketcheck/guest/pwfail/' . $userId;
    }

    /**
     * Atomically replace a guest's project grants (caller must hold guestAccessLockKey).
     *
     * Delete + re-grant run inside one transaction so a mid-batch failure cannot
     * leave the guest with a partial project set.
     *
     * @param int[] $projectIds
     * @throws \RuntimeException when any grant fails — callers must surface it
     */
    private function replaceGuestProjectAccess(string $userId, array $projectIds, string $context): void
    {
        $this->db->beginTransaction();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('helpdesk_guest_access')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
            $qb->executeStatement();

            if ($projectIds !== []) {
                $this->grantProjectAccessBatch($userId, $projectIds, $context);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            // A missing table means the feature schema was never created; keep
            // that legacy no-op behaviour, but every other failure is real.
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                $this->logger->warning('helpdesk_guest_access missing while replacing guest project access', [
                    'context' => $context,
                    'user_id' => $userId,
                ]);
                return;
            }

            if ($e instanceof \RuntimeException) {
                throw $e;
            }
            throw new \RuntimeException('Failed to replace guest project access: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param int[] $projectIds
     * @throws \RuntimeException when a grant fails — silent partial grants were
     *         the root cause of "selected projects are not saved" reports
     */
    private function grantProjectAccessBatch(string $userId, array $projectIds, string $context): void
    {
        $actingUser = $this->userSession->getUser()?->getUID() ?? 'system';
        $failed = [];
        foreach ($projectIds as $projectId) {
            try {
                $this->guestProjectAccessMapper->grantAccess($userId, (int)$projectId, null, $actingUser);
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), 'already has access')) {
                    continue;
                }
                $this->logger->error('Failed to grant project access', [
                    'context' => $context,
                    'project_id' => $projectId,
                    'user_id' => $userId,
                    'exception' => $e,
                ]);
                $failed[] = (int)$projectId;
            }
        }

        if ($failed !== []) {
            throw new \RuntimeException(
                'Failed to grant guest project access for project(s): ' . implode(', ', $failed)
            );
        }
    }

    /**
     * Resolve a helpdesk guest account by UID. Returns null for missing users, staff, or non-guests.
     */
    private function resolveGuestAccount(string $userId): ?\OCP\IUser
    {
        $userId = trim($userId);
        if ($userId === '' || str_contains($userId, "\0")) {
            return null;
        }

        $user = $this->userManager->get($userId);
        if ($user === null) {
            return null;
        }

        if (!$this->groupManager->isInGroup($userId, 'helpdesk_customers')) {
            return null;
        }

        if ($this->groupManager->isInGroup($userId, 'helpdesk_admins')
            || $this->groupManager->isInGroup($userId, 'helpdesk_agents')) {
            return null;
        }

        return $user;
    }

    private function ensureGuestGroupMembership(\OCP\IUser $user): void
    {
        $guestGroup = $this->groupManager->get('helpdesk_customers');
        if ($guestGroup === null) {
            $guestGroup = $this->groupManager->createGroup('helpdesk_customers');
        }
        if ($guestGroup !== null && !$this->groupManager->isInGroup($user->getUID(), 'helpdesk_customers')) {
            $guestGroup->addUser($user);
        }
    }

    private function enforceGuestRoleIsolation(\OCP\IUser $user): void
    {
        foreach (['helpdesk_agents', 'helpdesk_admins'] as $groupId) {
            if (!$this->groupManager->isInGroup($user->getUID(), $groupId)) {
                continue;
            }
            $group = $this->groupManager->get($groupId);
            if ($group !== null) {
                $group->removeUser($user);
            }
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
}

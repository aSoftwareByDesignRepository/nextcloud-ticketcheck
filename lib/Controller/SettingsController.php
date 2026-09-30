<?php

declare(strict_types=1);

/**
 * Settings controller for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Db\EscalationRule;
use OCA\Ticketcheck\Db\EscalationRuleMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\LicenseService;
use OCA\Ticketcheck\Service\LicenseUiStrings;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\EmailHeaderSanitizer;
use OCA\Ticketcheck\Service\SettingsSectionCatalog;
use OCA\Ticketcheck\Support\AboutProjectLinks;
use OCA\Ticketcheck\Support\SupportUsLinks;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Controller for settings management
 */
class SettingsController extends Controller
{
    use CSPTrait;
    use PageRenderTrait;

    /** @internal Preview scans at most this many users (performance / abuse guard). */
    private const APP_ACCESS_PREVIEW_USER_SEARCH_LIMIT = 1000;

    /** @internal Preview returns at most this many names per allow/block column. */
    private const APP_ACCESS_PREVIEW_LIST_LIMIT = 200;

    private PermissionService $permissionService;
    private EmailService $emailService;
    private IConfig $config;
    private IURLGenerator $urlGenerator;
    private \OCA\Ticketcheck\Service\CategoryService $categoryService;
    private EmailHeaderSanitizer $emailHeaderSanitizer;
    private IFactory $l10nFactory;
    private LoggerInterface $logger;
    private EscalationRuleMapper $escalationRuleMapper;
    private IUserManager $userManager;
    private IGroupManager $groupManager;
    private LocaleFormatService $localeFormat;
    private NavigationContextService $navigationContext;
    private FrontEndAssetService $frontEndAssets;
    private LicenseService $licenseService;
    private SettingsSectionCatalog $settingsSections;
    private IUserSession $userSession;

    public function __construct(
        string $appName,
        IRequest $request,
        PermissionService $permissionService,
        EmailService $emailService,
        IConfig $config,
        IURLGenerator $urlGenerator,
        \OCA\Ticketcheck\Service\CategoryService $categoryService,
        EmailHeaderSanitizer $emailHeaderSanitizer,
        IFactory $l10nFactory,
        LoggerInterface $logger,
        EscalationRuleMapper $escalationRuleMapper,
        IUserManager $userManager,
        IGroupManager $groupManager,
        LocaleFormatService $localeFormat,
        NavigationContextService $navigationContext,
        FrontEndAssetService $frontEndAssets,
        LicenseService $licenseService,
        SettingsSectionCatalog $settingsSections,
        IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
        $this->permissionService = $permissionService;
        $this->emailService = $emailService;
        $this->config = $config;
        $this->urlGenerator = $urlGenerator;
        $this->categoryService = $categoryService;
        $this->emailHeaderSanitizer = $emailHeaderSanitizer;
        $this->l10nFactory = $l10nFactory;
        $this->logger = $logger;
        $this->escalationRuleMapper = $escalationRuleMapper;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->localeFormat = $localeFormat;
        $this->navigationContext = $navigationContext;
        $this->frontEndAssets = $frontEndAssets;
        $this->licenseService = $licenseService;
        $this->settingsSections = $settingsSections;
        $this->userSession = $userSession;
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
     * Legacy single-page settings URL — redirects to the default sub-page.
     * Hard-denies non-admins (error template); TicketCheck has no soft denial card.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): Response
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            $response = $this->renderAppPage(
                'error',
                [
                    'message' => $l->t('permission_denied_admin_only_settings'),
                    'error' => $l->t('access_denied'),
                ],
                'error',
                $l->t('access_denied'),
                $l->t('permission_denied_admin_only_settings'),
            );
            $response->setStatus(Http::STATUS_FORBIDDEN);
            return $this->configureCSPWithNonce($response, 'settings');
        }

        return new RedirectResponse($this->urlGenerator->linkToRoute(
            'ticketcheck.settings.section',
            ['section' => SettingsSectionCatalog::DEFAULT_SECTION],
        ));
    }

    /**
     * One settings sub-page per former mega-page section.
     *
     * The route requirement already restricts {section} to the allowlist; the
     * catalog check below is defense in depth so a route change can never open
     * an unvalidated template dispatch.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function section(string $section): Response
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            $response = $this->renderAppPage(
                'error',
                [
                    'message' => $l->t('permission_denied_admin_only_settings'),
                    'error' => $l->t('access_denied'),
                ],
                'error',
                $l->t('access_denied'),
                $l->t('permission_denied_admin_only_settings'),
            );
            $response->setStatus(Http::STATUS_FORBIDDEN);
            return $this->configureCSPWithNonce($response, 'settings');
        }

        $section = strtolower(trim($section));
        if (!$this->settingsSections->isSection($section)) {
            return new NotFoundResponse();
        }

        if (in_array($section, ['knowledge-base', 'kb-categories'], true)) {
            $this->categoryService->ensureDefaultExists();
        }

        $settingsSectionLabels = [];
        foreach (SettingsSectionCatalog::routableSections() as $sectionId) {
            $settingsSectionLabels[$sectionId] = $this->settingsSections->navLabel($l, $sectionId);
        }

        $params = array_merge(
            [
                'settingsSection' => $section,
                'settingsSectionLabels' => $settingsSectionLabels,
                'breadcrumbParent' => [
                    'label' => $l->t('settings'),
                    'url' => $this->urlGenerator->linkToRoute('ticketcheck.settings.index'),
                ],
            ],
            $this->bodyParamsForSection($section, $l),
        );

        $response = $this->renderAppPage(
            'settings',
            $params,
            'settings',
            $this->settingsSections->label($l, $section),
            $this->settingsSections->help($l, $section),
            'settings',
        );

        return $this->configureCSPWithNonce($response, 'settings');
    }

    /**
     * Load only the template data needed for the active settings sub-page.
     *
     * @return array<string, mixed>
     */
    private function bodyParamsForSection(string $section, IL10N $l): array
    {
        return match ($section) {
            'access' => $this->accessSettingsExtras(),
            'email' => ['emailSettings' => $this->emailSettingsPayload()],
            'knowledge-base' => ['knowledgeBaseSettings' => $this->knowledgeBaseSettingsPayload()],
            'kb-categories' => [
                'knowledgeBaseSettings' => $this->knowledgeBaseSettingsPayload(),
                'kbCategories' => $this->categoryService->list(),
            ],
            'escalation' => $this->escalationSettingsExtras(),
            'license' => $this->licenseSettingsExtras($l),
            'support' => [
                'supportUsLinks' => new SupportUsLinks(
                    'TicketCheck',
                    true,
                    $this->urlGenerator->linkToRouteAbsolute(
                        'ticketcheck.settings.section',
                        ['section' => 'license'],
                    ) . '#ticketcheck-license',
                ),
            ],
            'about' => $this->aboutSettingsExtras($l),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function accessSettingsExtras(): array
    {
        $appAccess = $this->permissionService->getAppAccessSettings();
        return [
            'appAccessSettings' => $appAccess,
            'appAccessExtraGroupsPicker' => $this->formatExtraGroupsForPicker($appAccess['extra_groups']),
            'appAccessAllowedUsersPicker' => $this->formatUsersForPicker($appAccess['access_allowed_user_ids']),
            'appAccessAppAdminsPicker' => $this->formatUsersForPicker($appAccess['app_admin_user_ids']),
            'appAccessSearchUsersUrl' => $this->urlGenerator->linkToRouteAbsolute('ticketcheck.settings.searchNextcloudUsers'),
        ];
    }

    /** @return array<string, string> */
    private function emailSettingsPayload(): array
    {
        return [
            'from_name' => $this->config->getAppValue('ticketcheck', 'email_from_name', 'Helpdesk'),
            'enabled' => $this->config->getAppValue('ticketcheck', 'email_notifications_enabled', 'yes'),
            'daily_digest_enabled' => $this->config->getAppValue('ticketcheck', 'daily_digest_enabled', 'yes'),
            'weekly_digest_enabled' => $this->config->getAppValue('ticketcheck', 'weekly_digest_enabled', 'yes'),
            'inbound_email_address' => $this->config->getAppValue('ticketcheck', 'inbound_email_address', ''),
            'inbound_email_enabled' => $this->config->getAppValue('ticketcheck', 'inbound_email_enabled', 'no'),
        ];
    }

    /** @return array<string, string> */
    private function knowledgeBaseSettingsPayload(): array
    {
        return [
            'enabled' => $this->config->getAppValue('ticketcheck', 'kb_enabled', 'yes'),
            'portal_enabled' => $this->config->getAppValue('ticketcheck', 'kb_portal_enabled', 'yes'),
            'feedback_enabled' => $this->config->getAppValue('ticketcheck', 'kb_feedback_enabled', 'yes'),
            'comments_enabled' => $this->config->getAppValue('ticketcheck', 'kb_comments_enabled', 'yes'),
        ];
    }

    /** @return array<string, mixed> */
    private function escalationSettingsExtras(): array
    {
        $escalationPriorities = Ticket::getPriorities();
        $escalationStatuses = array_unique(array_merge(
            Ticket::getStatuses(),
            ['open', 'resolved', 'closed', 'waiting_customer', 'working']
        ));
        sort($escalationStatuses);

        return [
            'escalationPriorities' => $escalationPriorities,
            'escalationStatuses' => $escalationStatuses,
            'assignableUsers' => $this->getAssignableUsers(),
        ];
    }

    /**
     * "About this project" (Prototype Fund / BMFTR funding acknowledgement).
     * The logo file ships unaltered under img/funding/ — locale selects the
     * official DE or EN variant, never a request value.
     *
     * @return array<string, mixed>
     */
    private function aboutSettingsExtras(IL10N $l): array
    {
        $aboutLinks = new AboutProjectLinks();
        $languageCode = method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en';
        return [
            'aboutLinks' => $aboutLinks->forLocale($languageCode),
            'aboutBmftrLogoUrl' => $aboutLinks->assetUrl(
                $this->urlGenerator,
                $aboutLinks->logoFile($languageCode),
            ),
            'aboutPfLogoUrl' => $aboutLinks->assetUrl(
                $this->urlGenerator,
                AboutProjectLinks::PF_LOGO_FILE,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function licenseSettingsExtras(IL10N $l): array
    {
        $licenseStatus = null;
        $licenseSeatsList = null;
        try {
            $licenseStatus = $this->licenseService->status();
            $licenseSeatsList = $this->licenseService->listSeats(50, 0);
        } catch (\Throwable) {
            $licenseStatus = null;
            $licenseSeatsList = null;
        }
        $licenseSeatsUrl = $this->urlGenerator->linkToRouteAbsolute('ticketcheck.license.seats');
        $licenseApiUrl = $this->urlGenerator->linkToRouteAbsolute('ticketcheck.license.show');
        return [
            'licenseStatus' => $licenseStatus,
            'licenseSeatsList' => $licenseSeatsList,
            'licenseI18n' => LicenseUiStrings::forPanel($l),
            'licenseApiUrl' => $licenseApiUrl,
            'licenseClearUrl' => $licenseApiUrl,
            'licenseSeatsUrl' => $licenseSeatsUrl,
            'licenseAssignSeatUrl' => $licenseSeatsUrl,
            'licenseRemoveSeatBase' => rtrim($licenseSeatsUrl, '/') . '/',
            'licenseSearchUsersUrl' => $this->urlGenerator->linkToRouteAbsolute('ticketcheck.license.searchUsers'),
            'requesttoken' => \OCP\Util::callRegister(),
        ];
    }

    /**
     * Update knowledge base feature flags.
     */
    #[NoAdminRequired]
    public function updateKnowledgeBase(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }

        $enabled = $this->normalizeYesNo($this->request->getParam('enabled', 'yes'));
        $portalEnabled = $this->normalizeYesNo($this->request->getParam('portal_enabled', 'yes'));
        $feedbackEnabled = $this->normalizeYesNo($this->request->getParam('feedback_enabled', 'yes'));
        $commentsEnabled = $this->normalizeYesNo($this->request->getParam('comments_enabled', 'yes'));

        // If KB is globally disabled, dependent features are forced off.
        if ($enabled !== 'yes') {
            $portalEnabled = 'no';
            $feedbackEnabled = 'no';
            $commentsEnabled = 'no';
        }

        $this->config->setAppValue('ticketcheck', 'kb_enabled', $enabled);
        $this->config->setAppValue('ticketcheck', 'kb_portal_enabled', $portalEnabled);
        $this->config->setAppValue('ticketcheck', 'kb_feedback_enabled', $feedbackEnabled);
        $this->config->setAppValue('ticketcheck', 'kb_comments_enabled', $commentsEnabled);

        return new JSONResponse([
            'success' => true,
            'saved_values' => [
                'enabled' => $enabled,
                'portal_enabled' => $portalEnabled,
                'feedback_enabled' => $feedbackEnabled,
                'comments_enabled' => $commentsEnabled,
            ],
        ]);
    }

    /**
     * Update app-wide access controls.
     */
    #[NoAdminRequired]
    public function updateAppAccess(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }

        $allowAdmins = $this->normalizeYesNo($this->request->getParam('allow_helpdesk_admins', 'yes'));
        $allowAgents = $this->normalizeYesNo($this->request->getParam('allow_helpdesk_agents', 'yes'));
        $allowCustomers = $this->normalizeYesNo($this->request->getParam('allow_helpdesk_customers', 'yes'));
        $extraGroups = $this->normalizeGroupList((string)$this->request->getParam('extra_groups', ''));
        $restriction = $this->normalizeYesNo($this->request->getParam('access_restriction_enabled', 'no'));
        $allowedUsers = $this->normalizeUidListParam($this->request->getParam('access_allowed_user_ids', ''));
        $appAdmins = $this->normalizeUidListParam($this->request->getParam('app_admin_user_ids', ''));
        $acknowledgeRisk = $this->normalizeYesNo($this->request->getParam('acknowledge_non_admin_lockout_risk', 'no')) === 'yes';

        // Fail-safe: ensure at least one explicit role/group remains enabled.
        if ($allowAdmins !== 'yes' && $allowAgents !== 'yes' && $allowCustomers !== 'yes' && $extraGroups === '') {
            return new JSONResponse(['error' => $l->t('app_access_requires_one_enabled_scope')], 422);
        }

        if ($restriction === 'yes' && $allowedUsers === [] && $extraGroups === '' && $allowCustomers !== 'yes') {
            return new JSONResponse(['error' => $l->t('app_access_restricted_needs_allowlist')], 422);
        }

        $unknownGroups = [];
        foreach ($this->parseGroupList($extraGroups) as $groupId) {
            if ($this->groupManager->get($groupId) === null) {
                $unknownGroups[] = $groupId;
            }
        }
        if (!empty($unknownGroups)) {
            return new JSONResponse([
                'error' => $l->t('app_access_unknown_groups', [implode(', ', $unknownGroups)]),
            ], 422);
        }

        $unknownUsers = [];
        foreach (array_merge($allowedUsers, $appAdmins) as $uid) {
            if (!$this->userManager->userExists($uid)) {
                $unknownUsers[] = $uid;
            }
        }
        if (!empty($unknownUsers)) {
            return new JSONResponse([
                'error' => $l->t('app_access_unknown_users', [implode(', ', array_values(array_unique($unknownUsers)))]),
            ], 422);
        }

        // Self-lockout guard for dedicated app admins (non–NC-admins).
        $actor = $this->userSession->getUser();
        $actorUid = $actor !== null ? $actor->getUID() : '';
        if ($actorUid !== ''
            && !$this->groupManager->isAdmin($actorUid)
            && !$this->groupManager->isInGroup($actorUid, PermissionService::GROUP_HELPDESK_ADMINS)
            && $this->permissionService->isAppAdmin($actorUid)
            && !in_array($actorUid, $appAdmins, true)) {
            return new JSONResponse(['error' => $l->t('app_access_admin_self_lockout')], 422);
        }

        $proposedSettings = [
            'allow_helpdesk_admins' => $allowAdmins === 'yes',
            'allow_helpdesk_agents' => $allowAgents === 'yes',
            'allow_helpdesk_customers' => $allowCustomers === 'yes',
            'extra_groups' => $this->parseGroupList($extraGroups),
            'access_restriction_enabled' => $restriction === 'yes',
            'access_allowed_user_ids' => $allowedUsers,
            'app_admin_user_ids' => $appAdmins,
        ];

        $impact = $this->calculatePolicyImpact($proposedSettings);
        if (($impact['affected_non_admin_count'] ?? 0) > 0 && !$acknowledgeRisk) {
            return new JSONResponse([
                'error' => $l->t('app_access_blocks_currently_allowed_non_admins'),
                'impact' => $impact,
            ], 409);
        }

        $this->config->setAppValue('ticketcheck', 'app_access_helpdesk_admins', $allowAdmins);
        $this->config->setAppValue('ticketcheck', 'app_access_helpdesk_agents', $allowAgents);
        $this->config->setAppValue('ticketcheck', 'app_access_helpdesk_customers', $allowCustomers);
        $this->config->setAppValue('ticketcheck', 'app_access_extra_groups', $extraGroups);
        $this->config->setAppValue('ticketcheck', 'access_restriction_enabled', $restriction === 'yes' ? '1' : '0');
        $this->config->setAppValue('ticketcheck', 'access_allowed_user_ids', json_encode(array_values($allowedUsers), JSON_THROW_ON_ERROR));
        $this->config->setAppValue('ticketcheck', 'app_admin_user_ids', json_encode(array_values($appAdmins), JSON_THROW_ON_ERROR));

        return new JSONResponse([
            'success' => true,
            'saved_values' => [
                'allow_helpdesk_admins' => $allowAdmins,
                'allow_helpdesk_agents' => $allowAgents,
                'allow_helpdesk_customers' => $allowCustomers,
                'extra_groups' => $extraGroups,
                'access_restriction_enabled' => $restriction,
                'access_allowed_user_ids' => $allowedUsers,
                'app_admin_user_ids' => $appAdmins,
            ],
            'impact' => $impact,
        ]);
    }

    /**
     * Search Nextcloud groups for the app-access picker (admin settings).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function searchNextcloudGroups(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }

        $query = trim((string)$this->request->getParam('q', ''));
        $limit = 25;
        $groups = $query === ''
            ? []
            : $this->groupManager->search($query, $limit, 0);

        $items = [];
        foreach ($groups as $group) {
            $gid = $group->getGID();
            $items[] = [
                'id' => $gid,
                'displayName' => $group->getDisplayName() !== '' ? $group->getDisplayName() : $gid,
            ];
        }

        return new JSONResponse([
            'success' => true,
            'groups' => $items,
        ]);
    }

    /**
     * Full directory user search for access allow-list / app-admin pickers.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function searchNextcloudUsers(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }

        $query = trim((string)$this->request->getParam('q', ''));
        if (mb_strlen($query) < 2) {
            return new JSONResponse(['success' => true, 'users' => []]);
        }
        $limit = 25;
        $byId = (array)$this->userManager->search($query, $limit * 2, 0);
        $byName = (array)$this->userManager->searchDisplayName($query, $limit * 2, 0);
        $merged = [];
        foreach ([$byId, $byName] as $batch) {
            foreach ($batch as $user) {
                if (!$user->isEnabled()) {
                    continue;
                }
                $uid = $user->getUID();
                if ($uid === '' || isset($merged[$uid])) {
                    continue;
                }
                $merged[$uid] = [
                    'id' => $uid,
                    'displayName' => $user->getDisplayName() !== '' ? $user->getDisplayName() : $uid,
                ];
            }
        }
        $items = array_values($merged);
        usort($items, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
        return new JSONResponse([
            'success' => true,
            'users' => array_slice($items, 0, $limit),
        ]);
    }

    /**
     * Preview effective app access for current policy.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getAppAccessPreview(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }

        $impact = $this->calculatePolicyImpact($this->permissionService->getAppAccessSettings());

        return new JSONResponse([
            'success' => true,
            'summary' => $impact['summary'],
            'allowed_users' => $impact['allowed_users'],
            'blocked_users' => $impact['blocked_users'],
            'truncated' => $impact['truncated'],
            'preview_limits' => $impact['preview_limits'],
            'strings' => $this->buildAppAccessPreviewStrings($l, $impact),
            'impact' => $impact,
        ]);
    }

    /**
     * Localized strings for the settings UI (avoids relying on async JS l10n for security-sensitive preview).
     *
     * @param array $impact Output shape of calculatePolicyImpact()
     * @return array<string, string>
     */
    private function buildAppAccessPreviewStrings(IL10N $l, array $impact): array
    {
        $summary = $impact['summary'];
        $limits = $impact['preview_limits'];
        $trunc = $impact['truncated'];
        $strings = [
            'summary_line' => strtr($l->t('app_access_preview_summary'), [
                '{total}' => (string) $summary['total_users'],
                '{allowed}' => (string) $summary['allowed_users'],
                '{blocked}' => (string) $summary['blocked_users'],
            ]),
            'none' => $l->t('none'),
            'list_truncated' => $l->t('list_truncated'),
            'live_announce' => strtr($l->t('app_access_preview_live_announce'), [
                '{total}' => (string) $summary['total_users'],
                '{allowed}' => (string) $summary['allowed_users'],
                '{blocked}' => (string) $summary['blocked_users'],
            ]),
        ];
        if ($limits['user_sample_reached_cap']) {
            $strings['user_sample_warning'] = strtr($l->t('app_access_preview_user_sample_capped'), [
                '{limit}' => (string) $limits['user_search_limit'],
            ]);
        } else {
            $strings['user_sample_warning'] = '';
        }
        if (($trunc['allowed'] ?? false) || ($trunc['blocked'] ?? false)) {
            $strings['names_list_cap_notice'] = strtr($l->t('app_access_preview_names_limited_notice'), [
                '{limit}' => (string) $limits['display_list_limit'],
            ]);
        } else {
            $strings['names_list_cap_notice'] = '';
        }
        return $strings;
    }

    /**
     * Update email settings
     */
    #[NoAdminRequired]
    public function updateEmail(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }

        $fromName = $this->emailHeaderSanitizer->sanitizeWithDefault(
            $this->request->getParam('from_name'),
            'Helpdesk'
        );
        // Normalize to 'yes'|'no' for persistence
        $enabled = $this->normalizeYesNo($this->request->getParam('enabled', 'yes'));
        $dailyDigestEnabled = $this->normalizeYesNo($this->request->getParam('daily_digest_enabled', 'yes'));
        $weeklyDigestEnabled = $this->normalizeYesNo($this->request->getParam('weekly_digest_enabled', 'yes'));
        $inboundEmailEnabled = $this->normalizeYesNo($this->request->getParam('inbound_email_enabled', 'no'));

        $inboundAddressRaw = $this->request->getParam('inbound_email_address');
        $inboundAddress = is_string($inboundAddressRaw) ? trim($inboundAddressRaw) : '';
        if ($inboundAddress !== '' && !filter_var($inboundAddress, FILTER_VALIDATE_EMAIL)) {
            return new JSONResponse(['error' => $l->t('invalid_email_address')], 422);
        }
        if ($inboundEmailEnabled === 'yes' && $inboundAddress === '') {
            return new JSONResponse(['error' => $l->t('inbound_email_address_required_when_enabled')], 422);
        }

        // Dependent settings are forced off when global notifications are disabled.
        if ($enabled !== 'yes') {
            $dailyDigestEnabled = 'no';
            $weeklyDigestEnabled = 'no';
            $inboundEmailEnabled = 'no';
        }

        $this->config->setAppValue('ticketcheck', 'email_from_name', $fromName);
        $this->config->setAppValue('ticketcheck', 'email_notifications_enabled', $enabled);
        $this->config->setAppValue('ticketcheck', 'daily_digest_enabled', $dailyDigestEnabled);
        $this->config->setAppValue('ticketcheck', 'weekly_digest_enabled', $weeklyDigestEnabled);
        $this->config->setAppValue('ticketcheck', 'inbound_email_address', $inboundAddress);
        $this->config->setAppValue('ticketcheck', 'inbound_email_enabled', $inboundEmailEnabled);

        // Verify the values were saved
        $savedFromName = $this->config->getAppValue('ticketcheck', 'email_from_name');
        $savedEnabled = $this->config->getAppValue('ticketcheck', 'email_notifications_enabled');
        $savedDailyDigest = $this->config->getAppValue('ticketcheck', 'daily_digest_enabled');
        $savedWeeklyDigest = $this->config->getAppValue('ticketcheck', 'weekly_digest_enabled');
        $savedInboundAddress = $this->config->getAppValue('ticketcheck', 'inbound_email_address');
        $savedInboundEnabled = $this->config->getAppValue('ticketcheck', 'inbound_email_enabled');

        return new JSONResponse([
            'success' => true,
            'saved_values' => [
                'from_name' => $savedFromName,
                'enabled' => $savedEnabled,
                'daily_digest_enabled' => $savedDailyDigest,
                'weekly_digest_enabled' => $savedWeeklyDigest,
                'inbound_email_address' => $savedInboundAddress,
                'inbound_email_enabled' => $savedInboundEnabled,
            ]
        ]);
    }


    /**
     * Test email configuration
     */
    #[NoAdminRequired]
    public function testEmail(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }

        $email = $this->request->getParam('email');
        $name = $this->emailHeaderSanitizer->sanitizeWithDefault(
            $this->request->getParam('name'),
            'Test User'
        );

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JSONResponse(['error' => $l->t('invalid_email_address')], 400);
        }

        try {
            $success = $this->emailService->sendTestEmail($email, $name);

            if ($success) {
                return new JSONResponse([
                    'success' => true,
                    'message' => $l->t('test_email_sent_successfully_to', [$email])
                ]);
            } else {
                return new JSONResponse([
                    'success' => false,
                    'message' => $l->t('failed_to_send_test_email_check_configuration')
                ], 500);
            }
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('error_sending_test_email')
            ], 500);
        }
    }

    /**
     * List KB categories
     */
    #[NoAdminRequired]
    public function listKBCategories(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }
        if (!$this->isKnowledgeBaseEnabled()) {
            return new JSONResponse(['error' => $l->t('knowledge_base_disabled_message')], 403);
        }
        return new JSONResponse(array_map(static function ($c) {
            return [
                'id' => $c->getId(),
                'name' => $c->getName(),
                'position' => $c->getPosition(),
            ];
        }, $this->categoryService->list()));
    }

    /**
     * Create KB category
     */
    #[NoAdminRequired]
    public function createKBCategory(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }
        if (!$this->isKnowledgeBaseEnabled()) {
            return new JSONResponse(['error' => $l->t('knowledge_base_disabled_message')], 403);
        }
        $name = trim((string)$this->request->getParam('name', ''));
        if ($name === '') {
            return new JSONResponse(['error' => $l->t('name_required')], 422);
        }
        try {
            $created = $this->categoryService->create($name);
            return new JSONResponse([
                'success' => true,
                'category' => ['id' => $created->getId(), 'name' => $created->getName(), 'position' => $created->getPosition()]
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Settings operation failed', ['exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], 400);
        }
    }

    /**
     * Update KB category (name or position)
     */
    #[NoAdminRequired]
    public function updateKBCategory(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }
        if (!$this->isKnowledgeBaseEnabled()) {
            return new JSONResponse(['error' => $l->t('knowledge_base_disabled_message')], 403);
        }
        try {
            $name = $this->request->getParam('name');
            $position = $this->request->getParam('position');
            if ($name !== null && trim((string)$name) === '') {
                return new JSONResponse(['error' => $l->t('name_required')], 422);
            }
            $updated = $this->categoryService->update($id, $name !== null ? trim((string)$name) : null, $position !== null ? (int)$position : null);
            return new JSONResponse([
                'success' => true,
                'category' => ['id' => $updated->getId(), 'name' => $updated->getName(), 'position' => $updated->getPosition()]
            ]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $l->t('category_not_found')], 404);
        }
    }

    /**
     * Delete KB category
     */
    #[NoAdminRequired]
    public function deleteKBCategory(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }
        if (!$this->isKnowledgeBaseEnabled()) {
            return new JSONResponse(['error' => $l->t('knowledge_base_disabled_message')], 403);
        }
        try {
            // Prevent deleting default "General"
            foreach ($this->categoryService->list() as $c) {
                if ($c->getId() === $id && $c->getName() === 'General') {
                    return new JSONResponse(['error' => $l->t('cannot_delete_default_category')], 400);
                }
            }
            $this->categoryService->delete($id);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $l->t('category_not_found')], 404);
        }
    }

    /**
     * Get escalation rules
     */
    #[NoAdminRequired]
    public function getEscalationRules(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }
        try {
            $rules = $this->escalationRuleMapper->findAll();
            $data = array_map(static function (EscalationRule $r) {
                return [
                    'id' => $r->getId(),
                    'name' => $r->getName(),
                    'conditions' => $r->getConditionsDecoded(),
                    'action' => $r->getActionDecoded(),
                    'is_active' => $r->getIsActive(),
                    'created_at' => $r->getCreatedAt()?->format('c'),
                ];
            }, $rules);
            return new JSONResponse($data);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get escalation rules', ['exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], 500);
        }
    }

    /**
     * Create escalation rule
     */
    #[NoAdminRequired]
    public function createEscalationRule(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }
        $name = trim((string)$this->request->getParam('name', ''));
        if ($name === '') {
            return new JSONResponse(['error' => $l->t('name_required')], 422);
        }
        $conditions = $this->parseConditions($this->request->getParam('conditions', []));
        $action = $this->parseAction($this->request->getParam('action', []));
        if (!$this->isAssignableUserValid($action['assign_to'])) {
            return new JSONResponse(['error' => $l->t('invalid_user_id')], 422);
        }
        $isActive = (int)($this->request->getParam('is_active') !== false);

        $rule = new EscalationRule();
        $rule->setName($name);
        $rule->setConditions($conditions);
        $rule->setAction($action);
        $rule->setIsActive($isActive);
        $rule->setCreatedAt(new \DateTime());

        try {
            $created = $this->escalationRuleMapper->insertRule($rule);
            return new JSONResponse([
                'success' => true,
                'rule' => [
                    'id' => $created->getId(),
                    'name' => $created->getName(),
                    'conditions' => $created->getConditionsDecoded(),
                    'action' => $created->getActionDecoded(),
                    'is_active' => $created->getIsActive(),
                    'created_at' => $created->getCreatedAt()?->format('c'),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create escalation rule', ['exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], 500);
        }
    }

    /**
     * Update escalation rule
     */
    #[NoAdminRequired]
    public function updateEscalationRule(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }
        try {
            $rule = $this->escalationRuleMapper->find($id);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $l->t('rule_not_found')], 404);
        }

        $name = $this->request->getParam('name');
        if ($name !== null) {
            $name = trim((string)$name);
            if ($name === '') {
                return new JSONResponse(['error' => $l->t('name_required')], 422);
            }
            $rule->setName($name);
        }
        if ($this->request->getParam('conditions') !== null) {
            $rule->setConditions($this->parseConditions($this->request->getParam('conditions', [])));
        }
        if ($this->request->getParam('action') !== null) {
            $action = $this->parseAction($this->request->getParam('action', []));
            if (!$this->isAssignableUserValid($action['assign_to'])) {
                return new JSONResponse(['error' => $l->t('invalid_user_id')], 422);
            }
            $rule->setAction($action);
        }
        if ($this->request->getParam('is_active') !== null) {
            $rule->setIsActive((int)(bool)$this->request->getParam('is_active'));
        }

        try {
            $updated = $this->escalationRuleMapper->updateRule($rule);
            return new JSONResponse([
                'success' => true,
                'rule' => [
                    'id' => $updated->getId(),
                    'name' => $updated->getName(),
                    'conditions' => $updated->getConditionsDecoded(),
                    'action' => $updated->getActionDecoded(),
                    'is_active' => $updated->getIsActive(),
                    'created_at' => $updated->getCreatedAt()?->format('c'),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update escalation rule', ['exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], 500);
        }
    }

    /**
     * Delete escalation rule
     */
    #[NoAdminRequired]
    public function deleteEscalationRule(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }
        try {
            $rule = $this->escalationRuleMapper->find($id);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $l->t('rule_not_found')], 404);
        }
        try {
            $this->escalationRuleMapper->deleteRule($rule);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete escalation rule', ['exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], 500);
        }
    }

    private function parseConditions(mixed $raw = []): array
    {
        $conditions = is_array($raw) ? $raw : [];
        $ageHours = isset($conditions['age_hours']) ? (int)$conditions['age_hours'] : 24;
        $ageHours = max(1, min(8760, $ageHours)); // 1h–1y
        $minPriority = isset($conditions['min_priority']) ? (string)$conditions['min_priority'] : null;
        if ($minPriority !== null && !in_array($minPriority, Ticket::getPriorities(), true)) {
            $minPriority = null;
        }

        $statuses = [];
        if (isset($conditions['statuses']) && is_array($conditions['statuses'])) {
            foreach ($conditions['statuses'] as $statusRaw) {
                $normalized = $this->normalizeEscalationStatusToken((string)$statusRaw);
                if ($normalized !== null) {
                    $statuses[] = $normalized;
                }
            }
            $statuses = array_values(array_unique($statuses));
        }

        return [
            'age_hours' => $ageHours,
            'min_priority' => $minPriority,
            'statuses' => $statuses,
        ];
    }

    private function parseAction(mixed $raw = []): array
    {
        $action = is_array($raw) ? $raw : [];
        $setPriority = isset($action['set_priority']) ? (string)$action['set_priority'] : null;
        $assignTo = isset($action['assign_to']) ? trim((string)$action['assign_to']) : null;
        if ($assignTo === '') {
            $assignTo = null;
        }
        return [
            'set_priority' => $setPriority,
            'assign_to' => $assignTo,
        ];
    }

    /**
     * Build assignable users for escalation actions.
     *
     * @return array<int,array{user_id:string,user_name:string}>
     */
    private function getAssignableUsers(): array
    {
        $usersById = [];
        $helpdeskGroups = [PermissionService::GROUP_HELPDESK_ADMINS, PermissionService::GROUP_HELPDESK_AGENTS];
        foreach ($helpdeskGroups as $groupId) {
            $group = $this->groupManager->get($groupId);
            if ($group === null) {
                continue;
            }
            foreach ($group->getUsers() as $user) {
                $uid = $user->getUID();
                if (!isset($usersById[$uid])) {
                    $usersById[$uid] = [
                        'user_id' => $uid,
                        'user_name' => $user->getDisplayName(),
                    ];
                }
            }
        }

        if (empty($usersById)) {
            $allUsers = $this->userManager->search('', 200);
            foreach ($allUsers as $user) {
                $uid = $user->getUID();
                if ($this->groupManager->isInGroup($uid, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {
                    continue;
                }
                $usersById[$uid] = [
                    'user_id' => $uid,
                    'user_name' => $user->getDisplayName(),
                ];
            }
        }

        $users = array_values($usersById);
        usort($users, static function (array $a, array $b): int {
            return strcmp($a['user_name'], $b['user_name']);
        });
        return $users;
    }

    private function isAssignableUserValid(?string $assignTo): bool
    {
        if ($assignTo === null || $assignTo === '') {
            return true;
        }
        foreach ($this->getAssignableUsers() as $user) {
            if ($user['user_id'] === $assignTo) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize a value to 'yes' or 'no' for config storage.
     */
    private function normalizeYesNo(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        $v = strtolower(trim((string) $value));
        return in_array($v, ['yes', '1', 'true', 'on'], true) ? 'yes' : 'no';
    }

    private function isKnowledgeBaseEnabled(): bool
    {
        return $this->config->getAppValue('ticketcheck', 'kb_enabled', 'yes') === 'yes';
    }

    private function normalizeGroupList(string $raw): string
    {
        $groups = $this->parseGroupList($raw);
        return implode(',', $groups);
    }

    /**
     * @return array<int, string>
     */
    private function parseGroupList(string $raw): array
    {
        $groups = array_map(static fn (string $group): string => trim($group), explode(',', $raw));
        $groups = array_filter($groups, static fn (string $group): bool => $group !== '');
        return array_values(array_unique($groups));
    }

    /**
     * @param array<int, string> $groupIds
     * @return list<array{id: string, displayName: string}>
     */
    private function formatExtraGroupsForPicker(array $groupIds): array
    {
        $items = [];
        foreach ($groupIds as $groupId) {
            $gid = trim((string)$groupId);
            if ($gid === '') {
                continue;
            }
            $group = $this->groupManager->get($gid);
            $displayName = $group !== null && $group->getDisplayName() !== ''
                ? $group->getDisplayName()
                : $gid;
            $items[] = [
                'id' => $gid,
                'displayName' => $displayName,
            ];
        }
        return $items;
    }

    /**
     * @param list<string> $userIds
     * @return list<array{id: string, displayName: string}>
     */
    private function formatUsersForPicker(array $userIds): array
    {
        $items = [];
        foreach ($userIds as $userId) {
            $uid = trim((string)$userId);
            if ($uid === '') {
                continue;
            }
            $user = $this->userManager->get($uid);
            $items[] = [
                'id' => $uid,
                'displayName' => $user !== null && $user->getDisplayName() !== ''
                    ? $user->getDisplayName()
                    : $uid,
            ];
        }
        return $items;
    }

    /**
     * @return list<string>
     */
    private function normalizeUidListParam(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $raw = trim((string)$raw);
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $parts = $decoded;
            } else {
                $parts = preg_split('/[\s,;]+/', $raw) ?: [];
            }
        }
        $out = [];
        foreach ($parts as $uid) {
            if (!is_string($uid) && !is_int($uid)) {
                continue;
            }
            $uid = trim((string)$uid);
            if ($uid !== '' && !in_array($uid, $out, true)) {
                $out[] = $uid;
            }
        }
        return $out;
    }

    /**
     * @param array{
     *   allow_helpdesk_admins: bool,
     *   allow_helpdesk_agents: bool,
     *   allow_helpdesk_customers: bool,
     *   extra_groups: array<int, string>,
     *   access_restriction_enabled: bool,
     *   access_allowed_user_ids: list<string>,
     *   app_admin_user_ids: list<string>
     * } $settings
     * @return array{
     *   summary: array{total_users:int,allowed_users:int,blocked_users:int},
     *   allowed_users: array<int, array{uid:string,display_name:string}>,
     *   blocked_users: array<int, array{uid:string,display_name:string}>,
     *   truncated: array{allowed:bool,blocked:bool},
     *   preview_limits: array{user_search_limit:int,display_list_limit:int,user_sample_reached_cap:bool},
     *   affected_non_admin_count:int,
     *   affected_non_admin_users: array<int, array{uid:string,display_name:string}>,
     *   affected_includes_current_user: bool
     * }
     */
    private function calculatePolicyImpact(array $settings): array
    {
        $users = $this->userManager->search('', self::APP_ACCESS_PREVIEW_USER_SEARCH_LIMIT);
        if (!is_array($users)) {
            $users = [];
        }
        $allowed = [];
        $blocked = [];
        $affectedNonAdmins = [];

        $currentUserId = $this->permissionService->getCurrentUserId();
        $currentSettings = $this->permissionService->getAppAccessSettings();
        $affectedIncludesCurrentUser = false;

        foreach ($users as $user) {
            $uid = $user->getUID();
            $entry = [
                'uid' => $uid,
                'display_name' => $user->getDisplayName(),
            ];
            $isAllowedCurrent = $this->permissionService->canUserAccessAppWithSettings($uid, $currentSettings);
            $isAllowedProposed = $this->permissionService->canUserAccessAppWithSettings($uid, $settings);
            if ($isAllowedProposed) {
                $allowed[] = $entry;
            } else {
                $blocked[] = $entry;
            }

            $isInstanceAdmin = $this->groupManager->isAdmin($uid);
            if (!$isInstanceAdmin && $isAllowedCurrent && !$isAllowedProposed) {
                $affectedNonAdmins[] = $entry;
                if ($currentUserId !== null && $uid === $currentUserId) {
                    $affectedIncludesCurrentUser = true;
                }
            }
        }

        usort($allowed, static fn (array $a, array $b): int => strcmp($a['display_name'], $b['display_name']));
        usort($blocked, static fn (array $a, array $b): int => strcmp($a['display_name'], $b['display_name']));
        usort($affectedNonAdmins, static fn (array $a, array $b): int => strcmp($a['display_name'], $b['display_name']));

        return [
            'summary' => [
                'total_users' => count($users),
                'allowed_users' => count($allowed),
                'blocked_users' => count($blocked),
            ],
            'allowed_users' => array_slice($allowed, 0, self::APP_ACCESS_PREVIEW_LIST_LIMIT),
            'blocked_users' => array_slice($blocked, 0, self::APP_ACCESS_PREVIEW_LIST_LIMIT),
            'truncated' => [
                'allowed' => count($allowed) > self::APP_ACCESS_PREVIEW_LIST_LIMIT,
                'blocked' => count($blocked) > self::APP_ACCESS_PREVIEW_LIST_LIMIT,
            ],
            'preview_limits' => [
                'user_search_limit' => self::APP_ACCESS_PREVIEW_USER_SEARCH_LIMIT,
                'display_list_limit' => self::APP_ACCESS_PREVIEW_LIST_LIMIT,
                'user_sample_reached_cap' => count($users) >= self::APP_ACCESS_PREVIEW_USER_SEARCH_LIMIT,
            ],
            'affected_non_admin_count' => count($affectedNonAdmins),
            'affected_non_admin_users' => array_slice($affectedNonAdmins, 0, 50),
            'affected_includes_current_user' => $affectedIncludesCurrentUser,
        ];
    }

    private function normalizeEscalationStatusToken(string $status): ?string
    {
        $token = strtolower(trim($status));
        if ($token === '') {
            return null;
        }

        $map = [
            'open' => Ticket::STATUS_IN_PROGRESS,
            'working' => Ticket::STATUS_IN_PROGRESS,
            'in_progress' => Ticket::STATUS_IN_PROGRESS,
            'waiting_customer' => Ticket::STATUS_WAITING,
            'waiting' => Ticket::STATUS_WAITING,
            'new' => Ticket::STATUS_NEW,
            'resolved' => Ticket::STATUS_DONE,
            'closed' => Ticket::STATUS_DONE,
            'done' => Ticket::STATUS_DONE,
        ];

        $normalized = $map[$token] ?? null;
        if ($normalized === null) {
            return null;
        }
        return in_array($normalized, Ticket::getStatuses(), true) ? $normalized : null;
    }
}

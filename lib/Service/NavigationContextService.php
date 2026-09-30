<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\TicketMapper;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Builds sidebar navigation and shell params for staff and guest portal pages.
 */
class NavigationContextService
{
	public function __construct(
		private PermissionService $permissionService,
		private IRequest $request,
		private TicketMapper $ticketMapper,
		private ProjectService $projectService,
	) {
	}

	/**
	 * Lightweight stats for the unified sidebar footer (COUNT queries).
	 *
	 * @return array{total: int, open: int}|null
	 */
	public function buildSidebarFooterStats(string $navMode): ?array
	{
		$perm = $this->permissionService;
		if ($navMode === 'guest') {
			if (!$perm->isGuest()) {
				return null;
			}
			// Guest ticket visibility is project-scoped (plus own no-project
			// tickets), not email-scoped — count the same universe the portal
			// lists, or the sidebar disagrees with the dashboard.
			$userId = (string)($perm->getCurrentUserId() ?? '');
			if ($userId === '') {
				return null;
			}
			$email = (string)($perm->getCurrentUserEmail() ?? '');
			$projectIds = array_values(array_map('intval', $perm->getAccessibleProjectIds() ?? []));
			return [
				'total' => $this->ticketMapper->countGuestScope($userId, $email, $projectIds, false),
				'open' => $this->ticketMapper->countGuestScope($userId, $email, $projectIds, true),
			];
		}

		$userId = $perm->getCurrentUserId();
		if ($userId === null || !$perm->canViewHelpdeskOverview($userId)) {
			return null;
		}

		return [
			'total' => $this->ticketMapper->countAllTickets(),
			'open' => $this->ticketMapper->countOpenAllTickets(),
		];
	}

	/**
	 * Infer page id from the current request path (used when controllers omit pageId).
	 */
	public function resolvePageIdFromPath(?string $path = null): string
	{
		$path = GuestAccessAllowlist::normalizePath($path ?? (string)($this->request->getPathInfo() ?? ''));
		$path = (string)preg_replace('#^/index\.php/apps/ticketcheck#', '', $path);
		$path = (string)preg_replace('#^/apps/ticketcheck#', '', $path);
		if ($path === '' || $path === '/') {
			return 'dashboard';
		}

		if (str_starts_with($path, '/portal/kb/articles/')) {
			return 'portal-kb-article';
		}
		if (str_starts_with($path, '/portal/kb') || str_starts_with($path, '/portal/kp')) {
			return 'portal-kb';
		}
		if (str_starts_with($path, '/portal/tickets/create')) {
			return 'portal-create';
		}
		if (preg_match('#^/portal/tickets/\d+#', $path)) {
			return 'portal-ticket-detail';
		}
		if (str_starts_with($path, '/portal/tickets')) {
			return 'portal-tickets';
		}
		if (str_starts_with($path, '/portal/rate-limit')) {
			return 'rate-limit';
		}
		if (str_starts_with($path, '/portal/change-password')) {
			return 'portal-password';
		}
		if (str_starts_with($path, '/portal/email-preferences')) {
			return 'portal-email-prefs';
		}
		if (str_starts_with($path, '/portal')) {
			return 'portal-home';
		}

		if (str_starts_with($path, '/dashboard')) {
			return 'dashboard';
		}
		if (str_starts_with($path, '/tickets/kanban')) {
			return 'tickets-kanban';
		}
		if (preg_match('#^/tickets/\d+/edit#', $path)) {
			return 'ticket-form';
		}
		if (preg_match('#^/tickets/\d+#', $path)) {
			return 'ticket-detail';
		}
		if (str_starts_with($path, '/tickets/create')) {
			return 'ticket-form';
		}
		if (str_starts_with($path, '/tickets')) {
			return 'tickets';
		}
		if (preg_match('#^/projects/\d+/edit#', $path) || str_starts_with($path, '/projects/create')) {
			return 'project-form';
		}
		if (preg_match('#^/projects/\d+#', $path)) {
			return 'project-detail';
		}
		if (str_starts_with($path, '/projects')) {
			return 'projects';
		}
		if (preg_match('#^/customers/\d+/edit#', $path) || str_starts_with($path, '/customers/create')) {
			return 'customer-form';
		}
		if (preg_match('#^/customers/\d+#', $path)) {
			return 'customer-detail';
		}
		if (str_starts_with($path, '/customers')) {
			return 'customers';
		}
		if (str_starts_with($path, '/guests/create')) {
			return 'guest-form';
		}
		if (preg_match('#^/guests/\d+/edit#', $path)) {
			return 'guest-edit';
		}
		if (str_starts_with($path, '/guests')) {
			return 'guests';
		}
		if (preg_match('#^/kb/articles/\d+/edit#', $path) || str_starts_with($path, '/kb/articles/create')) {
			return 'kb-form';
		}
		if (preg_match('#^/kb/articles/\d+#', $path)) {
			return 'kb-article';
		}
		if (str_starts_with($path, '/kb/search')) {
			return 'kb-search';
		}
		if (str_starts_with($path, '/kb') || str_starts_with($path, '/kp')) {
			return 'kb';
		}
		if (str_starts_with($path, '/settings')) {
			return 'settings';
		}

		return 'dashboard';
	}

	/**
	 * Default translated page title and lead for shell chrome when controllers omit them.
	 *
	 * @return array{pageTitle:string,pageHelp:string}
	 */
	public function resolvePageMeta(string $pageId, IL10N $l): array
	{
		$titles = [
			'dashboard' => [$l->t('dashboard'), $l->t('scope_all_projects')],
			'tickets' => [$l->t('all_tickets'), $l->t('view_and_manage_requests')],
			'tickets-kanban' => [$l->t('kanban_board'), $l->t('view_and_manage_requests')],
			'ticket-detail' => [$l->t('ticket_details'), ''],
			'ticket-form' => [$l->t('create_ticket'), ''],
			'projects' => [$l->t('projects'), $l->t('organize_customer_projects')],
			'project-detail' => [$l->t('project_details'), ''],
			'project-form' => [$l->t('create_project'), ''],
			'customers' => [$l->t('customers_page_title'), $l->t('customers_subtitle')],
			'customer-detail' => [$l->t('customer_details'), ''],
			'customer-form' => [$l->t('create_customer'), ''],
			'guests' => [$l->t('guest_users'), $l->t('guest_users_subtitle')],
			'guest-form' => [$l->t('invite_guest_user'), ''],
			'guest-edit' => [$l->t('edit_guest_user'), ''],
			'kb' => [$l->t('knowledge_base'), ''],
			'kb-article' => [$l->t('kb_article'), ''],
			'kb-form' => [$l->t('kb_editor'), ''],
			'kb-search' => [$l->t('search_results'), ''],
			'settings' => [$l->t('settings_page_title'), $l->t('configure_helpdesk')],
			'portal-home' => [$l->t('support_dashboard'), $l->t('all_your_tickets_in_one_place')],
			'portal-tickets' => [$l->t('tickets'), $l->t('create_track_tickets_desc')],
			'portal-create' => [$l->t('create_ticket'), $l->t('portal_create_lead')],
			'portal-ticket-detail' => [$l->t('ticket_details'), ''],
			'portal-kb' => [$l->t('knowledge_base'), ''],
			'portal-kb-article' => [$l->t('kb_article'), ''],
			'portal-password' => [$l->t('change_password'), $l->t('portal_password_intro')],
			'portal-email-prefs' => [$l->t('email_preferences_portal_title'), $l->t('email_preferences_intro')],
			'access-denied' => [$l->t('access_denied'), ''],
			'error' => [$l->t('error'), ''],
			'rate-limit' => [$l->t('please_slow_down'), $l->t('too_many_requests')],
		];
		$pair = $titles[$pageId] ?? [$pageId, ''];
		return ['pageTitle' => (string)$pair[0], 'pageHelp' => (string)$pair[1]];
	}

	/** @return array<string, mixed> */
	public function buildCanonicalUrls(IURLGenerator $urlGenerator): array
	{
		$settingsSections = [];
		foreach (SettingsSectionCatalog::routableSections() as $sectionId) {
			$settingsSections[$sectionId] = $urlGenerator->linkToRoute(
				'ticketcheck.settings.section',
				['section' => $sectionId],
			);
		}

		return [
			'dashboard' => $urlGenerator->linkToRoute('ticketcheck.dashboard.index'),
			'tickets' => $urlGenerator->linkToRoute('ticketcheck.ticket.index'),
			'ticketsKanban' => $urlGenerator->linkToRoute('ticketcheck.ticket.kanban'),
			'ticketCreate' => $urlGenerator->linkToRoute('ticketcheck.ticket.create'),
			'projects' => $urlGenerator->linkToRoute('ticketcheck.project.index'),
			'projectCreate' => $urlGenerator->linkToRoute('ticketcheck.project.create'),
			'customers' => $urlGenerator->linkToRoute('ticketcheck.customer.index'),
			'customerCreate' => $urlGenerator->linkToRoute('ticketcheck.customer.create'),
			'guests' => $urlGenerator->linkToRoute('ticketcheck.guestUser.index'),
			'guestCreate' => $urlGenerator->linkToRoute('ticketcheck.guestUser.create'),
			'kb' => $urlGenerator->linkToRoute('ticketcheck.knowledgeBase.index'),
			'settings' => $urlGenerator->linkToRoute('ticketcheck.settings.index'),
			'settingsSections' => $settingsSections,
			'export' => $urlGenerator->linkToRoute('ticketcheck.export.index'),
			'portalHome' => $urlGenerator->linkToRoute('ticketcheck.customerPortal.index'),
			'portalTickets' => $urlGenerator->linkToRoute('ticketcheck.customerPortal.myTickets'),
			'portalCreate' => $urlGenerator->linkToRoute('ticketcheck.customerPortal.createTicket'),
			'portalKb' => $urlGenerator->linkToRoute('ticketcheck.customerPortal.knowledgeBase'),
			'portalPassword' => $urlGenerator->linkToRoute('ticketcheck.customerPortal.changePassword'),
			'portalEmailPrefs' => $urlGenerator->linkToRoute('ticketcheck.customerPortal.emailPreferences'),
			'portalLogout' => $urlGenerator->linkToRoute('ticketcheck.customerPortal.logout'),
			'guestLanguage' => $urlGenerator->linkToRoute('ticketcheck.customerPortal.setLanguage'),
			'home' => $urlGenerator->linkToDefaultPageUrl(),
		];
	}

	/**
	 * @return list<array{group:string,items:list<array{id:string,label:string,url:string,icon:string,active:bool,hint:string,children?:list<array{id:string,label:string,url:string,active:bool}>}>}>
	 */
	public function buildNavigation(string $currentPageId, string $mode, IL10N $l, IURLGenerator $urlGenerator, ?string $settingsSection = null): array
	{
		$urls = $this->buildCanonicalUrls($urlGenerator);
		if ($currentPageId === 'error') {
			return $mode === 'guest'
				? $this->buildGuestErrorNavigation($l, $urls)
				: $this->buildStaffErrorNavigation($l, $urls);
		}
		if ($mode === 'guest') {
			return $this->buildGuestNavigation($currentPageId, $l, $urls);
		}
		return $this->buildStaffNavigation($currentPageId, $l, $urls, $settingsSection);
	}

	/**
	 * Minimal staff nav on error pages — recovery only, no directory/admin links.
	 *
	 * @param array<string,string> $urls
	 * @return list<array{group:string,items:list<array{id:string,label:string,url:string,icon:string,active:bool,hint:string}>}>
	 */
	private function buildStaffErrorNavigation(IL10N $l, array $urls): array
	{
		$recovery = [
			['id' => 'dashboard', 'label' => $l->t('dashboard'), 'icon' => 'layout-grid', 'url' => $urls['dashboard'], 'hint' => $l->t('dashboard_nav_hint')],
		];
		return [
			['group' => $l->t('nav_group_recovery'), 'items' => $this->navItemsFromDefs($recovery, 'error')],
		];
	}

	/**
	 * @param array<string,string> $urls
	 * @return list<array{group:string,items:list<array{id:string,label:string,url:string,icon:string,active:bool,hint:string}>}>
	 */
	private function buildGuestErrorNavigation(IL10N $l, array $urls): array
	{
		$recovery = [
			['id' => 'portal-home', 'label' => $l->t('portal_home'), 'icon' => 'home', 'url' => $urls['portalHome'], 'hint' => $l->t('portal_home_nav_hint')],
		];
		return [
			['group' => $l->t('nav_group_recovery'), 'items' => $this->navItemsFromDefs($recovery, 'error')],
		];
	}

	/** @param array<string,mixed> $scopeContext */
	public function buildScopeContext(array $scopeContext, string $mode, IL10N $l): array
	{
		if ($mode === 'guest' && $this->permissionService->isGuest()) {
			$scopeContext = $this->mergeGuestOrganizationScope($scopeContext, $l);
		}

		$perm = $this->permissionService;
		$roleLabel = (string)($scopeContext['roleLabel'] ?? '');
		$roleBadge = (string)($scopeContext['roleBadge'] ?? '');
		if ($roleLabel === '') {
			// Guest membership wins (dual-role guest∩agent/admin stays portal-scoped).
			// A pure admin/agent previewing the guest portal still sees their staff badge.
			if ($perm->isGuest()) {
				$roleLabel = $mode === 'guest'
					? $l->t('scope_role_guest_portal')
					: $l->t('scope_role_guest');
				$roleBadge = 'guest';
			} elseif ($perm->isHelpdeskAdmin()) {
				$roleLabel = $l->t('scope_role_admin');
				$roleBadge = 'admin';
			} elseif ($perm->isAgent()) {
				$roleLabel = $l->t('scope_role_agent');
				$roleBadge = 'agent';
			} elseif ($mode === 'guest') {
				$roleLabel = $l->t('scope_role_guest');
				$roleBadge = 'guest';
			}
		}
		return array_merge([
			'roleLabel' => $roleLabel,
			'roleBadge' => $roleBadge,
			'projectName' => (string)($scopeContext['projectName'] ?? ''),
			'customerName' => (string)($scopeContext['customerName'] ?? ''),
			'organizationName' => (string)($scopeContext['organizationName'] ?? ''),
			'contextLine' => (string)($scopeContext['contextLine'] ?? ''),
		], $scopeContext);
	}

	/**
	 * Guest portal scope strip: customer/organization names from accessible projects only (never project titles).
	 *
	 * @param array<string,mixed> $scopeContext
	 * @return array<string,mixed>
	 */
	private function mergeGuestOrganizationScope(array $scopeContext, IL10N $l): array
	{
		if ((string)($scopeContext['organizationName'] ?? '') !== ''
			|| (string)($scopeContext['projectName'] ?? '') !== '') {
			return $scopeContext;
		}

		$label = $this->resolveGuestOrganizationLabel($l);
		if ($label !== '') {
			$scopeContext['organizationName'] = $label;
		}

		return $scopeContext;
	}

	private function resolveGuestOrganizationLabel(IL10N $l): string
	{
		$projectIds = $this->permissionService->getAccessibleProjectIds();
		if ($projectIds === null || $projectIds === []) {
			return '';
		}

		$names = [];
		foreach ($projectIds as $projectId) {
			$id = (int)$projectId;
			if ($id <= 0) {
				continue;
			}
			$project = $this->projectService->getProject($id);
			if ($project === null) {
				continue;
			}
			$name = trim((string)($project['customer_name'] ?? ''));
			if ($name !== '' && !in_array($name, $names, true)) {
				$names[] = $name;
			}
		}

		if ($names === []) {
			return '';
		}

		sort($names, SORT_STRING);

		if (count($names) === 1) {
			return $names[0];
		}

		if (count($names) === 2) {
			return $names[0] . ', ' . $names[1];
		}

		return strtr($l->t('scope_guest_organizations_summary'), [
			'{first}' => $names[0],
			'{second}' => $names[1],
			'{more}' => (string)(count($names) - 2),
		]);
	}

	/**
	 * @param array<string,mixed> $urls
	 * @return list<array{group:string,items:list<array{id:string,label:string,url:string,icon:string,active:bool,hint:string,children?:list<array{id:string,label:string,url:string,active:bool}>}>}>
	 */
	private function buildStaffNavigation(string $currentPageId, IL10N $l, array $urls, ?string $settingsSection = null): array
	{
		$perm = $this->permissionService;
		$uid = (string) ($perm->getCurrentUserId() ?? '');
		if (!$perm->canViewHelpdeskOverview($uid)) {
			return [];
		}

		$groups = [];
		$operations = [
			['id' => 'dashboard', 'label' => $l->t('dashboard'), 'icon' => 'layout-grid', 'url' => $urls['dashboard'], 'hint' => $l->t('dashboard_nav_hint')],
			['id' => 'tickets', 'label' => $l->t('tickets'), 'icon' => 'ticket', 'url' => $urls['tickets'], 'hint' => $l->t('tickets_nav_hint')],
			['id' => 'tickets-kanban', 'label' => $l->t('kanban_board'), 'icon' => 'columns', 'url' => $urls['ticketsKanban'], 'hint' => $l->t('kanban_nav_hint')],
		];
		$groups[] = ['group' => $l->t('nav_group_operations'), 'items' => $this->navItemsFromDefs($operations, $currentPageId)];

		$directory = [
			['id' => 'projects', 'label' => $l->t('projects'), 'icon' => 'folder', 'url' => $urls['projects'], 'hint' => $l->t('projects_nav_hint')],
		];
		if ($perm->canAccessCustomerAdministration()) {
			$directory[] = ['id' => 'customers', 'label' => $l->t('customers'), 'icon' => 'users', 'url' => $urls['customers'], 'hint' => $l->t('customers_nav_hint')];
		}
		if ($perm->canAccessGuestUserAdministration()) {
			$directory[] = ['id' => 'guests', 'label' => $l->t('guest_users'), 'icon' => 'user-plus', 'url' => $urls['guests'], 'hint' => $l->t('guests_nav_hint')];
		}
		if ($directory !== []) {
			$groups[] = ['group' => $l->t('nav_group_directory'), 'items' => $this->navItemsFromDefs($directory, $currentPageId)];
		}

		if ($perm->isKnowledgeBaseIndexAvailable()) {
			$knowledge = [
				['id' => 'kb', 'label' => $l->t('knowledge_base'), 'icon' => 'book', 'url' => $urls['kb'], 'hint' => $l->t('kb_nav_hint')],
			];
			$groups[] = ['group' => $l->t('nav_group_knowledge'), 'items' => $this->navItemsFromDefs($knowledge, $currentPageId)];
		}

		if ($perm->canManageSettings()) {
			$admin = [
				['id' => 'export', 'label' => $l->t('export_data'), 'icon' => 'download', 'url' => $urls['export'], 'hint' => $l->t('export_nav_hint')],
				['id' => 'settings', 'label' => $l->t('settings'), 'icon' => 'settings', 'url' => $urls['settings'], 'hint' => $l->t('settings_nav_hint')],
			];
			$adminItems = $this->navItemsFromDefs($admin, $currentPageId);
			if ($currentPageId === 'settings') {
				$sectionUrls = is_array($urls['settingsSections'] ?? null) ? $urls['settingsSections'] : [];
				$catalog = new SettingsSectionCatalog();
				$children = [];
				foreach (SettingsSectionCatalog::routableSections() as $sectionId) {
					$childHref = (string)($sectionUrls[$sectionId] ?? '');
					if ($childHref === '' || $childHref === '#') {
						continue;
					}
					$children[] = [
						'id' => $sectionId,
						'label' => $catalog->navLabel($l, $sectionId),
						'url' => $childHref,
						'active' => $settingsSection !== null && $settingsSection === $sectionId,
					];
				}
				foreach ($adminItems as $i => $item) {
					if (($item['id'] ?? '') === 'settings') {
						$adminItems[$i]['children'] = $children;
						$adminItems[$i]['active'] = true;
					}
				}
			}
			$groups[] = ['group' => $l->t('nav_group_administration'), 'items' => $adminItems];
		}

		return $groups;
	}

	/**
	 * Active projects the current guest may select when creating a ticket.
	 * Uses per-project lookups (not a bulk list) so pagination limits cannot hide access.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function getActiveAccessibleProjectsForGuest(): array
	{
		$perm = $this->permissionService;
		if (!$perm->isGuest()) {
			return [];
		}

		$accessibleProjectIds = $perm->getAccessibleProjectIds();
		if ($accessibleProjectIds === null || $accessibleProjectIds === []) {
			return [];
		}

		$projects = [];
		foreach ($accessibleProjectIds as $projectId) {
			$id = (int)$projectId;
			if ($id <= 0) {
				continue;
			}
			$project = $this->projectService->getProject($id);
			if ($project !== null && (!isset($project['active']) || (int)$project['active'] === 1)) {
				$projects[] = $project;
			}
		}

		return $projects;
	}

	public function canGuestCreateTicket(): bool
	{
		if (!$this->permissionService->isGuest()) {
			return true;
		}

		return $this->getActiveAccessibleProjectsForGuest() !== [];
	}

	/**
	 * @param array<string, string> $urls
	 * @return list<array{group: string, items: list<array{id: string, label: string, url: string, icon: string, active: bool, hint: string}>}>
	 */
	private function buildGuestNavigation(string $currentPageId, IL10N $l, array $urls): array
	{
		$perm = $this->permissionService;
		$groups = [];
		// Create-ticket entry points live on portal home (quick action) and the
		// my-tickets toolbar — not duplicated in the sidebar nav.
		$support = [
			['id' => 'portal-home', 'label' => $l->t('portal_home'), 'icon' => 'home', 'url' => $urls['portalHome'], 'hint' => $l->t('portal_home_nav_hint')],
			['id' => 'portal-tickets', 'label' => $l->t('tickets'), 'icon' => 'inbox', 'url' => $urls['portalTickets'], 'hint' => $l->t('portal_tickets_nav_hint')],
		];
		$groups[] = ['group' => $l->t('nav_group_support'), 'items' => $this->navItemsFromDefs($support, $currentPageId)];

		if ($perm->isKnowledgeBasePortalContentEnabled()) {
			$help = [
				['id' => 'portal-kb', 'label' => $l->t('knowledge_base'), 'icon' => 'book', 'url' => $urls['portalKb'], 'hint' => $l->t('portal_kb_nav_hint')],
			];
			$groups[] = ['group' => $l->t('nav_group_help'), 'items' => $this->navItemsFromDefs($help, $currentPageId)];
		}

		$account = [
			['id' => 'portal-password', 'label' => $l->t('change_password'), 'icon' => 'lock', 'url' => $urls['portalPassword'], 'hint' => $l->t('portal_password_nav_hint')],
			['id' => 'portal-email-prefs', 'label' => $l->t('email_preferences'), 'icon' => 'mail', 'url' => $urls['portalEmailPrefs'], 'hint' => $l->t('portal_email_nav_hint')],
		];
		$groups[] = ['group' => $l->t('nav_group_account'), 'items' => $this->navItemsFromDefs($account, $currentPageId)];

		return array_values($groups);
	}

	/**
	 * @param list<array{id:string,label:string,icon:string,url:string,hint:string}> $defs
	 * @return list<array{id:string,label:string,url:string,icon:string,active:bool,hint:string}>
	 */
	private function navItemsFromDefs(array $defs, string $currentPageId): array
	{
		$out = [];
		foreach ($defs as $item) {
			$id = $item['id'];
			$active = $id === $currentPageId
				|| ($currentPageId === 'ticket-detail' && $id === 'tickets')
				|| ($currentPageId === 'ticket-form' && $id === 'tickets')
				|| (str_starts_with($currentPageId, 'project-') && $id === 'projects')
				|| (str_starts_with($currentPageId, 'customer-') && $id === 'customers')
				|| (str_starts_with($currentPageId, 'guest-') && $id === 'guests')
				|| (str_starts_with($currentPageId, 'kb-') && $id === 'kb')
				|| (str_starts_with($currentPageId, 'portal-ticket') && $id === 'portal-tickets')
				|| ($currentPageId === 'rate-limit' && $id === 'portal-home');
			$out[] = [
				'id' => $id,
				'label' => $item['label'],
				'url' => $item['url'],
				'icon' => $item['icon'],
				'active' => $active,
				'hint' => $item['hint'],
			];
		}
		return $out;
	}

	/**
	 * Primary header actions for list/index pages when controllers omit pageHeaderActionsHtml.
	 *
	 * @param array<string,mixed> $templateParams
	 */
	public function buildDefaultHeaderActions(
		string $pageId,
		IL10N $l,
		IURLGenerator $urlGenerator,
		array $templateParams = [],
	): string {
		$perm = $this->permissionService;
		$urls = $this->buildCanonicalUrls($urlGenerator);
		$actions = [];

		// Staff ticket lists: quick path to the create form (create page enforces
		// its own permissions server-side; this is navigation only).
		if (($pageId === 'tickets' || $pageId === 'tickets-kanban') && !$perm->isGuest()) {
			$actions[] = [$urls['ticketCreate'], $l->t('create_new_ticket'), true];
		}
		if ($pageId === 'customers' && $perm->canAccessCustomerAdministration()) {
			$actions[] = [$urls['customerCreate'], $l->t('create_new_customer'), true];
		}
		if ($pageId === 'projects' && $perm->canManageSettings()) {
			$actions[] = [$urls['projectCreate'], $l->t('create_new_project'), true];
		}
		if ($pageId === 'guests' && $perm->canAccessGuestUserAdministration()) {
			$actions[] = [$urls['guestCreate'], $l->t('invite_guest_user'), true];
		}
		if (($pageId === 'kb' || $pageId === 'kb-search')
			&& $perm->canManageKnowledgeBase()) {
			$actions[] = [
				$urlGenerator->linkToRoute('ticketcheck.knowledgeBase.create'),
				$l->t('create_article'),
				true,
			];
		}
		// Guest portal create actions live in page toolbars / quick-action cards and
		// the sidebar footer — not duplicated in the page header (same as staff tickets).

		$html = '';
		foreach ($actions as [$href, $label, $primary]) {
			$html .= $this->headerActionLink((string)$href, (string)$label, (bool)$primary);
		}
		return $html;
	}

	private function headerActionLink(string $href, string $label, bool $primary): string
	{
		return ButtonHtml::link(
			$href,
			$label,
			$primary ? ButtonHtml::VARIANT_PRIMARY : ButtonHtml::VARIANT_SECONDARY,
		);
	}
}

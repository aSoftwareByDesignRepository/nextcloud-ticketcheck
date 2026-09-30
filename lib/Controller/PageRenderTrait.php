<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\SettingsSectionCatalog;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Shared page chrome: navigation, assets, client hints, scope strip.
 *
 * Controllers must expose: $appName, $permissionService, $urlGenerator, $localeFormat, $l10n (or l10nFactory->get).
 */
trait PageRenderTrait
{
	abstract protected function getPermissionService(): PermissionService;

	abstract protected function getUrlGenerator(): IURLGenerator;

	abstract protected function getLocaleFormatService(): LocaleFormatService;

	abstract protected function getNavigationContextService(): NavigationContextService;

	abstract protected function getPageL10n(): IL10N;

	abstract protected function getFrontEndAssetService(): FrontEndAssetService;

	/** Pages that use a constrained reading column (forms / settings / license). */
	private const CONSTRAINED_SHELL_PAGE_IDS = [
		'settings',
		'export',
		'ticket-form',
		'project-form',
		'customer-form',
		'guest-form',
		'guest-edit',
		'kb-form',
		'portal-password',
		'portal-email-prefs',
		'access-denied',
	];

	/** Pages that need full-bleed width (lists / kanban / dashboards). */
	private const WIDE_SHELL_PAGE_IDS = [
		'tickets',
		'tickets-kanban',
		'dashboard',
		'projects',
		'customers',
		'guests',
		'kb',
		'portal-home',
		'portal-tickets',
	];

	/**
	 * @param array<string,mixed> $bodyParams
	 * @param array<string,mixed> $scopeContext optional keys: roleLabel, roleBadge, projectName, customerName, organizationName
	 * @param 'standard'|'wide'|'constrained'|'minimal' $shellWidth
	 */
	protected function renderAppPage(
		string $template,
		array $bodyParams,
		string $pageId,
		string $pageTitle,
		string $pageHelp = '',
		?string $pageScript = null,
		string $mode = 'staff',
		array $scopeContext = [],
		string $renderAs = 'user',
		string $shellWidth = 'standard',
	): TemplateResponse {
		$perm = $this->getPermissionService();
		$l = $this->getPageL10n();
		$localeFormat = $this->getLocaleFormatService();
		$isGuest = $mode === 'guest' || $perm->isGuest();
		// Guest membership wins for chrome flags (dual-role stays portal-scoped).
		$isAdmin = !$perm->isGuest() && $perm->isHelpdeskAdmin();
		$isAgent = !$perm->isGuest() && $perm->isAgent();

		$navContext = $this->getNavigationContextService();

		// Same fallback as EnrichTemplateShellContext: when a controller does not
		// provide explicit header actions, render the permission-gated defaults
		// (e.g. "Create customer/project", "Invite guest user") for this page.
		$pageHeaderActionsHtml = (string)($bodyParams['pageHeaderActionsHtml'] ?? '');
		if (trim($pageHeaderActionsHtml) === '') {
			$pageHeaderActionsHtml = $navContext->buildDefaultHeaderActions(
				$pageId,
				$l,
				$this->getUrlGenerator(),
				$bodyParams,
			);
		}

		$shellWidth = in_array($shellWidth, ['standard', 'wide', 'constrained', 'minimal'], true)
			? $shellWidth
			: 'standard';
		$renderAs = in_array($renderAs, ['', 'base', 'error', 'guest', 'public', 'user'], true)
			? $renderAs
			: 'user';
		if ($shellWidth === 'standard' && in_array($pageId, self::CONSTRAINED_SHELL_PAGE_IDS, true)) {
			$shellWidth = 'constrained';
		} elseif ($shellWidth === 'standard' && in_array($pageId, self::WIDE_SHELL_PAGE_IDS, true)) {
			$shellWidth = 'wide';
		}

		$settingsSection = isset($bodyParams['settingsSection'])
			? (string)$bodyParams['settingsSection']
			: null;
		if ($settingsSection === '') {
			$settingsSection = null;
		}

		$urls = $navContext->buildCanonicalUrls($this->getUrlGenerator());
		$settingsSectionLabels = is_array($bodyParams['settingsSectionLabels'] ?? null)
			? $bodyParams['settingsSectionLabels']
			: [];
		if ($pageId === 'settings' && $settingsSectionLabels === []) {
			foreach (SettingsSectionCatalog::routableSections() as $sectionId) {
				$settingsSectionLabels[$sectionId] = (new SettingsSectionCatalog())->navLabel($l, $sectionId);
			}
		}

		$breadcrumbParent = is_array($bodyParams['breadcrumbParent'] ?? null)
			? $bodyParams['breadcrumbParent']
			: null;
		if ($breadcrumbParent === null && $settingsSection !== null) {
			$breadcrumbParent = [
				'label' => $l->t('settings'),
				'url' => $urls['settings'] ?? '#',
			];
		}

		$shell = [
			'pageId' => $pageId,
			'pageTitle' => $pageTitle,
			'pageHelp' => $pageHelp,
			'shellWidth' => $shellWidth,
			'navMode' => $mode,
			'navigation' => $navContext->buildNavigation($pageId, $mode, $l, $this->getUrlGenerator(), $settingsSection),
			'urls' => $urls,
			'settingsSection' => $settingsSection ?? '',
			'settingsSectionLabels' => $settingsSectionLabels,
			'breadcrumbParent' => $breadcrumbParent,
			'clientHints' => $localeFormat->clientHints(),
			'localeFormat' => $localeFormat,
			'l' => $l,
			'urlGenerator' => $this->getUrlGenerator(),
			'isGuest' => $isGuest,
			'isAdmin' => $isAdmin,
			'isAgent' => $isAgent,
			'canExportData' => $perm->canExportData(),
			'kbIndexAvailable' => $perm->isKnowledgeBaseIndexAvailable(),
			'showKnowledgeBase' => $perm->isKnowledgeBasePortalContentEnabled(),
			'scopeContext' => $navContext->buildScopeContext($scopeContext, $mode, $l),
			'pageHeaderActionsHtml' => $pageHeaderActionsHtml,
		];

		$params = array_merge($shell, $bodyParams);
		// Body params win on merge, but an empty value always means "use the defaults"
		// (same contract as the shell-enrichment listener for legacy render paths).
		$params['pageHeaderActionsHtml'] = $pageHeaderActionsHtml;
		if (!isset($params['settingsSectionLabels']) || !is_array($params['settingsSectionLabels'])) {
			$params['settingsSectionLabels'] = $settingsSectionLabels;
		}
		if (!array_key_exists('breadcrumbParent', $params) || $params['breadcrumbParent'] === null) {
			$params['breadcrumbParent'] = $breadcrumbParent;
		}
		// Ensure settings section URLs survive any body-param urls overlay.
		$bodyUrls = is_array($bodyParams['urls'] ?? null) ? $bodyParams['urls'] : [];
		$params['urls'] = array_merge($urls, $bodyUrls);
		if (!isset($params['urls']['settingsSections']) || !is_array($params['urls']['settingsSections'])) {
			$params['urls']['settingsSections'] = $urls['settingsSections'] ?? [];
		}

		if (!is_array($params['stats'] ?? null)) {
			$footerStats = $navContext->buildSidebarFooterStats($mode);
			if ($footerStats !== null) {
				$params['stats'] = $footerStats;
			}
		}

		$response = new TemplateResponse($this->appName, $template, $params, $renderAs);
		$this->registerFrontEndAssets($pageScript ?? $pageId, $mode, $params);
		return $response;
	}

	/**
	 * @param array<string,mixed> $templateParams
	 */
	protected function registerFrontEndAssets(?string $pageScript, string $mode = 'staff', array $templateParams = []): void
	{
		$this->getFrontEndAssetService()->registerForPage($pageScript, $mode, $templateParams);
	}
}

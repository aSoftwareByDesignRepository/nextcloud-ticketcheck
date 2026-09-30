<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Listener;

use OCA\Ticketcheck\AppInfo\Application;
use OCA\Ticketcheck\Service\CSPService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Util;

/**
 * Injects sidebar navigation, URLs, and client hints into every Ticketcheck TemplateResponse
 * so legacy templates do not render an empty navigation block.
 *
 * @template-implements IEventListener<BeforeTemplateRenderedEvent>
 */
class EnrichTemplateShellContext implements IEventListener
{
	/** Templates that manage their own chrome (no staff sidebar). */
	private const SKIP_TEMPLATES = [
		'access-denied',
		'layout.guest',
	];

	public function __construct(
		private NavigationContextService $navigationContext,
		private PermissionService $permissionService,
		private LocaleFormatService $localeFormat,
		private FrontEndAssetService $frontEndAssets,
		private CSPService $cspService,
		private IURLGenerator $urlGenerator,
		private IFactory $l10nFactory,
		private IUserSession $userSession,
		private IRequest $request,
	) {
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof BeforeTemplateRenderedEvent) {
			return;
		}

		$response = $event->getResponse();
		if (!$response instanceof TemplateResponse || $response->getApp() !== Application::APP_ID) {
			return;
		}

		$template = $response->getTemplateName();
		$isPortalTemplate = str_starts_with($template, 'portal/');
		$isGuestRender = $response->getRenderAs() === TemplateResponse::RENDER_AS_GUEST;

		// SECURITY: guest portal uses NC core `layout.guest.php` (`cspNonce` meta).
		// Apply once here (before render) so template params override layout defaults.
		if ($isGuestRender || $isPortalTemplate) {
			$response = $this->cspService->applyPolicyWithNonce($response, 'guest');
		}

		if ($template === 'redirect') {
			$params = $response->getParams();
			$hints = $this->localeFormat->clientHints();
			$params['htmlLang'] = (string)($hints['htmlLang'] ?? $hints['locale'] ?? 'en');
			$params['clientHints'] = $params['clientHints'] ?? $hints;
			$response->setParams($params);
			Util::addStyle(Application::APP_ID, 'redirect');
			return;
		}

		if (in_array($template, self::SKIP_TEMPLATES, true)) {
			return;
		}

		if (!$event->isLoggedIn()) {
			return;
		}

		$params = $response->getParams();
		// Feedback mailto context: hand the raw request URI to the nav footer as a
		// template param — templates must never read $_SERVER themselves.
		if (!is_string($params['appFeedbackPageUrl'] ?? null)) {
			$params['appFeedbackPageUrl'] = $this->request->getRequestUri();
		}
		$shellComplete = !empty($params['navigation']) && is_array($params['navigation']);

		$isPortalTemplate = str_starts_with($template, 'portal/');
		$isGuest = $this->permissionService->isGuest() || $isPortalTemplate;

		$l = $params['l'] ?? null;
		if (!$l instanceof IL10N) {
			$l = $this->l10nFactory->get(Application::APP_ID);
		}

		// Rate-limit HTML is returned from create-ticket while the URL still matches
		// /portal/tickets/create — path-based pageId would wrongly show "Create ticket".
		if ($template === 'portal/rate-limit') {
			$params['pageId'] = 'rate-limit';
		}
		if ($template === 'error') {
			$params['pageId'] = 'error';
			$errorMeta = $this->navigationContext->resolvePageMeta('error', $l);
			if (trim((string)($params['pageTitle'] ?? '')) === '') {
				$params['pageTitle'] = $errorMeta['pageTitle'];
			}
			if (trim((string)($params['pageHelp'] ?? '')) === '') {
				$params['pageHelp'] = $errorMeta['pageHelp'];
			}
		}

		$pageId = (string)($params['pageId'] ?? $this->navigationContext->resolvePageIdFromPath());
		$navMode = (string)($params['navMode'] ?? ($isGuest ? 'guest' : 'staff'));
		$scopeInput = is_array($params['scopeContext'] ?? null) ? $params['scopeContext'] : [];

		if (empty($params['shellWidth'])) {
			$params['shellWidth'] = $this->resolveShellWidth($pageId);
		}

		if ($shellComplete) {
			$params['pageId'] = $pageId;
			$params = $this->mergeSidebarFooterStats($params, $navMode);
			$params = $this->mergeGuestShellParams($params, $isGuest);
			$response->setParams($params);
			$this->frontEndAssets->registerForPage($pageId, $navMode, $params);
			return;
		}

		$params['pageId'] = $pageId;
		if (trim((string)($params['pageTitle'] ?? '')) === '') {
			$meta = $this->navigationContext->resolvePageMeta($pageId, $l);
			$params['pageTitle'] = $meta['pageTitle'];
			if (trim((string)($params['pageHelp'] ?? '')) === '') {
				$params['pageHelp'] = $meta['pageHelp'];
			}
		} elseif (trim((string)($params['pageHelp'] ?? '')) === '') {
			$params['pageHelp'] = $this->navigationContext->resolvePageMeta($pageId, $l)['pageHelp'];
		}
		$params['navMode'] = $navMode;
		$settingsSection = isset($params['settingsSection']) ? (string)$params['settingsSection'] : null;
		if ($settingsSection === '') {
			$settingsSection = null;
		}
		$params['navigation'] = $this->navigationContext->buildNavigation(
			$pageId,
			$navMode,
			$l,
			$this->urlGenerator,
			$settingsSection,
		);
		$canonicalUrls = $this->navigationContext->buildCanonicalUrls($this->urlGenerator);
		$params['urls'] = array_merge($canonicalUrls, is_array($params['urls'] ?? null) ? $params['urls'] : []);
		if (!isset($params['urls']['settingsSections'])) {
			$params['urls']['settingsSections'] = $canonicalUrls['settingsSections'] ?? [];
		}
		$params['clientHints'] = $params['clientHints'] ?? $this->localeFormat->clientHints();
		$params['localeFormat'] = $params['localeFormat'] ?? $this->localeFormat;
		$params['scopeContext'] = $this->navigationContext->buildScopeContext($scopeInput, $navMode, $l);
		$params['isGuest'] = $isGuest;
		// Guest membership wins for chrome flags (dual-role stays portal-scoped).
		$params['isAdmin'] = $params['isAdmin'] ?? (!$this->permissionService->isGuest() && $this->permissionService->isHelpdeskAdmin());
		$params['isAgent'] = $params['isAgent'] ?? (!$this->permissionService->isGuest() && $this->permissionService->isAgent());
		$params['canExportData'] = $params['canExportData'] ?? $this->permissionService->canExportData();
		$params['kbIndexAvailable'] = $params['kbIndexAvailable'] ?? $this->permissionService->isKnowledgeBaseIndexAvailable();
		$params['showKnowledgeBase'] = $params['showKnowledgeBase'] ?? $this->permissionService->isKnowledgeBasePortalContentEnabled();

		if (!isset($params['urlGenerator'])) {
			$params['urlGenerator'] = $this->urlGenerator;
		}

		if ($navMode !== 'guest' && trim((string)($params['pageHeaderActionsHtml'] ?? '')) === '') {
			$params['pageHeaderActionsHtml'] = $this->navigationContext->buildDefaultHeaderActions(
				$pageId,
				$l,
				$this->urlGenerator,
				$params,
			);
		} elseif ($navMode === 'guest') {
			$params['pageHeaderActionsHtml'] = '';
		}

		$params = $this->mergeSidebarFooterStats($params, $navMode);
		$params = $this->mergeGuestShellParams($params, $isGuest);

		$this->frontEndAssets->registerForPage($pageId, $navMode, $params);

		$response->setParams($params);
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function mergeSidebarFooterStats(array $params, string $navMode): array
	{
		$current = is_array($params['stats'] ?? null) ? $params['stats'] : null;
		$needsTotal = $current === null || !isset($current['total']);
		$needsOpen = $current === null || !isset($current['open']);
		if (!$needsTotal && !$needsOpen) {
			return $params;
		}
		$footerStats = $this->navigationContext->buildSidebarFooterStats($navMode);
		if ($footerStats === null) {
			return $params;
		}
		if ($current === null) {
			$params['stats'] = $footerStats;
			return $params;
		}
		if ($needsTotal) {
			$current['total'] = $footerStats['total'];
		}
		if ($needsOpen) {
			$current['open'] = $footerStats['open'];
		}
		$params['stats'] = $current;
		return $params;
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function mergeGuestShellParams(array $params, bool $isGuest): array
	{
		if (!$isGuest) {
			return $params;
		}
		if (trim((string)($params['requesttoken'] ?? '')) === '') {
			$params['requesttoken'] = Util::callRegister();
		}
		if (!isset($params['currentUser'])) {
			$params['currentUser'] = $this->userSession->getUser();
		}
		return $params;
	}

	/**
	 * Mirror PageRenderTrait shell-width defaults for legacy render paths.
	 */
	private function resolveShellWidth(string $pageId): string
	{
		$constrained = [
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
		$wide = [
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
		if (in_array($pageId, $constrained, true)) {
			return 'constrained';
		}
		if (in_array($pageId, $wide, true)) {
			return 'wide';
		}
		return 'standard';
	}
}

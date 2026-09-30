<?php

declare(strict_types=1);

/**
 * Guest portal page renderer.
 *
 * Single render path for every `CustomerPortalController` GET that returns
 * HTML. Wraps `TemplateResponse(..., 'guest')` and merges the base layout
 * params expected by `templates/layout.guest.php` (translations, theme color,
 * GDPR ack, etc.). Shell context (navigation, urls, scope strip, client
 * hints, asset registration) is handled by `EnrichTemplateShellContext` for
 * any template path starting with `portal/`.
 *
 * @copyright Copyright (c) 2026 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Util;

class GuestPortalPageService
{
	public function __construct(
		private readonly GuestLayoutParamsProvider $layoutParams,
		private readonly NavigationContextService $navigation,
		private readonly PermissionService $permission,
		private readonly IURLGenerator $urlGenerator,
		private readonly IUserSession $userSession,
		private readonly IFactory $l10nFactory,
	) {
	}

	/**
	 * Render a guest portal page.
	 *
	 * @param string $template         Template path under `templates/`, e.g. `portal/index`.
	 * @param string $pageId           Stable id matching `FrontEndAssetService` map (e.g. `portal-home`).
	 * @param array<string, mixed> $bodyParams Page-specific template params.
	 * @param string $pageTitle        Heading text for the page. When empty, `NavigationContextService::resolvePageMeta` fills it.
	 * @param string $pageHelp         Lead paragraph under the title. When empty, same fallback as above.
	 * @param array<string, mixed> $scopeContext Optional scope-strip context (projectName, customerName, scopeTicketRef, …).
	 */
	public function render(
		string $template,
		string $pageId,
		array $bodyParams = [],
		string $pageTitle = '',
		string $pageHelp = '',
		array $scopeContext = [],
	): TemplateResponse {
		$l = $this->l10nFactory->get(Application::APP_ID);

		$base = $this->layoutParams->getParams();

		// `Util::callRegister()` requires the `\OC` bootstrap; in unit tests
		// (which run without `\OC`) it would throw `Class "OC" not found`. We
		// guard it so the service stays directly unit-testable. The real
		// guest portal always runs in a fully-bootstrapped Nextcloud, where
		// the token will be present as expected.
		$requesttoken = '';
		if (class_exists('OC', false)) {
			$requesttoken = Util::callRegister();
		}

		$shell = [
			'pageId' => $pageId,
			'pageTitle' => $pageTitle,
			'pageHelp' => $pageHelp,
			'navMode' => 'guest',
			'urlGenerator' => $this->urlGenerator,
			'currentUser' => $this->userSession->getUser(),
			'requesttoken' => $requesttoken,
			'canCreateTicket' => $this->navigation->canGuestCreateTicket(),
			'showKnowledgeBase' => $this->permission->isKnowledgeBasePortalContentEnabled(),
			'scopeContext' => $scopeContext,
			'portalSpeculationRules' => $this->buildPortalSpeculationRules(),
		];

		// `l` must always be the localised IL10N instance for the portal templates.
		$shell['l'] = $l;

		$params = array_merge($base, $shell, $bodyParams);

		return new TemplateResponse(Application::APP_ID, $template, $params, 'guest');
	}

	/**
	 * Speculation Rules payload emitted by `templates/common/page-start.php`
	 * (`<script type="speculationrules">`). Gives the MPA guest portal
	 * SPA-like navigation: Chromium/Safari hover-prerender the target page,
	 * other engines prefetch it; unsupported browsers ignore the block.
	 *
	 * Scope: same-origin `/portal/*` GET pages only. Excluded on purpose:
	 *   - `/portal/api/*` and `/portal/rate-limit/*` (JSON endpoints),
	 *   - attachment downloads (`/portal/tickets/{id}/attachments/{id}`),
	 *   - `a[download]`, new-tab links, in-page `#` anchors, opt-outs.
	 *
	 * @return array<string, mixed>|null Null when the portal base URL cannot
	 *                                   be resolved (e.g. unit tests without
	 *                                   a URL generator route table).
	 */
	private function buildPortalSpeculationRules(): ?array
	{
		$portalBase = (string)$this->urlGenerator->linkToRoute('ticketcheck.customerPortal.index');
		if ($portalBase === '') {
			return null;
		}

		$where = [
			'and' => [
				['href_matches' => $portalBase . '*'],
				['not' => ['href_matches' => $portalBase . '/api/*']],
				['not' => ['href_matches' => $portalBase . '/rate-limit/*']],
				['not' => ['href_matches' => $portalBase . '/tickets/*/attachments/*']],
				['not' => ['selector_matches' => 'a[download], a[target], a[href^="#"], a[data-tc-no-prerender]']],
			],
		];

		return [
			'prefetch' => [
				['source' => 'document', 'where' => $where, 'eagerness' => 'moderate'],
			],
			'prerender' => [
				['source' => 'document', 'where' => $where, 'eagerness' => 'moderate'],
			],
		];
	}
}

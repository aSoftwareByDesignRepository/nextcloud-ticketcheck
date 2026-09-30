<?php

declare(strict_types=1);

/**
 * Entry controller: app root redirect + public l10n bootstrap API.
 *
 * ARCH-22 waived: kept name `PageController` (not renamed to EntryController) to avoid
 * route/class churn. Responsibility matches EntryController in the visual epic plan.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\App\IAppManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Page controller for the main app page
 */
class PageController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IURLGenerator $urlGenerator,
		private readonly PermissionService $permissionService,
		private readonly IAppManager $appManager,
		private readonly IFactory $l10nFactory,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Main page - redirect based on user type / enrollment.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): RedirectResponse|TemplateResponse
	{
		// Redirect guests to customer portal
		if ($this->permissionService->isGuest()) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('ticketcheck.customerPortal.index'));
		}

		// Open (or allow-listed) users without a helpdesk scope still pass the
		// directory door — calm enrollment instead of hiding the app or 403.
		if ($this->permissionService->needsRoleEnrollment()) {
			return $this->needsRole();
		}

		// Redirect agents/admins to dashboard
		return new RedirectResponse($this->urlGenerator->linkToRoute('ticketcheck.dashboard.index'));
	}

	/**
	 * Calm enrollment shell (HTTP 200): door open, role still required.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function needsRole(): TemplateResponse|RedirectResponse
	{
		if (!$this->permissionService->needsRoleEnrollment()) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('ticketcheck.page.index'));
		}

		$l = $this->l10nFactory->get('ticketcheck');
		return new TemplateResponse($this->appName, 'needs-role', [
			'l' => $l,
			'message' => $l->t('needs_role_message'),
			'hint' => $l->t('needs_role_hint'),
			'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
			'homeLabel' => $l->t('back_to_nextcloud'),
		]);
	}

	/**
	 * Load translations for JavaScript
	 * Returns translation JSON for the specified locale
	 *
	 * SECURITY: PublicPage required for login/portal pre-auth; locale strictly validated
	 * to prevent path traversal. Only ISO 639-1 codes allowed. No sensitive data exposed.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getTranslations(string $locale): DataResponse
	{
		// Strict whitelist: only allow [a-z]{2} or [a-z]{2}-[a-z]{2,4} (e.g. en, de, de-DE)
		if (!preg_match('/^[a-z]{2}(-[a-z]{2,4})?$/i', trim($locale))) {
			return new DataResponse([
				'translations' => [],
				'pluralForm' => 'nplurals=2; plural=(n != 1);'
			]);
		}

		$shortLocale = strtolower(explode('-', $locale)[0]);
		$appPath = $this->appManager->getAppPath('ticketcheck');
		$l10nDir = $appPath . '/l10n';

		// Use basename to prevent any path traversal from locale manipulation
		$requestedFile = $l10nDir . '/' . basename($shortLocale . '.json');

		// Canonical path check: ensure we never leave the l10n directory
		$baseReal = realpath($l10nDir);
		$fileReal = $requestedFile !== '' ? realpath($requestedFile) : false;

		if ($baseReal === false || $fileReal === false || !str_starts_with($fileReal, $baseReal)) {
			$requestedFile = $l10nDir . '/en.json';
			$fileReal = realpath($requestedFile);
		}

		if ($fileReal !== false && file_exists($fileReal)) {
			$content = file_get_contents($fileReal);
			$json = json_decode($content, true);
			if ($json !== null && isset($json['translations'])) {
				return new DataResponse($json);
			}
		}

		return new DataResponse([
			'translations' => [],
			'pluralForm' => 'nplurals=2; plural=(n != 1);'
		]);
	}
}

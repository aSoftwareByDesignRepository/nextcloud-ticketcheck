<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\AppInfo\Application;
use OCP\IRequest;
use OCP\Util;

/**
 * Central registry for TicketCheck CSS/JS load order (ARCH-04).
 */
class FrontEndAssetService
{
	public function __construct(
		private IRequest $request,
	) {
	}

	/**
	 * @param array<string,mixed> $templateParams
	 */
	public function registerForPage(?string $pageScript, string $mode = 'staff', array $templateParams = []): void
	{
		Util::addStyle(Application::APP_ID, 'app');
		Util::addScript(Application::APP_ID, 'i18n');
		Util::addScript(Application::APP_ID, 'common/l10n');
		Util::addScript(Application::APP_ID, 'common/constants');
		Util::addScript(Application::APP_ID, 'common/icons');
		Util::addScript(Application::APP_ID, 'common/dates');
		Util::addScript(Application::APP_ID, 'common/messaging');
		Util::addScript(Application::APP_ID, 'common/components');
		Util::addScript(Application::APP_ID, 'common/app-feedback');
		// Soft keyboard: keep focused notes/inputs above the IME on phones.
		Util::addScript(Application::APP_ID, 'common/keep-focused-visible');

		Util::addScript(Application::APP_ID, 'common/select-combobox');
		Util::addScript(Application::APP_ID, 'common/form-validation');
		// Canonical field-error renderer (WCAG 3.3.1/3.3.3): verbatim sync of
		// apps/_shared/field-errors — api.js calls CheckFieldErrors.markValidationFields
		// when a !ok response carries a `fields` map.
		Util::addScript(Application::APP_ID, 'common/field-errors');
		Util::addStyle(Application::APP_ID, 'common/field-errors');
		Util::addScript(Application::APP_ID, 'common/api');
		Util::addScript(Application::APP_ID, 'common/nav');

		// Guest-portal-only chrome: a11y prefs + GDPR ack banner. Loaded here so
		// `layout.guest.php` stays free of `Util::add*` (no duplicate asset loads).
		if ($mode === 'guest') {
			Util::addScript(Application::APP_ID, 'guest-layout-boot');
			Util::addScript(Application::APP_ID, 'gdpr-notice');
			// Cross-document view transitions for MPA → SPA-feel navigation.
			Util::addStyle(Application::APP_ID, 'guest-portal');
		}

		$path = (string)$this->request->getPathInfo();
		$isTicketEdit = $pageScript === 'ticket-form' && str_contains($path, '/edit');

		$scriptMap = [
			'dashboard' => null,
			'tickets' => 'tickets',
			'tickets-kanban' => 'tickets',
			'ticket-detail' => 'ticket-detail',
			'ticket-form' => $isTicketEdit ? 'ticket-edit-form' : 'ticket-form',
			'projects' => 'projects-index',
			'project-detail' => 'project-detail',
			'project-form' => 'project-form',
			'customers' => 'customers-index',
			'customer-detail' => 'customer-detail',
			'customer-form' => 'customer-form',
			'guests' => 'guest-management',
			'guest-form' => 'guest-form',
			'guest-edit' => 'guest-edit-form',
			'kb' => 'kb-categories',
			'kb-article' => null,
			'kb-form' => 'kb-editor',
			'kb-search' => null,
			'settings' => 'settings',
			'export' => 'export',
			'portal-home' => 'portal-home',
			'portal-tickets' => null,
			'portal-create' => 'guest-ticket-form',
			'portal-ticket-detail' => 'guest-ticket-reply',
			'portal-kb' => 'kb-search',
			'portal-kb-article' => 'kb-comments',
			'portal-password' => 'password-change',
			'portal-email-prefs' => 'personal-settings',
			'rate-limit' => null,
			'error' => null,
		];

		if ($pageScript === 'settings') {
			// Legacy /settings#anchor forward — must load before settings.js boot.
			Util::addScript(Application::APP_ID, 'settings-legacy-redirect');
		}

		if ($pageScript === 'tickets') {
			Util::addScript(Application::APP_ID, 'ticket-filters');
			Util::addScript(Application::APP_ID, 'bulk-actions');
			Util::addScript(Application::APP_ID, 'list-row-click');
		}

		if ($pageScript === 'tickets-kanban') {
			Util::addScript(Application::APP_ID, 'ticket-filters');
			Util::addScript(Application::APP_ID, 'kanban-dnd');
		}

		if ($pageScript === 'portal-tickets') {
			Util::addScript(Application::APP_ID, 'list-row-click');
		}

		if (in_array($pageScript, ['customers', 'projects'], true)) {
			Util::addScript(Application::APP_ID, 'list-row-click');
		}

		if ($pageScript === 'kb') {
			Util::addScript(Application::APP_ID, 'kb-search');
		}

		if ($pageScript === 'kb-article') {
			Util::addScript(Application::APP_ID, 'ticketcheck');
			if (!empty($templateParams['kbCommentsEnabled'])) {
				Util::addScript(Application::APP_ID, 'kb-comments');
			}
			if (!empty($templateParams['kbFeedbackEnabled'])) {
				Util::addScript(Application::APP_ID, 'kb-article-feedback');
			}
		} elseif ($pageScript === 'kb-search') {
			Util::addScript(Application::APP_ID, 'ticketcheck');
			Util::addScript(Application::APP_ID, 'kb-categories');
		} else {
			if ($pageScript === 'ticket-detail') {
				Util::addScript(Application::APP_ID, 'common/ticket-search-picker');
			}
			$resolved = null;
			if ($pageScript !== null) {
				// Use array_key_exists: map values may be null (no page script), and `??`
				// would incorrectly fall back to $pageScript (e.g. load missing dashboard.js).
				$resolved = array_key_exists($pageScript, $scriptMap)
					? $scriptMap[$pageScript]
					: $pageScript;
			}
			if ($resolved === 'export') {
				Util::addScript(Application::APP_ID, 'common/export-entity-picker');
				Util::addScript(Application::APP_ID, 'common/export-assignee-picker');
			}
			if ($resolved !== null && $resolved !== '') {
				Util::addScript(Application::APP_ID, $resolved);
			}
		}

		if ($pageScript === 'settings') {
			$settingsSection = (string)($templateParams['settingsSection'] ?? '');
			if ($settingsSection === 'access' || $settingsSection === '') {
				Util::addScript(Application::APP_ID, 'app-access-groups-picker');
				Util::addScript(Application::APP_ID, 'app-access-users-picker');
			}
			if ($settingsSection === 'license') {
				Util::addStyle(Application::APP_ID, 'license-settings');
				Util::addScript(Application::APP_ID, 'license-settings');
			}
		}

		if (in_array($pageScript, ['ticket-detail', 'customer-detail', 'project-detail', 'customers', 'projects'], true)) {
			Util::addScript(Application::APP_ID, 'deletion-modal');
		}

		if ($pageScript === 'portal-ticket-detail') {
			Util::addScript(Application::APP_ID, 'guest-ticket-reply-preview');
		}

		if (in_array($pageScript, ['ticket-detail', 'portal-ticket-detail', 'ticket-form'], true)) {
			Util::addScript(Application::APP_ID, 'common/attachment-lightbox');
		}

		if ($pageScript === 'portal-kb-article') {
			Util::addScript(Application::APP_ID, 'kb-article-feedback');
		}
	}
}

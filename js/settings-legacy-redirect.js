/**
 * Legacy /settings#anchor → split sub-page forwarding.
 *
 * The old settings page was one long document with jump anchors. URL fragments
 * are never sent to the server, so stale bookmarks like /settings#kb-categories
 * land on the default sub-page. This module forwards them client-side to the
 * owning sub-page, keeping the fragment so the browser still scrolls to the
 * section after navigation.
 *
 * ANCHOR_SECTIONS mirrors OCA\Ticketcheck\Service\SettingsSectionCatalog::LEGACY_ANCHORS —
 * a contract test (tests/js/settings-pages.test.mjs + tests/Unit) pins both maps.
 *
 * Security: the target URL is read from the server-rendered, HTML-escaped
 * data-tc-urls payload and selected only through the frozen allowlist below;
 * no fragment content ever becomes a URL by itself.
 */
(function (root) {
	'use strict';

	const ANCHOR_SECTIONS = Object.freeze({
		'settings-email-heading': 'email',
		'settings-kb-heading': 'knowledge-base',
		'settings-access-heading': 'access',
		'app-access-preview-card': 'access',
		'app-access-preview-heading': 'access',
		'kb-categories': 'kb-categories',
		'settings-kb-categories-heading': 'kb-categories',
		'escalation-rules': 'escalation',
		'settings-escalation-heading': 'escalation',
		'settings-about-heading': 'about',
	});

	/**
	 * @param {object} doc document (or a stub exposing getElementById)
	 * @param {object} loc location (or a stub exposing hash)
	 * @returns {string|null} absolute-path URL (with fragment) to forward to, or null
	 */
	function resolve(doc, loc) {
		const hash = String((loc && loc.hash) || '').replace(/^#/, '');
		if (!Object.prototype.hasOwnProperty.call(ANCHOR_SECTIONS, hash)) {
			return null;
		}
		const targetSection = ANCHOR_SECTIONS[hash];
		const rootEl = doc && typeof doc.getElementById === 'function'
			? doc.getElementById('app-content')
			: null;
		if (!rootEl || typeof rootEl.getAttribute !== 'function') {
			return null;
		}
		const currentSection = String(rootEl.getAttribute('data-tc-settings-section') || '');
		// Only forward while on a settings sub-page, and never when the anchor
		// already lives on the current page (native scroll handles that).
		if (currentSection === '' || currentSection === targetSection) {
			return null;
		}
		let urls = null;
		try {
			urls = JSON.parse(String(rootEl.getAttribute('data-tc-urls') || '{}'));
		} catch (_err) {
			return null;
		}
		const sectionUrl = urls && urls.settingsSections ? urls.settingsSections[targetSection] : null;
		if (typeof sectionUrl !== 'string' || sectionUrl === '') {
			return null;
		}
		return sectionUrl + '#' + hash;
	}

	const api = Object.freeze({ ANCHOR_SECTIONS, resolve });
	if (root) {
		root.TicketCheckSettingsLegacyRedirect = api;
	}
})(typeof window !== 'undefined' ? window : null);

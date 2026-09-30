<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCP\IL10N;

/**
 * Single source of truth for the split settings sub-pages.
 *
 * Every artifact that knows about settings sections derives from this class:
 *  - appinfo/routes.php pins its `{section}` requirement to {@see routeRequirement()},
 *  - SettingsController validates and titles pages through it,
 *  - templates/settings.php dispatches to templates/parts/settings/<section>.php,
 *  - js/settings-legacy-redirect.js mirrors {@see LEGACY_ANCHORS} for old `/settings#anchor` links.
 *
 * Contract tests in tests/Unit assert all four artifacts stay in sync, so a
 * drifting copy fails CI instead of shipping a dead link.
 */
final class SettingsSectionCatalog
{
	public const DEFAULT_SECTION = 'access';

	/**
	 * Ordered section slugs — order drives the sidebar sub-navigation.
	 *
	 * This is the registry of every known section, including hidden ones.
	 * Routable (reachable) sections are derived via {@see routableSections()}.
	 *
	 * @var list<string>
	 */
	public const SECTIONS = [
		'access',
		'email',
		'knowledge-base',
		'kb-categories',
		'escalation',
		'license',
		'support',
		'about',
	];

	/**
	 * Sections registered but not reachable — removed from routing, sidebar
	 * sub-navigation, chip bar, and legacy-anchor forwarding. Their templates,
	 * controller params, and label/navLabel arms are kept so restoring a
	 * section is a one-line removal here.
	 *
	 * license + support are hidden until further notice (product decision).
	 * The license JSON API (/api/license/*) is unaffected — the mobile
	 * companion still depends on it.
	 *
	 * @var list<string>
	 */
	public const HIDDEN_SECTIONS = [
		'license',
		'support',
	];

	/**
	 * Legacy single-page anchors → owning section slug.
	 *
	 * The old settings page was one long document with jump anchors. URL
	 * fragments never reach the server, so js/settings-legacy-redirect.js uses
	 * this map to forward stale bookmarks client-side.
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_ANCHORS = [
		'settings-email-heading' => 'email',
		'settings-kb-heading' => 'knowledge-base',
		'settings-access-heading' => 'access',
		'app-access-preview-card' => 'access',
		'app-access-preview-heading' => 'access',
		'kb-categories' => 'kb-categories',
		'settings-kb-categories-heading' => 'kb-categories',
		'escalation-rules' => 'escalation',
		'settings-escalation-heading' => 'escalation',
		'settings-about-heading' => 'about',
	];

	/**
	 * Anchors owned by {@see HIDDEN_SECTIONS}. Removed from LEGACY_ANCHORS so
	 * stale bookmarks are not forwarded to a 404; restore together with the
	 * section.
	 *
	 * @var array<string, string>
	 */
	public const HIDDEN_LEGACY_ANCHORS = [
		'ticketcheck-license' => 'license',
		'tc-license-panel' => 'license',
		'tc-license-heading' => 'license',
		'tc-support-us' => 'support',
		'tc-support-us-title' => 'support',
	];

	/**
	 * Sections that resolve to a real sub-page right now.
	 *
	 * @return list<string>
	 */
	public static function routableSections(): array
	{
		return array_values(array_diff(self::SECTIONS, self::HIDDEN_SECTIONS));
	}

	public function isSection(string $section): bool
	{
		return in_array($section, self::routableSections(), true);
	}

	/**
	 * Value for the `{section}` route placeholder requirement.
	 */
	public static function routeRequirement(): string
	{
		return implode('|', self::routableSections());
	}

	/**
	 * Human page title (H1 / breadcrumb current). Longer, descriptive copy.
	 */
	public function label(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Access control'),
			'email' => $l->t('Email notifications'),
			'knowledge-base' => $l->t('Knowledge base settings'),
			'kb-categories' => $l->t('Knowledge base categories'),
			'escalation' => $l->t('Escalation rules'),
			'license' => $l->t('Mobile license'),
			'support' => $l->t('Support & us'),
			'about' => $l->t('About this project'),
			default => $l->t('Settings'),
		};
	}

	/**
	 * Short sidebar / in-page chip label.
	 */
	public function navLabel(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Access'),
			'email' => $l->t('Email'),
			'knowledge-base' => $l->t('Knowledge base'),
			'kb-categories' => $l->t('KB categories'),
			'escalation' => $l->t('Escalation'),
			'license' => $l->t('License'),
			'support' => $l->t('Support us'),
			// Intentionally the same string as label(): a bare "Über" reads
			// broken in German, and the full name still fits chips/sidebar.
			'about' => $l->t('About this project'),
			default => $l->t('Settings'),
		};
	}

	/**
	 * One-line page lead under the H1. Reuses existing settings_*_lead keys.
	 *
	 * License and support intentionally return '' — their panels ship a
	 * self-contained intro and a page lead would duplicate that copy.
	 */
	public function help(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('settings_app_access_lead'),
			'email' => $l->t('settings_email_lead'),
			'knowledge-base' => $l->t('settings_kb_lead'),
			'kb-categories' => $l->t('settings_kb_categories_lead'),
			'escalation' => $l->t('settings_escalation_lead'),
			'about' => $l->t('settings_about_lead'),
			default => '',
		};
	}
}

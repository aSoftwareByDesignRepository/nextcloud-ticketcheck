<?php
/**
 * App settings shell (governance, app-admins only).
 *
 * Split into one sub-page per section: the controller
 * validates `settingsSection` against {@see \OCA\Ticketcheck\Service\SettingsSectionCatalog}
 * and this template dispatches through a literal slug → file map, so no
 * request value is ever used to build an include path.
 *
 * Permission is hard-denied in SettingsController (error template) — there is
 * no soft denial card in this dispatcher.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';

// Map must cover exactly SettingsSectionCatalog::routableSections() — hidden
// sections (license, support) keep their partials on disk but stay unreachable.
$tcSettingsSectionFiles = [
	'access' => 'access.php',
	'email' => 'email.php',
	'knowledge-base' => 'knowledge-base.php',
	'kb-categories' => 'kb-categories.php',
	'escalation' => 'escalation.php',
	'about' => 'about.php',
];
$tcRequestedSection = (string) ($_['settingsSection'] ?? '');
?>
			<div id="tc-settings-page" class="tc-settings">
<?php
include __DIR__ . '/parts/settings-nav.php';
if (!isset($tcSettingsSectionFiles[$tcRequestedSection])) {
	throw new \RuntimeException('TicketCheck settings: unknown section reached the template dispatcher.');
}
include __DIR__ . '/parts/settings/' . $tcSettingsSectionFiles[$tcRequestedSection];
?>
			</div>
<?php include __DIR__ . '/common/page-end.php'; ?>

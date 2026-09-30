<?php
/**
 * In-page settings sub-navigation.
 *
 * Complements the sidebar sub-list: Nextcloud collapses #app-navigation below
 * ~1024px, so without this chip bar admins cannot reach sibling settings pages
 * on phones/tablets. Labels and URLs come from the controller
 * (SettingsSectionCatalog) — never hardcoded here.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var string $tcRequestedSection
 */

$tcNavLabels = (array) ($_['settingsSectionLabels'] ?? []);
$tcNavUrls = (array) (($_['urls']['settingsSections'] ?? []) ?: []);
if ($tcNavLabels === []) {
	return;
}
?>
<nav class="tc-settings-nav" id="tc-settings-pages" aria-label="<?php p($l->t('Settings pages')); ?>">
	<?php foreach ($tcNavLabels as $sectionId => $sectionLabel):
		$sectionId = (string) $sectionId;
		$href = (string) ($tcNavUrls[$sectionId] ?? '');
		if ($href === '' || $href === '#') {
			continue;
		}
		$active = $tcRequestedSection === $sectionId;
		?>
		<a class="tc-settings-nav__link<?php p($active ? ' is-active' : ''); ?>"
			href="<?php p($href); ?>"
			<?php if ($active): ?>aria-current="page"<?php endif; ?>>
			<?php p((string) $sectionLabel); ?>
		</a>
	<?php endforeach; ?>
</nav>

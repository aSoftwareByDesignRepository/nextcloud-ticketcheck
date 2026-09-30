<?php

/**
 * Dashboard overview KPI card (equal-height grid cell).
 *
 * Layout: tinted icon → large value → label → meta (granny-proof scan order).
 *
 * @var \OCP\IL10N $l
 * @var string $kpiValue Display value (pre-formatted)
 * @var string $kpiLabel Primary label
 * @var string $kpiMeta Secondary line
 * @var string $kpiIconHtml Rendered icon markup (IconCatalog)
 * @var string $kpiIconVariant primary|accent|success|neutral
 * @var string|null $kpiHref Link target; omit for non-interactive card
 * @var string $kpiAriaLabel Accessible name (translated)
 * @var string|null $kpiCardVariant highlighted|success|null (unused; kept for callers)
 */

$kpiHref = $kpiHref ?? null;
$kpiCardVariant = $kpiCardVariant ?? null;
$kpiIconVariant = $kpiIconVariant ?? 'neutral';
$kpiIsLink = $kpiHref !== null && $kpiHref !== '';
$kpiIsZero = trim((string)$kpiValue) === '0';

$kpiClasses = ['tc-dashboard-kpi', 'helpdesk-card'];
if ($kpiIsLink) {
	$kpiClasses[] = 'helpdesk-card--interactive';
} else {
	$kpiClasses[] = 'helpdesk-card--static';
}
if ($kpiIsZero) {
	$kpiClasses[] = 'tc-dashboard-kpi--zero';
}
?>
<?php if ($kpiIsLink): ?>
	<a href="<?php p($kpiHref); ?>"
		class="<?php p(implode(' ', $kpiClasses)); ?>"
		aria-label="<?php p($kpiAriaLabel); ?>">
<?php else: ?>
	<div class="<?php p(implode(' ', $kpiClasses)); ?>"
		role="group"
		aria-label="<?php p($kpiAriaLabel); ?>">
<?php endif; ?>
		<span class="tc-dashboard-kpi__icon tc-dashboard-kpi__icon--<?php p($kpiIconVariant); ?>" aria-hidden="true">
			<?php print_unescaped($kpiIconHtml); ?>
		</span>
		<span class="tc-dashboard-kpi__body">
			<span class="tc-dashboard-kpi__value"><?php p($kpiValue); ?></span>
			<span class="tc-dashboard-kpi__label"><?php p($kpiLabel); ?></span>
			<span class="tc-dashboard-kpi__meta"><?php p($kpiMeta); ?></span>
		</span>
<?php if ($kpiIsLink): ?>
	</a>
<?php else: ?>
	</div>
<?php endif; ?>

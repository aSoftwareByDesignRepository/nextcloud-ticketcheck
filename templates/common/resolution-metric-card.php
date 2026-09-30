<?php

/**
 * Average resolution time metric — only rendered when backed by measurable completed tickets.
 *
 * IMPORTANT: Nextcloud IL10N uses vsprintf(), so named `{days}` / `{count}` placeholders
 * must be substituted with strtr() after translation (same pattern as the rest of this app).
 *
 * @var array<string, mixed> $analytics
 * @var \OCP\IL10N $l
 * @var string $resolutionMetricCardClass optional extra card classes
 */

$sampleCount = (int)($analytics['resolution_sample_count'] ?? 0);
$avgHours = (float)($analytics['avg_resolution_time_hours'] ?? 0);
if ($sampleCount <= 0 || $avgHours <= 0) {
	return;
}

$avgDays = (float)($analytics['avg_resolution_time_days'] ?? ($avgHours / 24));
if ($avgHours >= 24) {
	$roundedDays = round($avgDays, 1);
	$durationLabel = strtr($l->t('metric_duration_days'), [
		'{days}' => (string)$roundedDays,
	]);
} else {
	$durationLabel = strtr($l->t('metric_duration_hours'), [
		'{hours}' => (string)round($avgHours, 1),
	]);
}

$sampleLabel = strtr(
	$l->n('avg_resolution_from_ticket', 'avg_resolution_from_tickets', $sampleCount),
	['{count}' => (string)$sampleCount],
);
$ariaLabel = strtr($l->t('avg_resolution_time_aria'), [
	'{duration}' => $durationLabel,
	'{count}' => (string)$sampleCount,
]);
$cardClass = trim('helpdesk-metric-card ' . ($resolutionMetricCardClass ?? ''));
?>

<div class="<?php p($cardClass); ?>"
	role="group"
	aria-label="<?php p($ariaLabel); ?>">
	<div class="helpdesk-metric-card__value"><?php p($durationLabel); ?></div>
	<div class="helpdesk-metric-card__label"><?php p($l->t('avg_resolution_time')); ?></div>
	<div class="helpdesk-metric-card__subtitle"><?php p($sampleLabel); ?></div>
</div>

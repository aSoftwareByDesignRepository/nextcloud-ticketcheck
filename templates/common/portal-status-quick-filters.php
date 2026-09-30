<?php

declare(strict_types=1);

/**
 * Portal status quick filters — same chip pattern as staff ticket list (helpdesk-filter-toggle).
 *
 * @var \OCP\IL10N $l
 * @var string $filterMode 'link' (my-tickets) or 'js' (portal home)
 * @var string $currentStatusFilter normalized: '', 'new', 'in_progress', 'waiting', 'done'
 * @var string $filterBaseUrl base URL for link mode (no trailing query)
 */

$filterMode = (string)($filterMode ?? 'link');
$currentStatusFilter = (string)($currentStatusFilter ?? '');
$filterBaseUrl = (string)($filterBaseUrl ?? '');

$statusDefs = [
	'' => 'all_statuses',
	'new' => 'new_status',
	'in_progress' => 'in_progress_status',
	'waiting' => 'waiting_status',
	'done' => 'done_status',
];

$buildFilterUrl = static function (string $base, string $status): string {
	if ($status === '') {
		return $base;
	}
	$separator = str_contains($base, '?') ? '&' : '?';
	return $base . $separator . 'status=' . rawurlencode($status);
};
?>
<section class="tc-section portal-quick-status-filters" aria-labelledby="portal-quick-status-label">
	<div class="helpdesk-filter-bar helpdesk-filter-bar--quick">
		<div class="helpdesk-filter-bar__label-row">
			<span id="portal-quick-status-label" class="helpdesk-filter-bar__label"><?php p($l->t('quick_filters')); ?></span>
		</div>
		<div class="helpdesk-filter-bar__group helpdesk-filter-bar__group--quick tc-quick-filter-grid"
			role="group"
			aria-label="<?php p($l->t('quick_filters')); ?>">
			<?php foreach ($statusDefs as $statusKey => $labelKey): ?>
				<?php
				$isActive = $currentStatusFilter === $statusKey;
				$toggleClass = 'helpdesk-filter-toggle' . ($isActive ? ' helpdesk-filter-toggle--active' : '');
				?>
				<?php if ($filterMode === 'js'): ?>
					<button type="button"
						class="<?php p($toggleClass); ?> portal-status-chip"
						data-filter-status="<?php p($statusKey); ?>"
						<?php if ($isActive): ?>aria-pressed="true"<?php else: ?>aria-pressed="false"<?php endif; ?>>
						<?php p($l->t($labelKey)); ?>
					</button>
				<?php else: ?>
					<a href="<?php p($buildFilterUrl($filterBaseUrl, $statusKey)); ?>"
						class="<?php p($toggleClass); ?>"
						<?php if ($isActive): ?>aria-current="page"<?php endif; ?>>
						<?php p($l->t($labelKey)); ?>
					</a>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	</div>
</section>

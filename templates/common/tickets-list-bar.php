<?php

/**
 * Ticket count + list/kanban view toggle (single row above the ticket view).
 *
 * Expects $ticketCount (int) in the including template scope.
 */

if (!isset($_['l'], $ticketCount)) {
	return;
}

/** @var \OCP\IL10N $l */
$l = $_['l'];
$count = (int) $ticketCount;
?>
<div class="tc-tickets-list-bar">
	<p class="tc-tickets-list-bar__count helpdesk-text-muted"
		role="status"
		aria-live="polite"
		aria-atomic="true">
		<?php p($l->t('showing')); ?>
		<strong><?php p((string) $count); ?></strong>
		<?php p($count === 1 ? $l->t('ticket') : $l->t('tickets_lowercase')); ?>
	</p>
	<?php include __DIR__ . '/tickets-view-toggle.php'; ?>
</div>

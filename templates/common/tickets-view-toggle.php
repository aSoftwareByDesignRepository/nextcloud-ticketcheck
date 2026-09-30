<?php

/**
 * List / Kanban segmented view toggle (staff ticket list + kanban).
 *
 * @var \OCP\IURLGenerator $urlGenerator
 * @var string $pageId
 * @var array<string, string> $currentFilters
 */

if (!isset($_['l'], $_['urlGenerator'])) {
	return;
}

/** @var \OCP\IL10N $l */
$l = $_['l'];

/** @var \OCP\IURLGenerator $urlGenerator */
$urlGenerator = $_['urlGenerator'];
$pageId = (string) ($_['pageId'] ?? 'tickets');
$activeView = $pageId === 'tickets-kanban' ? 'kanban' : 'list';
$cfTickets = $_['currentFilters'] ?? [];

$ticketViewLink = static function (string $routeName) use ($urlGenerator, $cfTickets): string {
	$query = [];
	foreach (['status', 'priority', 'category', 'assigned_to', 'project_id', 'customer_id', 'search'] as $key) {
		$v = $cfTickets[$key] ?? '';
		if ($v !== null && $v !== '') {
			$query[$key] = (string) $v;
		}
	}
	if (($cfTickets['hide_done'] ?? '1') === '0') {
		$query['hide_done'] = '0';
	}
	$base = $urlGenerator->linkToRoute($routeName);
	if ($query === []) {
		return $base;
	}

	return $base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
};

$listUrl = $ticketViewLink('ticketcheck.ticket.index');
$kanbanUrl = $ticketViewLink('ticketcheck.ticket.kanban');
?>
<nav class="tc-view-toggle"
	aria-label="<?php p($l->t('ticket_views_nav')); ?>">
	<a href="<?php p($listUrl); ?>"
		class="tc-view-toggle__option<?php echo $activeView === 'list' ? ' tc-view-toggle__option--active' : ''; ?>"
		<?php if ($activeView === 'list') : ?>
			aria-current="page"
		<?php endif; ?>>
		<?php p($l->t('ticket_list_view')); ?>
	</a>
	<a href="<?php p($kanbanUrl); ?>"
		class="tc-view-toggle__option<?php echo $activeView === 'kanban' ? ' tc-view-toggle__option--active' : ''; ?>"
		<?php if ($activeView === 'kanban') : ?>
			aria-current="page"
		<?php endif; ?>>
		<?php p($l->t('kanban_board')); ?>
	</a>
</nav>

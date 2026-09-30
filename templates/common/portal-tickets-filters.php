<?php

/**
 * Guest portal ticket list filters (quick chips + advanced GET form).
 *
 * @var \OCP\IL10N $l
 * @var string $filterBaseUrl Base URL for filter links and form action
 */

use OCA\Ticketcheck\Service\IconCatalog;

/** @var \OCP\IL10N $l */
$l = $_['l'];

$filterBareUrl = (string) ($_['filterBaseUrl'] ?? '');
$cfTickets = $_['currentFilters'] ?? [];
$ticketsListHasActiveFilters = !empty($_['ticketsListHasActiveFilters']);

$portalFilterLink = static function (array $params = []) use ($filterBareUrl, $cfTickets): string {
	if (($cfTickets['hide_done'] ?? '1') === '0') {
		$params['hide_done'] = '0';
	}
	if ($params === []) {
		return $filterBareUrl;
	}

	return $filterBareUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
};

$allActive = empty($cfTickets['status'])
	&& empty($cfTickets['priority'])
	&& empty($cfTickets['category'])
	&& empty($cfTickets['project_id'])
	&& empty($cfTickets['search'])
	&& (($cfTickets['hide_done'] ?? '1') === '1');

$portalProjects = $_['portalProjects'] ?? [];
$showProjectFilter = empty($_['scopedProjectId']) && count($portalProjects) > 1;
?>

<section class="tc-section tc-tickets-filters" aria-label="<?php p($l->t('tickets_filters_title')); ?>">
	<h2 class="tc-sr-only"><?php p($l->t('tickets_filters_title')); ?></h2>

	<div class="tc-tickets-filters__quick" role="group" aria-label="<?php p($l->t('quick_filters')); ?>">
		<p class="tc-tickets-filters__quick-label" id="portal-tickets-quick-filters-label"><?php p($l->t('quick_filters')); ?></p>
		<div class="tc-filter-chips__list" aria-labelledby="portal-tickets-quick-filters-label">
			<a href="<?php p($portalFilterLink([])); ?>"
				class="tc-filter-chip<?php echo $allActive ? ' tc-filter-chip--active' : ''; ?>"
				<?php if ($allActive) : ?>aria-current="page"<?php endif; ?>>
				<?php p($l->t('all_statuses')); ?>
			</a>
			<?php $newStatusActive = in_array($cfTickets['status'] ?? '', ['new', 'open'], true); ?>
			<a href="<?php p($portalFilterLink(['status' => 'new'])); ?>"
				class="tc-filter-chip<?php echo $newStatusActive ? ' tc-filter-chip--active' : ''; ?>"
				<?php if ($newStatusActive) : ?>aria-current="page"<?php endif; ?>>
				<?php p($l->t('status_new')); ?>
			</a>
			<?php $inProgressActive = in_array($cfTickets['status'] ?? '', ['in_progress', 'working'], true); ?>
			<a href="<?php p($portalFilterLink(['status' => 'in_progress'])); ?>"
				class="tc-filter-chip<?php echo $inProgressActive ? ' tc-filter-chip--active' : ''; ?>"
				<?php if ($inProgressActive) : ?>aria-current="page"<?php endif; ?>>
				<?php p($l->t('status_in_progress')); ?>
			</a>
			<?php $waitingActive = in_array($cfTickets['status'] ?? '', ['waiting', 'waiting_customer'], true); ?>
			<a href="<?php p($portalFilterLink(['status' => 'waiting'])); ?>"
				class="tc-filter-chip<?php echo $waitingActive ? ' tc-filter-chip--active' : ''; ?>"
				<?php if ($waitingActive) : ?>aria-current="page"<?php endif; ?>>
				<?php p($l->t('status_waiting')); ?>
			</a>
			<?php $doneActive = in_array($cfTickets['status'] ?? '', ['done', 'resolved', 'closed'], true); ?>
			<a href="<?php p($portalFilterLink(['status' => 'done'])); ?>"
				class="tc-filter-chip<?php echo $doneActive ? ' tc-filter-chip--active' : ''; ?>"
				<?php if ($doneActive) : ?>aria-current="page"<?php endif; ?>>
				<?php p($l->t('status_done')); ?>
			</a>
			<?php $urgentActive = ($cfTickets['priority'] ?? '') === 'urgent'; ?>
			<a href="<?php p($portalFilterLink(['priority' => 'urgent'])); ?>"
				class="tc-filter-chip<?php echo $urgentActive ? ' tc-filter-chip--active' : ''; ?>"
				<?php if ($urgentActive) : ?>aria-current="page"<?php endif; ?>>
				<?php p($l->t('priority_urgent')); ?>
			</a>
			<?php foreach (($_['categories'] ?? []) as $category): ?>
				<?php $categoryActive = ($cfTickets['category'] ?? '') === $category; ?>
				<a href="<?php p($portalFilterLink(['category' => $category])); ?>"
					class="tc-filter-chip<?php echo $categoryActive ? ' tc-filter-chip--active' : ''; ?>"
					<?php if ($categoryActive) : ?>aria-current="page"<?php endif; ?>>
					<?php p($l->t('category_' . strtolower((string) $category))); ?>
				</a>
			<?php endforeach; ?>
		</div>
	</div>

	<details class="tc-tickets-filters__panel tc-filter-more"
		id="portal-ticket-filters-panel"
		<?php if ($ticketsListHasActiveFilters): ?>open<?php endif; ?>>
		<summary class="tc-filter-more__summary tc-tickets-filters__panel-toggle" id="portal-ticket-filters-toggle">
			<span class="tc-tickets-filters__panel-toggle-text">
				<span class="tc-tickets-filters__summary-label"><?php p($l->t('filter')); ?></span>
				<?php if ($ticketsListHasActiveFilters): ?>
					<span class="tc-tickets-filters__active-hint"><?php p($l->t('filters_active')); ?></span>
				<?php endif; ?>
			</span>
		</summary>

		<div class="tc-filter-more__body tc-tickets-filters__panel-body">
			<form method="GET"
				id="portal-ticket-filters-form"
				action="<?php p($filterBareUrl); ?>"
				class="tc-tickets-filters__form"
				role="search"
				aria-label="<?php p($l->t('filter')); ?>">

				<div class="tc-tickets-filters__grid">
					<div class="tc-filter-field">
						<label for="portal-filter-status" class="tc-filter-field__label"><?php p($l->t('status')); ?></label>
						<select name="status" id="portal-filter-status" class="tc-filter-field__control">
							<option value=""><?php p($l->t('all_statuses')); ?></option>
							<?php foreach (($_['statuses'] ?? []) as $status): ?>
								<option value="<?php p($status); ?>" <?php if (($cfTickets['status'] ?? '') === $status) {
									p('selected');
								} ?>>
									<?php p($l->t('status_' . $status)); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="tc-filter-field">
						<label for="portal-filter-priority" class="tc-filter-field__label"><?php p($l->t('priority')); ?></label>
						<select name="priority" id="portal-filter-priority" class="tc-filter-field__control">
							<option value=""><?php p($l->t('all_priorities_option')); ?></option>
							<?php foreach (($_['priorities'] ?? []) as $priority): ?>
								<option value="<?php p($priority); ?>" <?php if (($cfTickets['priority'] ?? '') === $priority) {
									p('selected');
								} ?>>
									<?php p($l->t('priority_' . $priority)); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="tc-filter-field">
						<label for="portal-filter-category" class="tc-filter-field__label"><?php p($l->t('category')); ?></label>
						<select name="category" id="portal-filter-category" class="tc-filter-field__control">
							<option value=""><?php p($l->t('all_categories_option')); ?></option>
							<?php foreach (($_['categories'] ?? []) as $category): ?>
								<option value="<?php p($category); ?>" <?php if (($cfTickets['category'] ?? '') === $category) {
									p('selected');
								} ?>>
									<?php p($l->t('category_' . strtolower((string) $category))); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<?php if ($showProjectFilter): ?>
						<div class="tc-filter-field">
							<label for="portal-filter-project" class="tc-filter-field__label"><?php p($l->t('project')); ?></label>
							<select name="project_id" id="portal-filter-project" class="tc-filter-field__control">
								<option value=""><?php p($l->t('all_projects')); ?></option>
								<?php foreach ($portalProjects as $project): ?>
									<option value="<?php p((string)($project['id'] ?? '')); ?>" <?php if (($cfTickets['project_id'] ?? '') == ($project['id'] ?? '')) {
										p('selected');
									} ?>>
										<?php p((string)($project['name'] ?? '')); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					<?php endif; ?>

					<div class="tc-filter-field tc-filter-field--search">
						<label for="portal-filter-search" class="tc-filter-field__label"><?php p($l->t('search')); ?></label>
						<div class="tc-filter-picker__shell">
							<span class="tc-filter-picker__leading" aria-hidden="true">
								<?php print_unescaped(IconCatalog::render('search', 'tc-filter-picker__icon')); ?>
							</span>
							<input type="search"
								name="search"
								id="portal-filter-search"
								value="<?php p($cfTickets['search'] ?? ''); ?>"
								placeholder="<?php p($l->t('search_tickets')); ?>"
								class="tc-filter-picker__input"
								maxlength="<?php p((string)\OCA\Ticketcheck\Service\PortalTicketListFilterService::MAX_SEARCH_LENGTH); ?>"
								autocomplete="off">
						</div>
					</div>

					<div class="tc-filter-field tc-filter-field--checkbox">
						<input type="hidden" name="hide_done" value="1">
						<label class="tc-filter-checkbox" for="portal-filter-hide-done">
							<input type="checkbox"
								name="hide_done"
								id="portal-filter-hide-done"
								value="0"
								<?php if (($cfTickets['hide_done'] ?? '1') === '0') {
									p('checked');
								} ?>>
							<span class="tc-filter-checkbox__text">
								<span class="tc-filter-checkbox__title"><?php p($l->t('show_done_tickets')); ?></span>
								<span class="tc-filter-checkbox__help"><?php p($l->t('include_completed_tickets_in_list')); ?></span>
							</span>
						</label>
					</div>
				</div>

				<div class="tc-tickets-filters__actions">
					<a href="<?php p($filterBareUrl); ?>"
						id="portal-ticket-filters-clear"
						class="helpdesk-btn helpdesk-btn--secondary">
						<?php p($l->t('clear_button')); ?>
					</a>
					<button type="submit" class="helpdesk-btn helpdesk-btn--primary">
						<?php p($l->t('apply_button')); ?>
					</button>
				</div>
			</form>
		</div>
	</details>

	<?php include __DIR__ . '/portal-tickets-toolbar.php'; ?>
</section>

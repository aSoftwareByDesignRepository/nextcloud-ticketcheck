<?php

/**
 * Shared ticket list / kanban filters (quick chips + advanced GET form).
 *
 * @var \OCP\IL10N $l
 * @var string $ticketsFilterRoute Route name, e.g. ticketcheck.ticket.index or ticketcheck.ticket.kanban
 */

use OCA\Ticketcheck\Service\IconCatalog;

/** @var \OCP\IUser|null $currentUser */
$currentUser = $_['currentUser'] ?? null;
$currentUserId = $currentUser ? $currentUser->getUID() : null;

$filterRoute = (string) ($_['ticketsFilterRoute'] ?? 'ticketcheck.ticket.index');
$filterBareUrl = $_['urlGenerator']->linkToRoute($filterRoute);
$ticketFilterLink = static function (array $params = []) use ($filterBareUrl): string {
	if ($params === []) {
		return $filterBareUrl;
	}

	return $filterBareUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
};

$cfTickets = $_['currentFilters'] ?? [];
$ticketsListHasActiveFilters = (($cfTickets['hide_done'] ?? '1') === '0');
if (!$ticketsListHasActiveFilters) {
	foreach (['status', 'priority', 'category', 'assigned_to', 'project_id', 'customer_id', 'search'] as $k) {
		$v = $cfTickets[$k] ?? '';
		if ($v !== null && $v !== '') {
			$ticketsListHasActiveFilters = true;
			break;
		}
	}
}

$allActive = empty($cfTickets['assigned_to'])
	&& empty($cfTickets['status'])
	&& empty($cfTickets['priority'])
	&& empty($cfTickets['category'])
	&& empty($cfTickets['project_id'])
	&& empty($cfTickets['customer_id'])
	&& empty($cfTickets['search'])
	&& (($cfTickets['hide_done'] ?? '1') === '1');
?>

<section class="tc-section tc-tickets-filters" aria-label="<?php p($l->t('tickets_filters_title')); ?>">
	<h2 class="tc-sr-only"><?php p($l->t('tickets_filters_title')); ?></h2>

	<div class="tc-tickets-filters__quick" role="group" aria-label="<?php p($l->t('quick_filters')); ?>">
		<p class="tc-tickets-filters__quick-label" id="tickets-quick-filters-label"><?php p($l->t('quick_filters')); ?></p>
		<div class="tc-filter-chips__list" aria-labelledby="tickets-quick-filters-label">
			<a href="<?php p($ticketFilterLink([])); ?>"
				class="tc-filter-chip<?php echo $allActive ? ' tc-filter-chip--active' : ''; ?>"
				<?php if ($allActive) : ?>aria-current="page"<?php endif; ?>>
				<?php p($l->t('all_tickets')); ?>
			</a>
			<?php if (!empty($currentUserId)): ?>
				<?php $myTicketsActive = ($cfTickets['assigned_to'] ?? '') === $currentUserId; ?>
				<a href="<?php p($ticketFilterLink(['assigned_to' => $currentUserId])); ?>"
					class="tc-filter-chip<?php echo $myTicketsActive ? ' tc-filter-chip--active' : ''; ?>"
					<?php if ($myTicketsActive) : ?>aria-current="page"<?php endif; ?>>
					<?php p($l->t('my_tickets')); ?>
				</a>
			<?php endif; ?>
			<?php $newStatusActive = ($cfTickets['status'] ?? '') === 'new'; ?>
			<a href="<?php p($ticketFilterLink(['status' => 'new'])); ?>"
				class="tc-filter-chip<?php echo $newStatusActive ? ' tc-filter-chip--active' : ''; ?>"
				<?php if ($newStatusActive) : ?>aria-current="page"<?php endif; ?>>
				<?php p($l->t('status_new')); ?>
			</a>
			<?php $inProgressActive = in_array($cfTickets['status'] ?? '', ['in_progress', 'working'], true); ?>
			<a href="<?php p($ticketFilterLink(['status' => 'in_progress'])); ?>"
				class="tc-filter-chip<?php echo $inProgressActive ? ' tc-filter-chip--active' : ''; ?>"
				<?php if ($inProgressActive) : ?>aria-current="page"<?php endif; ?>>
				<?php p($l->t('status_in_progress')); ?>
			</a>
			<?php $waitingActive = ($cfTickets['status'] ?? '') === 'waiting'; ?>
			<a href="<?php p($ticketFilterLink(['status' => 'waiting'])); ?>"
				class="tc-filter-chip<?php echo $waitingActive ? ' tc-filter-chip--active' : ''; ?>"
				<?php if ($waitingActive) : ?>aria-current="page"<?php endif; ?>>
				<?php p($l->t('status_waiting')); ?>
			</a>
			<?php $urgentActive = ($cfTickets['priority'] ?? '') === 'urgent'; ?>
			<a href="<?php p($ticketFilterLink(['priority' => 'urgent'])); ?>"
				class="tc-filter-chip<?php echo $urgentActive ? ' tc-filter-chip--active' : ''; ?>"
				<?php if ($urgentActive) : ?>aria-current="page"<?php endif; ?>>
				<?php p($l->t('priority_urgent')); ?>
			</a>
			<?php foreach (($_['categories'] ?? []) as $category): ?>
				<?php $categoryActive = ($cfTickets['category'] ?? '') === $category; ?>
				<a href="<?php p($ticketFilterLink(['category' => $category])); ?>"
					class="tc-filter-chip<?php echo $categoryActive ? ' tc-filter-chip--active' : ''; ?>"
					<?php if ($categoryActive) : ?>aria-current="page"<?php endif; ?>>
					<?php p($l->t('category_' . strtolower((string) $category))); ?>
				</a>
			<?php endforeach; ?>
		</div>
	</div>

	<details class="tc-tickets-filters__panel tc-filter-more"
		id="ticket-filters-panel"
		<?php if ($ticketsListHasActiveFilters): ?>open<?php endif; ?>>
		<summary class="tc-filter-more__summary tc-tickets-filters__panel-toggle" id="ticket-filters-toggle">
			<span class="tc-tickets-filters__panel-toggle-text">
				<span class="tc-tickets-filters__summary-label"><?php p($l->t('filter')); ?></span>
				<?php if ($ticketsListHasActiveFilters): ?>
					<span class="tc-tickets-filters__active-hint"><?php p($l->t('filters_active')); ?></span>
				<?php endif; ?>
			</span>
		</summary>

		<div class="tc-filter-more__body tc-tickets-filters__panel-body">
			<form method="GET"
				id="ticket-filters-form"
				action="<?php p($filterBareUrl); ?>"
				class="tc-tickets-filters__form"
				role="search"
				aria-label="<?php p($l->t('filter')); ?>">

				<div class="tc-tickets-filters__grid">
					<div class="tc-filter-field">
						<label for="filter-status" class="tc-filter-field__label"><?php p($l->t('status')); ?></label>
						<select name="status" id="filter-status" class="tc-filter-field__control">
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
						<label for="filter-priority" class="tc-filter-field__label"><?php p($l->t('priority')); ?></label>
						<select name="priority" id="filter-priority" class="tc-filter-field__control">
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
						<label for="filter-assigned" class="tc-filter-field__label"><?php p($l->t('assigned_to')); ?></label>
						<select name="assigned_to" id="filter-assigned" class="tc-filter-field__control">
							<option value=""><?php p($l->t('anyone')); ?></option>
							<option value="unassigned" <?php if (($cfTickets['assigned_to'] ?? '') === 'unassigned') {
								p('selected');
							} ?>><?php p($l->t('unassigned')); ?></option>
							<?php if (!empty($currentUserId)): ?>
								<option value="<?php p($currentUserId); ?>" <?php if (($cfTickets['assigned_to'] ?? '') === $currentUserId) {
									p('selected');
								} ?>><?php p($l->t('me')); ?></option>
							<?php endif; ?>
						</select>
					</div>

					<div class="tc-filter-field">
						<label for="filter-category" class="tc-filter-field__label"><?php p($l->t('category')); ?></label>
						<select name="category" id="filter-category" class="tc-filter-field__control">
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

					<div class="tc-filter-field tc-filter-picker tc-select-combobox">
						<label for="filter-project-search" class="tc-filter-field__label"><?php p($l->t('project')); ?></label>
						<div class="tc-filter-picker__shell">
							<span class="tc-filter-picker__leading" aria-hidden="true">
								<?php print_unescaped(IconCatalog::render('search', 'tc-filter-picker__icon')); ?>
							</span>
							<input type="search"
								id="filter-project-search"
								class="tc-filter-picker__input tc-select-combobox__input"
								placeholder="<?php p($l->t('project_filter_search_placeholder')); ?>"
								autocomplete="off"
								aria-controls="filter-project-results"
								aria-expanded="false"
								aria-autocomplete="list"
								aria-describedby="filter-project-search-status">
							<button type="button"
								class="tc-filter-picker__clear"
								data-tc-combobox-clear
								hidden
								aria-label="<?php p($l->t('reset_filter')); ?>">
								<?php print_unescaped(IconCatalog::render('x', 'tc-filter-picker__icon')); ?>
							</button>
						</div>
						<select name="project_id" id="filter-project" class="tc-filter-picker__native tc-select-combobox__native" tabindex="-1" aria-hidden="true">
							<option value=""><?php p($l->t('all_projects')); ?></option>
							<?php foreach (($_['projects'] ?? []) as $project): ?>
								<option value="<?php p($project['id']); ?>" <?php if (($cfTickets['project_id'] ?? '') == $project['id']) {
									p('selected');
								} ?>>
									<?php p($project['name']); ?>
									<?php if (!empty($project['customer_name'])): ?>
										— <?php p($project['customer_name']); ?>
									<?php endif; ?>
								</option>
							<?php endforeach; ?>
						</select>
						<div id="filter-project-results" class="tc-filter-picker__results tc-select-combobox__results" role="listbox" aria-label="<?php p($l->t('project')); ?>" hidden></div>
						<p id="filter-project-search-status" class="tc-filter-picker__hint tc-select-combobox__status" role="status" aria-live="polite" aria-atomic="true" hidden></p>
					</div>

					<div class="tc-filter-field tc-filter-picker tc-select-combobox">
						<label for="filter-customer-search" class="tc-filter-field__label"><?php p($l->t('customer')); ?></label>
						<div class="tc-filter-picker__shell">
							<span class="tc-filter-picker__leading" aria-hidden="true">
								<?php print_unescaped(IconCatalog::render('search', 'tc-filter-picker__icon')); ?>
							</span>
							<input type="search"
								id="filter-customer-search"
								class="tc-filter-picker__input tc-select-combobox__input"
								placeholder="<?php p($l->t('customer_filter_search_placeholder')); ?>"
								autocomplete="off"
								aria-controls="filter-customer-results"
								aria-expanded="false"
								aria-autocomplete="list"
								aria-describedby="filter-customer-search-status">
							<button type="button"
								class="tc-filter-picker__clear"
								data-tc-combobox-clear
								hidden
								aria-label="<?php p($l->t('reset_filter')); ?>">
								<?php print_unescaped(IconCatalog::render('x', 'tc-filter-picker__icon')); ?>
							</button>
						</div>
						<select name="customer_id" id="filter-customer" class="tc-filter-picker__native tc-select-combobox__native" tabindex="-1" aria-hidden="true">
							<option value=""><?php p($l->t('all_customers')); ?></option>
							<?php foreach (($_['customers'] ?? []) as $customer): ?>
								<option value="<?php p($customer['id']); ?>" <?php if (($cfTickets['customer_id'] ?? '') == $customer['id']) {
									p('selected');
								} ?>>
									<?php p($customer['name']); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<div id="filter-customer-results" class="tc-filter-picker__results tc-select-combobox__results" role="listbox" aria-label="<?php p($l->t('customer')); ?>" hidden></div>
						<p id="filter-customer-search-status" class="tc-filter-picker__hint tc-select-combobox__status" role="status" aria-live="polite" aria-atomic="true" hidden></p>
					</div>

					<div class="tc-filter-field tc-filter-field--search">
						<label for="filter-search" class="tc-filter-field__label"><?php p($l->t('search')); ?></label>
						<input type="search"
							name="search"
							id="filter-search"
							value="<?php p($cfTickets['search'] ?? ''); ?>"
							placeholder="<?php p($l->t('search_tickets')); ?>"
							class="tc-filter-field__control"
							autocomplete="off">
					</div>

					<div class="tc-filter-field tc-filter-field--checkbox">
						<input type="hidden" name="hide_done" value="1">
						<label class="tc-filter-checkbox" for="filter-hide-done">
							<input type="checkbox"
								name="hide_done"
								id="filter-hide-done"
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

				<div class="tc-tickets-filters__saved" role="region" aria-labelledby="saved-views-heading">
					<h3 id="saved-views-heading" class="tc-tickets-filters__saved-title"><?php p($l->t('saved_filter_views')); ?></h3>
					<div id="saved-views-list" class="tc-tickets-filters__saved-list"></div>
					<div class="tc-tickets-filters__save-form">
						<label for="filter-save-view-name" class="tc-filter-field__label">
							<?php p($l->t('modal_save_view_label')); ?>
						</label>
						<div class="tc-tickets-filters__save-row">
							<input type="text"
								id="filter-save-view-name"
								class="tc-filter-field__control"
								maxlength="100"
								autocomplete="off"
								placeholder="<?php p($l->t('modal_save_view_label')); ?>">
							<button type="button" id="filter-save-view" class="helpdesk-btn helpdesk-btn--secondary">
								<?php p($l->t('save_current_view')); ?>
							</button>
						</div>
					</div>
				</div>

				<div class="tc-tickets-filters__actions">
					<a href="<?php p($filterBareUrl); ?>"
						id="ticket-filters-clear"
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

</section>

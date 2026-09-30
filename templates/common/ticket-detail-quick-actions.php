<?php

/**
 * Ticket detail quick actions — status, priority, assignee (staff).
 */

if (empty($_['canEdit']) || !isset($_['ticket'], $_['l'])) {
	return;
}

/** @var \OCP\IL10N $l */
$l = $_['l'];
$ticket = $_['ticket'];
?>
<div class="ticket-detail-quick-actions ticket-detail-quick-actions--top helpdesk-card" role="region" aria-labelledby="ticket-detail-quick-actions-title">
	<div class="helpdesk-card__body">
		<h2 id="ticket-detail-quick-actions-title" class="ticket-detail-quick-actions-title"><?php p($l->t('quick_actions')); ?></h2>
		<p class="ticket-detail-quick-actions-lead helpdesk-text-muted helpdesk-text-sm" id="ticket-detail-quick-actions-help">
			<?php p($l->t('quick_actions_help')); ?>
		</p>
		<div class="ticket-detail-quick-actions-grid" role="group" aria-describedby="ticket-detail-quick-actions-help">
			<div class="helpdesk-form-group ticket-detail-form-group-compact tc-field">
				<label for="status-select" class="helpdesk-form-label"><?php p($l->t('change_status')); ?></label>
				<select id="status-select" class="helpdesk-form-control" data-ticket-id="<?php p($ticket->getId()); ?>">
					<option value="new" <?php if ($ticket->getStatus() === 'new') {
						p('selected');
					} ?>><?php p($l->t('new_status')); ?></option>
					<option value="in_progress" <?php if ($ticket->getStatus() === 'in_progress' || $ticket->getStatus() === 'working') {
						p('selected');
					} ?>><?php p($l->t('in_progress_status')); ?></option>
					<option value="waiting" <?php if ($ticket->getStatus() === 'waiting') {
						p('selected');
					} ?>><?php p($l->t('waiting_status')); ?></option>
					<option value="done" <?php if ($ticket->getStatus() === 'done') {
						p('selected');
					} ?>><?php p($l->t('done_status')); ?></option>
				</select>
			</div>

			<div class="helpdesk-form-group ticket-detail-form-group-compact tc-field">
				<label for="priority-select" class="helpdesk-form-label"><?php p($l->t('change_priority')); ?></label>
				<select id="priority-select" class="helpdesk-form-control" data-ticket-id="<?php p($ticket->getId()); ?>">
					<?php foreach (($_['priorities'] ?? []) as $pr): ?>
						<option value="<?php p($pr); ?>" <?php if ($ticket->getPriority() === $pr) {
							p('selected');
						} ?>><?php p($l->t('priority_' . $pr)); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="helpdesk-form-group ticket-detail-form-group-compact tc-field tc-select-combobox">
				<label for="assign-search-input" class="helpdesk-form-label"><?php p($l->t('assign_to')); ?></label>
				<input type="text"
					id="assign-search-input"
					class="helpdesk-form-control"
					placeholder="<?php p($l->t('assignee_search_placeholder')); ?>"
					autocomplete="off"
					aria-controls="assign-search-results"
					aria-expanded="false"
					aria-autocomplete="list"
					aria-describedby="assign-search-status">
				<span id="assign-search-status" class="tc-select-combobox__status" role="status" aria-live="polite" hidden></span>
				<select id="assign-select" class="tc-select-combobox__native" tabindex="-1" aria-hidden="true" data-ticket-id="<?php p($ticket->getId()); ?>">
					<option value="">-- <?php p($l->t('unassigned')); ?> --</option>
					<?php if (!empty($_['availableAgents'])): ?>
						<?php foreach ($_['availableAgents'] as $agent): ?>
							<option value="<?php p($agent['user_id']); ?>"
								<?php if ($ticket->getAssignedTo() === $agent['user_id']) {
									p('selected');
								} ?>>
								<?php p($agent['user_name']); ?>
							</option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
				<div id="assign-search-results" class="tc-select-combobox__results" role="listbox" aria-label="<?php p($l->t('assign_to')); ?>" hidden></div>
			</div>
		</div>
	</div>
</div>

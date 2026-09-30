<?php
/**
 * Settings sub-page: Escalation rules.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$escalationPriorities = is_array($_['escalationPriorities'] ?? null) ? $_['escalationPriorities'] : [];
$escalationStatuses = is_array($_['escalationStatuses'] ?? null) ? $_['escalationStatuses'] : [];
$assignableUsers = is_array($_['assignableUsers'] ?? null) ? $_['assignableUsers'] : [];
?>
<section class="tc-section" id="escalation-rules" aria-labelledby="settings-escalation-heading">
	<h2 id="settings-escalation-heading" class="helpdesk-sr-only"><?php p($l->t('Escalation rules')); ?></h2>
	<div class="helpdesk-card">
		<div class="helpdesk-card__body">
			<p class="helpdesk-form-help helpdesk-mb-md"><?php p($l->t('escalation_rules_help')); ?></p>
			<button type="button" id="add-escalation-rule-btn" class="helpdesk-btn helpdesk-btn--primary helpdesk-mb-md">
				<?php p($l->t('add_escalation_rule')); ?>
			</button>

			<ul id="escalation-rule-list" class="helpdesk-list"
				data-priorities="<?php p(json_encode($escalationPriorities)); ?>"
				data-statuses="<?php p(json_encode($escalationStatuses)); ?>"
				data-assignable-users="<?php p(json_encode($assignableUsers)); ?>">
			</ul>

			<div id="escalation-rule-form-wrapper" class="helpdesk-mt-md settings-escalation-rule-form-wrapper">
				<form id="escalation-rule-form" class="tc-form-grid" novalidate>
					<input type="hidden" id="escalation-rule-id" name="id" value="">
					<div class="helpdesk-form-group tc-field tc-field--full-width">
						<label for="escalation-rule-name" class="helpdesk-form-label"><?php p($l->t('name')); ?></label>
						<input type="text" id="escalation-rule-name" name="name" class="helpdesk-form-control" required>
					</div>
					<div class="helpdesk-form-group tc-field">
						<label for="escalation-rule-age-hours" class="helpdesk-form-label"><?php p($l->t('age_hours')); ?></label>
						<input type="number" id="escalation-rule-age-hours" name="age_hours" min="1" max="8760"
							class="helpdesk-form-control" value="24" required>
						<span class="helpdesk-form-help"><?php p($l->t('age_hours_help')); ?></span>
					</div>
					<div class="helpdesk-form-group tc-field">
						<label for="escalation-rule-min-priority" class="helpdesk-form-label"><?php p($l->t('min_priority')); ?></label>
						<select id="escalation-rule-min-priority" name="min_priority" class="helpdesk-form-control">
							<option value=""><?php p($l->t('any_priority')); ?></option>
							<?php foreach ($escalationPriorities as $p): ?>
								<option value="<?php p($p); ?>"><?php p($l->t($p)); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<fieldset class="helpdesk-form-group tc-field tc-field--full-width">
						<legend class="helpdesk-form-label"><?php p($l->t('statuses')); ?></legend>
						<div id="escalation-rule-statuses" class="helpdesk-form-checkbox-group tc-settings__status-grid">
							<?php foreach ($escalationStatuses as $s): ?>
								<label class="helpdesk-form-checkbox">
									<input type="checkbox" name="statuses[]" value="<?php p($s); ?>" class="escalation-status-cb">
									<span><?php p($l->t($s)); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
						<span class="helpdesk-form-help"><?php p($l->t('statuses_help')); ?></span>
					</fieldset>
					<div class="helpdesk-form-group tc-field">
						<label for="escalation-rule-set-priority" class="helpdesk-form-label"><?php p($l->t('action_set_priority')); ?></label>
						<select id="escalation-rule-set-priority" name="set_priority" class="helpdesk-form-control">
							<option value=""><?php p($l->t('no_change')); ?></option>
							<?php foreach ($escalationPriorities as $p): ?>
								<option value="<?php p($p); ?>"><?php p($l->t($p)); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="helpdesk-form-group tc-field">
						<label for="escalation-rule-assign-to" class="helpdesk-form-label"><?php p($l->t('action_assign_to')); ?></label>
						<select id="escalation-rule-assign-to" name="assign_to" class="helpdesk-form-control">
							<option value=""><?php p($l->t('unassigned')); ?></option>
							<?php foreach ($assignableUsers as $user): ?>
								<option value="<?php p($user['user_id']); ?>"><?php p($user['user_name']); ?> (<?php p($user['user_id']); ?>)</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="helpdesk-form-actions tc-field--full-width">
						<button type="submit" class="helpdesk-btn helpdesk-btn--primary"><?php p($l->t('save')); ?></button>
						<button type="button" id="escalation-rule-cancel-btn" class="helpdesk-btn helpdesk-btn--ghost"><?php p($l->t('cancel')); ?></button>
					</div>
				</form>
			</div>
		</div>
	</div>
</section>

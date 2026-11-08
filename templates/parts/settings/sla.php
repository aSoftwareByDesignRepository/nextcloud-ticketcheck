<?php
/**
 * Settings sub-page: SLA targets per priority and the business-hours calendar.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\Ticketcheck\Service\SlaPolicyService;

$slaSettings = is_array($_['slaSettings'] ?? null) ? $_['slaSettings'] : [];
$slaTargets = is_array($slaSettings['targets'] ?? null) ? $slaSettings['targets'] : SlaPolicyService::DEFAULT_TARGETS;
$slaCalendar = is_array($slaSettings['business_hours'] ?? null) ? $slaSettings['business_hours'] : [];
$slaMaxHours = (string)(int)($slaSettings['max_hours'] ?? SlaPolicyService::MAX_HOURS);
$slaDays = is_array($slaCalendar['days'] ?? null) ? $slaCalendar['days'] : SlaPolicyService::DEFAULT_BUSINESS_DAYS;
$slaHolidays = is_array($slaCalendar['holidays'] ?? null) ? $slaCalendar['holidays'] : [];
$slaPriorities = [
	'urgent' => $l->t('priority_urgent'),
	'high' => $l->t('priority_high'),
	'normal' => $l->t('priority_normal'),
	'low' => $l->t('priority_low'),
];
$slaTargetColumns = [
	'response' => $l->t('sla_first_response_hours'),
	'resolution' => $l->t('sla_resolution_hours'),
];
?>
<section class="tc-section" id="settings-sla-heading" aria-label="<?php p($l->t('SLA targets')); ?>">
	<div class="helpdesk-card">
		<div class="helpdesk-card__body">
			<form id="sla-settings-form" data-helpdesk-submit-feedback="1">
				<table class="helpdesk-table tc-sla-targets-table">
					<caption class="helpdesk-form-label"><?php p($l->t('sla_targets_caption')); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php p($l->t('priority')); ?></th>
							<?php foreach ($slaTargetColumns as $columnLabel): ?>
								<th scope="col"><?php p($columnLabel); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($slaPriorities as $priority => $priorityLabel): ?>
							<tr>
								<th scope="row"><?php p($priorityLabel); ?></th>
								<?php foreach ($slaTargetColumns as $column => $columnLabel): ?>
									<td>
										<input type="number" min="1" max="<?php p($slaMaxHours); ?>" step="1" required
											class="tc-sla-hours"
											id="sla-<?php p($priority); ?>-<?php p($column); ?>"
											name="targets[<?php p($priority); ?>][<?php p($column); ?>]"
											data-sla-priority="<?php p($priority); ?>"
											data-sla-target="<?php p($column); ?>"
											value="<?php p((string)(int)($slaTargets[$priority][$column] ?? 1)); ?>"
											aria-label="<?php p($priorityLabel . ' — ' . $columnLabel); ?>">
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="helpdesk-form-help tc-field__hint-block"><?php p($l->t('sla_targets_apply_note')); ?></p>

				<fieldset class="helpdesk-form-group tc-field tc-field--full-width">
					<legend class="helpdesk-form-label"><?php p($l->t('sla_business_hours')); ?></legend>

					<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
						<input type="checkbox"
							name="business_hours_enabled"
							id="sla-business-hours-enabled"
							<?php if (!empty($slaCalendar['enabled'])) {
								p('checked');
							} ?>>
						<label for="sla-business-hours-enabled"><?php p($l->t('sla_business_hours_enable')); ?></label>
						<span class="helpdesk-form-help tc-field__hint-block"><?php p($l->t('sla_business_hours_help')); ?></span>
					</div>

					<fieldset class="helpdesk-form-group tc-field tc-field--full-width" id="sla-working-days">
						<legend class="helpdesk-form-label"><?php p($l->t('sla_working_days')); ?></legend>
						<?php foreach (range(1, 7) as $day): ?>
							<span class="tc-field tc-field--checkbox">
								<input type="checkbox"
									name="business_days[]"
									id="sla-day-<?php p((string)$day); ?>"
									value="<?php p((string)$day); ?>"
									<?php if (in_array($day, $slaDays, true)) {
										p('checked');
									} ?>>
								<label for="sla-day-<?php p((string)$day); ?>"><?php p($l->t('sla_weekday_' . $day)); ?></label>
							</span>
						<?php endforeach; ?>
					</fieldset>

					<div class="tc-form-grid">
						<div class="helpdesk-form-group tc-field">
							<label for="sla-business-start" class="helpdesk-form-label"><?php p($l->t('sla_business_start')); ?></label>
							<input type="time" name="business_start" id="sla-business-start" step="60"
								value="<?php p((string)($slaCalendar['start'] ?? SlaPolicyService::DEFAULT_BUSINESS_START)); ?>">
						</div>
						<div class="helpdesk-form-group tc-field">
							<label for="sla-business-end" class="helpdesk-form-label"><?php p($l->t('sla_business_end')); ?></label>
							<input type="time" name="business_end" id="sla-business-end" step="60"
								value="<?php p((string)($slaCalendar['end'] ?? SlaPolicyService::DEFAULT_BUSINESS_END)); ?>">
						</div>
						<div class="helpdesk-form-group tc-field">
							<label for="sla-timezone" class="helpdesk-form-label"><?php p($l->t('sla_timezone')); ?></label>
							<input type="text" name="timezone" id="sla-timezone" autocomplete="off" spellcheck="false"
								aria-describedby="sla-timezone-help"
								value="<?php p((string)($slaCalendar['timezone'] ?? '')); ?>">
							<span class="helpdesk-form-help tc-field__hint-block" id="sla-timezone-help"><?php p($l->t('sla_timezone_help')); ?></span>
						</div>
						<div class="helpdesk-form-group tc-field tc-field--full-width">
							<label for="sla-holidays" class="helpdesk-form-label"><?php p($l->t('sla_holidays')); ?></label>
							<textarea name="holidays" id="sla-holidays" rows="5" spellcheck="false"
								aria-describedby="sla-holidays-help"><?php p(implode("\n", $slaHolidays)); ?></textarea>
							<span class="helpdesk-form-help tc-field__hint-block" id="sla-holidays-help"><?php p($l->t('sla_holidays_help')); ?></span>
						</div>
					</div>
				</fieldset>

				<div class="helpdesk-form-actions helpdesk-form-actions--compact">
					<button type="submit" class="helpdesk-btn helpdesk-btn--primary">
						<?php p($l->t('save_settings')); ?>
					</button>
				</div>
			</form>
		</div>
	</div>
</section>

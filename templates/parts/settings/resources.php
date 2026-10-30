<?php
/**
 * Settings sub-page: Ticket resources.
 *
 * Admin-managed catalog of rooms/devices reporters can attach to tickets.
 * Delete detaches the resource from all tickets in one transaction —
 * disable is the reversible soft-removal. Rename/delete use inline
 * row editors (same contract as ticket categories, no native dialogs).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\Ticketcheck\Service\IconCatalog;

$tcRes = is_array($_['ticketResources'] ?? null) ? $_['ticketResources'] : [];
?>
<section class="tc-section" id="settings-resources-heading" aria-labelledby="tc-resources-heading">
	<span id="ticket-resources" class="tc-anchor-target" aria-hidden="true"></span>
	<h2 id="tc-resources-heading" class="helpdesk-sr-only"><?php p($l->t('Ticket resources')); ?></h2>
	<div class="helpdesk-card">
		<div class="helpdesk-card__body">

			<div class="helpdesk-form-group tc-field tc-field--full-width">
				<label for="tc-new-resource-name" class="helpdesk-form-label"><?php p($l->t('add_resource')); ?></label>
				<div class="settings-kb-category-add-row">
					<input type="text" id="tc-new-resource-name" class="helpdesk-form-control"
						maxlength="80" placeholder="<?php p($l->t('resource_name')); ?>">
					<select id="tc-new-resource-type" class="helpdesk-form-control" aria-label="<?php p($l->t('resource_type')); ?>">
						<option value="other" selected><?php p($l->t('resource_type_other')); ?></option>
						<option value="room"><?php p($l->t('resource_type_room')); ?></option>
						<option value="device"><?php p($l->t('resource_type_device')); ?></option>
					</select>
					<button type="button" id="tc-add-resource-btn" class="helpdesk-btn helpdesk-btn--primary"><?php p($l->t('add_resource')); ?></button>
				</div>
				<span class="helpdesk-form-help"><?php p($l->t('settings_resources_lead')); ?></span>
				<span id="tc-resource-name-error" class="tc-field-error helpdesk-form-help" role="alert" hidden></span>
			</div>

			<?php if (empty($tcRes)): ?>
				<div class="helpdesk-empty">
					<p class="helpdesk-empty__text"><?php p($l->t('no_resources_yet')); ?></p>
				</div>
			<?php else: ?>
				<ul id="tc-resource-list" class="helpdesk-list settings-kb-category-list tc-resource-list">
					<?php foreach ($tcRes as $res): ?>
						<li data-key="<?php p($res['key']); ?>" class="settings-kb-category-item tc-resource-item<?php if (empty($res['enabled'])) p(' tc-resource-item--disabled'); ?>">
							<span class="settings-kb-category-item__name" data-role="name"><?php p($res['name']); ?></span>
							<span class="tc-badge tc-badge--muted tc-resource-type-badge" data-role="type" aria-hidden="true"><?php p($l->t('resource_type_' . ($res['type'] ?? 'other'))); ?></span>
							<?php if (!empty($res['ticketCount'])): ?>
								<span class="helpdesk-text-muted" data-role="count"><?php p($res['ticketCount']); ?></span>
							<?php endif; ?>
							<span class="settings-kb-category-item__actions">
								<button type="button" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm" data-action="rename" aria-label="<?php p($l->t('rename')); ?>">
									<?php print_unescaped(IconCatalog::render('edit')); ?>
								</button>
								<button type="button" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm" data-action="toggle">
									<?php p(!empty($res['enabled']) ? $l->t('disable') : $l->t('enable')); ?>
								</button>
								<button type="button" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm" data-action="move-up" aria-label="<?php p($l->t('move_up')); ?>">↑</button>
								<button type="button" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm" data-action="move-down" aria-label="<?php p($l->t('move_down')); ?>">↓</button>
								<button type="button" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm tc-resource-delete" data-action="delete" data-count="<?php p((string)(int)($res['ticketCount'] ?? 0)); ?>" aria-label="<?php p($l->t('delete')); ?>">
									<?php print_unescaped(IconCatalog::render('trash-2')); ?>
								</button>
							</span>
							<div class="tc-resource-item__rename" hidden>
								<input type="text" class="helpdesk-form-control" maxlength="80" value="<?php p($res['name']); ?>" aria-label="<?php p($l->t('resource_name')); ?>">
								<select class="helpdesk-form-control tc-resource-type-select" aria-label="<?php p($l->t('resource_type')); ?>">
									<?php foreach (['room', 'device', 'other'] as $rt): ?>
										<option value="<?php p($rt); ?>" <?php if (($res['type'] ?? 'other') === $rt) p('selected'); ?>><?php p($l->t('resource_type_' . $rt)); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="button" class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--sm" data-action="rename-save"><?php p($l->t('save')); ?></button>
								<button type="button" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm" data-action="rename-cancel"><?php p($l->t('cancel')); ?></button>
							</div>
							<div class="tc-resource-item__delete-confirm" hidden>
								<span class="tc-resource-delete-text">
									<?php p(str_replace('{count}', (string)(int)($res['ticketCount'] ?? 0), $l->t('resource_delete_confirm'))); ?>
								</span>
								<select class="helpdesk-form-control tc-resource-reassign" aria-label="<?php p($l->t('resource_reassign_to')); ?>">
									<option value=""><?php p($l->t('resource_detach_only')); ?></option>
									<?php foreach ($tcRes as $other):
										if ($other['key'] !== $res['key'] && !empty($other['enabled'])): ?>
										<option value="<?php p($other['key']); ?>"><?php p($l->t('resource_reassign_to') . ': ' . $other['name']); ?></option>
										<?php endif; endforeach; ?>
								</select>
								<button type="button" class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--sm" data-action="delete-confirm"><?php p($l->t('delete')); ?></button>
								<button type="button" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm" data-action="delete-cancel"><?php p($l->t('cancel')); ?></button>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p id="tc-resource-status" class="helpdesk-form-help" role="status" aria-live="polite"></p>
		</div>
	</div>
</section>
<?php \OCP\Util::addScript('ticketcheck', 'settings-resources'); ?>

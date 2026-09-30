<?php
/**
 * Settings sub-page: Knowledge base categories.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\Ticketcheck\Service\IconCatalog;

$kbSettings = $_['knowledgeBaseSettings'] ?? [];
$kbCategories = is_array($_['kbCategories'] ?? null) ? $_['kbCategories'] : [];
$kbSettingsUrl = (string) (($_['urls']['settingsSections'] ?? [])['knowledge-base'] ?? '');
?>
<section class="tc-section" id="kb-categories" aria-labelledby="settings-kb-categories-heading">
	<h2 id="settings-kb-categories-heading" class="helpdesk-sr-only"><?php p($l->t('Knowledge base categories')); ?></h2>
	<div class="helpdesk-card">
		<div class="helpdesk-card__body">
			<div id="kb-disabled-notice"
				class="helpdesk-empty helpdesk-empty--error helpdesk-mb-md"
				role="status"
				aria-live="polite"
				<?php if (($kbSettings['enabled'] ?? 'yes') === 'yes') {
					p('hidden');
				} ?>>
				<h3 class="helpdesk-empty__title helpdesk-empty__title--error"><?php p($l->t('knowledge_base_disabled_short')); ?></h3>
				<p class="helpdesk-empty__text">
					<?php p($l->t('kb_categories_disabled_help')); ?>
					<?php if ($kbSettingsUrl !== '' && $kbSettingsUrl !== '#'): ?>
						<a class="tc-inline-link" href="<?php p($kbSettingsUrl); ?>"><?php p($l->t('Knowledge base settings')); ?></a>
					<?php endif; ?>
				</p>
			</div>
			<div class="helpdesk-form-group tc-field tc-field--full-width">
				<label for="new-kb-category" class="helpdesk-form-label"><?php p($l->t('add_category')); ?></label>
				<div class="settings-kb-category-add-row">
					<input type="text" id="new-kb-category" class="helpdesk-form-control" placeholder="<?php p($l->t('category_example_general')); ?>">
					<button type="button" id="add-kb-category-btn" class="helpdesk-btn helpdesk-btn--primary"><?php p($l->t('add_button')); ?></button>
				</div>
			</div>

			<ul id="kb-category-list" class="helpdesk-list settings-kb-category-list">
				<?php foreach ($kbCategories as $cat): ?>
					<li data-id="<?php p($cat->getId()); ?>" class="settings-kb-category-item">
						<span class="settings-kb-category-item__handle"
							aria-hidden="true"
							title="<?php p($l->t('drag_to_reorder_tooltip')); ?>">
							<?php print_unescaped(IconCatalog::render('grip-vertical', 'settings-kb-category-item__handle-icon')); ?>
						</span>
						<label class="helpdesk-sr-only" for="kb-category-input-<?php p($cat->getId()); ?>"><?php p($l->t('category')); ?></label>
						<input type="text"
							id="kb-category-input-<?php p($cat->getId()); ?>"
							value="<?php p($cat->getName()); ?>"
							data-category-id="<?php p($cat->getId()); ?>"
							class="kb-category-name-input helpdesk-form-control settings-kb-category-name-input"
							aria-label="<?php p($l->t('category')); ?>">
						<?php if ($cat->getName() !== 'General'): ?>
							<button type="button" class="helpdesk-btn helpdesk-btn--danger helpdesk-btn--sm kb-category-delete-btn"
								data-category-id="<?php p($cat->getId()); ?>"><?php p($l->t('delete_button')); ?></button>
						<?php else: ?>
							<span class="helpdesk-text-muted" title="<?php p($l->t('default_category_cannot_be_deleted')); ?>"><?php p($l->t('default_label')); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<span class="helpdesk-form-help"><?php p($l->t('drag_categories_to_reorder')); ?></span>
		</div>
	</div>
</section>

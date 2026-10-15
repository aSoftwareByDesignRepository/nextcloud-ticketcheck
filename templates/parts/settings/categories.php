<?php
/**
 * Settings sub-page: Ticket categories.
 *
 * Manageable ticket taxonomy — rename per locale, add, reorder, disable,
 * and delete (with reassignment) categories. Builtin rows ship l10n names
 * and can be relabeled/reordered but never deleted: the immutable keys are
 * the API contract stored on tickets and in inbound routing rules.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\Ticketcheck\Service\IconCatalog;

$tcCats = is_array($_['ticketCategories'] ?? null) ? $_['ticketCategories'] : [];
$tcCatLocales = is_array($_['ticketCategoryLocales'] ?? null) ? $_['ticketCategoryLocales'] : ['en'];
$tcDefaultKey = (string)($_['ticketCategoryDefaultKey'] ?? 'general');
?>
<section class="tc-section" id="settings-categories-heading" aria-labelledby="tc-categories-heading">
	<span id="ticket-categories" class="tc-anchor-target" aria-hidden="true"></span>
	<h2 id="tc-categories-heading" class="helpdesk-sr-only"><?php p($l->t('Ticket categories')); ?></h2>
	<div class="helpdesk-card">
		<div class="helpdesk-card__body">

			<!-- Default category for new tickets -->
			<div class="helpdesk-form-group tc-field tc-field--full-width">
				<label for="tc-default-category" class="helpdesk-form-label"><?php p($l->t('default_category_label')); ?></label>
				<select id="tc-default-category" class="helpdesk-form-control tc-category-default-select">
					<?php foreach ($tcCats as $cat):
						if (empty($cat['enabled'])) { continue; }
						?>
						<option value="<?php p($cat['key']); ?>" <?php if ($cat['key'] === $tcDefaultKey) { p('selected'); } ?>>
							<?php p($cat['label']); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<span class="helpdesk-form-help"><?php p($l->t('default_category_help')); ?></span>
			</div>

			<!-- Add new category -->
			<div class="helpdesk-form-group tc-field tc-field--full-width">
				<label for="tc-new-category-label" class="helpdesk-form-label"><?php p($l->t('add_category')); ?></label>
				<div class="settings-kb-category-add-row">
					<input type="text" id="tc-new-category-label" class="helpdesk-form-control"
						data-field="labels"
						maxlength="60" placeholder="<?php p($l->t('category_example_general')); ?>">
					<button type="button" id="tc-add-category-btn" class="helpdesk-btn helpdesk-btn--primary"><?php p($l->t('add_button')); ?></button>
				</div>
				<span class="helpdesk-form-help"><?php p($l->t('category_add_help')); ?></span>
			</div>

			<!-- Category list -->
			<ul id="tc-category-list" class="helpdesk-list settings-kb-category-list tc-category-list">
				<?php foreach ($tcCats as $cat): ?>
					<li data-key="<?php p($cat['key']); ?>" class="settings-kb-category-item tc-category-item">
						<span class="settings-kb-category-item__handle"
							aria-hidden="true"
							title="<?php p($l->t('drag_to_reorder_tooltip')); ?>">
							<?php print_unescaped(IconCatalog::render('grip-vertical', 'settings-kb-category-item__handle-icon')); ?>
						</span>

						<span class="tc-category-item__move">
							<button type="button"
								class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm tc-category-move-btn tc-category-move-up"
								data-category-key="<?php p($cat['key']); ?>"
								data-direction="up"
								aria-label="<?php p($l->t('category_move_up') . ': ' . $cat['label']); ?>"
							><span aria-hidden="true">↑</span></button>
							<button type="button"
								class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm tc-category-move-btn tc-category-move-down"
								data-category-key="<?php p($cat['key']); ?>"
								data-direction="down"
								aria-label="<?php p($l->t('category_move_down') . ': ' . $cat['label']); ?>"
							><span aria-hidden="true">↓</span></button>
						</span>

						<div class="tc-category-item__main">
							<span class="tc-category-item__label"><?php p($cat['label']); ?></span>
							<code class="tc-category-item__key"><?php p($cat['key']); ?></code>
							<span class="tc-category-item__badges">
								<?php if (!empty($cat['builtin'])): ?>
									<span class="helpdesk-text-muted tc-category-badge"><?php p($l->t('builtin_label')); ?></span>
								<?php endif; ?>
								<?php if ($cat['key'] === $tcDefaultKey): ?>
									<span class="helpdesk-text-muted tc-category-badge"><?php p($l->t('default_label')); ?></span>
								<?php endif; ?>
								<?php if (empty($cat['enabled'])): ?>
									<span class="helpdesk-text-muted tc-category-badge tc-category-badge--disabled"><?php p($l->t('disabled_label')); ?></span>
								<?php endif; ?>
							</span>
							<span class="helpdesk-text-muted tc-category-item__count">
								<?php p($l->n('category_ticket_count_singular', 'category_ticket_count_plural', (int)($cat['ticketCount'] ?? 0))); ?>
							</span>
						</div>

						<button type="button"
							class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm tc-category-toggle-btn"
							data-category-key="<?php p($cat['key']); ?>"
							aria-expanded="false"
							aria-controls="tc-cat-editor-<?php p($cat['key']); ?>"
							aria-label="<?php p($l->t('edit_labels_button') . ': ' . $cat['label']); ?>">
							<?php p($l->t('edit_labels_button')); ?>
						</button>

						<?php if (empty($cat['builtin'])): ?>
							<button type="button"
								class="helpdesk-btn helpdesk-btn--danger helpdesk-btn--sm tc-category-delete-btn"
								data-category-key="<?php p($cat['key']); ?>"
								aria-label="<?php p($l->t('delete_button') . ': ' . $cat['label']); ?>">
								<?php p($l->t('delete_button')); ?>
							</button>
						<?php endif; ?>

						<div class="tc-category-item__editor" id="tc-cat-editor-<?php p($cat['key']); ?>" hidden>
							<div class="tc-category-locale-grid">
								<?php foreach ($tcCatLocales as $locale):
									$localeLabel = (string)($cat['labels'][$locale] ?? '');
									?>
									<div class="helpdesk-form-group tc-category-locale-field">
										<label class="helpdesk-form-label"
											for="tc-cat-label-<?php p($cat['key']); ?>-<?php p($locale); ?>">
											<?php p(strtoupper(str_replace('_', '-', $locale))); ?>
										</label>
										<input type="text"
											id="tc-cat-label-<?php p($cat['key']); ?>-<?php p($locale); ?>"
											class="helpdesk-form-control tc-category-locale-input"
											data-category-key="<?php p($cat['key']); ?>"
											data-locale="<?php p($locale); ?>"
											data-field="labels_<?php p($locale); ?>"
											maxlength="60"
											value="<?php p($localeLabel); ?>">
									</div>
								<?php endforeach; ?>
							</div>
							<div class="tc-category-editor-actions">
								<label class="helpdesk-form-checkbox tc-category-enabled-toggle" for="tc-cat-enabled-<?php p($cat['key']); ?>">
									<input type="checkbox"
										id="tc-cat-enabled-<?php p($cat['key']); ?>"
										class="tc-category-enabled-input"
										data-category-key="<?php p($cat['key']); ?>"
										<?php if (!empty($cat['enabled'])) { p('checked'); } ?>
										<?php // 'general' is the hard fallback everywhere — disabling it is
											  // always rejected server-side, so never offer the control.
											if ($cat['key'] === $tcDefaultKey || $cat['key'] === 'general') { p('disabled'); } ?>>
									<span class="helpdesk-form-checkbox__label"><?php p($l->t('category_enabled_label')); ?></span>
								</label>
								<button type="button"
									class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--sm tc-category-save-btn"
									data-category-key="<?php p($cat['key']); ?>">
									<?php p($l->t('save_button')); ?>
								</button>
								<span class="helpdesk-form-help"><?php p($l->t('category_labels_help')); ?></span>
							</div>
						</div>

						<div class="tc-category-item__delete-confirm" id="tc-cat-delete-<?php p($cat['key']); ?>" hidden>
							<p class="tc-category-delete-text"></p>
							<div class="tc-category-delete-actions">
								<label class="helpdesk-sr-only" for="tc-cat-reassign-<?php p($cat['key']); ?>"><?php p($l->t('category_reassign_to') . ': ' . $cat['label']); ?></label>
								<select id="tc-cat-reassign-<?php p($cat['key']); ?>"
									class="helpdesk-form-control tc-category-reassign-select"
									data-category-key="<?php p($cat['key']); ?>"
									data-field="reassign_to">
									<?php foreach ($tcCats as $target):
										// Reassign targets must be enabled — the
										// service rejects disabled ones, so do not
										// offer them (mirrors isEnabledKey check).
										if ($target['key'] === $cat['key'] || empty($target['enabled'])) { continue; }
										?>
										<option value="<?php p($target['key']); ?>" <?php if ($target['key'] === $tcDefaultKey) { p('selected'); } ?>>
											<?php p($target['label']); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<button type="button"
									class="helpdesk-btn helpdesk-btn--danger helpdesk-btn--sm tc-category-delete-confirm-btn"
									data-category-key="<?php p($cat['key']); ?>">
									<?php p($l->t('delete_button')); ?>
								</button>
								<button type="button"
									class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm tc-category-delete-cancel-btn"
									data-category-key="<?php p($cat['key']); ?>">
									<?php p($l->t('cancel_button')); ?>
								</button>
							</div>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
			<span class="helpdesk-form-help"><?php p($l->t('drag_categories_to_reorder')); ?></span>
		</div>
	</div>

	<!-- Import / export -->
	<div class="helpdesk-card helpdesk-mt-md">
		<div class="helpdesk-card__body">
			<h3 class="tc-section__title tc-section__title--sub"><?php p($l->t('category_transfer_title')); ?></h3>
			<div class="tc-category-transfer">
				<button type="button" id="tc-export-categories-btn" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm">
					<?php p($l->t('export_button')); ?>
				</button>
				<div class="helpdesk-form-group tc-field tc-field--full-width">
					<label for="tc-import-categories" class="helpdesk-form-label"><?php p($l->t('import_categories_label')); ?></label>
					<textarea id="tc-import-categories" class="helpdesk-form-control tc-category-import-input" rows="4"
						placeholder='{"version":1,"categories":[…]}'></textarea>
					<button type="button" id="tc-import-categories-btn" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm helpdesk-mt-sm">
						<?php p($l->t('import_button')); ?>
					</button>
					<span class="helpdesk-form-help"><?php p($l->t('category_import_help')); ?></span>
				</div>
			</div>
		</div>
	</div>
</section>

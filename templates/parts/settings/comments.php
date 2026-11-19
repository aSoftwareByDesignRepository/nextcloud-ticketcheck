<?php
/**
 * Settings sub-page: comment lifecycle (author self-edit window).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$editWindowMinutes = (int)($_['commentEditWindowMinutes'] ?? 1440);
?>
<section class="tc-section" id="settings-comments-heading" aria-label="<?php p($l->t('Comment management')); ?>">
	<div class="helpdesk-card">
		<div class="helpdesk-card__body">
			<form id="comments-settings-form" class="tc-form" data-helpdesk-submit-feedback="1">
				<div class="tc-form-grid">
					<div class="helpdesk-form-group tc-field tc-field--full-width">
						<label for="comment-edit-window-minutes"><?php p($l->t('settings_comments_window_label')); ?></label>
						<input type="number"
							name="edit_window_minutes"
							id="comment-edit-window-minutes"
							min="0"
							max="100000"
							step="1"
							inputmode="numeric"
							value="<?php p((string)$editWindowMinutes); ?>"
							required>
						<span class="helpdesk-form-help tc-field__hint-block"><?php p($l->t('settings_comments_window_help')); ?></span>
						<span class="tc-field-error" data-field-error="edit_window_minutes" hidden></span>
					</div>
				</div>

				<div class="helpdesk-form-actions helpdesk-form-actions--compact">
					<button type="submit" class="helpdesk-btn helpdesk-btn--primary">
						<?php p($l->t('save_settings')); ?>
					</button>
				</div>
			</form>
		</div>
	</div>
</section>

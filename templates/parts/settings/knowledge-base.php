<?php
/**
 * Settings sub-page: Knowledge base feature flags.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$kbSettings = $_['knowledgeBaseSettings'] ?? [];
?>
<section class="tc-section" id="settings-kb-heading" aria-label="<?php p($l->t('Knowledge base settings')); ?>">
	<div class="helpdesk-card">
		<div class="helpdesk-card__body">
			<form id="kb-settings-form" data-helpdesk-submit-feedback="1">
				<div class="tc-form-grid">
					<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
						<input type="checkbox"
							name="enabled"
							id="kb-enabled"
							<?php if (($kbSettings['enabled'] ?? 'yes') === 'yes') {
								p('checked');
							} ?>>
						<label for="kb-enabled"><?php p($l->t('enable_knowledge_base')); ?></label>
						<span class="helpdesk-form-help tc-field__hint-block"><?php p($l->t('enable_knowledge_base_help')); ?></span>
					</div>

					<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
						<input type="checkbox"
							name="portal_enabled"
							id="kb-portal-enabled"
							<?php if (($kbSettings['portal_enabled'] ?? 'yes') === 'yes') {
								p('checked');
							} ?>>
						<label for="kb-portal-enabled"><?php p($l->t('enable_knowledge_base_portal')); ?></label>
						<span class="helpdesk-form-help tc-field__hint-block"><?php p($l->t('enable_knowledge_base_portal_help')); ?></span>
					</div>

					<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
						<input type="checkbox"
							name="feedback_enabled"
							id="kb-feedback-enabled"
							<?php if (($kbSettings['feedback_enabled'] ?? 'yes') === 'yes') {
								p('checked');
							} ?>>
						<label for="kb-feedback-enabled"><?php p($l->t('enable_knowledge_base_feedback')); ?></label>
						<span class="helpdesk-form-help tc-field__hint-block"><?php p($l->t('enable_knowledge_base_feedback_help')); ?></span>
					</div>

					<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
						<input type="checkbox"
							name="comments_enabled"
							id="kb-comments-enabled"
							<?php if (($kbSettings['comments_enabled'] ?? 'yes') === 'yes') {
								p('checked');
							} ?>>
						<label for="kb-comments-enabled"><?php p($l->t('enable_knowledge_base_comments')); ?></label>
						<span class="helpdesk-form-help tc-field__hint-block"><?php p($l->t('enable_knowledge_base_comments_help')); ?></span>
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

<?php
/**
 * Settings sub-page: Email notifications.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$emailSettings = $_['emailSettings'] ?? [];
?>
<section class="tc-section" id="settings-email-heading" aria-label="<?php p($l->t('Email notifications')); ?>">
	<div class="helpdesk-card">
		<div class="helpdesk-card__body">
			<form id="email-settings-form" data-helpdesk-submit-feedback="1">
				<div class="tc-form-grid">
					<div class="helpdesk-form-group tc-field tc-field--full-width">
						<label for="email-from-name" class="helpdesk-form-label">
							<?php p($l->t('from_name_label')); ?>
						</label>
						<input type="text"
							id="email-from-name"
							name="from_name"
							value="<?php p($emailSettings['from_name'] ?? ''); ?>"
							placeholder="<?php p($l->t('your_company_support_placeholder')); ?>"
							class="helpdesk-form-control"
							autocomplete="organization">
						<span class="helpdesk-form-help"><?php p($l->t('from_name_help')); ?></span>
					</div>

					<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
						<input type="checkbox"
							name="enabled"
							id="email-enabled"
							<?php if (($emailSettings['enabled'] ?? '') === 'yes') {
								p('checked');
							} ?>>
						<label for="email-enabled"><?php p($l->t('enable_email_notifications')); ?></label>
					</div>

					<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
						<input type="checkbox"
							name="daily_digest_enabled"
							id="daily-digest-enabled"
							<?php if (($emailSettings['daily_digest_enabled'] ?? '') === 'yes') {
								p('checked');
							} ?>>
						<label for="daily-digest-enabled"><?php p($l->t('enable_daily_digest_emails')); ?></label>
						<span class="helpdesk-form-help tc-field__hint-block"><?php p($l->t('enable_daily_digest_emails_help')); ?></span>
					</div>

					<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
						<input type="checkbox"
							name="weekly_digest_enabled"
							id="weekly-digest-enabled"
							<?php if (($emailSettings['weekly_digest_enabled'] ?? 'yes') === 'yes') {
								p('checked');
							} ?>>
						<label for="weekly-digest-enabled"><?php p($l->t('enable_weekly_digest_emails')); ?></label>
						<span class="helpdesk-form-help tc-field__hint-block"><?php p($l->t('enable_weekly_digest_emails_help')); ?></span>
					</div>

					<div class="helpdesk-form-group tc-field tc-field--full-width">
						<label for="inbound-email-address" class="helpdesk-form-label">
							<?php p($l->t('inbound_email_address_label')); ?>
						</label>
						<input type="email"
							id="inbound-email-address"
							name="inbound_email_address"
							value="<?php p($emailSettings['inbound_email_address'] ?? ''); ?>"
							placeholder="<?php p($l->t('support_email_placeholder')); ?>"
							autocomplete="email"
							inputmode="email"
							class="helpdesk-form-control">
						<span class="helpdesk-form-help"><?php p($l->t('inbound_email_address_help')); ?></span>
					</div>

					<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
						<input type="checkbox"
							name="inbound_email_enabled"
							id="inbound-email-enabled"
							<?php if (($emailSettings['inbound_email_enabled'] ?? 'no') === 'yes') {
								p('checked');
							} ?>>
						<label for="inbound-email-enabled"><?php p($l->t('inbound_email_enabled_label')); ?></label>
						<span class="helpdesk-form-help tc-field__hint-block"><?php p($l->t('inbound_email_enabled_help')); ?></span>
					</div>

					<div class="helpdesk-alert helpdesk-alert--info tc-field tc-field--full-width" role="note">
						<p class="helpdesk-alert__text"><?php p($l->t('inbound_email_webhook_security_help')); ?></p>
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

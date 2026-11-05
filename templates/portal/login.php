<?php
/** @var \OCP\IL10N $l */
/** Portal login — one e-mail field, one button (spec §8). */
$sent = (bool)($_['sent'] ?? false);
$ncEra = (bool)($_['ncLoginEra'] ?? false);
?>
<div class="tc-portal-auth" data-page="portal-login">
	<h1><?php p($l->t('portal_sign_in')); ?></h1>
	<p class="tc-page-lead"><?php p($l->t('portal_login_lead')); ?></p>

	<?php if ($ncEra): ?>
		<p class="tc-notice" role="note"><?php p($l->t('portal_login_nc_era_hint')); ?></p>
	<?php endif; ?>

	<div id="tc-login-request-block" <?php if ($sent) { p('hidden'); } ?>>
		<form method="post" action="#" data-tc-portal-send>
			<label for="tc-login-email" class="tc-label"><?php p($l->t('email')); ?></label>
			<input type="email" id="tc-login-email" name="email" required autocomplete="email"
				inputmode="email" class="tc-input" aria-describedby="tc-login-email-hint">
			<p id="tc-login-email-hint" class="tc-hint"><?php p($l->t('portal_login_email_hint')); ?></p>
			<button type="submit" class="primary tc-btn" data-tc-submit><?php p($l->t('portal_send_code')); ?></button>
		</form>
	</div>

	<div id="tc-login-code-block" aria-live="polite" <?php if (!$sent) { p('hidden'); } ?>>
		<p class="tc-notice tc-notice--success"><?php p($l->t('portal_check_mail')); ?></p>
		<form method="post" action="#" data-tc-portal-verify>
			<label for="tc-login-code" class="tc-label"><?php p($l->t('portal_code_label')); ?></label>
			<input type="text" id="tc-login-code" name="code" required inputmode="numeric"
				pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" class="tc-input">
			<button type="submit" class="primary tc-btn"><?php p($l->t('portal_sign_in')); ?></button>
		</form>
		<p class="tc-hint"><?php p($l->t('portal_open_mail_hint')); ?></p>
	</div>
	<div id="tc-login-error" class="tc-field-error" role="alert" hidden></div>
</div>

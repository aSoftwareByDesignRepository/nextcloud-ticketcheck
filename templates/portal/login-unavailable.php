<?php
/** @var \OCP\IL10N $l */
$reason = (string)($_['reason'] ?? '');
?>
<div class="tc-portal-auth" data-page="portal-login">
	<h1><?php p($l->t('portal_sign_in')); ?></h1>
	<p class="tc-page-lead" role="alert"><?php p($reason !== '' ? $reason : $l->t('portal_login_unavailable')); ?></p>
	<p class="tc-hint"><?php p($l->t('portal_contact_admin')); ?></p>
</div>

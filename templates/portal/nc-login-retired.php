<?php
/** @var \OCP\IL10N $l */
$masked = (string)($_['maskedEmail'] ?? '');
$loginUrl = (string)($_['loginUrl'] ?? '#');
?>
<div class="tc-portal-auth" data-page="portal-login">
	<h1><?php p($l->t('portal_new_signin_title')); ?></h1>
	<p class="tc-page-lead"><?php p($l->t('portal_new_signin_lead')); ?></p>
	<?php if ($masked !== ''): ?>
		<p><?php p($l->t('portal_new_signin_masked', [$masked])); ?></p>
	<?php endif; ?>
	<p><a class="primary tc-btn" href="<?php p($loginUrl); ?>"><?php p($l->t('portal_go_to_signin')); ?></a></p>
	<p class="tc-hint"><?php p($l->t('portal_contact_admin')); ?></p>
</div>

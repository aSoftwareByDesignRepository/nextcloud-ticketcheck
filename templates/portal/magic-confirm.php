<?php
/** @var \OCP\IL10N $l */
$magicUrl = (string)($_['magicUrl'] ?? '');
?>
<div class="tc-portal-auth" data-page="portal-magic">
	<h1><?php p($l->t('portal_sign_in')); ?></h1>
	<p class="tc-page-lead"><?php p($l->t('portal_magic_confirm_lead')); ?></p>
	<form method="post" action="<?php p($magicUrl); ?>">
		<button type="submit" class="primary tc-btn"><?php p($l->t('portal_sign_in')); ?></button>
	</form>
</div>

<?php

/**
 * Member availability hint — rendered inside the roles card on the project
 * detail page whenever the "add team member" form is NOT shown.
 *
 * Two audiences:
 *  - Managers (canManage): explains why the picker is empty (only users in
 *    the helpdesk_agents/helpdesk_admins groups are eligible; existing
 *    members and guests are excluded) and links NC admins to user
 *    management so the dead end becomes actionable.
 *  - Non-managers: a quiet hint that project admins maintain the roster,
 *    so the missing button is understood instead of reported as a bug.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/** @var \OCP\IL10N $l */
$l = $_['l'];

$canManage = !empty($_['canManage']);
$isNcAdmin = !empty($_['isNcAdmin']);

// Only NC admins can actually fix group membership — a link to /settings/users
// would be a dead end for anyone else, so it stays admin-only.
$userManagementUrl = '';
if ($isNcAdmin && isset($_['urlGenerator']) && $_['urlGenerator'] instanceof \OCP\IURLGenerator) {
	$userManagementUrl = $_['urlGenerator']->linkTo('settings', 'users');
}
?>
<?php if ($canManage): ?>
	<div class="helpdesk-alert helpdesk-alert--info helpdesk-mt-sm" role="status">
		<div class="helpdesk-alert__content">
			<p class="helpdesk-alert__title"><?php p($l->t('no_users_available_to_add')); ?></p>
			<p class="helpdesk-alert__text">
				<?php p($l->t('team_members_must_be_helpdesk_staff')); ?>
				<?php if ($userManagementUrl !== ''): ?>
					<a href="<?php p($userManagementUrl); ?>"><?php p($l->t('open_user_management')); ?></a>
				<?php endif; ?>
			</p>
		</div>
	</div>
<?php else: ?>
	<p class="helpdesk-text-muted helpdesk-mt-sm tc-project-detail-member-hint"><?php p($l->t('team_members_managed_by_admins')); ?></p>
<?php endif; ?>

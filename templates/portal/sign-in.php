<?php
/** @var \OCP\IL10N $l */
$sessions = is_array($_['sessions'] ?? null) ? $_['sessions'] : [];
?>
<?php include __DIR__ . '/../common/page-start.php'; ?>
<div class="tc-portal-signin" data-page="portal-signin">
	<section aria-labelledby="tc-sessions-h">
		<h2 id="tc-sessions-h"><?php p($l->t('portal_sessions_title')); ?></h2>
		<ul class="tc-session-list" data-tc-session-list>
		<?php foreach ($sessions as $s): ?>
			<li class="tc-session" data-session-id="<?php p((string)$s['id']); ?>">
				<span class="tc-session__device"><?php p((string)$s['device']); ?></span>
				<?php if (!empty($s['current'])): ?>
					<span class="tc-badge"><?php p($l->t('portal_session_current')); ?></span>
				<?php endif; ?>
				<span class="tc-session__meta"><?php p($l->t('portal_session_seen', [(string)$s['last_seen']])); ?></span>
				<button type="button" class="tc-btn" data-tc-revoke="<?php p((string)$s['id']); ?>"
					<?php if (!empty($s['current'])) { p('disabled'); } ?>><?php p($l->t('portal_revoke')); ?></button>
			</li>
		<?php endforeach; ?>
		</ul>
		<button type="button" class="tc-btn" data-tc-revoke-all><?php p($l->t('portal_signout_everywhere')); ?></button>
	</section>

	<section aria-labelledby="tc-resend-h">
		<h2 id="tc-resend-h"><?php p($l->t('portal_resend_title')); ?></h2>
		<p><?php p($l->t('portal_resend_lead')); ?></p>
		<button type="button" class="tc-btn" data-tc-resend><?php p($l->t('portal_resend_button')); ?></button>
		<div role="status" aria-live="polite" id="tc-resend-status"></div>
	</section>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

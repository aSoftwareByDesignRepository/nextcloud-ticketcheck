<?php
/** @var \OCP\IL10N $l */
$buckets = is_array($_['buckets'] ?? null) ? $_['buckets'] : [];
$lists = is_array($_['lists'] ?? null) ? $_['lists'] : [];
$ncAllowed = (bool)($_['ncLoginAllowed'] ?? true);
$identProv = (bool)($_['identityProvisioning'] ?? false);
$s5Armed = (bool)($_['s5Armed'] ?? false);
$outbox = (int)($_['outboxDepth'] ?? 0);
$miglog = is_array($_['miglogRecent'] ?? null) ? $_['miglogRecent'] : [];
$identities = is_array($_['identities'] ?? null) ? $_['identities'] : [];

$bucketMeta = [
	'migratable'              => ['tone' => 'ok',    'actions' => []],
	'blocked_no_email'        => ['tone' => 'warn',  'actions' => ['exempt', 'abandon']],
	'blocked_duplicate_email' => ['tone' => 'warn',  'actions' => ['merge', 'abandon']],
	'skipped_staff'           => ['tone' => 'muted', 'actions' => []],
	'no_access'               => ['tone' => 'muted', 'actions' => ['abandon']],
	'already_migrated'        => ['tone' => 'ok',    'actions' => ['rollback']],
	'nc_fallback'             => ['tone' => 'warn',  'actions' => ['unexempt']],
	'error'                   => ['tone' => 'error', 'actions' => ['rollback']],
];
?>
<?php include __DIR__ . '/../common/page-start.php'; ?>
<div class="tc-migration" data-page="guests-migration">
	<section class="tc-card tc-stage" aria-label="<?php p($l->t('migration_stage_status')); ?>">
		<ul class="tc-stage__list">
			<li><strong><?php p($l->t('nc_login_door')); ?>:</strong>
				<span class="tc-badge tc-badge--<?php p($ncAllowed ? 'warn' : 'ok'); ?>">
					<?php p($ncAllowed ? $l->t('door_open') : $l->t('door_closed')); ?>
				</span></li>
			<li><strong><?php p($l->t('provisioning_mode')); ?>:</strong>
				<span class="tc-badge"><?php p($identProv ? $l->t('identity_mode') : $l->t('nc_account_mode')); ?></span></li>
			<li><strong><?php p($l->t('s5_gate')); ?>:</strong>
				<span class="tc-badge tc-badge--<?php p($s5Armed ? 'warn' : 'ok'); ?>">
					<?php p($s5Armed ? $l->t('gate_armed') : $l->t('gate_clear')); ?>
				</span></li>
			<li><strong><?php p($l->t('outbox_depth')); ?>:</strong> <code><?php p((string)$outbox); ?></code></li>
		</ul>
	</section>

	<div class="tc-bucket-grid" data-tc-buckets>
		<?php foreach ($buckets as $bucket => $count):
			$meta = $bucketMeta[$bucket] ?? ['tone' => 'muted', 'actions' => []];
			$uids = $lists[$bucket] ?? [];
		?>
			<div class="tc-card tc-bucket tc-bucket--<?php p($meta['tone']); ?>" data-bucket="<?php p($bucket); ?>">
				<h2><?php p($l->t('migration_bucket_' . $bucket)); ?></h2>
				<p class="tc-bucket__count"><?php p((string)$count); ?></p>
				<p class="tc-bucket__hint"><?php p($l->t('migration_hint_' . $bucket)); ?></p>
				<?php if (!empty($uids)): ?>
					<details class="tc-bucket__list">
						<summary><?php p($l->t('show_list') . ' (' . count($uids) . ')'); ?></summary>
						<ul>
						<?php foreach ($uids as $uid): ?>
							<li class="tc-uid-row" data-uid="<?php p($uid); ?>">
								<code class="tc-uid"><?php p($uid); ?></code>
								<?php if (in_array('exempt', $meta['actions'], true)): ?>
									<button type="button" class="tc-btn tc-btn--small" data-tc-exempt="<?php p($uid); ?>"><?php p($l->t('mark_nc_fallback')); ?></button>
								<?php endif; ?>
								<?php if (in_array('unexempt', $meta['actions'], true)): ?>
									<button type="button" class="tc-btn tc-btn--small" data-tc-unexempt="<?php p($uid); ?>"><?php p($l->t('remove_fallback')); ?></button>
								<?php endif; ?>
								<?php if (in_array('abandon', $meta['actions'], true)): ?>
									<button type="button" class="tc-btn tc-btn--small tc-btn--danger" data-tc-abandon="<?php p($uid); ?>"><?php p($l->t('abandon_uid')); ?></button>
								<?php endif; ?>
								<?php if (in_array('rollback', $meta['actions'], true)): ?>
									<button type="button" class="tc-btn tc-btn--small" data-tc-rollback="<?php p($uid); ?>"><?php p($l->t('rollback_uid')); ?></button>
								<?php endif; ?>
								<?php if (in_array('merge', $meta['actions'], true) && !empty($identities)): ?>
									<span class="tc-merge">
										<select data-tc-merge-target aria-label="<?php p($l->t('merge_into')); ?>">
											<?php foreach ($identities as $ident): ?>
												<option value="<?php p((string)$ident['id']); ?>"><?php p($ident['email']); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="button" class="tc-btn tc-btn--small" data-tc-merge="<?php p($uid); ?>"><?php p($l->t('merge_into')); ?></button>
									</span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>

	<section class="tc-card tc-actions">
		<h2><?php p($l->t('migration_actions')); ?></h2>
		<div class="tc-actions__row">
			<button type="button" class="tc-btn" data-tc-refresh><?php p($l->t('refresh_status')); ?></button>
			<button type="button" class="tc-btn" data-tc-dryrun><?php p($l->t('migration_dry_run')); ?></button>
			<button type="button" class="tc-btn" data-tc-run><?php p($l->t('migration_run')); ?></button>
			<button type="button" class="tc-btn" data-tc-disable><?php p($l->t('migration_disable_nc')); ?></button>
		</div>
		<div class="tc-actions__door">
			<label class="tc-checkbox"><input type="checkbox" id="tc-ack"> <?php p($l->t('migration_ack')); ?></label>
			<button type="button" class="tc-btn tc-btn--danger" data-tc-door
				data-current="<?php p($ncAllowed ? 'allowed' : 'refused'); ?>">
				<?php p($ncAllowed ? $l->t('migration_close_door') : $l->t('migration_open_door')); ?>
			</button>
		</div>
	</section>

	<?php if (!empty($miglog)): ?>
	<section class="tc-card tc-miglog" aria-labelledby="miglog-h">
		<h2 id="miglog-h"><?php p($l->t('recent_activity')); ?></h2>
		<div class="tc-table-wrap">
			<table class="tc-table">
				<thead><tr>
					<th><?php p($l->t('time')); ?></th><th><?php p($l->t('guest_uid')); ?></th>
					<th><?php p($l->t('action')); ?></th><th><?php p($l->t('actor')); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ($miglog as $row): ?>
					<tr>
						<td data-cell="<?php p($l->t('time')); ?>"><time><?php p((string)($row['created_at'] ?? '')); ?></time></td>
						<td data-cell="<?php p($l->t('guest_uid')); ?>"><code><?php p((string)($row['uid'] ?? '—')); ?></code></td>
						<td data-cell="<?php p($l->t('action')); ?>"><?php p((string)($row['action'] ?? '')); ?></td>
						<td data-cell="<?php p($l->t('actor')); ?>"><code><?php p((string)($row['actor'] ?? '')); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>
	<?php endif; ?>

	<div id="tc-mig-status" class="tc-mig-status" role="status" aria-live="polite"></div>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

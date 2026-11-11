<?php
/**
 * Settings sub-page: Transfer configuration (test → production).
 * Two cards — Export (passphrase → .nctransfer download) and Import
 * (mode → file → passphrase → mandatory dry-run → confirm) — plus the
 * "Last transfer" receipt line. The report dialog is a native <dialog>.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$isSystemAdmin = !empty($_['isSystemAdmin']);
$lastReceipt = is_array($_['transferLastReceipt'] ?? null) ? $_['transferLastReceipt'] : null;
?>
<section class="tc-card tc-section" aria-labelledby="tc-xfer-export-h" id="tc-transfer-export-card">
	<header class="tc-section__header">
		<div>
			<h2 id="tc-xfer-export-h"><?php p($l->t('Export configuration')); ?></h2>
			<p class="tc-section__sub"><?php p($l->t('Download all settings, catalogues and business data as one encrypted file — then import it on your production instance.')); ?></p>
		</div>
	</header>
	<div class="tc-callout tc-callout--info" role="note">
		<p><strong><?php p($l->t('Secrets are never included.')); ?></strong>
		<?php p($l->t('Passwords, tokens and salts stay on this instance — the file is encrypted and still does not carry them. It does contain user and group names: handle it like personal data.')); ?></p>
	</div>
	<form id="tc-transfer-export-form" class="tc-form" novalidate>
		<div class="tc-form-row">
			<label for="tc-transfer-passphrase"><?php p($l->t('Encryption passphrase')); ?></label>
			<div class="tc-input-group">
				<input type="password" id="tc-transfer-passphrase" name="passphrase" autocomplete="new-password" required minlength="12" aria-describedby="tc-transfer-passphrase-hint tc-transfer-passphrase-strength">
				<button type="button" class="tc-btn tc-btn--ghost" id="tc-transfer-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
				<button type="button" class="tc-btn tc-btn--secondary" id="tc-transfer-passphrase-generate"><?php p($l->t('Generate')); ?></button>
			</div>
			<p class="tc-form-row__hint" id="tc-transfer-passphrase-hint"><?php p($l->t('At least 12 characters. Without this passphrase the file cannot be restored — store it safely.')); ?></p>
			<p class="tc-form-row__hint tc-transfer-strength" id="tc-transfer-passphrase-strength" role="status" aria-live="polite"></p>
		</div>
		<div class="tc-actions">
			<button type="submit" class="tc-btn tc-btn--primary" id="tc-transfer-export-btn">
				<?php p($l->t('Download encrypted transfer file')); ?>
			</button>
		</div>
	</form>
</section>

<section class="tc-card tc-section" aria-labelledby="tc-xfer-import-h" id="tc-transfer-import-card">
	<header class="tc-section__header">
		<div>
			<h2 id="tc-xfer-import-h"><?php p($l->t('Import configuration')); ?></h2>
			<p class="tc-section__sub"><?php p($l->t('Pick a transfer file, enter its passphrase, review the preview — then confirm. Nothing is written before you confirm.')); ?></p>
		</div>
	</header>
	<?php if ($lastReceipt !== null): ?>
	<p class="tc-transfer-last" id="tc-transfer-last">
		<?php p($l->t('Last transfer')); ?>:
		<strong><?php p((string)($lastReceipt['at'] ?? '—')); ?></strong>
		· <?php p((string)($lastReceipt['mode'] ?? 'merge')); ?>
		· <?php p(($lastReceipt['result'] ?? '') === 'applied' ? $l->t('applied') : $l->t('failed')); ?>
		<?php if (($lastReceipt['snapshotId'] ?? null) !== null): ?>
			· <?php p($l->t('undo via snapshot')); ?> <code><?php p((string)$lastReceipt['snapshotId']); ?></code>
		<?php endif; ?>
	</p>
	<?php endif; ?>
	<form id="tc-transfer-import-form" class="tc-form" novalidate>
		<fieldset class="tc-form-row tc-fieldset">
			<legend><?php p($l->t('Mode')); ?></legend>
			<label class="tc-radio">
				<input type="radio" name="mode" value="merge" checked>
				<span><strong><?php p($l->t('Merge')); ?></strong> — <?php p($l->t('add and update; nothing is deleted')); ?></span>
			</label>
			<label class="tc-radio tc-radio--danger">
				<input type="radio" name="mode" value="replace" <?php if (!$isSystemAdmin): ?>disabled<?php endif; ?>>
				<span><strong><?php p($l->t('Replace')); ?></strong> — <?php p($l->t('make identical to the file; prod-only rows are deleted')); ?></span>
			</label>
			<p class="tc-form-row__hint" id="tc-transfer-replace-hint">
				<?php if ($isSystemAdmin): ?>
					<?php p($l->t('Replace needs maintenance mode ON (occ maintenance:mode --on) — you will type REPLACE in the preview to confirm.')); ?>
				<?php else: ?>
					<?php p($l->t('Replace is available to Nextcloud system administrators only.')); ?>
				<?php endif; ?>
			</p>
		</fieldset>
		<div class="tc-form-row">
			<label for="tc-transfer-file"><?php p($l->t('Transfer file')); ?></label>
			<input type="file" id="tc-transfer-file" name="file" accept=".nctransfer,application/json" class="tc-file-input">
		</div>
		<div class="tc-form-row">
			<label for="tc-transfer-import-passphrase"><?php p($l->t('File passphrase')); ?></label>
			<div class="tc-input-group">
				<input type="password" id="tc-transfer-import-passphrase" name="import_passphrase" autocomplete="off">
				<button type="button" class="tc-btn tc-btn--ghost" id="tc-transfer-import-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
			</div>
		</div>
		<div id="tc-transfer-import-status" class="tc-form-row__hint" role="status" aria-live="polite"></div>
	</form>
</section>

<dialog id="tc-transfer-report" class="tc-dialog tc-transfer-report" aria-labelledby="tc-transfer-report-h">
	<div class="tc-dialog__body">
		<h3 id="tc-transfer-report-h"><?php p($l->t('Import preview')); ?></h3>
		<p id="tc-transfer-report-verdict" class="tc-transfer-report__verdict"></p>
		<p id="tc-transfer-report-file" class="tc-form-row__hint"></p>
		<div id="tc-transfer-report-groups"></div>
	</div>
	<footer class="tc-dialog__footer">
		<div id="tc-transfer-replace-confirm" class="tc-form-row" hidden>
			<label for="tc-transfer-replace-token"><?php p($l->t('Type REPLACE to confirm')); ?></label>
			<input type="text" id="tc-transfer-replace-token" autocomplete="off" spellcheck="false" placeholder="REPLACE">
		</div>
		<div class="tc-actions">
			<button type="button" class="tc-btn tc-btn--secondary" id="tc-transfer-cancel" value="cancel"><?php p($l->t('Cancel')); ?></button>
			<button type="button" class="tc-btn tc-btn--primary" id="tc-transfer-confirm"><?php p($l->t('Apply import')); ?></button>
		</div>
	</footer>
</dialog>

<dialog id="tc-transfer-progress" class="tc-dialog" aria-labelledby="tc-transfer-progress-h" aria-live="polite">
	<div class="tc-dialog__body">
		<h3 id="tc-transfer-progress-h"><?php p($l->t('Applying import')); ?></h3>
		<progress id="tc-transfer-progress-bar" max="100" value="0"></progress>
		<p id="tc-transfer-progress-text"><?php p($l->t('Starting…')); ?></p>
		<p class="tc-form-row__hint"><?php p($l->t('You can close this — the result is saved and shown under “Last transfer”.')); ?></p>
	</div>
</dialog>

<div id="tc-transfer-result-live" class="tc-visually-hidden" role="status" aria-live="polite"></div>

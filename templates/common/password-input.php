<?php

/**
 * Accessible password field with optional visibility toggle.
 *
 * @var string $id
 * @var string $name
 * @var string $label
 * @var string $autocomplete
 * @var bool $required
 * @var \OCP\IL10N $l
 * @var string|null $describedby Extra ids for aria-describedby (space-separated).
 * @var int|null $minlength
 */

use OCA\Ticketcheck\Service\IconCatalog;

$describedby = trim((string)($describedby ?? ''));
$toggleId = $id . '-toggle';
$describedbyParts = array_filter([$describedby, $toggleId . '-hint']);
$describedbyAttr = implode(' ', $describedbyParts);
?>
<div class="tc-password-field">
	<label for="<?php p($id); ?>" class="helpdesk-form-label<?php echo !empty($required) ? ' helpdesk-form-label--required' : ''; ?>">
		<?php p($label); ?>
	</label>
	<div class="tc-password-field__control">
		<input type="password"
			id="<?php p($id); ?>"
			name="<?php p($name); ?>"
			class="helpdesk-form-control tc-password-field__input"
			autocomplete="<?php p($autocomplete); ?>"
			<?php if (!empty($required)) { ?>required<?php } ?>
			<?php if (isset($minlength)) { ?>minlength="<?php p((string)$minlength); ?>"<?php } ?>
			<?php if ($describedbyAttr !== '') { ?>aria-describedby="<?php p($describedbyAttr); ?>"<?php } ?>>
		<button type="button"
			class="tc-password-field__toggle helpdesk-btn helpdesk-btn--ghost"
			id="<?php p($toggleId); ?>"
			data-tc-password-toggle="<?php p($id); ?>"
			aria-pressed="false"
			aria-label="<?php p($l->t('show_password')); ?>"
			aria-describedby="<?php p($toggleId); ?>-hint">
			<span class="tc-password-field__toggle-show" aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('eye', 'tc-icon tc-password-field__toggle-icon')); ?>
			</span>
			<span class="tc-password-field__toggle-hide" hidden aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('eye-off', 'tc-icon tc-password-field__toggle-icon')); ?>
			</span>
		</button>
	</div>
	<span id="<?php p($toggleId); ?>-hint" class="helpdesk-sr-only"><?php p($l->t('password_visibility_toggle_hint')); ?></span>
</div>

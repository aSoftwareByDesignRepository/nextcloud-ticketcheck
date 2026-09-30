<?php

/**
 * Guest portal email preference toggles (portal layout only).
 *
 * Expects: $l (IL10N), $pref (callable(string, string): string)
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\EmailPreferencesService;

/** @var \OCP\IL10N $l */
$pref = $pref ?? static fn (string $key, string $default = 'yes'): string => $default;

$guestPrefs = [
	[
		'key' => EmailPreferencesService::PREF_GUEST_TICKET_CREATED,
		'label' => 'email_pref_guest_ticket_created',
		'help' => 'email_pref_guest_ticket_created_help',
	],
	[
		'key' => EmailPreferencesService::PREF_GUEST_TICKET_UPDATED,
		'label' => 'email_pref_guest_ticket_updated',
		'help' => 'email_pref_guest_ticket_updated_help',
	],
	[
		'key' => EmailPreferencesService::PREF_GUEST_TICKET_STATUS_CHANGED,
		'label' => 'email_pref_guest_ticket_status_changed',
		'help' => 'email_pref_guest_ticket_status_changed_help',
	],
	[
		'key' => EmailPreferencesService::PREF_GUEST_TICKET_COMMENT,
		'label' => 'email_pref_guest_ticket_comment',
		'help' => 'email_pref_guest_ticket_comment_help',
	],
];
?>
<ul class="portal-email-prefs__list" role="list">
	<?php foreach ($guestPrefs as $item): ?>
		<?php
		$inputId = 'pref-' . $item['key'];
		$helpId = $inputId . '-help';
		$checked = $pref($item['key']) === 'yes';
		?>
		<li class="portal-email-prefs__item">
			<label class="portal-email-prefs__row helpdesk-form-checkbox" for="<?php p($inputId); ?>">
				<input type="checkbox"
					class="portal-email-prefs__input"
					id="<?php p($inputId); ?>"
					name="<?php p($item['key']); ?>"
					<?php if ($checked) {
						print_unescaped(' checked');
					} ?>
					aria-describedby="<?php p($helpId); ?>">
				<span class="portal-email-prefs__label"><?php p($l->t($item['label'])); ?></span>
			</label>
			<p id="<?php p($helpId); ?>" class="portal-email-prefs__hint"><?php p($l->t($item['help'])); ?></p>
		</li>
	<?php endforeach; ?>
</ul>

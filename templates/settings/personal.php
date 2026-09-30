<?php

/**
 * Personal settings — email preferences (User → Settings → TicketCheck)
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/** @var \OCP\IL10N $l */
$l = $_['l'];

$prefs = $_['preferences'] ?? [];
$isGuest = !empty($_['isGuest']);
$pref = static fn (string $key, string $default = 'yes') => $prefs[$key] ?? $default;
$saveUrl = (string)($_['saveUrl'] ?? '');
?>
<div id="helpdesk-email-preferences" class="section tc-personal-email-prefs">
    <h2 class="tc-personal-email-prefs__title"><?php p($l->t('email_preferences_title')); ?></h2>
    <p class="settings-hint tc-personal-email-prefs__intro"><?php p($l->t('email_preferences_intro')); ?></p>

    <form id="helpdesk-email-preferences-form"
        class="tc-form-grid tc-personal-email-prefs__form tc-email-prefs"
        data-helpdesk-submit-feedback="1"
        data-save-url="<?php p($saveUrl); ?>"
        novalidate>
        <fieldset class="tc-personal-email-prefs__fieldset">
            <legend class="tc-sr-only"><?php p($l->t('email_preferences_title')); ?></legend>

            <?php include __DIR__ . '/../common/email-preferences-fields.php'; ?>

            <div class="tc-form-actions tc-personal-email-prefs__actions">
                <button type="submit" class="primary"><?php p($l->t('save_settings')); ?></button>
                <span class="helpdesk-save-status tc-personal-email-prefs__status"
                    id="helpdesk-save-status"
                    role="status"
                    aria-live="polite"
                    aria-atomic="true"></span>
            </div>
        </fieldset>
    </form>
</div>

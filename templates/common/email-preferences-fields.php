<?php

/**
 * Shared email preference checkboxes (staff + guest).
 * Included from NC personal settings and portal email preferences.
 *
 * Expects: $l (IL10N), $pref (callable), $isGuest (bool)
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/** @var \OCP\IL10N $l */
$isGuest = !empty($isGuest);
$pref = $pref ?? static fn (string $key, string $default = 'yes'): string => $default;

if (!$isGuest): ?>
    <div class="tc-email-prefs__block tc-field--full-width">
        <h3 class="tc-email-prefs__heading"><?php p($l->t('email_prefs_digest_section')); ?></h3>

        <div class="tc-field tc-field--checkbox">
            <input type="checkbox" id="pref-receive_daily_digest" name="receive_daily_digest"
                <?php if ($pref('receive_daily_digest') === 'yes') {
                    print_unescaped(' checked');
                } ?>>
            <label for="pref-receive_daily_digest"><?php p($l->t('email_pref_daily_digest')); ?></label>
        </div>
        <p id="pref-receive_daily_digest-help" class="settings-hint tc-field__hint"><?php p($l->t('email_pref_daily_digest_help')); ?></p>

        <div class="tc-field tc-field--checkbox">
            <input type="checkbox" id="pref-receive_weekly_digest" name="receive_weekly_digest"
                <?php if ($pref('receive_weekly_digest') === 'yes') {
                    print_unescaped(' checked');
                } ?>>
            <label for="pref-receive_weekly_digest"><?php p($l->t('email_pref_weekly_digest')); ?></label>
        </div>
        <p id="pref-receive_weekly_digest-help" class="settings-hint tc-field__hint"><?php p($l->t('email_pref_weekly_digest_help')); ?></p>
    </div>

    <div class="tc-email-prefs__block tc-field--full-width">
        <h3 class="tc-email-prefs__heading"><?php p($l->t('email_prefs_ticket_section')); ?></h3>

        <div class="tc-field tc-field--checkbox">
            <input type="checkbox" id="pref-receive_ticket_assignment" name="receive_ticket_assignment"
                <?php if ($pref('receive_ticket_assignment') === 'yes') {
                    print_unescaped(' checked');
                } ?>>
            <label for="pref-receive_ticket_assignment"><?php p($l->t('email_pref_ticket_assignment')); ?></label>
        </div>
        <p id="pref-receive_ticket_assignment-help" class="settings-hint tc-field__hint"><?php p($l->t('email_pref_ticket_assignment_help')); ?></p>

        <div class="tc-field tc-field--checkbox">
            <input type="checkbox" id="pref-receive_new_ticket_in_project" name="receive_new_ticket_in_project"
                <?php if ($pref('receive_new_ticket_in_project') === 'yes') {
                    print_unescaped(' checked');
                } ?>>
            <label for="pref-receive_new_ticket_in_project"><?php p($l->t('email_pref_new_ticket_in_project')); ?></label>
        </div>
        <p id="pref-receive_new_ticket_in_project-help" class="settings-hint tc-field__hint"><?php p($l->t('email_pref_new_ticket_in_project_help')); ?></p>

        <div class="tc-field tc-field--checkbox">
            <input type="checkbox" id="pref-receive_customer_reply" name="receive_customer_reply"
                <?php if ($pref('receive_customer_reply') === 'yes') {
                    print_unescaped(' checked');
                } ?>>
            <label for="pref-receive_customer_reply"><?php p($l->t('email_pref_customer_reply')); ?></label>
        </div>
        <p id="pref-receive_customer_reply-help" class="settings-hint tc-field__hint"><?php p($l->t('email_pref_customer_reply_help')); ?></p>

        <div class="tc-field tc-field--checkbox">
            <input type="checkbox" id="pref-receive_project_member_added" name="receive_project_member_added"
                <?php if ($pref('receive_project_member_added') === 'yes') {
                    print_unescaped(' checked');
                } ?>>
            <label for="pref-receive_project_member_added"><?php p($l->t('email_pref_project_member_added')); ?></label>
        </div>
        <p id="pref-receive_project_member_added-help" class="settings-hint tc-field__hint"><?php p($l->t('email_pref_project_member_added_help')); ?></p>
    </div>
<?php endif; ?>

<?php if ($isGuest): ?>
    <div class="tc-email-prefs__block tc-field--full-width">
        <h3 class="tc-email-prefs__heading"><?php p($l->t('email_prefs_guest_section')); ?></h3>

        <div class="tc-field tc-field--checkbox">
            <input type="checkbox" id="pref-receive_guest_ticket_created" name="receive_guest_ticket_created"
                <?php if ($pref('receive_guest_ticket_created') === 'yes') {
                    print_unescaped(' checked');
                } ?>>
            <label for="pref-receive_guest_ticket_created"><?php p($l->t('email_pref_guest_ticket_created')); ?></label>
        </div>
        <p id="pref-receive_guest_ticket_created-help" class="settings-hint tc-field__hint"><?php p($l->t('email_pref_guest_ticket_created_help')); ?></p>

        <div class="tc-field tc-field--checkbox">
            <input type="checkbox" id="pref-receive_guest_ticket_updated" name="receive_guest_ticket_updated"
                <?php if ($pref('receive_guest_ticket_updated') === 'yes') {
                    print_unescaped(' checked');
                } ?>>
            <label for="pref-receive_guest_ticket_updated"><?php p($l->t('email_pref_guest_ticket_updated')); ?></label>
        </div>
        <p id="pref-receive_guest_ticket_updated-help" class="settings-hint tc-field__hint"><?php p($l->t('email_pref_guest_ticket_updated_help')); ?></p>

        <div class="tc-field tc-field--checkbox">
            <input type="checkbox" id="pref-receive_guest_ticket_status_changed" name="receive_guest_ticket_status_changed"
                <?php if ($pref('receive_guest_ticket_status_changed') === 'yes') {
                    print_unescaped(' checked');
                } ?>>
            <label for="pref-receive_guest_ticket_status_changed"><?php p($l->t('email_pref_guest_ticket_status_changed')); ?></label>
        </div>
        <p id="pref-receive_guest_ticket_status_changed-help" class="settings-hint tc-field__hint"><?php p($l->t('email_pref_guest_ticket_status_changed_help')); ?></p>

        <div class="tc-field tc-field--checkbox">
            <input type="checkbox" id="pref-receive_guest_ticket_comment" name="receive_guest_ticket_comment"
                <?php if ($pref('receive_guest_ticket_comment') === 'yes') {
                    print_unescaped(' checked');
                } ?>>
            <label for="pref-receive_guest_ticket_comment"><?php p($l->t('email_pref_guest_ticket_comment')); ?></label>
        </div>
        <p id="pref-receive_guest_ticket_comment-help" class="settings-hint tc-field__hint"><?php p($l->t('email_pref_guest_ticket_comment_help')); ?></p>
    </div>
<?php endif; ?>

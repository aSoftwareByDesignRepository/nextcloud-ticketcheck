<?php

/**
 * Guest portal — email notification preferences and account deletion request.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

/** @var \OCP\IL10N $l */
$l = $_['l'];
$prefs = $_['preferences'] ?? [];
$pref = static fn (string $key, string $default = 'yes') => $prefs[$key] ?? $default;
$saveUrl = (string)($_['saveUrl'] ?? '');
$accountDeletionUrl = (string)($_['accountDeletionUrl'] ?? '');
?>

<?php include __DIR__ . '/../common/page-start.php'; ?>

            <div class="portal-email-prefs">
                <form id="helpdesk-email-preferences-form"
                    class="portal-email-prefs__form"
                    data-helpdesk-submit-feedback="1"
                    data-save-url="<?php p($saveUrl); ?>"
                    data-msg-saved="<?php p($l->t('settings_saved')); ?>"
                    data-msg-error="<?php p($l->t('error_saving_settings')); ?>"
                    data-msg-token="<?php p($l->t('token_expired_reload')); ?>"
                    data-msg-generic-error="<?php p($l->t('an_error_occurred')); ?>"
                    data-msg-saving="<?php p($l->t('saving')); ?>"
                    novalidate>

                    <div class="portal-email-prefs__panel helpdesk-card helpdesk-card--static">
                        <section class="portal-email-prefs__section"
                            aria-labelledby="portal-email-prefs-notifications-heading">
                            <div class="helpdesk-card__body portal-email-prefs__body">
                                <h2 id="portal-email-prefs-notifications-heading" class="portal-email-prefs__section-title">
                                    <?php p($l->t('email_prefs_guest_section')); ?>
                                </h2>
                                <p class="portal-email-prefs__section-lead helpdesk-text-muted">
                                    <?php p($l->t('portal_email_prefs_section_lead')); ?>
                                </p>

                                <div id="portal-email-prefs-form-alert"
                                    class="helpdesk-alert helpdesk-alert--error portal-email-prefs__alert"
                                    role="alert"
                                    tabindex="-1"
                                    hidden>
                                    <div class="helpdesk-alert__content">
                                        <p class="helpdesk-alert__text" id="portal-email-prefs-form-alert-text"></p>
                                    </div>
                                </div>

                                <?php include __DIR__ . '/../common/email-preferences-guest-portal.php'; ?>
                            </div>

                            <footer class="portal-email-prefs__footer helpdesk-form-actions">
                                <button type="submit"
                                    class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg portal-email-prefs__submit"
                                    data-submit-label="<?php p($l->t('save_settings')); ?>">
                                    <?php print_unescaped(IconCatalog::render('save')); ?>
                                    <span class="portal-email-prefs__submit-label"><?php p($l->t('save_settings')); ?></span>
                                </button>
                                <span class="helpdesk-save-status portal-email-prefs__status"
                                    id="helpdesk-save-status"
                                    role="status"
                                    aria-live="polite"
                                    aria-atomic="true"></span>
                            </footer>
                        </section>
                    </div>
                </form>

                <?php if ($accountDeletionUrl !== ''): ?>
                <section class="portal-email-prefs__danger helpdesk-card helpdesk-card--static"
                    aria-labelledby="portal-account-deletion-heading"
                    data-msg-deletion-success="<?php p($l->t('account_deletion_request_submitted_successfully')); ?>"
                    data-msg-deletion-error="<?php p($l->t('an_error_occurred')); ?>"
                    data-msg-deletion-confirm-title="<?php p($l->t('account_deletion_confirm_title')); ?>"
                    data-msg-deletion-confirm-body="<?php p($l->t('account_deletion_confirm_body')); ?>"
                    data-msg-deletion-confirm-action="<?php p($l->t('account_deletion_confirm_action')); ?>"
                    data-msg-deletion-cancel="<?php p($l->t('cancel')); ?>">
                    <div class="helpdesk-card__body portal-email-prefs__danger-body">
                        <div class="portal-email-prefs__danger-icon" aria-hidden="true">
                            <?php print_unescaped(IconCatalog::render('alert-triangle')); ?>
                        </div>
                        <div class="portal-email-prefs__danger-content">
                            <h2 id="portal-account-deletion-heading" class="portal-email-prefs__danger-title">
                                <?php p($l->t('account_deletion_section_title')); ?>
                            </h2>
                            <p class="portal-email-prefs__danger-lead"><?php p($l->t('account_deletion_section_lead')); ?></p>
                            <p class="portal-email-prefs__danger-desc helpdesk-text-muted"
                                id="portal-account-deletion-desc">
                                <?php p($l->t('account_deletion_section_desc')); ?>
                            </p>
                            <div class="helpdesk-form-actions portal-email-prefs__danger-actions">
                                <button type="button"
                                    class="helpdesk-btn helpdesk-btn--secondary portal-email-prefs__danger-btn"
                                    id="portal-account-deletion-btn"
                                    data-deletion-url="<?php p($accountDeletionUrl); ?>"
                                    aria-describedby="portal-account-deletion-desc">
                                    <?php p($l->t('request_account_deletion')); ?>
                                </button>
                                <span class="helpdesk-save-status portal-email-prefs__status"
                                    id="portal-account-deletion-status"
                                    role="status"
                                    aria-live="polite"
                                    aria-atomic="true"></span>
                            </div>
                        </div>
                    </div>
                </section>
                <?php endif; ?>
            </div>

<?php include __DIR__ . '/../common/page-end.php'; ?>

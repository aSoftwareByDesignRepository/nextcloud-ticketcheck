<?php

/**
 * Customer portal password change - Allow guests to update their password
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

/** @var \OCP\IL10N $l */
$l = $_['l'];

$requirementItems = [
	'length' => 'password_requirement_length',
	'upper' => 'password_requirement_upper',
	'lower' => 'password_requirement_lower',
	'number' => 'password_requirement_number',
	'special' => 'password_requirement_special',
];
?>
<?php include __DIR__ . '/../common/page-start.php'; ?>

            <section class="portal-password tc-section" aria-labelledby="portal-password-heading">
                <h2 id="portal-password-heading" class="tc-section__title tc-sr-only"><?php p($l->t('change_password')); ?></h2>

                <form id="password-change-form"
                    class="portal-password__form"
                    data-update-url="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.updatePassword')); ?>"
                    data-return-url="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.index')); ?>"
                    data-msg-current-required="<?php p($l->t('current_password_required')); ?>"
                    data-msg-new-required="<?php p($l->t('password_required')); ?>"
                    data-msg-confirm-required="<?php p($l->t('confirm_password_required')); ?>"
                    data-msg-mismatch="<?php p($l->t('password_mismatch')); ?>"
                    data-msg-same-as-current="<?php p($l->t('password_same_as_current')); ?>"
                    data-msg-success="<?php p($l->t('password_changed_successfully')); ?>"
                    data-msg-current-wrong="<?php p($l->t('incorrect_current_password')); ?>"
                    data-msg-rate-limited="<?php p($l->t('too_many_failed_attempts')); ?>"
                    data-msg-change="<?php p($l->t('change_password')); ?>"
                    data-msg-show-password="<?php p($l->t('show_password')); ?>"
                    data-msg-hide-password="<?php p($l->t('hide_password')); ?>"
                    novalidate>

                    <div id="password-form-summary" class="helpdesk-alert helpdesk-alert--error portal-password__alert" role="alert" hidden>
                        <div class="helpdesk-alert__content">
                            <p class="helpdesk-alert__text" id="password-error-text"></p>
                        </div>
                    </div>

                    <div id="password-form-success" class="helpdesk-alert helpdesk-alert--success portal-password__alert" role="status" aria-live="polite" hidden>
                        <div class="helpdesk-alert__content">
                            <p class="helpdesk-alert__text" id="password-success-text"></p>
                            <p class="portal-password__success-next helpdesk-text-muted">
                                <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.index')); ?>" class="portal-password__success-link">
                                    <?php p($l->t('back_to_portal')); ?>
                                </a>
                            </p>
                        </div>
                    </div>

                    <div class="portal-password__card helpdesk-card helpdesk-card--static">
                        <div class="helpdesk-card__body portal-password__card-body">
                            <div class="helpdesk-alert helpdesk-alert--info portal-password__notice" role="note">
                                <div class="helpdesk-alert__content">
                                    <p class="helpdesk-alert__text"><?php p($l->t('portal_password_security_note')); ?></p>
                                </div>
                            </div>

                            <ol class="portal-password__steps">
                                <li class="portal-password__step">
                                    <h3 class="portal-password__step-title">
                                        <span class="portal-password__step-badge" aria-hidden="true">1</span>
                                        <?php p($l->t('portal_password_step_current')); ?>
                                    </h3>
                                    <?php
                                    $id = 'current-password';
                                    $name = 'current_password';
                                    $label = $l->t('current_password');
                                    $autocomplete = 'current-password';
                                    $required = true;
                                    $describedby = null;
                                    $minlength = null;
                                    include __DIR__ . '/../common/password-input.php';
                                    ?>
                                </li>

                                <li class="portal-password__step">
                                    <h3 class="portal-password__step-title">
                                        <span class="portal-password__step-badge" aria-hidden="true">2</span>
                                        <?php p($l->t('portal_password_step_new')); ?>
                                    </h3>

                                    <div id="portal-password-requirements"
                                        class="portal-password__requirements"
                                        role="group"
                                        aria-labelledby="portal-password-requirements-heading">
                                        <p id="portal-password-requirements-heading" class="portal-password__requirements-heading">
                                            <?php p($l->t('password_requirements_heading')); ?>
                                        </p>
                                        <ul class="portal-password__requirements-list" aria-live="polite" aria-atomic="false">
                                            <?php foreach ($requirementItems as $reqId => $reqKey): ?>
                                                <li class="portal-password__req"
                                                    data-requirement="<?php p($reqId); ?>"
                                                    id="pw-req-<?php p($reqId); ?>">
                                                    <span class="portal-password__req-icon" aria-hidden="true">
                                                        <?php print_unescaped(IconCatalog::render('alert-circle', 'portal-password__req-icon-svg')); ?>
                                                    </span>
                                                    <span class="portal-password__req-text"><?php p($l->t($reqKey)); ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>

                                    <?php
                                    $id = 'new-password';
                                    $name = 'new_password';
                                    $label = $l->t('new_password');
                                    $autocomplete = 'new-password';
                                    $required = true;
                                    $describedby = 'portal-password-requirements';
                                    $minlength = 12;
                                    include __DIR__ . '/../common/password-input.php';
                                    ?>

                                    <?php
                                    $id = 'confirm-password';
                                    $name = 'confirm_password';
                                    $label = $l->t('confirm_new_password');
                                    $autocomplete = 'new-password';
                                    $required = true;
                                    $describedby = null;
                                    $minlength = null;
                                    include __DIR__ . '/../common/password-input.php';
                                    ?>
                                </li>
                            </ol>
                        </div>

                        <div class="portal-password__actions helpdesk-form-actions">
                            <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.index')); ?>"
                                class="helpdesk-btn helpdesk-btn--secondary">
                                <?php p($l->t('cancel')); ?>
                            </a>
                            <button type="submit" class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg" data-submit-label="<?php p($l->t('change_password')); ?>">
                                <?php p($l->t('change_password')); ?>
                            </button>
                        </div>
                    </div>
                </form>
            </section>

<?php include __DIR__ . '/../common/page-end.php'; ?>

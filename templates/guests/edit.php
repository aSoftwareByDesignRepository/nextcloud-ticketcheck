<?php

/**
 * Edit guest user form
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

$_['isGuest'] = false;
$_['isAdmin'] = $_['isAdmin'] ?? false;

$guest = $_['guest'];
$projects = is_array($_['projects'] ?? null) ? $_['projects'] : [];
$assignedProjectIds = is_array($_['assigned_project_ids'] ?? null) ? array_map('intval', $_['assigned_project_ids']) : [];
$selectedSet = array_fill_keys($assignedProjectIds, true);

/** @var \OCP\IL10N $l */
$l = $_['l'];

$userId = (string)($guest['user_id'] ?? '');
$cancelHref = $_['urlGenerator']->linkToRoute('ticketcheck.guestUser.index');
?>

<?php include __DIR__ . '/../common/page-start.php'; ?>

            <form id="guest-edit-form"
                class="tc-guest-form helpdesk-customer-form"
                novalidate
                data-user-id="<?php p($userId); ?>"
                data-draft-key="ticketcheck_guest_form_edit_<?php p($userId); ?>"
                aria-describedby="guest-edit-form-required-hint">
                <p id="guest-edit-form-required-hint" class="helpdesk-form-help helpdesk-customer-form__legend">
                    <span class="helpdesk-form-label--required"><?php p($l->t('required_fields')); ?></span>
                    — <?php p($l->t('required_to_save')); ?>
                </p>

                <section class="tc-section" aria-labelledby="guest-edit-scope-heading">
                    <h2 id="guest-edit-scope-heading" class="tc-section__title"><?php p($l->t('portal_access')); ?></h2>
                    <p class="tc-section__lead"><?php p($l->t('guest_edit_portal_scope_lead')); ?></p>
                    <div class="helpdesk-card tc-guest-edit__scope-card">
                        <div class="helpdesk-card__body">
                            <p class="tc-guest-edit__scope-text"><?php p($l->t('guest_users_explanation')); ?></p>
                            <ul class="helpdesk-project-form__hint-list tc-guest-edit__scope-list">
                                <li><?php p($l->t('guest_edit_scope_tickets_only')); ?></li>
                                <li><?php p($l->t('guest_edit_scope_no_files')); ?></li>
                                <li><?php p($l->t('guest_edit_scope_projects_control_access')); ?></li>
                            </ul>
                        </div>
                    </div>
                </section>

                <section class="tc-section" aria-labelledby="guest-edit-basic-heading">
                    <h2 id="guest-edit-basic-heading" class="tc-section__title"><?php p($l->t('customer_information')); ?></h2>
                    <p class="tc-section__lead"><?php p($l->t('update_guest_info')); ?></p>
                    <div class="helpdesk-card">
                        <div class="helpdesk-card__body">
                            <div class="tc-form-grid">
                                <div class="helpdesk-form-group tc-field tc-field--full-width">
                                    <label for="guest-name" class="helpdesk-form-label helpdesk-form-label--required">
                                        <?php p($l->t('full_name')); ?>
                                    </label>
                                    <input type="text"
                                        id="guest-name"
                                        name="display_name"
                                        required
                                        aria-required="true"
                                        autocomplete="name"
                                        value="<?php p($guest['display_name']); ?>"
                                        placeholder="<?php p($l->t('guest_full_name_placeholder')); ?>"
                                        class="helpdesk-form-control"
                                        aria-describedby="guest-name-hint">
                                    <span class="helpdesk-form-help" id="guest-name-hint"><?php p($l->t('name_appears_on_tickets')); ?></span>
                                </div>

                                <div class="helpdesk-form-group tc-field tc-field--full-width">
                                    <label for="guest-email" class="helpdesk-form-label helpdesk-form-label--required">
                                        <?php p($l->t('email_address')); ?>
                                    </label>
                                    <input type="email"
                                        id="guest-email"
                                        name="email"
                                        required
                                        aria-required="true"
                                        autocomplete="email"
                                        inputmode="email"
                                        value="<?php p($guest['email']); ?>"
                                        placeholder="<?php p($l->t('guest_email_placeholder')); ?>"
                                        class="helpdesk-form-control"
                                        aria-describedby="guest-email-hint">
                                    <span class="helpdesk-form-help" id="guest-email-hint"><?php p($l->t('used_for_login_notifications')); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="tc-section" aria-labelledby="guest-edit-projects-heading">
                    <h2 id="guest-edit-projects-heading" class="tc-section__title"><?php p($l->t('project_access')); ?></h2>
                    <p class="tc-section__lead"><?php p($l->t('guest_form_portal_lead')); ?></p>
                    <div class="helpdesk-card">
                        <div class="helpdesk-card__body">
                            <?php if ($projects === []): ?>
                                <p class="helpdesk-text-muted" role="status"><?php p($l->t('no_projects_available_for_guest_assignment')); ?></p>
                            <?php else: ?>
                                <div class="tc-field tc-field--full-width">
                                    <label for="guest-project-filter" class="helpdesk-form-label"><?php p($l->t('project_filter_search_placeholder')); ?></label>
                                    <input type="search"
                                        id="guest-project-filter"
                                        class="helpdesk-form-control tc-entity-picker__q"
                                        autocomplete="off"
                                        aria-controls="guest-project-list"
                                        aria-describedby="guest-project-filter-hint"
                                        placeholder="<?php p($l->t('project_filter_search_placeholder')); ?>">
                                    <span id="guest-project-filter-hint" class="helpdesk-form-help"><?php p($l->t('project_filter_search_help')); ?></span>
                                    <p id="guest-project-filter-status" class="helpdesk-text-muted helpdesk-form-help" role="status" aria-live="polite"></p>
                                </div>
                                <fieldset class="helpdesk-form-group">
                                    <legend class="tc-sr-only">
                                        <?php p($l->t('select_projects')); ?> (<?php p($l->t('optional')); ?>)
                                    </legend>
                                    <div id="guest-project-list"
                                        class="helpdesk-checkbox-list helpdesk-customer-form__project-grid tc-guest-form__project-list"
                                        role="group"
                                        aria-label="<?php p($l->t('project_access')); ?>">
                                        <?php foreach ($projects as $project): ?>
                                            <?php
                                            $pid = (int)($project['id'] ?? 0);
                                            if ($pid <= 0) {
                                                continue;
                                            }
                                            $pname = (string)($project['name'] ?? '');
                                            $isChecked = isset($selectedSet[$pid]);
                                            ?>
                                            <label class="helpdesk-checkbox helpdesk-customer-form__project-item tc-guest-form__project-item"
                                                data-project-name="<?php p($pname); ?>">
                                                <input type="checkbox"
                                                    name="project_ids[]"
                                                    value="<?php p((string)$pid); ?>"
                                                    <?php if ($isChecked) {
                                                        p('checked');
                                                    } ?>>
                                                <span><?php p($pname); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </fieldset>
                            <?php endif; ?>
                            <p class="helpdesk-form-help"><?php p($l->t('select_which_projects_guest_can_access')); ?></p>
                        </div>
                    </div>
                </section>

                <div class="helpdesk-form-actions tc-guest-form__actions">
                    <a href="<?php p($cancelHref); ?>" class="helpdesk-btn helpdesk-btn--secondary"><?php p($l->t('cancel')); ?></a>
                    <button type="button" id="guest-reset-password" class="helpdesk-btn helpdesk-btn--secondary"
                        aria-describedby="guest-reset-password-hint">
                        <?php print_unescaped(IconCatalog::render('mail')); ?>
                        <span class="tc-guest-reset__text"><?php p($l->t('guest_send_new_login_details')); ?></span>
                    </button>
                    <button type="submit" id="guest-edit-form-submit" class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg">
                        <?php print_unescaped(IconCatalog::render('save')); ?>
                        <span class="tc-guest-form-submit__text"><?php p($l->t('update_guest_user')); ?></span>
                    </button>
                </div>
                <p id="guest-reset-password-hint" class="helpdesk-form-help"><?php p($l->t('guest_send_new_login_details_hint')); ?></p>
            </form>
<?php include __DIR__ . '/../common/page-end.php'; ?>

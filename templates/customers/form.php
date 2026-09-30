<?php

/**
 * Customer create / edit form
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

$_['isGuest'] = false;
$_['isAdmin'] = $_['isAdmin'] ?? false;

$customer = $_['customer'] ?? null;
$isEdit = !empty($_['isEdit']);
$assignableProjects = is_array($_['assignableProjects'] ?? null) ? $_['assignableProjects'] : [];
$selectedProjectIds = is_array($_['selectedProjectIds'] ?? null) ? array_map('intval', $_['selectedProjectIds']) : [];
$selectedSet = array_fill_keys($selectedProjectIds, true);

/** @var \OCP\IL10N $l */
$l = $_['l'];

$cid = $isEdit ? (int)($customer['id'] ?? 0) : 0;
$cancelHref = $isEdit && $cid > 0
    ? $_['urlGenerator']->linkToRoute('ticketcheck.customer.show', ['id' => $cid])
    : $_['urlGenerator']->linkToRoute('ticketcheck.customer.index');
?>

<?php include __DIR__ . '/../common/page-start.php'; ?>

            <form id="customer-form"
                class="tc-customer-form helpdesk-customer-form"
                novalidate
                aria-describedby="customer-form-required-hint"
                data-draft-key="<?php p($isEdit ? 'ticketcheck_customer_form_edit_' . (string)$cid : 'ticketcheck_customer_form_create'); ?>">

                <p id="customer-form-required-hint" class="tc-ticket-form-required-hint"><?php p($l->t('ticket_form_required_hint')); ?></p>

                <div id="customer-form-errors" class="helpdesk-alert helpdesk-alert--error helpdesk-form-error-summary helpdesk-mb-md" role="alert" hidden>
                    <div class="helpdesk-alert__content">
                        <p class="helpdesk-alert__title"><?php p($l->t('please_check_required_fields')); ?></p>
                        <ul class="helpdesk-form-errors-list"></ul>
                    </div>
                </div>

                <?php if ($isEdit): ?>
                    <input type="hidden" name="customer_id" id="customer-id" value="<?php p((string)$cid); ?>">
                <?php endif; ?>

                <section class="tc-section" aria-labelledby="customer-form-basic-heading">
                    <h2 id="customer-form-basic-heading" class="tc-section__title"><?php p($l->t('basic_information')); ?></h2>
                    <p class="tc-section__lead"><?php p($l->t('customer_form_basic_lead')); ?></p>
                    <div class="helpdesk-card">
                        <div class="helpdesk-card__body">
                            <div class="tc-form-grid">
                                <div class="helpdesk-form-group tc-field tc-field--full-width">
                                    <label for="customer-name" class="helpdesk-form-label helpdesk-form-label--required">
                                        <?php p($l->t('customer_name')); ?>
                                    </label>
                                    <input type="text"
                                        id="customer-name"
                                        name="name"
                                        data-tc-required="1"
                                        aria-required="true"
                                        autocomplete="organization"
                                        value="<?php p($customer['name'] ?? ''); ?>"
                                        placeholder="<?php p($l->t('customer_name_placeholder')); ?>"
                                        class="helpdesk-form-control">
                                    <span class="helpdesk-form-help" id="customer-name-hint"><?php p($l->t('organization_or_individual_name')); ?></span>
                                </div>

                                <div class="helpdesk-form-group tc-field">
                                    <label for="customer-email" class="helpdesk-form-label helpdesk-form-label--required">
                                        <?php p($l->t('email_address')); ?>
                                    </label>
                                    <input type="email"
                                        id="customer-email"
                                        name="email"
                                        data-tc-required="1"
                                        aria-required="true"
                                        autocomplete="email"
                                        inputmode="email"
                                        value="<?php p($customer['email'] ?? ''); ?>"
                                        placeholder="<?php p($l->t('contact_email_placeholder')); ?>"
                                        class="helpdesk-form-control"
                                        aria-describedby="customer-email-hint">
                                    <span class="helpdesk-form-help" id="customer-email-hint"><?php p($l->t('primary_email_communications')); ?></span>
                                </div>

                                <div class="helpdesk-form-group tc-field">
                                    <label for="customer-phone" class="helpdesk-form-label">
                                        <?php p($l->t('phone_number')); ?> (<?php p($l->t('optional')); ?>)
                                    </label>
                                    <input type="tel"
                                        id="customer-phone"
                                        name="phone"
                                        autocomplete="tel"
                                        inputmode="tel"
                                        value="<?php p($customer['phone'] ?? ''); ?>"
                                        placeholder="<?php p($l->t('contact_phone_placeholder')); ?>"
                                        class="helpdesk-form-control"
                                        aria-describedby="customer-phone-hint">
                                    <span class="helpdesk-form-help" id="customer-phone-hint"><?php p($l->t('contact_phone_number')); ?></span>
                                </div>

                                <div class="helpdesk-form-group tc-field tc-field--full-width">
                                    <label for="customer-notes" class="helpdesk-form-label">
                                        <?php p($l->t('notes')); ?> (<?php p($l->t('optional')); ?>)
                                    </label>
                                    <textarea id="customer-notes"
                                        name="notes"
                                        rows="4"
                                        placeholder="<?php p($l->t('internal_notes_placeholder')); ?>"
                                        class="helpdesk-form-control"
                                        aria-describedby="customer-notes-hint"><?php p($customer['notes'] ?? ''); ?></textarea>
                                    <span class="helpdesk-form-help" id="customer-notes-hint"><?php p($l->t('internal_notes_not_visible')); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="tc-section" aria-labelledby="customer-form-projects-heading">
                    <h2 id="customer-form-projects-heading" class="tc-section__title"><?php p($l->t('assign_existing_projects')); ?></h2>
                    <p class="tc-section__lead"><?php p($isEdit ? $l->t('customer_form_projects_help_edit') : $l->t('assign_existing_projects_help')); ?></p>
                    <div class="helpdesk-card">
                        <div class="helpdesk-card__body">
                            <?php if ($assignableProjects === []): ?>
                                <p class="helpdesk-text-muted" role="status"><?php p($isEdit ? $l->t('customer_form_projects_empty_edit') : $l->t('no_unassigned_projects_available')); ?></p>
                            <?php else: ?>
                                <div class="tc-field tc-field--full-width">
                                    <label for="customer-project-filter" class="helpdesk-form-label"><?php p($l->t('project_filter_search_placeholder')); ?></label>
                                    <input type="search"
                                        id="customer-project-filter"
                                        class="helpdesk-form-control tc-entity-picker__q"
                                        autocomplete="off"
                                        aria-controls="customer-project-list"
                                        aria-describedby="customer-project-filter-hint"
                                        placeholder="<?php p($l->t('project_filter_search_placeholder')); ?>">
                                    <span id="customer-project-filter-hint" class="helpdesk-form-help"><?php p($l->t('project_filter_search_help')); ?></span>
                                    <p id="customer-project-filter-status" class="helpdesk-text-muted helpdesk-form-help" role="status" aria-live="polite"></p>
                                </div>
                                <fieldset class="helpdesk-form-group">
                                    <legend class="tc-sr-only"><?php p($l->t('assign_existing_projects')); ?></legend>
                                    <div id="customer-project-list"
                                        class="helpdesk-checkbox-list helpdesk-customer-form__project-grid tc-customer-form__project-list"
                                        role="group"
                                        aria-label="<?php p($l->t('assign_existing_projects')); ?>">
                                        <?php foreach ($assignableProjects as $project): ?>
                                            <?php
                                            $pid = (int)($project['id'] ?? 0);
                                            if ($pid <= 0) {
                                                continue;
                                            }
                                            $pname = (string)($project['name'] ?? '');
                                            $isChecked = isset($selectedSet[$pid]);
                                            ?>
                                            <label class="helpdesk-checkbox helpdesk-customer-form__project-item tc-customer-form__project-item"
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
                        </div>
                    </div>
                </section>

                <div class="helpdesk-form-actions tc-customer-form__actions">
                    <a href="<?php p($cancelHref); ?>" class="helpdesk-btn helpdesk-btn--secondary"><?php p($l->t('cancel')); ?></a>
                    <button type="submit" id="customer-form-submit" class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg">
                        <?php print_unescaped(IconCatalog::render('save')); ?>
                        <span class="tc-customer-form-submit__text"><?php p($isEdit ? $l->t('update_customer') : $l->t('create_customer')); ?></span>
                    </button>
                </div>
            </form>
<?php include __DIR__ . '/../common/page-end.php'; ?>

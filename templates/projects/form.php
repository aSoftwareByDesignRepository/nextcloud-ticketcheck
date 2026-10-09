<?php

/**
 * Project create / edit form
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

$_['isGuest'] = false;
$_['isAdmin'] = $_['isAdmin'] ?? false;

$project = $_['project'];
$customers = is_array($_['customers'] ?? null) ? $_['customers'] : [];
$mode = $_['mode'] ?? 'create';
$isEdit = $mode === 'edit';
$selectedCustomerId = $_['selectedCustomerId'] ?? null;
$returnTo = (string)($_['returnTo'] ?? '');
$availableUsers = is_array($_['availableUsers'] ?? null) ? $_['availableUsers'] : [];
$showMemberPicker = $isEdit && !empty($project['id']) && $availableUsers !== [];

/** @var \OCP\IL10N $l */
$l = $_['l'];

$cancelHref = $returnTo !== '' ? $returnTo : ($isEdit && !empty($project['id'])
    ? $_['urlGenerator']->linkToRoute('ticketcheck.project.show', ['id' => $project['id']])
    : $_['urlGenerator']->linkToRoute('ticketcheck.project.index'));

$isProjectActive = !$project || !isset($project['active']) || (int)$project['active'] === 1;

$availableUsersJson = '';
if ($showMemberPicker) {
    $availableUsersJson = htmlspecialchars(
        json_encode($availableUsers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ENT_QUOTES,
        'UTF-8',
    );
}
?>

<?php include __DIR__ . '/../common/page-start.php'; ?>

            <form id="project-form"
                class="tc-project-form"
                novalidate
                aria-describedby="project-form-required-hint"
                data-draft-key="ticketcheck_project_form_<?php p($isEdit ? ('edit_' . ($project['id'] ?? '')) : 'create'); ?>">

                <p id="project-form-required-hint" class="tc-ticket-form-required-hint"><?php p($l->t('ticket_form_required_hint')); ?></p>
                <div id="project-form-errors" class="helpdesk-alert helpdesk-alert--error helpdesk-form-error-summary helpdesk-mb-md" role="alert" hidden></div>

                <?php if ($isEdit): ?>
                    <input type="hidden" name="project_id" id="project-id" value="<?php p((string)($project['id'] ?? '')); ?>">
                <?php endif; ?>
                <?php if (!$isEdit && $returnTo !== ''): ?>
                    <input type="hidden" name="return_to" value="<?php p($returnTo); ?>">
                <?php endif; ?>

                <section class="tc-section" aria-labelledby="project-form-basic-heading">
                    <h2 id="project-form-basic-heading" class="tc-section__title"><?php p($l->t('basic_information')); ?></h2>
                    <p class="tc-section__lead"><?php p($l->t('project_form_basic_lead')); ?></p>
                    <div class="helpdesk-card">
                        <div class="helpdesk-card__body">
                            <div class="tc-form-grid">
                                <div class="helpdesk-form-group tc-field tc-field--full-width">
                                    <label for="project-name" class="helpdesk-form-label helpdesk-form-label--required">
                                        <?php p($l->t('project_name')); ?>
                                    </label>
                                    <input type="text"
                                        id="project-name"
                                        name="name"
                                        required
                                        aria-required="true"
                                        autocomplete="organization"
                                        value="<?php p($project['name'] ?? ''); ?>"
                                        placeholder="<?php p($l->t('project_name_placeholder')); ?>"
                                        class="helpdesk-form-control">
                                    <span class="helpdesk-form-help"><?php p($l->t('clear_name_identify_project')); ?></span>
                                </div>

                                <div class="helpdesk-form-group tc-field tc-field--full-width">
                                    <label for="project-description" class="helpdesk-form-label">
                                        <?php p($l->t('description')); ?> (<?php p($l->t('optional')); ?>)
                                    </label>
                                    <textarea id="project-description"
                                        name="description"
                                        rows="4"
                                        placeholder="<?php p($l->t('what_is_this_project_about')); ?>"
                                        class="helpdesk-form-control"><?php p($project['description'] ?? ''); ?></textarea>
                                    <span class="helpdesk-form-help"><?php p($l->t('brief_description_project_purpose')); ?></span>
                                </div>

                                <div class="helpdesk-form-group tc-field">
                                    <label for="customer-select" class="helpdesk-form-label helpdesk-form-label--required">
                                        <?php p($l->t('customer_label')); ?>
                                    </label>
                                    <select id="customer-select" name="customer_id" required aria-required="true" class="helpdesk-form-control">
                                        <option value=""><?php p($l->t('select_a_customer')); ?></option>
                                        <?php foreach ($customers as $customer): ?>
                                            <?php $cid = (int)($customer['id'] ?? 0); ?>
                                            <?php if ($cid <= 0) {
                                                continue;
                                            } ?>
                                            <option value="<?php p((string)$cid); ?>"
                                                <?php
                                                $selected = ($project && (int)($project['customer_id'] ?? 0) === $cid)
                                                    || (!$project && $selectedCustomerId && (int)$selectedCustomerId === $cid);
                                                if ($selected) {
                                                    p('selected');
                                                }
                                                ?>>
                                                <?php p((string)($customer['name'] ?? '')); ?>
                                                <?php if (!empty($customer['company'])): ?>
                                                    (<?php p((string)$customer['company']); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="helpdesk-form-help"><?php p($l->t('which_customer_project_belongs')); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="tc-section" aria-labelledby="project-form-settings-heading">
                    <h2 id="project-form-settings-heading" class="tc-section__title"><?php p($l->t('project_settings')); ?></h2>
                    <p class="tc-section__lead"><?php p($l->t('project_form_settings_lead')); ?></p>
                    <div class="helpdesk-card">
                        <div class="helpdesk-card__body tc-project-settings-card__body">
                            <fieldset class="tc-project-status-field">
                                <legend class="helpdesk-form-label tc-project-status-field__label"><?php p($l->t('project_status_label')); ?></legend>
                                <div class="tc-segmented-toggle">
                                    <label class="tc-segmented-toggle__option">
                                        <input type="radio"
                                            id="project-active-yes"
                                            name="active"
                                            value="1"
                                            class="tc-sr-only"
                                            <?php if ($isProjectActive) {
                                                p('checked');
                                            } ?>>
                                        <span><?php p($l->t('active_status')); ?></span>
                                    </label>
                                    <label class="tc-segmented-toggle__option">
                                        <input type="radio"
                                            id="project-active-no"
                                            name="active"
                                            value="0"
                                            class="tc-sr-only"
                                            <?php if (!$isProjectActive) {
                                                p('checked');
                                            } ?>>
                                        <span><?php p($l->t('inactive_status')); ?></span>
                                    </label>
                                </div>
                            </fieldset>
                            <div class="helpdesk-alert tc-project-status-info" role="status">
                                <div class="helpdesk-alert__content">
                                    <p class="helpdesk-alert__title"><?php p($l->t('what_happens')); ?></p>
                                    <ul class="helpdesk-project-form__hint-list">
                                        <li><?php p($l->t('when_active_new_tickets')); ?></li>
                                        <li><?php p($l->t('when_inactive_no_new_tickets')); ?></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <?php if ($showMemberPicker): ?>
                    <section class="tc-section" aria-labelledby="project-form-members-heading">
                        <h2 id="project-form-members-heading" class="tc-section__title"><?php p($l->t('add_team_member')); ?></h2>
                        <p class="tc-section__lead"><?php p($l->t('project_form_members_lead')); ?></p>
                        <div class="helpdesk-card">
                            <div class="helpdesk-card__body">
                                <div id="project-form-member-picker"
                                    class="tc-entity-field"
                                    data-project-id="<?php p((string)($project['id'] ?? '')); ?>"
                                    data-available-users="<?php print_unescaped($availableUsersJson); ?>"
                                    data-user-search-help="<?php p($l->t('user_search_help')); ?>"
                                    data-user-search-no-results="<?php p($l->t('user_search_no_results')); ?>"
                                    data-user-search-results-count="<?php p($l->t('user_search_results_count')); ?>">
                                    <label for="project-member-search" class="helpdesk-form-label"><?php p($l->t('user')); ?></label>
                                    <input type="search"
                                        id="project-member-search"
                                        class="helpdesk-form-control tc-entity-picker__q"
                                        autocomplete="off"
                                        aria-controls="project-member-search-results"
                                        aria-expanded="false"
                                        aria-autocomplete="list"
                                        placeholder="<?php p($l->t('project_form_member_search_placeholder')); ?>">
                                    <p id="project-member-search-status" class="helpdesk-text-muted helpdesk-form-help" role="status" aria-live="polite"><?php p($l->t('user_search_help')); ?></p>
                                    <div id="project-member-search-results"
                                        class="tc-user-search-results"
                                        role="listbox"
                                        aria-label="<?php p($l->t('project_form_member_search_results')); ?>"
                                        hidden></div>
                                    <div class="tc-form-grid helpdesk-mt-md">
                                        <div class="helpdesk-form-group tc-field">
                                            <label for="project-member-role" class="helpdesk-form-label"><?php p($l->t('role_label')); ?></label>
                                            <select id="project-member-role" class="helpdesk-form-control">
                                                <option value="Support User"><?php p($l->t('support_user')); ?></option>
                                                <option value="Agent"><?php p($l->t('agent')); ?></option>
                                                <option value="Admin"><?php p($l->t('admin')); ?></option>
                                            </select>
                                        </div>
                                    </div>
                                    <p class="helpdesk-text-muted helpdesk-form-help"><?php p($l->t('guests_cannot_be_team_members')); ?></p>
                                </div>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <div class="helpdesk-form-actions tc-project-form__actions">
                    <a href="<?php p($cancelHref); ?>" class="helpdesk-btn helpdesk-btn--secondary"><?php p($l->t('cancel')); ?></a>
                    <button type="submit" id="project-form-submit" class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg">
                        <?php print_unescaped(IconCatalog::render('save')); ?>
                        <span class="tc-project-form-submit__text"><?php p($isEdit ? $l->t('update_project') : $l->t('create_project')); ?></span>
                    </button>
                </div>
            </form>
<?php include __DIR__ . '/../common/page-end.php'; ?>

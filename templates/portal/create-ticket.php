<?php

/**
 * Customer portal — create ticket (guest)
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

/** @var \OCP\IL10N $l */
$l = $_['l'];
$canCreateTicket = !empty($_['canCreateTicket']);
$projects = is_array($_['projects'] ?? null) ? $_['projects'] : [];
$hasProjects = $projects !== [];
$defaultProjectId = isset($_['defaultProjectId']) ? (int)$_['defaultProjectId'] : 0;
$priorities = is_array($_['priorities'] ?? null) ? $_['priorities'] : ['low', 'normal', 'high', 'urgent'];
$categories = is_array($_['categories'] ?? null) ? $_['categories'] : ['general', 'technical', 'bug', 'billing', 'feature'];

$categoryLabelKeys = [
    'general' => 'general_category',
    'technical' => 'technical_issue',
    'bug' => 'bug_report',
    'billing' => 'billing',
    'feature' => 'feature_request',
];

$customerFilterOptions = [];
$seenCustomerIds = [];
foreach ($projects as $proj) {
    $cid = isset($proj['customer_id']) ? (int)$proj['customer_id'] : 0;
    if ($cid > 0 && !isset($seenCustomerIds[$cid])) {
        $seenCustomerIds[$cid] = true;
        $customerFilterOptions[] = [
            'id' => $cid,
            'name' => (string)($proj['customer_name'] ?? ''),
        ];
    }
}
usort($customerFilterOptions, static function (array $a, array $b): int {
    return strcasecmp($a['name'], $b['name']);
});

$activeProjects = array_values(array_filter($projects, static function (array $project): bool {
    return !isset($project['active']) || (int)$project['active'] !== 0;
}));
$activeProjectCount = count($activeProjects);
$portalProjectPickerMode = 'none';
if ($activeProjectCount === 1) {
    $portalProjectPickerMode = 'single';
    $defaultProjectId = (int)$activeProjects[0]['id'];
} elseif ($activeProjectCount >= 2 && $activeProjectCount <= 8) {
    $portalProjectPickerMode = 'radio';
} elseif ($activeProjectCount > 8) {
    $portalProjectPickerMode = 'select';
}
?>

<?php include __DIR__ . '/../common/page-start.php'; ?>

            <section class="portal-create tc-section" aria-labelledby="portal-create-heading">
                <h2 id="portal-create-heading" class="tc-sr-only"><?php p($l->t('create_new_ticket')); ?></h2>

                <?php if (!$canCreateTicket): ?>
                    <div class="helpdesk-card helpdesk-mb-md tc-ticket-form-card" role="region" aria-labelledby="portal-create-blocked-title">
                        <div class="helpdesk-card__body">
                            <div class="helpdesk-alert helpdesk-alert--warning" role="alert">
                                <div class="helpdesk-alert__icon" aria-hidden="true">
                                    <?php print_unescaped(IconCatalog::render('alert-triangle')); ?>
                                </div>
                                <div class="helpdesk-alert__content">
                                    <h3 id="portal-create-blocked-title" class="helpdesk-alert__title">
                                        <?php p($l->t('guest_create_ticket_unavailable_title')); ?>
                                    </h3>
                                    <p class="helpdesk-alert__text"><?php p($l->t('guest_no_active_projects_message')); ?></p>
                                </div>
                            </div>
                            <div class="helpdesk-form-actions ticket-form-actions portal-create__actions--blocked">
                                <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.index')); ?>" class="helpdesk-btn helpdesk-btn--secondary ticket-form-actions__cancel">
                                    <?php p($l->t('dashboard')); ?>
                                </a>
                                <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.myTickets')); ?>" class="helpdesk-btn helpdesk-btn--primary ticket-form-actions__submit">
                                    <?php p($l->t('your_tickets')); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <form id="create-ticket-form"
                        class="portal-create__form"
                        data-guest-portal-form="true"
                        data-submit-url="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.storeTicket')); ?>"
                        novalidate
                        enctype="multipart/form-data"
                        aria-describedby="portal-create-ticket-form-help"
                        data-i18n-please-check-required-fields="<?php p($l->t('please_check_required_fields')); ?>"
                        data-i18n-field-required="<?php p($l->t('field_required')); ?>"
                        data-i18n-restore-draft-prompt="<?php p($l->t('restore_draft_prompt')); ?>"
                        data-i18n-restore-draft-button="<?php p($l->t('restore_draft_button')); ?>"
                        data-i18n-discard-draft-button="<?php p($l->t('discard_draft_button')); ?>"
                        data-i18n-or-drag-drop-here="<?php p($l->t('or drag and drop here')); ?>"
                        data-i18n-files-selected-count="<?php p($l->t('files_selected_count')); ?>"
                        data-i18n-remove-attachment="<?php p($l->t('remove_attachment')); ?>"
                        data-i18n-file-too-large="<?php p($l->t('file_too_large')); ?>"
                        data-i18n-upload-limit-exceeded="<?php p($l->t('upload_limit_exceeded')); ?>"
                        data-i18n-server-error-try-again-later="<?php p($l->t('server_error_try_again_later')); ?>"
                        data-i18n-an-error-occurred="<?php p($l->t('an_error_occurred')); ?>"
                        data-i18n-error="<?php p($l->t('error')); ?>"
                        data-i18n-creating="<?php p($l->t('creating')); ?>"
                        data-i18n-creating-uploading="<?php p($l->t('creating_ticket_uploading_files')); ?>"
                        data-i18n-creating-upload-notice="<?php p($l->t('creating_ticket_uploading_files_notice')); ?>"
                        data-i18n-success-redirect="<?php p($l->t('ticket_created_files_uploaded_redirecting')); ?>"
                        data-i18n-complete="<?php p($l->t('complete')); ?>"
                        data-i18n-error-with-recovery="<?php p($l->t('error_with_recovery')); ?>"
                        data-i18n-guest-no-projects="<?php p($l->t('guest_no_active_projects_message')); ?>"
                        data-i18n-rate-limit="<?php p($l->t('rate_limit_50_per_day')); ?>"
                        data-i18n-redirect-failed="<?php p($l->t('ticket_created_redirect_failed')); ?>"
                        data-i18n-project-search-placeholder="<?php p($l->t('project_search_placeholder')); ?>"
                        data-i18n-project-search-help="<?php p($l->t('project_search_help')); ?>"
                        data-i18n-project-search-results-count="<?php p($l->t('project_search_results_count')); ?>"
                        data-i18n-project-search-no-results="<?php p($l->t('project_search_no_results')); ?>"
                                data-i18n-select-project="<?php p($l->t('select_a_project')); ?>"
                                data-portal-project-mode="<?php p($portalProjectPickerMode); ?>">
                        <input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']) ?>">
                        <p id="portal-create-ticket-form-help" class="tc-ticket-form-required-hint"><?php p($l->t('ticket_form_required_hint')); ?></p>

                        <div id="ticket-form-errors" class="helpdesk-alert helpdesk-alert--error" role="alert" hidden>
                            <div class="helpdesk-alert__content">
                                <p class="helpdesk-alert__title"><?php p($l->t('please_check_required_fields')); ?></p>
                                <ul id="ticket-form-errors-list" class="helpdesk-form-errors-list"></ul>
                            </div>
                        </div>

                        <?php if ($hasProjects): ?>
                            <div class="helpdesk-card tc-ticket-form-card" role="region" aria-labelledby="portal-form-project-heading">
                                <div class="helpdesk-card__header">
                                    <h2 id="portal-form-project-heading" class="helpdesk-card__title"><?php p($l->t('project_information')); ?></h2>
                                </div>
                                <div class="helpdesk-card__body">
                                    <?php if ($portalProjectPickerMode === 'single'): ?>
                                        <?php
                                        $onlyProject = $activeProjects[0];
                                        $onlyProjectId = (int)$onlyProject['id'];
                                        ?>
                                        <input type="hidden" name="project_id" id="project_id" value="<?php p($onlyProjectId); ?>">
                                        <div class="helpdesk-alert helpdesk-alert--info portal-project-single" role="status" aria-labelledby="portal-single-project-label">
                                            <div class="helpdesk-alert__content">
                                                <p id="portal-single-project-label" class="helpdesk-alert__title"><?php p($l->t('portal_single_project_notice')); ?></p>
                                                <p class="helpdesk-alert__text portal-project-single__name">
                                                    <strong><?php p($onlyProject['name']); ?></strong>
                                                    <?php if (!empty($onlyProject['customer_name'])): ?>
                                                        <span class="helpdesk-text-muted"> — <?php p($onlyProject['customer_name']); ?></span>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                    <?php elseif ($portalProjectPickerMode === 'radio'): ?>
                                        <?php if (count($customerFilterOptions) > 1): ?>
                                            <div class="helpdesk-form-group tc-field">
                                                <label for="customer_filter" class="helpdesk-form-label"><?php p($l->t('filter_by_customer')); ?></label>
                                                <select id="customer_filter" class="helpdesk-form-control" aria-describedby="customer-filter-help">
                                                    <option value=""><?php p($l->t('all_customers')); ?></option>
                                                    <?php foreach ($customerFilterOptions as $cust): ?>
                                                        <option value="<?php p((string)$cust['id']); ?>"><?php p($cust['name'] !== '' ? $cust['name'] : $l->t('customer')); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <span id="customer-filter-help" class="helpdesk-form-help"><?php p($l->t('customer_filter_limits_projects')); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <fieldset class="portal-project-picker helpdesk-form-group tc-field" aria-describedby="portal-project-picker-help" data-portal-project-fieldset="true">
                                            <legend class="helpdesk-form-label helpdesk-form-label--required portal-project-picker__legend"><?php p($l->t('project')); ?></legend>
                                            <p id="portal-project-picker-help" class="helpdesk-form-help portal-project-picker__intro"><?php p($l->t('portal_project_picker_help')); ?></p>
                                            <div class="portal-project-picker__options" role="presentation">
                                                <?php foreach ($activeProjects as $project): ?>
                                                    <?php
                                                    $projectId = (int)$project['id'];
                                                    $isSelected = $defaultProjectId > 0 && $projectId === $defaultProjectId;
                                                    $inputId = 'portal-project-' . $projectId;
                                                    ?>
                                                    <div class="portal-project-picker__item"
                                                        data-customer-id="<?php p((string)($project['customer_id'] ?? '')); ?>">
                                                        <label for="<?php p($inputId); ?>" class="portal-project-picker__option">
                                                            <input type="radio"
                                                                name="project_id"
                                                                id="<?php p($inputId); ?>"
                                                                class="portal-project-picker__input"
                                                                value="<?php p($projectId); ?>"
                                                                required
                                                                aria-required="true"
                                                                <?php if ($isSelected) {
                                                                    print_unescaped(' checked');
                                                                } ?>>
                                                            <span class="portal-project-picker__card">
                                                                <span class="portal-project-picker__name"><?php p($project['name']); ?></span>
                                                                <?php if (!empty($project['customer_name'])): ?>
                                                                    <span class="portal-project-picker__meta helpdesk-text-muted"><?php p($project['customer_name']); ?></span>
                                                                <?php endif; ?>
                                                            </span>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </fieldset>
                                        <p class="helpdesk-form-help"><?php p($l->t('all_tickets_must_belong_project')); ?></p>
                                    <?php elseif ($portalProjectPickerMode === 'select'): ?>
                                        <?php if (count($customerFilterOptions) > 1): ?>
                                            <div class="helpdesk-form-group tc-field">
                                                <label for="customer_filter" class="helpdesk-form-label"><?php p($l->t('filter_by_customer')); ?></label>
                                                <select id="customer_filter" class="helpdesk-form-control" aria-describedby="customer-filter-help">
                                                    <option value=""><?php p($l->t('all_customers')); ?></option>
                                                    <?php foreach ($customerFilterOptions as $cust): ?>
                                                        <option value="<?php p((string)$cust['id']); ?>"><?php p($cust['name'] !== '' ? $cust['name'] : $l->t('customer')); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <span id="customer-filter-help" class="helpdesk-form-help"><?php p($l->t('customer_filter_limits_projects')); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="helpdesk-form-group tc-field">
                                            <label for="project_id" class="helpdesk-form-label helpdesk-form-label--required"><?php p($l->t('project')); ?></label>
                                            <select id="project_id"
                                                name="project_id"
                                                class="helpdesk-form-control portal-project-select"
                                                required
                                                aria-required="true"
                                                data-tc-required="1"
                                                aria-describedby="portal-project-select-help">
                                                <option value=""><?php p($l->t('select_a_project')); ?></option>
                                                <?php foreach ($activeProjects as $project): ?>
                                                    <?php
                                                    $projectId = (int)$project['id'];
                                                    $isSelected = $defaultProjectId > 0 && $projectId === $defaultProjectId;
                                                    ?>
                                                    <option value="<?php p($projectId); ?>"
                                                        data-customer-id="<?php p((string)($project['customer_id'] ?? '')); ?>"
                                                        <?php if ($isSelected) {
                                                            print_unescaped(' selected');
                                                        } ?>>
                                                        <?php p($project['name']); ?>
                                                        <?php if (!empty($project['customer_name'])): ?>
                                                            (<?php p($project['customer_name']); ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <span id="portal-project-select-help" class="helpdesk-form-help"><?php p($l->t('all_tickets_must_belong_project')); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="helpdesk-card tc-ticket-form-card" role="region" aria-labelledby="portal-form-details-heading">
                            <div class="helpdesk-card__header">
                                <h2 id="portal-form-details-heading" class="helpdesk-card__title"><?php p($l->t('ticket_details_section')); ?></h2>
                            </div>
                            <div class="helpdesk-card__body">
                                <div class="helpdesk-alert helpdesk-alert--info portal-create__intro" role="note">
                                    <div class="helpdesk-alert__content">
                                        <p class="helpdesk-alert__text"><?php p($l->t('portal_create_intro')); ?></p>
                                    </div>
                                </div>

                                <div class="helpdesk-form-group tc-field tc-field--full-width">
                                    <label for="title" class="helpdesk-form-label helpdesk-form-label--required"><?php p($l->t('title')); ?></label>
                                    <input type="text"
                                        id="title"
                                        name="title"
                                        class="helpdesk-form-control"
                                        required
                                        aria-required="true"
                                        data-tc-required="1"
                                        autocomplete="off"
                                        maxlength="255"
                                        placeholder="<?php p($l->t('short_problem_headline')); ?>">
                                    <span class="helpdesk-form-help"><?php p($l->t('clear_concise_summary')); ?></span>
                                </div>

                                <div class="helpdesk-form-group tc-field tc-field--full-width">
                                    <label for="description" class="helpdesk-form-label helpdesk-form-label--required"><?php p($l->t('description')); ?></label>
                                    <textarea id="description"
                                        name="description"
                                        rows="8"
                                        class="helpdesk-form-control"
                                        required
                                        aria-required="true"
                                        data-tc-required="1"
                                        placeholder="<?php p($l->t('detailed_information')); ?>"></textarea>
                                    <span class="helpdesk-form-help"><?php p($l->t('include_steps_to_reproduce')); ?></span>
                                </div>

                                <div class="tc-form-grid">
                                    <div class="helpdesk-form-group tc-field">
                                        <label for="category" class="helpdesk-form-label"><?php p($l->t('category')); ?></label>
                                        <select id="category" name="category" class="helpdesk-form-control">
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?php p($cat); ?>"><?php p($l->t($categoryLabelKeys[$cat] ?? ('category_' . $cat))); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="helpdesk-form-group tc-field">
                                        <label for="priority" class="helpdesk-form-label"><?php p($l->t('priority_label')); ?></label>
                                        <select id="priority" name="priority" class="helpdesk-form-control">
                                            <?php foreach ($priorities as $pr): ?>
                                                <option value="<?php p($pr); ?>" <?php if ($pr === 'normal') {
                                                    print_unescaped(' selected');
                                                } ?>><?php p($l->t('priority_' . $pr)); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="helpdesk-alert helpdesk-alert--info portal-create__tips" role="note" aria-labelledby="portal-create-checklist-title">
                                    <div class="helpdesk-alert__content">
                                        <h3 id="portal-create-checklist-title" class="helpdesk-alert__title portal-create__checklist-title"><?php p($l->t('before_you_submit')); ?></h3>
                                        <ul class="portal-create__checklist-list">
                                            <li><?php p($l->t('make_sure_explained_clearly')); ?></li>
                                            <li><?php p($l->t('include_error_messages')); ?></li>
                                            <li><?php p($l->t('tell_us_what_tried')); ?></li>
                                            <li><?php p($l->t('include_urls')); ?></li>
                                            <li><?php p($l->t('device_browser_info')); ?></li>
                                            <li><?php p($l->t('attach_screenshots')); ?></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="helpdesk-card tc-ticket-form-card" role="region" aria-labelledby="portal-form-attachments-heading">
                            <div class="helpdesk-card__header">
                                <h2 id="portal-form-attachments-heading" class="helpdesk-card__title"><?php p($l->t('attachments_optional')); ?></h2>
                            </div>
                            <div class="helpdesk-card__body">
                                <div class="helpdesk-form-group tc-field">
                                    <label class="helpdesk-form-label" id="portal-attachments-label" for="ticket-attachments"><?php p($l->t('upload_files')); ?></label>
                                    <div class="helpdesk-upload-dropzone"
                                        data-attachment-dropzone="portal-create-form"
                                        role="button"
                                        tabindex="0"
                                        aria-labelledby="portal-attachments-label"
                                        aria-describedby="ticket-attachments-help file-count-text">
                                        <?php print_unescaped(IconCatalog::render('upload', 'helpdesk-upload-dropzone__icon')); ?>
                                        <span class="helpdesk-upload-dropzone__title"><?php p($l->t('Click to choose files')); ?></span>
                                        <span class="helpdesk-upload-dropzone__subtitle" id="file-count-text" role="status" aria-live="polite"><?php p($l->t('or drag and drop here')); ?></span>
                                    </div>
                                    <input type="file"
                                        id="ticket-attachments"
                                        name="attachments[]"
                                        multiple
                                        accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.txt,.rtf,.zip,.7z"
                                        class="helpdesk-visually-hidden-file-input"
                                        tabindex="-1"
                                        aria-describedby="ticket-attachments-help">
                                    <span id="ticket-attachments-help" class="helpdesk-form-help"><?php p($l->t('attach_screenshots_documents')); ?></span>
                                    <div id="ticket-attachment-preview"></div>
                                </div>
                            </div>
                        </div>

                        <div id="portal-create-submit-status" class="portal-create-ticket__status helpdesk-mb-md" role="status" aria-live="polite" hidden></div>

                        <div class="helpdesk-form-actions ticket-form-actions portal-create__actions">
                            <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.index')); ?>"
                                class="helpdesk-btn helpdesk-btn--secondary ticket-form-actions__cancel">
                                <?php p($l->t('cancel')); ?>
                            </a>
                            <button type="submit" id="portal-create-submit" class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg ticket-form-actions__submit">
                                <span class="ticket-form-submit__inner">
                                    <?php print_unescaped(IconCatalog::render('save', 'ticket-form-submit__icon')); ?>
                                    <span class="ticket-form-submit__text"><?php p($l->t('create_ticket')); ?></span>
                                </span>
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>

<?php include __DIR__ . '/../common/page-end.php'; ?>

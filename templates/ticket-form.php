<?php

/**
 * Ticket form template - Create/Edit ticket
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

$_['isGuest'] = false;
$_['isAdmin'] = $_['isAdmin'] ?? false;
$isEdit = $_['isEdit'] ?? false;

/** @var \OCP\IL10N $l */
$l = $_['l'];

$projectsList = $_['projects'] ?? [];
$customerFilterOptions = [];
$seenCustomerIds = [];
foreach ($projectsList as $proj) {
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

$categoryLabelKeys = [
    'general' => 'general_category',
    'technical' => 'technical_issue',
    'bug' => 'bug_report',
    'billing' => 'billing',
    'feature' => 'feature_request',
];
?>

<?php include __DIR__ . '/common/page-start.php'; ?>


            <!-- Form -->
            <form id="ticket-form"
                method="POST"
                enctype="multipart/form-data"
                novalidate
                aria-describedby="ticket-form-required-hint"
                data-is-edit="<?php p($isEdit ? '1' : '0'); ?>"
                data-i18n-please-check-required-fields="<?php p($l->t('please_check_required_fields')); ?>"
                data-i18n-field-required="<?php p($l->t('field_required')); ?>"
                data-i18n-restore-draft-prompt="<?php p($l->t('restore_draft_prompt')); ?>"
                data-i18n-restore-draft-button="<?php p($l->t('restore_draft_button')); ?>"
                data-i18n-discard-draft-button="<?php p($l->t('discard_draft_button')); ?>"
                data-i18n-or-drag-drop-here="<?php p($l->t('or drag and drop here')); ?>"
                data-i18n-files-selected="<?php p($l->t('files_selected')); ?>"
                data-i18n-remove-attachment="<?php p($l->t('remove_attachment')); ?>"
                data-i18n-file-too-large="<?php p($l->t('file_too_large')); ?>"
                data-i18n-upload-limit-exceeded="<?php p($l->t('upload_limit_exceeded')); ?>"
                data-i18n-server-error-try-again-later="<?php p($l->t('server_error_try_again_later')); ?>"
                data-i18n-loading-users="<?php p($l->t('loading_users')); ?>"
                data-i18n-unassigned="<?php p($l->t('unassigned')); ?>"
                data-i18n-max-file-size="<?php p($l->t('max_file_size')); ?>"
                data-i18n-updating="<?php p($l->t('updating')); ?>"
                data-i18n-creating="<?php p($l->t('creating')); ?>"
                data-i18n-an-error-occurred="<?php p($l->t('an_error_occurred')); ?>"
                data-i18n-ticket-updated="<?php p($l->t('ticket_updated')); ?>"
                data-i18n-ticket-created="<?php p($l->t('ticket_created')); ?>"
                data-i18n-error="<?php p($l->t('error')); ?>"
                data-i18n-project-search-placeholder="<?php p($l->t('project_search_placeholder')); ?>"
                data-i18n-project-search-help="<?php p($l->t('project_search_help')); ?>"
                data-i18n-project-search-results-count="<?php p($l->t('project_search_results_count')); ?>"
                data-i18n-project-search-no-results="<?php p($l->t('project_search_no_results')); ?>"
                <?php if ($isEdit): ?>
                data-update-url="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.updatePost', ['id' => $_['ticket']->getId()])); ?>"
                data-ticket-id="<?php p($_['ticket']->getId()); ?>"
                <?php endif; ?>>
                <input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']) ?>">
                <p id="ticket-form-required-hint" class="tc-ticket-form-required-hint"><?php p($l->t('ticket_form_required_hint')); ?></p>

                <div id="ticket-form-errors" class="helpdesk-alert helpdesk-alert--error helpdesk-form-error-summary helpdesk-mb-md" role="alert" hidden>
                    <div class="helpdesk-alert__content">
                        <p class="helpdesk-alert__title"><?php p($l->t('please_check_required_fields')); ?></p>
                        <ul id="ticket-form-errors-list" class="helpdesk-form-errors-list"></ul>
                    </div>
                </div>

                <!-- Project Selection -->
                <?php if (!empty($_['projects'])): ?>
                    <div class="helpdesk-card helpdesk-mb-md tc-ticket-form-card" role="region" aria-labelledby="ticket-form-project-heading">
                        <div class="helpdesk-card__header">
                            <h2 id="ticket-form-project-heading" class="helpdesk-card__title"><?php p($l->t('project_information')); ?></h2>
                            <?php if (!$isEdit && !empty($_['isAdmin'])): ?>
                                <div class="helpdesk-card__actions">
                                    <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customer.create')); ?>" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm">
                                        <?php p($l->t('create_customer')); ?>
                                    </a>
                                    <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.project.create')); ?>" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm">
                                        <?php p($l->t('create_project')); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="helpdesk-card__body">
                            <?php if (count($customerFilterOptions) > 1): ?>
                                <div class="helpdesk-form-group tc-field helpdesk-mb-md">
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
                            <div class="helpdesk-form-group tc-field tc-select-combobox">
                                <label for="project-search-input" class="helpdesk-form-label helpdesk-form-label--required"><?php p($l->t('project')); ?></label>
                                <input type="text"
                                    id="project-search-input"
                                    class="helpdesk-form-control helpdesk-mb-sm"
                                    placeholder="<?php p($l->t('project_search_placeholder')); ?>"
                                    autocomplete="off"
                                    aria-required="true"
                                    aria-controls="project-search-results"
                                    aria-expanded="false"
                                    aria-autocomplete="list"
                                    aria-describedby="project-search-help project-search-status">
                                <span id="project-search-help" class="helpdesk-form-help"><?php p($l->t('project_search_help')); ?></span>
                                <span id="project-search-status" class="tc-select-combobox__status" role="status" aria-live="polite" hidden></span>
                                <select id="project_id" name="project_id" class="tc-select-combobox__native" tabindex="-1" aria-hidden="true" data-tc-required="1">
                                    <option value=""><?php p($l->t('select_a_project')); ?></option>
                                    <?php foreach ($_['projects'] as $project): ?>
                                        <option value="<?php p($project['id']); ?>"
                                            data-customer-id="<?php p((string)($project['customer_id'] ?? '')); ?>"
                                            data-customer-name="<?php p($project['customer_name'] ?? $l->t('no_customer')); ?>"
                                            data-customer-email="<?php p($project['customer_email'] ?? ''); ?>"
                                            <?php if ($_['ticket'] && $_['ticket']->getProjectId() == $project['id']) p('selected'); ?>>
                                            <?php p($project['name']); ?>
                                            <?php if (!empty($project['customer_name'])): ?>
                                                (<?php p($project['customer_name']); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="project-search-results" class="tc-select-combobox__results" role="listbox" aria-label="<?php p($l->t('project')); ?>" hidden></div>
                                <span class="helpdesk-form-help">
                                    <?php p($l->t('all_tickets_must_belong_project')); ?>
                                </span>
                            </div>

                            <!-- Customer Info Display -->
                            <div id="customer-info-display" class="tc-customer-preview" hidden>
                                <div class="helpdesk-alert helpdesk-alert--info">
                                    <div class="helpdesk-alert__icon">
                                        <?php print_unescaped(IconCatalog::render('user')); ?>
                                    </div>
                                    <div class="helpdesk-alert__content">
                                        <div class="helpdesk-alert__title"><?php p($l->t('customer')); ?></div>
                                        <div class="helpdesk-alert__text" id="customer-display-text"><?php p($l->t('select_project_to_see_customer')); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="helpdesk-empty">
                        <div class="helpdesk-empty__icon">
                            <?php print_unescaped(IconCatalog::render('clipboard')); ?>
                        </div>
                        <h3 class="helpdesk-empty__title"><?php p($l->t('no_projects_available_text')); ?></h3>
                        <p class="helpdesk-empty__text"><?php p($l->t('create_project_before_ticket')); ?></p>
                        <?php if (!empty($_['isAdmin'])): ?>
                            <div class="helpdesk-form-actions helpdesk-form-actions--start">
                                <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customer.create')); ?>" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--lg">
                                    <?php p($l->t('create_customer')); ?>
                                </a>
                                <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.project.create')); ?>" class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg">
                                    <?php p($l->t('create_project')); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Ticket Details -->
                <div class="helpdesk-card helpdesk-mb-md tc-ticket-form-card" role="region" aria-labelledby="ticket-form-details-heading">
                    <div class="helpdesk-card__header">
                        <h2 id="ticket-form-details-heading" class="helpdesk-card__title"><?php p($l->t('ticket_details_section')); ?></h2>
                    </div>
                    <div class="helpdesk-card__body">
                        <div class="helpdesk-form-group tc-field tc-field--full-width">
                            <label for="title" class="helpdesk-form-label helpdesk-form-label--required">
                                <?php p($l->t('title')); ?>
                            </label>
                            <input type="text"
                                id="title"
                                name="title"
                                aria-required="true"
                                data-tc-required="1"
                                value="<?php p($_['ticket'] ? $_['ticket']->getTitle() : ''); ?>"
                                placeholder="<?php p($l->t('short_problem_headline')); ?>"
                                class="helpdesk-form-control">
                            <span class="helpdesk-form-help">
                                <?php p($l->t('clear_concise_summary')); ?>
                            </span>
                        </div>

                        <div class="helpdesk-form-group tc-field tc-field--full-width">
                            <label for="description" class="helpdesk-form-label helpdesk-form-label--required"><?php p($l->t('description')); ?></label>
                            <textarea id="description"
                                name="description"
                                rows="8"
                                aria-required="true"
                                data-tc-required="1"
                                placeholder="<?php p($l->t('detailed_information')); ?>"
                                class="helpdesk-form-control"><?php p($_['ticket'] ? $_['ticket']->getDescription() : ''); ?></textarea>
                            <span class="helpdesk-form-help">
                                <?php p($l->t('include_steps_to_reproduce')); ?>
                            </span>
                        </div>

                        <div class="tc-form-grid">
                            <div class="helpdesk-form-group tc-field">
                                <label for="category" class="helpdesk-form-label"><?php p($l->t('category')); ?></label>
                                <select id="category" name="category" class="helpdesk-form-control">
                                    <?php foreach (($_['categories'] ?? []) as $cat): ?>
                                        <option value="<?php p($cat); ?>" <?php if ($_['ticket'] && $_['ticket']->getCategory() === $cat) p('selected'); ?>>
                                            <?php p($l->t($categoryLabelKeys[$cat] ?? ('category_' . $cat))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="helpdesk-form-group tc-field">
                                <label for="priority" class="helpdesk-form-label"><?php p($l->t('priority_label')); ?></label>
                                <select id="priority" name="priority" class="helpdesk-form-control">
                                    <?php foreach (($_['priorities'] ?? []) as $pr): ?>
                                        <option value="<?php p($pr); ?>" <?php if (($_['ticket'] && $_['ticket']->getPriority() === $pr) || (!$_['ticket'] && $pr === 'normal')) p('selected'); ?>><?php p($l->t('priority_' . $pr)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="tc-form-grid">
                            <div class="helpdesk-form-group tc-field">
                                <label for="status" class="helpdesk-form-label"><?php p($l->t('status')); ?></label>
                                <select id="status" name="status" class="helpdesk-form-control">
                                    <option value="new" <?php if (!$_['ticket'] || $_['ticket']->getStatus() === 'new') p('selected'); ?>><?php p($l->t('new_status')); ?></option>
                                    <option value="in_progress" <?php if ($_['ticket'] && ($_['ticket']->getStatus() === 'in_progress' || $_['ticket']->getStatus() === 'working')) p('selected'); ?>><?php p($l->t('in_progress_status')); ?></option>
                                    <option value="waiting" <?php if ($_['ticket'] && $_['ticket']->getStatus() === 'waiting') p('selected'); ?>><?php p($l->t('waiting_status')); ?></option>
                                    <option value="done" <?php if ($_['ticket'] && $_['ticket']->getStatus() === 'done') p('selected'); ?>><?php p($l->t('done_status')); ?></option>
                                </select>
                            </div>

                            <div class="helpdesk-form-group tc-field">
                                <label for="assigned_to" class="helpdesk-form-label"><?php p($l->t('assign_to')); ?></label>
                                <select id="assigned_to" name="assigned_to" class="helpdesk-form-control">
                                    <option value="">-- <?php p($l->t('unassigned')); ?> --</option>
                                    <?php if (!empty($_['availableAgents'])): ?>
                                        <?php foreach ($_['availableAgents'] as $agent): ?>
                                            <option value="<?php p($agent['user_id']); ?>"
                                                <?php if ($_['ticket'] && $_['ticket']->getAssignedTo() === $agent['user_id']) p('selected'); ?>>
                                                <?php p($agent['user_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- File Attachments Section -->
                <div class="helpdesk-card helpdesk-mb-md tc-ticket-form-card" role="region" aria-labelledby="ticket-form-attachments-heading">
                    <div class="helpdesk-card__header">
                        <h2 id="ticket-form-attachments-heading" class="helpdesk-card__title"><?php p($isEdit ? $l->t('manage_attachments') : $l->t('attachments_optional')); ?></h2>
                    </div>
                    <div class="helpdesk-card__body">

                        <?php if ($isEdit && !empty($_['attachments'])): ?>
                            <!-- Existing Attachments -->
                            <div class="helpdesk-form-group tc-field tc-ticket-form-existing-wrap">
                                <span class="helpdesk-form-label" id="existing-attachments-label"><?php p($l->t('existing_attachments')); ?></span>
                                <div id="existing-attachments-list" class="tc-ticket-form-existing-list" role="list" aria-labelledby="existing-attachments-label">
                                    <?php foreach ($_['attachments'] as $attachment):
                                        $attUrls = \OCA\Ticketcheck\Service\AttachmentDisplayHelper::buildUrls(
                                            $_['urlGenerator'],
                                            'ticketcheck.ticket.downloadAttachment',
                                            ['ticketId' => $_['ticket']->getId(), 'attachmentId' => $attachment->getId()],
                                            $attachment,
                                        );
                                        $downloadUrl = $attUrls['download'];
                                        $previewUrl = $attUrls['preview'];
                                        ?>
                                        <div class="helpdesk-attachment-item tc-ticket-form-existing-row" data-attachment-id="<?php p($attachment->getId()); ?>" role="listitem">
                                            <div class="tc-ticket-form-existing-row-main">
                                                <?php if ($previewUrl !== null): ?>
                                                    <button type="button"
                                                        class="tc-ticket-form-existing-thumb-btn tc-attachment-preview-trigger"
                                                        data-tc-attachment-preview
                                                        data-preview-url="<?php p($previewUrl); ?>"
                                                        data-download-url="<?php p($downloadUrl); ?>"
                                                        data-filename="<?php p($attachment->getFileName()); ?>"
                                                        aria-label="<?php p(\OCA\Ticketcheck\Service\AttachmentDisplayHelper::previewAttachmentAriaLabel($l, $attachment->getFileName())); ?>">
                                                        <img src="<?php p($previewUrl); ?>"
                                                            alt="<?php p(\OCA\Ticketcheck\Service\AttachmentDisplayHelper::imageThumbnailAlt($l, $attachment->getFileName())); ?>"
                                                            class="tc-ticket-form-existing-thumb"
                                                            loading="lazy"
                                                            decoding="async" />
                                                    </button>
                                                <?php else: ?>
                                                    <?php print_unescaped(IconCatalog::render('file', 'tc-ticket-form-existing-icon')); ?>
                                                <?php endif; ?>
                                                <div class="tc-ticket-form-existing-meta">
                                                    <div class="tc-ticket-form-existing-name" title="<?php p($attachment->getFileName()); ?>">
                                                        <?php p($attachment->getFileName()); ?>
                                                    </div>
                                                    <div class="helpdesk-text-muted tc-ticket-form-existing-size">
                                                        <?php p($attachment->getFormattedFileSize()); ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="tc-ticket-form-existing-actions">
                                                <?php if ($previewUrl !== null): ?>
                                                    <button type="button"
                                                        class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm tc-attachment-preview-trigger"
                                                        data-tc-attachment-preview
                                                        data-preview-url="<?php p($previewUrl); ?>"
                                                        data-download-url="<?php p($downloadUrl); ?>"
                                                        data-filename="<?php p($attachment->getFileName()); ?>"
                                                        aria-label="<?php p(\OCA\Ticketcheck\Service\AttachmentDisplayHelper::previewAttachmentAriaLabel($l, $attachment->getFileName())); ?>">
                                                        <?php print_unescaped(IconCatalog::render('image', 'tc-ticket-form-existing-action-icon')); ?>
                                                    </button>
                                                <?php endif; ?>
                                                <a href="<?php p($downloadUrl); ?>"
                                                    class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm"
                                                    download
                                                    aria-label="<?php p(strtr($l->t('download_attachment_aria'), ['{filename}' => $attachment->getFileName()])); ?>">
                                                    <?php print_unescaped(IconCatalog::render('download', 'tc-ticket-form-existing-action-icon')); ?>
                                                </a>
                                                <button type="button"
                                                    class="helpdesk-btn helpdesk-btn--danger helpdesk-btn--sm delete-attachment-btn"
                                                    data-attachment-id="<?php p($attachment->getId()); ?>"
                                                    data-ticket-id="<?php p($_['ticket']->getId()); ?>"
                                                    aria-label="<?php p($l->t('delete')); ?>">
                                                    <?php print_unescaped(IconCatalog::render('trash-2', 'tc-ticket-form-existing-action-icon')); ?>
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Upload New Files -->
                        <div class="helpdesk-form-group tc-field">
                            <label class="helpdesk-form-label" id="ticket-attachments-heading" for="ticket-attachments">
                                <?php p($isEdit ? $l->t('add_more_files') : $l->t('upload_files')); ?>
                            </label>
                            <div class="helpdesk-upload-dropzone"
                                data-attachment-dropzone="ticket-form"
                                role="button"
                                tabindex="0"
                                aria-labelledby="ticket-attachments-heading"
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
                            <div id="ticket-attachment-preview" class="helpdesk-mt-md"></div>
                        </div>
                    </div>
                </div>
                <!-- Submit Actions -->
                <div class="helpdesk-form-actions ticket-form-actions">
                    <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.index')); ?>"
                        class="helpdesk-btn helpdesk-btn--secondary ticket-form-actions__cancel">
                        <?php p($l->t('cancel')); ?>
                    </a>
                    <button type="submit" id="ticket-form-submit" class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg ticket-form-actions__submit">
                        <span class="ticket-form-submit__inner">
                            <?php print_unescaped(IconCatalog::render('save', 'ticket-form-submit__icon')); ?>
                            <span class="ticket-form-submit__text"><?php p($isEdit ? $l->t('update_ticket') : $l->t('create_ticket')); ?></span>
                        </span>
                    </button>
                </div>
            </form>
<?php include __DIR__ . '/common/page-end.php'; ?>

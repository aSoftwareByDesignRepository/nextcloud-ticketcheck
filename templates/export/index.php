<?php

/**
 * Admin CSV export — choose data type, scope, and columns.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

$_['isGuest'] = false;
$_['isAdmin'] = $_['isAdmin'] ?? false;

/** @var \OCP\IL10N $l */
$l = $_['l'];
$projects = is_array($_['projects'] ?? null) ? $_['projects'] : [];
$customers = is_array($_['customers'] ?? null) ? $_['customers'] : [];

$exportProjectItems = [];
foreach ($projects as $project) {
    $pid = (int)($project['id'] ?? 0);
    if ($pid <= 0) {
        continue;
    }
    $exportProjectItems[] = [
        'id' => $pid,
        'name' => (string)($project['name'] ?? ''),
        'subtitle' => (string)($project['customer_name'] ?? ''),
    ];
}

$currentUserId = (string)($_['currentUserId'] ?? '');

/** @var \OCA\Ticketcheck\Service\LocaleFormatService|null $localeFormat */
$localeFormat = $_['localeFormat'] ?? null;
$exportDateFilterHelp = '';
if ($localeFormat instanceof \OCA\Ticketcheck\Service\LocaleFormatService) {
    $exportDateFilterHelp = strtr($l->t('export_date_filter_help'), [
        '{pattern}' => $localeFormat->calendarDayPatternHint(),
        '{timezone}' => $localeFormat->timezone()->getName(),
    ]);
}

$exportCustomerItems = [];
foreach ($customers as $customer) {
    $cid = (int)($customer['id'] ?? 0);
    if ($cid <= 0) {
        continue;
    }
    $exportCustomerItems[] = [
        'id' => $cid,
        'name' => (string)($customer['name'] ?? ''),
    ];
}
?>

<?php include __DIR__ . '/../common/page-start.php'; ?>

<div id="tc-export-page" class="tc-export" data-tc-export-root>

    <nav class="tc-export__related" aria-label="<?php p($l->t('export_related_links')); ?>">
        <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.settings.index')); ?>" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm">
            <?php p($l->t('export_back_to_settings')); ?>
        </a>
        <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.index')); ?>" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm">
            <?php p($l->t('tickets')); ?>
        </a>
    </nav>

    <div class="tc-export__prefill-notice" data-tc-export-prefill-notice hidden aria-hidden="true" role="status" aria-live="polite">
        <p class="tc-export__prefill-notice-text" data-tc-export-prefill-text></p>
    </div>

    <ol class="tc-export__steps">
        <li class="tc-export__step">
            <section class="tc-export__step-card" aria-labelledby="export-step-type-title">
                <header class="tc-export__step-header">
                    <span class="tc-export__step-badge" aria-hidden="true">1</span>
                    <div class="tc-export__step-heading">
                        <h2 id="export-step-type-title" class="tc-export__step-title"><?php p($l->t('export_section_type')); ?></h2>
                        <p class="tc-export__step-lead"><?php p($l->t('export_section_type_lead')); ?></p>
                    </div>
                </header>
                <div class="tc-export__type-switch" role="tablist" aria-label="<?php p($l->t('export_section_type')); ?>">
                    <button type="button"
                        class="tc-export__type-btn is-active"
                        role="tab"
                        id="export-tab-tickets"
                        aria-selected="true"
                        aria-controls="export-panel-tickets"
                        data-tc-export-entity="tickets">
                        <span class="tc-export__type-icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('ticket')); ?></span>
                        <span class="tc-export__type-label"><?php p($l->t('tickets')); ?></span>
                    </button>
                    <button type="button"
                        class="tc-export__type-btn"
                        role="tab"
                        id="export-tab-projects"
                        aria-selected="false"
                        aria-controls="export-panel-projects"
                        data-tc-export-entity="projects">
                        <span class="tc-export__type-icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('folder')); ?></span>
                        <span class="tc-export__type-label"><?php p($l->t('projects')); ?></span>
                    </button>
                </div>
            </section>
        </li>

        <li class="tc-export__step">
            <section class="tc-export__step-card" aria-labelledby="export-step-scope-title">
                <header class="tc-export__step-header">
                    <span class="tc-export__step-badge" aria-hidden="true">2</span>
                    <div class="tc-export__step-heading">
                        <h2 id="export-step-scope-title" class="tc-export__step-title"><?php p($l->t('export_section_scope')); ?></h2>
                        <p class="tc-export__step-lead"><?php p($l->t('export_section_scope_lead')); ?></p>
                    </div>
                </header>

                <div class="tc-export__scope-options" role="radiogroup" aria-labelledby="export-step-scope-title">
                    <label class="tc-export__scope-option">
                        <input type="radio" name="tc-export-scope" value="all" checked data-tc-export-scope>
                        <span class="tc-export__scope-option-text">
                            <strong><?php p($l->t('export_scope_all')); ?></strong>
                            <span data-tc-export-scope-hint="all"><?php p($l->t('export_scope_all_hint')); ?></span>
                        </span>
                    </label>
                    <label class="tc-export__scope-option">
                        <input type="radio" name="tc-export-scope" value="filtered" data-tc-export-scope>
                        <span class="tc-export__scope-option-text">
                            <strong><?php p($l->t('export_scope_filtered')); ?></strong>
                            <span data-tc-export-scope-hint="filtered"><?php p($l->t('export_scope_filtered_hint')); ?></span>
                        </span>
                    </label>
                    <label class="tc-export__scope-option">
                        <input type="radio" name="tc-export-scope" value="selected" data-tc-export-scope>
                        <span class="tc-export__scope-option-text">
                            <strong><?php p($l->t('export_scope_selected')); ?></strong>
                            <span data-tc-export-scope-hint="selected"><?php p($l->t('export_scope_selected_hint')); ?></span>
                        </span>
                    </label>
                </div>

                <div class="tc-export__scope-panel" data-tc-export-filters-panel hidden aria-hidden="true">
                    <h3 class="tc-export__scope-panel-title"><?php p($l->t('export_filters_panel_title')); ?></h3>
                    <div class="tc-export__filters-grid" data-tc-export-filters-tickets>
                        <?php
                        $type = 'search';
                        $id = 'export-filter-search';
                        $labelText = $l->t('search');
                        $helpText = $l->t('export_search_help');
                        $filterKey = 'search';
                        $fullWidth = false;
                        include __DIR__ . '/filter-field.php';

                        $type = 'select';
                        $id = 'export-filter-status';
                        $labelText = $l->t('status');
                        $helpText = '';
                        $filterKey = 'status';
                        $options = [['value' => '', 'label' => $l->t('all_statuses')]];
                        include __DIR__ . '/filter-field.php';

                        $type = 'select';
                        $id = 'export-filter-priority';
                        $labelText = $l->t('priority');
                        $helpText = '';
                        $filterKey = 'priority';
                        $options = [['value' => '', 'label' => $l->t('all_priorities')]];
                        include __DIR__ . '/filter-field.php';

                        $type = 'select';
                        $id = 'export-filter-category';
                        $labelText = $l->t('category');
                        $helpText = '';
                        $filterKey = 'category';
                        $options = [['value' => '', 'label' => $l->t('all_categories')]];
                        include __DIR__ . '/filter-field.php';
                        ?>
                        <?php include __DIR__ . '/filter-assignee-picker.php'; ?>
                        <?php
                        $pickerId = 'export-filter-project';
                        $filterKey = 'project_id';
                        $labelText = $l->t('project');
                        $helpText = $l->t('export_project_filter_help');
                        $emptyLabel = $l->t('all_projects');
                        $apiType = 'project';
                        $items = $exportProjectItems;
                        include __DIR__ . '/filter-entity-single.php';

                        $pickerId = 'export-filter-customer';
                        $filterKey = 'customer_id';
                        $labelText = $l->t('Customer');
                        $helpText = $l->t('export_customer_filter_help');
                        $emptyLabel = $l->t('all_customers');
                        $apiType = 'customer';
                        $items = $exportCustomerItems;
                        include __DIR__ . '/filter-entity-single.php';

                        $type = 'date';
                        $id = 'export-filter-date-from';
                        $labelText = $l->t('export_date_from');
                        $helpText = '';
                        $filterKey = 'date_from';
                        $ariaDescribedById = $exportDateFilterHelp !== '' ? 'export-filter-date-to-hint' : '';
                        include __DIR__ . '/filter-field.php';
                        unset($ariaDescribedById);

                        $type = 'date';
                        $id = 'export-filter-date-to';
                        $labelText = $l->t('export_date_to');
                        $helpText = $exportDateFilterHelp;
                        $filterKey = 'date_to';
                        include __DIR__ . '/filter-field.php';
                        ?>
                        <label class="tc-export-field tc-export-field--checkbox tc-export-field--full">
                            <input type="checkbox" id="export-filter-include-merged" data-tc-export-filter="include_merged">
                            <span class="tc-export-field__checkbox-text"><?php p($l->t('export_include_merged_tickets')); ?></span>
                        </label>
                    </div>

                    <div class="tc-export__filters-grid" data-tc-export-filters-projects hidden aria-hidden="true">
                        <?php
                        $type = 'search';
                        $id = 'export-filter-project-search';
                        $labelText = $l->t('search');
                        $helpText = $l->t('export_project_search_help');
                        $filterKey = 'search';
                        include __DIR__ . '/filter-field.php';
                        ?>
                        <?php
                        $pickerId = 'export-filter-project-customer';
                        $filterKey = 'customer_id';
                        $labelText = $l->t('Customer');
                        $helpText = $l->t('export_project_customer_help');
                        $emptyLabel = $l->t('all_customers');
                        $apiType = 'customer';
                        $items = $exportCustomerItems;
                        include __DIR__ . '/filter-entity-single.php';
                        ?>
                        <label class="tc-export-field tc-export-field--checkbox tc-export-field--full">
                            <input type="checkbox" id="export-filter-include-inactive" data-tc-export-filter="include_inactive">
                            <span class="tc-export-field__checkbox-text"><?php p($l->t('show_inactive')); ?></span>
                        </label>
                    </div>
                </div>

                <div class="tc-export__scope-panel" data-tc-export-selection-panel hidden aria-hidden="true">
                    <h3 class="tc-export__scope-panel-title" data-tc-export-selection-label><?php p($l->t('export_selected_ticket_ids')); ?></h3>
                    <div class="helpdesk-form-group tc-field tc-field--full-width">
                        <label for="export-selected-ids" class="helpdesk-form-label tc-sr-only"><?php p($l->t('export_selected_ticket_ids')); ?></label>
                        <textarea id="export-selected-ids"
                            class="helpdesk-form-control tc-export__ids-input"
                            rows="4"
                            data-tc-export-selected-ids
                            aria-describedby="export-selected-ids-hint export-selected-ids-error"></textarea>
                        <span id="export-selected-ids-hint" class="helpdesk-form-help"><?php p($l->t('export_selected_ids_hint')); ?></span>
                        <p id="export-selected-ids-error" class="tc-export__field-error" data-tc-export-selection-error hidden role="alert"></p>
                    </div>
                </div>
            </section>
        </li>

        <li class="tc-export__step">
            <section class="tc-export__step-card" aria-labelledby="export-step-columns-title">
                <header class="tc-export__step-header">
                    <span class="tc-export__step-badge" aria-hidden="true">3</span>
                    <div class="tc-export__step-heading">
                        <h2 id="export-step-columns-title" class="tc-export__step-title"><?php p($l->t('export_section_columns')); ?></h2>
                        <p class="tc-export__step-lead"><?php p($l->t('export_section_columns_lead')); ?></p>
                    </div>
                </header>

                <div class="tc-export__columns-toolbar">
                    <div class="tc-export-field tc-export-field--inline">
                        <label for="export-column-preset" class="tc-sr-only"><?php p($l->t('export_column_preset')); ?></label>
                        <div class="tc-export-field__control">
                            <div class="tc-export-field__shell tc-export-field__shell--select">
                                <select id="export-column-preset" class="tc-export-field__select" data-tc-export-preset aria-label="<?php p($l->t('export_column_preset')); ?>">
                                    <option value=""><?php p($l->t('export_column_preset_custom')); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="tc-export__column-actions">
                        <button type="button" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm" data-tc-export-select-all><?php p($l->t('export_select_all_columns')); ?></button>
                        <button type="button" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm" data-tc-export-deselect-all><?php p($l->t('export_deselect_all_columns')); ?></button>
                    </div>
                </div>

                <fieldset class="tc-fieldset tc-export__columns-fieldset">
                    <legend class="tc-fieldset__legend tc-sr-only"><?php p($l->t('export_available_columns')); ?></legend>
                    <div class="tc-export__columns-grid" data-tc-export-columns></div>
                </fieldset>
            </section>
        </li>

        <li class="tc-export__step tc-export__step--summary">
            <section class="tc-export__step-card tc-export__summary" aria-labelledby="export-step-summary-title">
                <header class="tc-export__step-header">
                    <span class="tc-export__step-badge" aria-hidden="true">4</span>
                    <div class="tc-export__step-heading">
                        <h2 id="export-step-summary-title" class="tc-export__step-title"><?php p($l->t('export_section_summary')); ?></h2>
                    </div>
                </header>
                <p class="tc-export__summary-count" role="status" aria-live="polite" aria-atomic="true" data-tc-export-count>
                    <?php p($l->t('export_preview_loading')); ?>
                </p>
                <p class="tc-export__summary-note" data-tc-export-limit-note hidden></p>
                <div class="tc-export__actions">
                    <button type="button" class="helpdesk-btn helpdesk-btn--primary" data-tc-export-submit>
                        <span class="tc-export__action-icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('download')); ?></span>
                        <?php p($l->t('export_download_csv')); ?>
                    </button>
                </div>
            </section>
        </li>
    </ol>

</div>

<?php include __DIR__ . '/../common/page-end.php'; ?>

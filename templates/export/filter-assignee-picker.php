<?php

/**
 * Searchable assignee filter for export.
 *
 * @var \OCP\IL10N $l
 * @var string $currentUserId
 */

use OCA\Ticketcheck\Service\IconCatalog;

$currentUserId = (string)($currentUserId ?? '');
$hintId = 'export-filter-assigned-to-hint';
$statusId = 'export-filter-assigned-to-status';
?>
<div class="tc-export-field tc-export-field--picker tc-export-assignee-picker"
    data-tc-export-assignee-picker
    <?php if ($currentUserId !== ''): ?>
    data-current-user-id="<?php p($currentUserId); ?>"
    <?php endif; ?>>
    <label for="export-filter-assigned-to-search" class="tc-export-field__label"><?php p($l->t('assigned_to')); ?></label>
    <select id="export-filter-assigned-to"
        class="tc-export-assignee-picker__native"
        data-tc-export-filter="assigned_to"
        tabindex="-1"
        aria-hidden="true">
        <option value=""><?php p($l->t('anyone')); ?></option>
        <option value="unassigned"><?php p($l->t('unassigned')); ?></option>
        <?php if ($currentUserId !== ''): ?>
            <option value="<?php p($currentUserId); ?>"><?php p($l->t('me')); ?></option>
        <?php endif; ?>
    </select>
    <div class="tc-export-field__control">
        <div class="tc-export-field__shell tc-export-field__shell--with-icon tc-export-field__shell--combo">
            <span class="tc-export-field__icon" aria-hidden="true">
                <?php print_unescaped(IconCatalog::render('user', 'tc-export-field__icon-svg')); ?>
            </span>
            <input type="search"
                id="export-filter-assigned-to-search"
                class="tc-export-field__input"
                data-tc-export-assignee-search
                autocomplete="off"
                role="combobox"
                aria-autocomplete="list"
                aria-controls="export-filter-assigned-to-results"
                aria-expanded="false"
                aria-label="<?php p($l->t('assigned_to')); ?>"
                aria-describedby="<?php p($hintId); ?> <?php p($statusId); ?>">
            <button type="button"
                class="tc-export-field__clear"
                data-tc-combobox-clear
                hidden
                aria-label="<?php p($l->t('reset_filter')); ?>">
                <?php print_unescaped(IconCatalog::render('x', 'tc-export-field__icon-svg')); ?>
            </button>
        </div>
        <div id="export-filter-assigned-to-results"
            class="tc-export-field__results"
            data-tc-export-assignee-results
            role="listbox"
            aria-label="<?php p($l->t('assigned_to')); ?>"
            hidden></div>
    </div>
    <p id="<?php p($statusId); ?>"
        class="tc-export-field__status"
        data-tc-export-assignee-status
        role="status"
        aria-live="polite"
        aria-atomic="true"
        hidden></p>
    <p id="<?php p($hintId); ?>" class="tc-export-field__help"><?php p($l->t('export_assigned_to_help')); ?></p>
</div>

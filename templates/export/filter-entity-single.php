<?php

/**
 * Searchable single-select for export filters (project, customer).
 *
 * @var \OCP\IL10N $l
 * @var string $pickerId
 * @var string $filterKey project_id|customer_id
 * @var string $labelText
 * @var string $helpText
 * @var string $emptyLabel
 * @var string $apiType project|customer
 * @var list<array{id:int,name:string,subtitle?:string}> $items
 */

use OCA\Ticketcheck\Service\IconCatalog;

$pickerId = (string)($pickerId ?? '');
$filterKey = (string)($filterKey ?? '');
$labelText = (string)($labelText ?? '');
$helpText = (string)($helpText ?? '');
$emptyLabel = (string)($emptyLabel ?? '');
$apiType = (string)($apiType ?? '');
$items = is_array($items ?? null) ? $items : [];

if ($pickerId === '' || $filterKey === '' || $apiType === '') {
    return;
}

$hintId = $pickerId . '-hint';
$statusId = $pickerId . '-status';
?>
<div id="<?php p($pickerId); ?>"
    class="tc-export-field tc-export-field--picker tc-export-entity-picker"
    data-tc-export-entity-picker
    data-api-type="<?php p($apiType); ?>"
    data-empty-label="<?php p($emptyLabel); ?>">
    <label for="<?php p($pickerId); ?>-search" class="tc-export-field__label"><?php p($labelText); ?></label>
    <select id="<?php p($pickerId); ?>-native"
        class="tc-export-entity-picker__native"
        data-tc-export-filter="<?php p($filterKey); ?>"
        tabindex="-1"
        aria-hidden="true">
        <option value=""><?php p($emptyLabel); ?></option>
        <?php foreach ($items as $item): ?>
            <?php
            $itemId = (int)($item['id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }
            $name = (string)($item['name'] ?? '');
            $subtitle = (string)($item['subtitle'] ?? '');
            $optionLabel = $subtitle !== '' ? $name . ' — ' . $subtitle : $name;
            ?>
            <option value="<?php p((string)$itemId); ?>"><?php p($optionLabel); ?></option>
        <?php endforeach; ?>
    </select>
    <div class="tc-export-field__control">
        <div class="tc-export-field__shell tc-export-field__shell--with-icon tc-export-field__shell--combo">
            <span class="tc-export-field__icon" aria-hidden="true">
                <?php print_unescaped(IconCatalog::render('search', 'tc-export-field__icon-svg')); ?>
            </span>
            <input type="search"
                id="<?php p($pickerId); ?>-search"
                class="tc-export-field__input"
                data-tc-export-entity-search
                autocomplete="off"
                role="combobox"
                aria-autocomplete="list"
                aria-controls="<?php p($pickerId); ?>-results"
                aria-expanded="false"
                aria-label="<?php p($labelText); ?>"
                aria-describedby="<?php p($hintId); ?> <?php p($statusId); ?>">
            <button type="button"
                class="tc-export-field__clear"
                data-tc-combobox-clear
                hidden
                aria-label="<?php p($l->t('reset_filter')); ?>">
                <?php print_unescaped(IconCatalog::render('x', 'tc-export-field__icon-svg')); ?>
            </button>
        </div>
        <div id="<?php p($pickerId); ?>-results"
            class="tc-export-field__results"
            data-tc-export-entity-results
            role="listbox"
            aria-label="<?php p($labelText); ?>"
            hidden></div>
    </div>
    <p id="<?php p($statusId); ?>"
        class="tc-export-field__status"
        data-tc-export-entity-status
        role="status"
        aria-live="polite"
        aria-atomic="true"
        hidden></p>
    <?php if ($helpText !== ''): ?>
        <p id="<?php p($hintId); ?>" class="tc-export-field__help"><?php p($helpText); ?></p>
    <?php endif; ?>
</div>

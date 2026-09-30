<?php

/**
 * Unified export filter field (text, search, select, date).
 *
 * @var \OCP\IL10N $l
 * @var string $type text|search|select|date
 * @var string $id field id (also used for hint id suffix)
 * @var string $labelText
 * @var string $helpText
 * @var string $filterKey data-tc-export-filter value (optional)
 * @var bool $fullWidth span full grid width
 * @var string $ariaDescribedById optional aria-describedby when help text is on another field
 * @var list<array{value:string,label:string}> $options for select only
 */

use OCA\Ticketcheck\Service\IconCatalog;

$type = (string)($type ?? 'text');
$id = (string)($id ?? '');
$labelText = (string)($labelText ?? '');
$helpText = (string)($helpText ?? '');
$filterKey = (string)($filterKey ?? '');
$fullWidth = !empty($fullWidth);
$options = is_array($options ?? null) ? $options : [];

if ($id === '' || $labelText === '') {
    return;
}

$hintId = $id . '-hint';
$ariaDescribedBy = (string)($ariaDescribedById ?? '');
if ($ariaDescribedBy === '' && $helpText !== '') {
    $ariaDescribedBy = $hintId;
}
$fieldClass = 'tc-export-field' . ($fullWidth ? ' tc-export-field--full' : '');
$iconName = match ($type) {
    'search' => 'search',
    'date' => '',
    default => '',
};
$inputType = match ($type) {
    'search' => 'search',
    'date' => 'date',
    default => 'text',
};
?>
<div class="<?php p($fieldClass); ?>">
    <label for="<?php p($id); ?>" class="tc-export-field__label"><?php p($labelText); ?></label>
    <div class="tc-export-field__control">
        <?php if ($type === 'select'): ?>
            <div class="tc-export-field__shell tc-export-field__shell--select">
                <select id="<?php p($id); ?>"
                    class="tc-export-field__select"
                    <?php if ($filterKey !== ''): ?>data-tc-export-filter="<?php p($filterKey); ?>"<?php endif; ?>
                    aria-describedby="<?php p($hintId); ?>">
                    <?php foreach ($options as $option): ?>
                        <option value="<?php p((string)($option['value'] ?? '')); ?>"><?php p((string)($option['label'] ?? '')); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <?php
            $shellClass = 'tc-export-field__shell';
            if ($iconName !== '') {
                $shellClass .= ' tc-export-field__shell--with-icon';
            }
            if ($type === 'date') {
                $shellClass .= ' tc-export-field__shell--date';
            }
            ?>
            <div class="<?php p($shellClass); ?>">
                <?php if ($iconName !== ''): ?>
                    <span class="tc-export-field__icon" aria-hidden="true">
                        <?php print_unescaped(IconCatalog::render($iconName, 'tc-export-field__icon-svg')); ?>
                    </span>
                <?php endif; ?>
                <input type="<?php p($inputType); ?>"
                    id="<?php p($id); ?>"
                    class="tc-export-field__input<?php p($type === 'date' ? ' tc-export-field__input--date-picker' : ''); ?>"
                    <?php if ($filterKey !== ''): ?>data-tc-export-filter="<?php p($filterKey); ?>"<?php endif; ?>
                    <?php if ($type === 'date'): ?>data-tc-export-date-input inputmode="none"<?php endif; ?>
                    autocomplete="off"
                    <?php if ($ariaDescribedBy !== ''): ?>aria-describedby="<?php p($ariaDescribedBy); ?>"<?php endif; ?>>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($helpText !== ''): ?>
        <p id="<?php p($hintId); ?>" class="tc-export-field__help"><?php p($helpText); ?></p>
    <?php endif; ?>
</div>

<?php

/**
 * Inline list row actions — View / Edit / Delete (customers, projects, tickets, …).
 *
 * @var \OCP\IL10N $l
 * @var string $listActionsViewHref
 * @var string $listActionsViewAriaLabel Full phrase for aria-label (already translated)
 * @var string|null $listActionsEditHref Optional edit link URL
 * @var string|null $listActionsEditAriaLabel Translated aria-label for edit link
 * @var array|null $listActionsEditButton Optional edit button: class, attrs (string=>string), ariaLabel
 * @var array|null $listActionsDelete Optional delete button: class, attrs, ariaLabel
 * @var bool $listActionsIconOnly When true, show icon-only buttons (accessible name via aria-label)
 */

use OCA\Ticketcheck\Service\IconCatalog;

$listActionsEditHref = $listActionsEditHref ?? null;
$listActionsEditAriaLabel = $listActionsEditAriaLabel ?? '';
$listActionsEditButton = $listActionsEditButton ?? null;
$listActionsDelete = $listActionsDelete ?? null;
$listActionsIconOnly = !empty($listActionsIconOnly);
$iconBtnClass = $listActionsIconOnly ? ' helpdesk-btn--icon' : '';
$actionsGroupClass = 'tc-list-inline-actions' . ($listActionsIconOnly ? ' tc-list-inline-actions--icon-only' : '');
?>
<div class="<?php p($actionsGroupClass); ?>" role="group" aria-label="<?php p($l->t('actions')); ?>">
	<a href="<?php p($listActionsViewHref); ?>"
		class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--sm<?php p($iconBtnClass); ?>"
		aria-label="<?php p($listActionsViewAriaLabel); ?>">
		<?php print_unescaped(IconCatalog::render('eye', 'tc-icon--inline')); ?>
		<?php if (!$listActionsIconOnly): ?>
			<span class="tc-list-btn-label"><?php p($l->t('view')); ?></span>
		<?php endif; ?>
	</a>
	<?php if ($listActionsEditHref !== null && $listActionsEditHref !== ''): ?>
		<a href="<?php p($listActionsEditHref); ?>"
			class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm<?php p($iconBtnClass); ?>"
			aria-label="<?php p($listActionsEditAriaLabel); ?>">
			<?php print_unescaped(IconCatalog::render('edit', 'tc-icon--inline')); ?>
			<?php if (!$listActionsIconOnly): ?>
				<span class="tc-list-btn-label"><?php p($l->t('edit')); ?></span>
			<?php endif; ?>
		</a>
	<?php elseif (is_array($listActionsEditButton) && $listActionsEditButton !== []): ?>
		<?php
		$editBtnClass = (string)($listActionsEditButton['class'] ?? 'helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm');
		$editBtnAttrs = is_array($listActionsEditButton['attrs'] ?? null) ? $listActionsEditButton['attrs'] : [];
		$editBtnAria = (string)($listActionsEditButton['ariaLabel'] ?? $l->t('edit'));
		?>
		<button type="button"
			class="<?php p($editBtnClass . $iconBtnClass); ?>"
			aria-label="<?php p($editBtnAria); ?>"
			<?php foreach ($editBtnAttrs as $attrName => $attrValue): ?>
				<?php p($attrName); ?>="<?php p((string)$attrValue); ?>"
			<?php endforeach; ?>>
			<?php print_unescaped(IconCatalog::render('edit', 'tc-icon--inline')); ?>
			<?php if (!$listActionsIconOnly): ?>
				<span class="tc-list-btn-label"><?php p($l->t('edit')); ?></span>
			<?php endif; ?>
		</button>
	<?php endif; ?>
	<?php if (is_array($listActionsDelete) && $listActionsDelete !== []): ?>
		<?php
		$deleteBtnClass = (string)($listActionsDelete['class'] ?? 'helpdesk-btn helpdesk-btn--danger helpdesk-btn--sm');
		$deleteBtnAttrs = is_array($listActionsDelete['attrs'] ?? null) ? $listActionsDelete['attrs'] : [];
		$deleteBtnAria = (string)($listActionsDelete['ariaLabel'] ?? $l->t('delete'));
		?>
		<button type="button"
			class="<?php p($deleteBtnClass . $iconBtnClass); ?>"
			aria-label="<?php p($deleteBtnAria); ?>"
			<?php foreach ($deleteBtnAttrs as $attrName => $attrValue): ?>
				<?php p($attrName); ?>="<?php p((string)$attrValue); ?>"
			<?php endforeach; ?>>
			<?php print_unescaped(IconCatalog::render('trash-2', 'tc-icon--inline')); ?>
			<?php if (!$listActionsIconOnly): ?>
				<span class="tc-list-btn-label"><?php p($l->t('delete')); ?></span>
			<?php endif; ?>
		</button>
	<?php endif; ?>
</div>

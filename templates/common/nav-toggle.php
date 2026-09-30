<?php
/**
 * In-page mobile menu control (staff + guest). Placed in page-start header — not fixed.
 *
 * @var \OCP\IL10N $l
 */

use OCA\Ticketcheck\Service\IconCatalog;

if (!isset($l)) {
	return;
}
?>
<button type="button"
	class="tc-nav-toggle"
	id="tc-nav-toggle"
	aria-label="<?php p($l->t('toggle_navigation_menu')); ?>"
	aria-expanded="false"
	aria-controls="app-navigation"
	data-tc-nav-toggle
	data-aria-label-open="<?php p($l->t('toggle_navigation_menu')); ?>"
	data-aria-label-close="<?php p($l->t('close_navigation_menu')); ?>">
	<?php print_unescaped(IconCatalog::render('menu', 'tc-nav-toggle__icon')); ?>
	<span class="tc-nav-toggle__label"><?php p($l->t('menu')); ?></span>
</button>

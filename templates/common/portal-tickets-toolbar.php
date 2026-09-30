<?php

/**
 * Guest portal ticket list toolbar — primary actions below filters.
 *
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 */

use OCA\Ticketcheck\Service\IconCatalog;

/** @var \OCP\IL10N $l */
$l = $_['l'] ?? null;
if ($l === null || !isset($_['urlGenerator'])) {
	return;
}

/** @var \OCP\IURLGenerator $urlGenerator */
$urlGenerator = $_['urlGenerator'];
$canCreateTicket = !empty($_['canCreateTicket']);
require __DIR__ . '/../portal/resolveShowKnowledgeBase.php';
?>
<div class="tc-tickets-toolbar" role="toolbar" aria-label="<?php p($l->t('actions')); ?>">
	<div class="tc-tickets-toolbar__secondary">
		<?php if (isset($_['project'])): ?>
			<a href="<?php p($urlGenerator->linkToRoute('ticketcheck.customerPortal.myTickets')); ?>"
				class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm">
				<?php p($l->t('back_to_all_tickets')); ?>
			</a>
		<?php endif; ?>
		<?php if ($showKnowledgeBase): ?>
			<a href="<?php p($urlGenerator->linkToRoute('ticketcheck.customerPortal.kpKnowledgeBase')); ?>"
				class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm">
				<?php p($l->t('knowledge_base')); ?>
			</a>
		<?php endif; ?>
		<a href="<?php p($urlGenerator->linkToRoute('ticketcheck.customerPortal.index')); ?>"
			class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm">
			<?php p($l->t('dashboard')); ?>
		</a>
	</div>
	<?php if ($canCreateTicket): ?>
		<a href="<?php p($urlGenerator->linkToRoute('ticketcheck.customerPortal.createTicket')); ?>"
			class="helpdesk-btn helpdesk-btn--primary tc-tickets-toolbar__create">
			<span class="tc-tickets-toolbar__create-icon" aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('plus-circle')); ?>
			</span>
			<?php p($l->t('create_ticket')); ?>
		</a>
	<?php endif; ?>
</div>

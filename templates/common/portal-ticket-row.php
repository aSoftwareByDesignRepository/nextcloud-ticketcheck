<?php
/**
 * Guest portal ticket row partial.
 *
 * Single source of truth for how a ticket is rendered inside a portal list
 * (`portal/index.php`, `portal/my-tickets.php`, and any future portal list).
 * The caller MUST set the row context in the `$rowContext` array, so this
 * partial never touches `$_` directly — caller stays in control of what
 * gets shown and the visual is identical across pages.
 *
 * Required context keys:
 *   - `ticket`            (\OCA\Ticketcheck\Db\Ticket)
 *   - `l`                 (\OCP\IL10N) – translator (same instance as page)
 *   - `urlGenerator`      (\OCP\IURLGenerator) – for the row link
 *
 * Optional context keys (all default to safe values):
 *   - `localeFormat`      (\OCA\Ticketcheck\Service\LocaleFormatService|null)
 *   - `projectLabel`      (string) – when set, shown as the first meta chip
 *   - `showCategoryBadge` (bool) – default true
 *   - `showExcerpt`       (bool) – default false (rows stay compact)
 *   - `excerptLimit`      (int) – default 150 chars when `showExcerpt` is true
 *
 * @var array{
 *   ticket: \OCA\Ticketcheck\Db\Ticket,
 *   l: \OCP\IL10N,
 *   urlGenerator: \OCP\IURLGenerator,
 *   localeFormat?: \OCA\Ticketcheck\Service\LocaleFormatService|null,
 *   projectLabel?: string,
 *   showCategoryBadge?: bool,
 *   showExcerpt?: bool,
 *   excerptLimit?: int,
 * } $rowContext
 */

use OCA\Ticketcheck\Service\IconCatalog;
use OCA\Ticketcheck\Service\PortalTicketDisplay;

if (!isset($rowContext) || !is_array($rowContext)) {
	return;
}

/** @var \OCA\Ticketcheck\Db\Ticket $row_ticket */
$row_ticket = $rowContext['ticket'];
/** @var \OCP\IL10N $row_l */
$row_l = $rowContext['l'];
/** @var \OCP\IURLGenerator $row_url */
$row_url = $rowContext['urlGenerator'];
/** @var \OCA\Ticketcheck\Service\LocaleFormatService|null $row_localeFormat */
$row_localeFormat = $rowContext['localeFormat'] ?? null;
$row_projectLabel = isset($rowContext['projectLabel']) ? (string)$rowContext['projectLabel'] : '';
$row_showCategoryBadge = $rowContext['showCategoryBadge'] ?? true;
$row_showExcerpt = $rowContext['showExcerpt'] ?? false;
$row_excerptLimit = (int)($rowContext['excerptLimit'] ?? 150);

$row_status = (string)$row_ticket->getStatus();
$row_isWaitingCustomer = PortalTicketDisplay::isWaitingStatus($row_status);
$row_normalizedClass = PortalTicketDisplay::statusBadgeClass($row_status);
$row_displayStatus = $row_l->t(PortalTicketDisplay::statusTranslationKey($row_status));
$row_priority = (string)$row_ticket->getPriority();
$row_category = (string)($row_ticket->getCategory() ?? '');
$row_createdAt = $row_ticket->getCreatedAt();
$row_createdLabel = $row_localeFormat !== null
	? $row_localeFormat->formatDate($row_createdAt->format('Y-m-d'), 'medium', $row_l)
	: $row_createdAt->format('Y-m-d');
$row_cardClass = 'portal-ticket-row helpdesk-card helpdesk-card--interactive'
	. ($row_isWaitingCustomer ? ' portal-ticket-row--attention' : '');
$row_excerpt = '';
if ($row_showExcerpt) {
	$row_descRaw = (string)$row_ticket->getDescription();
	$row_excerpt = strlen($row_descRaw) > $row_excerptLimit
		? substr($row_descRaw, 0, $row_excerptLimit) . '…'
		: $row_descRaw;
}
?>
<a href="<?php p($row_url->linkToRoute('ticketcheck.customerPortal.viewTicket', ['id' => $row_ticket->getId()])); ?>"
	class="<?php p($row_cardClass); ?>"
	data-status="<?php p($row_status); ?>"
	data-project-id="<?php p($row_ticket->getProjectId() ?: ''); ?>"
	data-priority="<?php p($row_priority); ?>"
	data-category="<?php p($row_category); ?>"
	data-created-at="<?php p($row_createdAt->format('c')); ?>"
	data-title="<?php p($row_ticket->getTitle()); ?>">
	<div class="helpdesk-card__body portal-ticket-row__body">
		<div class="portal-ticket-row__left">
			<div class="portal-ticket-row__numblock">
				<span class="portal-ticket-row__ticket-icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render('file-text')); ?>
				</span>
				<span class="portal-ticket-row__num">#<?php p((string)$row_ticket->getTicketNumber()); ?></span>
			</div>
		</div>
		<div class="portal-ticket-row__main">
			<div class="portal-ticket-row__title-wrap">
				<h3 class="portal-ticket-row__title"><?php p($row_ticket->getTitle()); ?></h3>
			</div>
			<?php if ($row_showExcerpt && $row_excerpt !== ''): ?>
				<p class="helpdesk-text-muted helpdesk-text-sm portal-ticket-row__excerpt">
					<?php p($row_excerpt); ?>
				</p>
			<?php endif; ?>
			<div class="portal-ticket-row__meta">
				<?php if ($row_projectLabel !== ''): ?>
					<span class="portal-ticket-row__meta-item">
						<span class="portal-ticket-row__meta-icon" aria-hidden="true">
							<?php print_unescaped(IconCatalog::render('folder')); ?>
						</span>
						<?php p($row_projectLabel); ?>
					</span>
					<span class="portal-ticket-row__meta-sep" aria-hidden="true">·</span>
				<?php endif; ?>
				<span class="portal-ticket-row__meta-item">
					<span class="portal-ticket-row__meta-icon portal-ticket-row__meta-icon--neutral" aria-hidden="true">
						<?php print_unescaped(IconCatalog::render('clock')); ?>
					</span>
					<?php p($row_createdLabel); ?>
				</span>
			</div>
		</div>
		<div class="portal-ticket-row__right">
			<div class="portal-ticket-row__badges">
				<div class="portal-ticket-row__badge-row">
					<span class="helpdesk-badge helpdesk-badge--<?php p($row_normalizedClass); ?>" title="<?php p($row_displayStatus); ?>">
						<?php p($row_displayStatus); ?>
					</span>
					<span class="helpdesk-badge helpdesk-badge--priority-<?php p($row_priority); ?>">
						<?php p($row_l->t('priority_' . $row_priority)); ?>
					</span>
				</div>
				<?php if ($row_showCategoryBadge && $row_category !== ''): ?>
					<span class="helpdesk-badge portal-ticket-row__cat-badge">
						<?php p($row_l->t('category_' . strtolower($row_category))); ?>
					</span>
				<?php endif; ?>
			</div>
			<span class="portal-ticket-row__chevron" aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('chevron-right')); ?>
			</span>
		</div>
	</div>
</a>

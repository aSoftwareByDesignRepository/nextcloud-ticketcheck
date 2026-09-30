<?php

/**
 * Guest portal ticket list — table (desktop) + cards (mobile).
 *
 * Expects $_['tickets'], $_['urlGenerator'], $_['l'], optional $_['localeFormat'].
 */

use OCA\Ticketcheck\Service\IconCatalog;
use OCA\Ticketcheck\Service\PortalTicketDisplay;

/** @var \OCP\IL10N $l */
$l = $_['l'];
/** @var \OCA\Ticketcheck\Service\LocaleFormatService|null $localeFormat */
$localeFormat = $_['localeFormat'] ?? null;
$tickets = $_['tickets'] ?? [];
$ticketsListHasActiveFilters = !empty($_['ticketsListHasActiveFilters']);
$showProjectColumn = !empty($_['portalShowProjectColumn']);
$projectNames = is_array($_['portalProjectNames'] ?? null) ? $_['portalProjectNames'] : [];
$canCreateTicket = !empty($_['canCreateTicket']);
require __DIR__ . '/../portal/resolveShowKnowledgeBase.php';
$filterBaseUrl = (string)($_['filterBaseUrl'] ?? $_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.myTickets'));

$portalProjectLabel = static function (?int $projectId) use ($projectNames): string {
	if ($projectId === null || $projectId <= 0) {
		return '';
	}

	return (string)($projectNames[$projectId] ?? '');
};
?>

<?php if (empty($tickets)): ?>
	<div class="tc-empty helpdesk-empty tc-tickets-empty"
		role="region"
		aria-labelledby="portal-tickets-empty-title">
		<div class="helpdesk-empty__icon" aria-hidden="true">
			<?php print_unescaped(IconCatalog::render('file-text')); ?>
		</div>
		<h3 id="portal-tickets-empty-title" class="helpdesk-empty__title">
			<?php p($ticketsListHasActiveFilters ? $l->t('no_tickets_match') : $l->t('no_tickets_yet')); ?>
		</h3>
		<p class="helpdesk-empty__text">
			<?php p($ticketsListHasActiveFilters ? $l->t('try_adjusting_filters_or_create_ticket') : $l->t('no_tickets_created_yet')); ?>
		</p>
		<div class="tc-tickets-empty__actions">
			<?php if ($canCreateTicket): ?>
				<a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.createTicket')); ?>"
					class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg">
					<?php p($l->t('create_your_first_ticket')); ?>
				</a>
			<?php elseif ($showKnowledgeBase): ?>
				<a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.kpKnowledgeBase')); ?>"
					class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg">
					<?php p($l->t('knowledge_base')); ?>
				</a>
			<?php endif; ?>
			<?php if ($ticketsListHasActiveFilters): ?>
				<a href="<?php p($filterBaseUrl); ?>"
					class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--lg">
					<?php p($l->t('clear_filters')); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
<?php else: ?>
	<div class="tc-tickets-list">
		<div class="tc-table-wrap">
			<table class="helpdesk-table tc-table tc-tickets-table tc-tickets-table--portal<?php echo $showProjectColumn ? ' tc-tickets-table--with-project' : ''; ?>">
				<caption class="tc-sr-only"><?php p($l->t('tickets_list_section')); ?></caption>
				<colgroup>
					<col class="tc-tickets-table__col-title">
					<?php if ($showProjectColumn): ?>
						<col class="tc-tickets-table__col-project">
					<?php endif; ?>
					<col class="tc-tickets-table__col-created">
					<col class="tc-tickets-table__col-category">
					<col class="tc-tickets-table__col-status">
					<col class="tc-tickets-table__col-priority">
					<col class="tc-tickets-table__col-actions">
				</colgroup>
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('title')); ?></th>
						<?php if ($showProjectColumn): ?>
							<th scope="col"><?php p($l->t('project')); ?></th>
						<?php endif; ?>
						<th scope="col"><?php p($l->t('created')); ?></th>
						<th scope="col"><?php p($l->t('category')); ?></th>
						<th scope="col"><?php p($l->t('status')); ?></th>
						<th scope="col"><?php p($l->t('priority')); ?></th>
						<th scope="col"><?php p($l->t('actions')); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($tickets as $ticket):
						$daysSinceUpdate = (new DateTime())->diff($ticket->getUpdatedAt())->days;
						$isOld = $daysSinceUpdate > 3 && !PortalTicketDisplay::isDoneStatus((string)$ticket->getStatus());
						$isUrgent = $ticket->getPriority() === 'urgent';
						$isHigh = $ticket->getPriority() === 'high';
						$rowAccent = '';
						if ($isUrgent) {
							$rowAccent = 'tc-tickets-row--urgent';
						} elseif ($isHigh) {
							$rowAccent = 'tc-tickets-row--high';
						}
						$createdAt = $ticket->getCreatedAt();
						$createdLabel = $localeFormat !== null
							? $localeFormat->formatDate($createdAt->format('Y-m-d'), 'medium', $l)
							: $createdAt->format('Y-m-d');
						$ticketShowHref = $_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.viewTicket', ['id' => $ticket->getId()]);
						$ticketStatus = (string)$ticket->getStatus();
						$ticketStatusLabel = $l->t(PortalTicketDisplay::statusTranslationKey($ticketStatus));
						?>
						<tr class="tc-tickets-row tc-list-row--clickable <?php p($rowAccent); ?>"
							data-list-row-href="<?php p($ticketShowHref); ?>"
							data-status="<?php p($ticketStatus); ?>"
							data-priority="<?php p((string)$ticket->getPriority()); ?>"
							data-category="<?php p((string)($ticket->getCategory() ?? '')); ?>"
							data-title="<?php p($ticket->getTitle()); ?>"
							data-created-at="<?php p($createdAt->format(\DateTimeInterface::ATOM)); ?>">
							<th scope="row" class="tc-tickets-table__ticket">
								<div class="tc-tickets-table__title-line">
									<a class="tc-tickets-table__title-link"
										href="<?php p($ticketShowHref); ?>"
										aria-label="<?php p(strtr($l->t('view_ticket_number_title'), [
											'{number}' => (string)$ticket->getTicketNumber(),
											'{title}' => $ticket->getTitle(),
										])); ?>">
										<span class="tc-tickets-table__title-text">
											<?php p($ticket->getTitle()); ?>
										</span>
									</a>
								</div>
								<div class="tc-tickets-table__meta-line">
									<span class="tc-tickets-table__meta-head">
										<span class="tc-tickets-table__meta" aria-hidden="true">
											#<?php p($ticket->getTicketNumber()); ?>
										</span>
										<?php if ($isOld): ?>
											<span class="helpdesk-badge helpdesk-badge--priority-high helpdesk-ticket-card__stale-badge">
												<?php
												if ($daysSinceUpdate === 1) {
													p($l->t('day_old'));
												} else {
													p(strtr($l->t('days_old'), ['{count}' => (string)$daysSinceUpdate]));
												}
												?>
											</span>
										<?php endif; ?>
									</span>
								</div>
							</th>
							<?php if ($showProjectColumn): ?>
								<td class="tc-tickets-table__cell-text">
									<?php
									$projectLabel = $portalProjectLabel($ticket->getProjectId());
									if ($projectLabel !== '') {
										p($projectLabel);
									} else {
										?><span class="helpdesk-text-muted"><?php p($l->t('none')); ?></span><?php
									}
									?>
								</td>
							<?php endif; ?>
							<td class="tc-tickets-table__cell-date"><?php p($createdLabel); ?></td>
							<td class="tc-tickets-table__cell-badge">
								<?php if ($ticket->getCategory()): ?>
									<span class="helpdesk-badge helpdesk-ticket-card__category-badge">
										<?php p($l->t('category_' . strtolower($ticket->getCategory()))); ?>
									</span>
								<?php else: ?>
									<span class="helpdesk-text-muted"><?php p($l->t('none')); ?></span>
								<?php endif; ?>
							</td>
							<td class="tc-tickets-table__cell-badge">
								<span class="helpdesk-badge helpdesk-badge--<?php p(PortalTicketDisplay::statusBadgeClass($ticketStatus)); ?>"
									title="<?php p($ticketStatusLabel); ?>"
									aria-label="<?php p($l->t('status') . ': ' . $ticketStatusLabel); ?>">
									<?php p($ticketStatusLabel); ?>
								</span>
							</td>
							<td class="tc-tickets-table__cell-badge">
								<span class="helpdesk-badge helpdesk-badge--priority-<?php p($ticket->getPriority()); ?>">
									<?php p($l->t('priority_' . $ticket->getPriority())); ?>
								</span>
							</td>
							<td class="tc-tickets-table__actions tc-list-row__no-nav">
								<?php
								$listActionsViewHref = $ticketShowHref;
								$listActionsViewAriaLabel = $l->t('view_ticket_details');
								include __DIR__ . '/list-row-actions.php';
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<ul class="tc-card-list" aria-label="<?php p($l->t('tickets_list_section')); ?>">
			<?php foreach ($tickets as $ticket):
				$daysSinceUpdate = (new DateTime())->diff($ticket->getUpdatedAt())->days;
				$isOld = $daysSinceUpdate > 3 && !PortalTicketDisplay::isDoneStatus((string)$ticket->getStatus());
				$isUrgent = $ticket->getPriority() === 'urgent';
				$isHigh = $ticket->getPriority() === 'high';
				$borderClass = '';
				if ($isUrgent) {
					$borderClass = 'helpdesk-card--urgent';
				} elseif ($isHigh) {
					$borderClass = 'helpdesk-card--highlighted';
				}
				$createdAt = $ticket->getCreatedAt();
				$createdLabel = $localeFormat !== null
					? $localeFormat->formatDate($createdAt->format('Y-m-d'), 'medium', $l)
					: $createdAt->format('Y-m-d');
				$ticketShowHref = $_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.viewTicket', ['id' => $ticket->getId()]);
				$ticketStatus = (string)$ticket->getStatus();
				$ticketStatusLabel = $l->t(PortalTicketDisplay::statusTranslationKey($ticketStatus));
				$projectLabel = $portalProjectLabel($ticket->getProjectId());
				?>
				<li class="tc-card helpdesk-card tc-list-row--clickable <?php p($borderClass); ?>"
					data-list-row-href="<?php p($ticketShowHref); ?>"
					data-status="<?php p($ticketStatus); ?>"
					data-priority="<?php p((string)$ticket->getPriority()); ?>"
					data-category="<?php p((string)($ticket->getCategory() ?? '')); ?>"
					data-title="<?php p($ticket->getTitle()); ?>"
					data-created-at="<?php p($createdAt->format(\DateTimeInterface::ATOM)); ?>">
					<div class="helpdesk-card__body">
						<div class="helpdesk-ticket-card__header">
							<div class="helpdesk-ticket-card__main">
								<h3 class="helpdesk-ticket-card__title">
									<a class="helpdesk-ticket-card__title-link"
										href="<?php p($ticketShowHref); ?>"
										aria-label="<?php p(strtr($l->t('view_ticket_number_title'), [
											'{number}' => (string)$ticket->getTicketNumber(),
											'{title}' => $ticket->getTitle(),
										])); ?>">
										<span class="helpdesk-ticket-card__title-text"><?php p($ticket->getTitle()); ?></span>
									</a>
								</h3>
								<div class="helpdesk-text-muted helpdesk-ticket-card__meta">
									<span class="helpdesk-ticket-card__meta-head">
										<span>#<?php p($ticket->getTicketNumber()); ?></span>
										<?php if ($isOld): ?>
											<span class="helpdesk-badge helpdesk-badge--priority-high helpdesk-ticket-card__stale-badge">
												<?php
												if ($daysSinceUpdate === 1) {
													p($l->t('day_old'));
												} else {
													p(strtr($l->t('days_old'), ['{count}' => (string)$daysSinceUpdate]));
												}
												?>
											</span>
										<?php endif; ?>
									</span>
									<?php if ($showProjectColumn && $projectLabel !== ''): ?>
										• <?php p($projectLabel); ?>
									<?php endif; ?>
									• <?php p($createdLabel); ?>
									<?php if ($ticket->getCategory()): ?>
										• <span class="helpdesk-badge helpdesk-ticket-card__category-badge">
											<?php p($l->t('category_' . strtolower($ticket->getCategory()))); ?>
										</span>
									<?php endif; ?>
								</div>
								<p class="helpdesk-text-muted helpdesk-ticket-card__description">
									<?php p(mb_substr($ticket->getDescription(), 0, 150)); ?>
									<?php if (mb_strlen($ticket->getDescription()) > 150): ?>...<?php endif; ?>
								</p>
							</div>
							<div class="helpdesk-ticket-card__status">
								<span class="helpdesk-badge helpdesk-badge--<?php p(PortalTicketDisplay::statusBadgeClass($ticketStatus)); ?>"
									title="<?php p($ticketStatusLabel); ?>"
									aria-label="<?php p($l->t('status') . ': ' . $ticketStatusLabel); ?>">
									<?php p($ticketStatusLabel); ?>
								</span>
								<span class="helpdesk-badge helpdesk-badge--priority-<?php p($ticket->getPriority()); ?>">
									<?php p($l->t('priority_' . $ticket->getPriority())); ?>
								</span>
							</div>
						</div>

						<div class="helpdesk-ticket-card__actions tc-list-row__no-nav">
							<?php
							$listActionsViewHref = $ticketShowHref;
							$listActionsViewAriaLabel = $l->t('view_ticket_details');
							include __DIR__ . '/list-row-actions.php';
							?>
						</div>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

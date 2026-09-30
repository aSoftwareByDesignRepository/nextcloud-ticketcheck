<?php

/**
 * Dashboard template - Clean, enhanced Nextcloud design
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

$_['isGuest'] = false;
$_['isAdmin'] = $_['isAdmin'] ?? false;

/** @var \OCP\IUser|null $currentUser */
$currentUser = $_['currentUser'] ?? null;
$currentUserId = $currentUser ? $currentUser->getUID() : null;

/** @var \OCP\IL10N $l */
$l = $_['l'];

/** @var \OCA\Ticketcheck\Service\LocaleFormatService|null $localeFormat */
$localeFormat = $_['localeFormat'] ?? null;

// Calculate key metrics
$urgentCount = (int)($_['stats']['my_urgent'] ?? 0);
$newUnassignedCount = (int)($_['stats']['new_unassigned'] ?? 0);
$unassignedInProgressCount = (int)($_['stats']['unassigned_in_progress'] ?? 0);
$myActiveTickets = $_['stats']['my_tickets'] ?? 0;
$ticketIndexBaseUrl = $_['urlGenerator']->linkToRoute('ticketcheck.ticket.index');
$buildTicketFilterUrl = static function (array $query) use ($ticketIndexBaseUrl): string {
    if ($query === []) {
        return $ticketIndexBaseUrl;
    }
    return $ticketIndexBaseUrl . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
};
$customerCount = (int)($_['stats']['customers'] ?? 0);
$projectCount = (int)($_['stats']['projects'] ?? 0);
$hasCustomers = $customerCount > 0;
$hasProjects = $projectCount > 0;
$setupRequired = !$hasCustomers || !$hasProjects;
$canCreateTicket = $hasCustomers && $hasProjects;

$myTicketsHref = $_['urlGenerator']->linkToRoute('ticketcheck.ticket.index');
if ($currentUserId !== null && $currentUserId !== '') {
    $myTicketsHref .= '?assigned_to=' . rawurlencode($currentUserId);
}
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

            <?php if ($setupRequired): ?>
                <details class="tc-quick-start helpdesk-card helpdesk-card--highlighted helpdesk-dashboard-setup helpdesk-mb-md" open>
                    <summary class="tc-quick-start__summary" id="helpdesk-setup-heading">
                        <span class="tc-quick-start__title"><?php p($l->t('dashboard_setup_title')); ?></span>
                    </summary>
                    <div class="helpdesk-card__body tc-quick-start__body">
                        <p class="helpdesk-text-muted helpdesk-dashboard-setup__intro">
                            <?php p($l->t('dashboard_setup_description')); ?>
                        </p>

                        <?php if ($_['isAdmin'] ?? false): ?>
                            <div class="helpdesk-dashboard-setup__actions tc-quick-start__steps">
                                <?php if (!$hasCustomers): ?>
                                    <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customer.create')); ?>"
                                        class="helpdesk-card helpdesk-card--interactive helpdesk-quick-action-card helpdesk-dashboard-setup__action"
                                        aria-label="<?php p($l->t('create_first_customer_shortcut')); ?>">
                                        <div class="helpdesk-card__body">
                                            <div class="helpdesk-dashboard-metric-row">
                                                <span class="helpdesk-dashboard-metric-icon helpdesk-dashboard-metric-icon--neutral helpdesk-dashboard-metric-icon--accent tc-stat-card__icon" aria-hidden="true">
                                                    <?php print_unescaped(IconCatalog::render('users')); ?>
                                                </span>
                                                <div class="helpdesk-dashboard-metric-content">
                                                    <div class="helpdesk-dashboard-metric-label"><?php p($l->t('create_first_customer_shortcut')); ?></div>
                                                    <div class="helpdesk-dashboard-metric-meta"><?php p($l->t('create_first_customer_shortcut_help')); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                <?php endif; ?>

                                <?php if (!$hasProjects): ?>
                                    <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.project.create')); ?>"
                                        class="helpdesk-card helpdesk-card--interactive helpdesk-quick-action-card helpdesk-dashboard-setup__action"
                                        aria-label="<?php p($l->t('create_first_project_shortcut')); ?>">
                                        <div class="helpdesk-card__body">
                                            <div class="helpdesk-dashboard-metric-row">
                                                <span class="helpdesk-dashboard-metric-icon helpdesk-dashboard-metric-icon--neutral helpdesk-dashboard-metric-icon--accent tc-stat-card__icon" aria-hidden="true">
                                                    <?php print_unescaped(IconCatalog::render('folder')); ?>
                                                </span>
                                                <div class="helpdesk-dashboard-metric-content">
                                                    <div class="helpdesk-dashboard-metric-label"><?php p($l->t('create_first_project_shortcut')); ?></div>
                                                    <div class="helpdesk-dashboard-metric-meta"><?php p($l->t('create_first_project_shortcut_help')); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p class="helpdesk-text-muted">
                                <?php p($l->t('dashboard_setup_admin_required')); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>

            <!-- Critical Alerts -->
            <?php if ($urgentCount > 0 || $newUnassignedCount > 0 || $unassignedInProgressCount > 0): ?>
                <section class="tc-section helpdesk-dashboard-section helpdesk-mb-md" role="region" aria-labelledby="helpdesk-alerts-heading">
                    <h2 id="helpdesk-alerts-heading" class="helpdesk-dashboard-section__heading tc-section__title"><?php p($l->t('dashboard_section_alerts')); ?></h2>
                <div class="helpdesk-alert-stack">
                    <?php if ($urgentCount > 0): ?>
                        <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.index')); ?>?priority=urgent"
                            class="helpdesk-alert helpdesk-alert--error helpdesk-alert--link"
                            aria-label="<?php p($urgentCount . ' ' . ($urgentCount === 1 ? $l->t('urgent_ticket') : $l->t('urgent_tickets')) . ' ' . $l->t('need_attention')); ?>">
                            <div class="helpdesk-alert__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('alert-triangle')); ?></div>
                            <div class="helpdesk-alert__content">
                                <div class="helpdesk-alert__title">
                                    <?php p($urgentCount); ?> 
                                    <?php p($urgentCount === 1 ? $l->t('urgent_ticket') : $l->t('urgent_tickets')); ?> 
                                    <?php p($l->t('need_attention')); ?>
                                </div>
                                <div class="helpdesk-alert__text"><?php p($l->t('these_tickets_require_immediate_action')); ?></div>
                            </div>
                        </a>
                    <?php endif; ?>

                    <?php if ($newUnassignedCount > 0): ?>
                        <a href="<?php p($buildTicketFilterUrl(['status' => 'new', 'assigned_to' => 'unassigned'])); ?>"
                            class="helpdesk-alert helpdesk-alert--info helpdesk-alert--link"
                            aria-label="<?php p($newUnassignedCount . ' ' . ($newUnassignedCount === 1 ? $l->t('new_ticket') : $l->t('new_tickets_plural')) . ' ' . $l->t('waiting')); ?>">
                            <div class="helpdesk-alert__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('plus-circle')); ?></div>
                            <div class="helpdesk-alert__content">
                                <div class="helpdesk-alert__title">
                                    <?php p($newUnassignedCount); ?> 
                                    <?php p($newUnassignedCount === 1 ? $l->t('new_ticket') : $l->t('new_tickets_plural')); ?> 
                                    <?php p($l->t('waiting')); ?>
                                </div>
                                <div class="helpdesk-alert__text"><?php p($l->t('review_and_assign_these_tickets')); ?></div>
                            </div>
                        </a>
                    <?php endif; ?>

                    <?php if ($unassignedInProgressCount > 0): ?>
                        <a href="<?php p($buildTicketFilterUrl(['assigned_to' => 'unassigned', 'status' => 'in_progress,waiting'])); ?>"
                            class="helpdesk-alert helpdesk-alert--warning helpdesk-alert--link"
                            aria-label="<?php p($unassignedInProgressCount . ' ' . ($unassignedInProgressCount === 1 ? $l->t('unassigned_ticket') : $l->t('unassigned_tickets_plural'))); ?>">
                            <div class="helpdesk-alert__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('info')); ?></div>
                            <div class="helpdesk-alert__content">
                                <div class="helpdesk-alert__title">
                                    <?php p($unassignedInProgressCount); ?> 
                                    <?php p($unassignedInProgressCount === 1 ? $l->t('unassigned_ticket') : $l->t('unassigned_tickets_plural')); ?>
                                </div>
                                <div class="helpdesk-alert__text"><?php p($l->t('assign_these_tickets_to_team_members')); ?></div>
                            </div>
                        </a>
                    <?php endif; ?>
                </div>
                </section>
            <?php endif; ?>

            <!-- Key Metrics Grid -->
            <section class="tc-section helpdesk-dashboard-section helpdesk-mb-lg" aria-labelledby="helpdesk-metrics-heading">
                <h2 id="helpdesk-metrics-heading" class="helpdesk-dashboard-section__heading tc-section__title"><?php p($l->t('dashboard_section_overview')); ?></h2>
            <?php
            $activeTicketCount = ($_['stats']['by_status']['new'] ?? 0)
                + ($_['stats']['by_status']['in_progress'] ?? 0)
                + ($_['stats']['by_status']['waiting'] ?? 0)
                + ($_['stats']['by_status']['open'] ?? 0);
            $ticketIndexUrl = $_['urlGenerator']->linkToRoute('ticketcheck.ticket.index');
            ?>
            <div class="tc-dashboard-kpis helpdesk-mb-lg" role="group" aria-label="<?php p($l->t('dashboard_section_overview')); ?>">
                <?php
                $kpiHref = $myTicketsHref;
                $kpiAriaLabel = $l->t('my_tickets') . ': ' . $myActiveTickets;
                $kpiValue = (string)$myActiveTickets;
                $kpiLabel = $l->t('my_tickets');
                $kpiMeta = $l->t('assigned_to_me');
                $kpiIconHtml = IconCatalog::render('ticket');
                $kpiIconVariant = 'primary';
                $kpiCardVariant = 'highlighted';
                include __DIR__ . '/common/dashboard-kpi-card.php';

                $kpiHref = $ticketIndexUrl . '?status=open,in_progress';
                $kpiAriaLabel = $l->t('active_tickets') . ': ' . $activeTicketCount;
                $kpiValue = (string)$activeTicketCount;
                $kpiLabel = $l->t('active_tickets');
                $kpiMeta = $l->t('being_worked_on');
                $kpiIconHtml = IconCatalog::render('clock');
                $kpiIconVariant = 'accent';
                $kpiCardVariant = null;
                include __DIR__ . '/common/dashboard-kpi-card.php';

                $kpiHref = $ticketIndexUrl . '?status=resolved&hide_done=0';
                $kpiAriaLabel = $l->t('resolved_recently') . ': ' . ($_['stats']['completed_total'] ?? 0);
                $kpiValue = (string)(int)($_['stats']['completed_total'] ?? 0);
                $kpiLabel = $l->t('resolved_recently');
                $kpiMeta = $l->t('completed_tickets');
                $kpiIconHtml = IconCatalog::render('check-circle');
                $kpiIconVariant = 'success';
                $kpiCardVariant = 'success';
                include __DIR__ . '/common/dashboard-kpi-card.php';

                $kpiHref = $ticketIndexUrl;
                $kpiAriaLabel = $l->t('total_tickets') . ': ' . ($_['stats']['total'] ?? 0);
                $kpiValue = (string)($_['stats']['total'] ?? 0);
                $kpiLabel = $l->t('total_tickets');
                $kpiMeta = $l->t('all_time');
                $kpiIconHtml = IconCatalog::render('file-text');
                $kpiIconVariant = 'neutral';
                $kpiCardVariant = null;
                include __DIR__ . '/common/dashboard-kpi-card.php';
                ?>
            </div>
            </section>

            <!-- Recent Activity -->
            <section class="tc-section helpdesk-dashboard-section helpdesk-mb-lg" aria-labelledby="helpdesk-recent-activity-heading">
            <h2 id="helpdesk-recent-activity-heading" class="helpdesk-dashboard-section__heading tc-section__title"><?php p($l->t('dashboard_section_recent_tickets')); ?></h2>
                <div class="helpdesk-card helpdesk-mb-lg">
                <div class="helpdesk-card__header helpdesk-card__header--split">
                    <h3 class="helpdesk-card__title helpdesk-card__title--sub"><?php p($l->t('recent_activity')); ?></h3>
                    <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.index')); ?>"
                        class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm">
                        <?php p($l->t('view_all_tickets')); ?>
                    </a>
                </div>

                <?php if (!empty($_['recentTickets'])): ?>
                    <div class="helpdesk-card__body helpdesk-recent-activity-list">
                        <div class="helpdesk-recent-activity-list__inner">
                            <?php foreach (array_slice($_['recentTickets'], 0, 5) as $ticket):
                                $isUrgent = $ticket->getPriority() === 'urgent';
                                $isHigh = $ticket->getPriority() === 'high';
                                $itemClass = 'helpdesk-recent-activity-item' . ($isUrgent ? ' helpdesk-recent-activity-item--urgent' : ($isHigh ? ' helpdesk-recent-activity-item--high' : ''));
                            ?>
                                <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.show', ['id' => $ticket->getId()])); ?>"
                                    class="<?php p($itemClass); ?>"
                                    aria-label="<?php p($ticket->getTitle() . ' - #' . $ticket->getTicketNumber()); ?>">
                                    <div class="helpdesk-recent-activity-item__title">
                                        <?php p($ticket->getTitle()); ?>
                                    </div>
                                    <div class="helpdesk-text-muted helpdesk-recent-activity-item__meta">
                                        #<?php p($ticket->getTicketNumber()); ?> •
                                        <?php p($ticket->getCustomerName()); ?> •
                                        <?php
                                        $createdAt = $ticket->getCreatedAt();
                                        p($localeFormat !== null
                                            ? $localeFormat->formatDateTime($createdAt, 'medium', 'short', $l)
                                            : $createdAt->format('Y-m-d H:i'));
                                        ?>
                                    </div>
                                    <div class="helpdesk-recent-activity-item__aside">
                                        <span class="helpdesk-badge helpdesk-badge--<?php p(str_replace('_', '-', $ticket->getStatus())); ?>">
                                            <?php p($l->t('status_' . $ticket->getStatus())); ?>
                                        </span>
                                        <span class="helpdesk-badge helpdesk-badge--priority-<?php p($ticket->getPriority()); ?>">
                                            <?php p($l->t('priority_' . $ticket->getPriority())); ?>
                                        </span>
                                        <span class="helpdesk-recent-activity-item__chevron" aria-hidden="true"><?php print_unescaped(IconCatalog::render('chevron-right')); ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="helpdesk-card__body">
                        <div class="helpdesk-empty">
                            <div class="helpdesk-empty__icon tc-empty-state__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('ticket')); ?></div>
                            <h3 class="helpdesk-empty__title"><?php p($l->t('no_tickets_yet')); ?></h3>
                            <p class="helpdesk-empty__text"><?php p($l->t('create_first_ticket')); ?></p>
                            <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.create')); ?>" class="helpdesk-btn helpdesk-btn--primary">
                                <?php p($l->t('create_first_ticket')); ?>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            </section>

            <!-- Quick Actions -->
            <section class="tc-section helpdesk-dashboard-section helpdesk-mb-lg" aria-labelledby="helpdesk-quick-actions-heading">
            <h2 id="helpdesk-quick-actions-heading" class="helpdesk-dashboard-section__heading tc-section__title"><?php p($l->t('dashboard_section_quick_actions')); ?></h2>
            <div class="tc-quick-action-grid">
                <?php if ($canCreateTicket): ?>
                    <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.create')); ?>"
                        class="helpdesk-card helpdesk-card--interactive helpdesk-quick-action-card helpdesk-quick-action-card--primary"
                        aria-label="<?php p($l->t('create_new_ticket')); ?>">
                        <div class="helpdesk-card__body">
                            <div class="helpdesk-dashboard-metric-row">
                                <span class="helpdesk-dashboard-metric-icon helpdesk-dashboard-metric-icon--neutral helpdesk-dashboard-metric-icon--accent" aria-hidden="true">
                                    <?php print_unescaped(IconCatalog::render('plus-circle')); ?>
                                </span>
                                <div class="helpdesk-dashboard-metric-content">
                                    <div class="helpdesk-dashboard-metric-label"><?php p($l->t('create_new_ticket')); ?></div>
                                    <div class="helpdesk-dashboard-metric-meta"><?php p($l->t('start_new_support_request')); ?></div>
                                </div>
                            </div>
                        </div>
                    </a>
                <?php else: ?>
                    <div class="helpdesk-card helpdesk-quick-action-card helpdesk-quick-action-card--disabled"
                        role="status"
                        tabindex="-1">
                        <div class="helpdesk-card__body">
                            <div class="helpdesk-dashboard-metric-row">
                                <span class="helpdesk-dashboard-metric-icon helpdesk-dashboard-metric-icon--neutral" aria-hidden="true">
                                    <?php print_unescaped(IconCatalog::render('plus-circle')); ?>
                                </span>
                                <div class="helpdesk-dashboard-metric-content">
                                    <div class="helpdesk-dashboard-metric-label"><?php p($l->t('create_new_ticket')); ?></div>
                                    <div class="helpdesk-dashboard-metric-meta"><?php p($l->t('create_ticket_requires_setup')); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($_['isAdmin'] ?? false): ?>
                    <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.project.index')); ?>"
                        class="helpdesk-card helpdesk-card--interactive helpdesk-quick-action-card"
                        aria-label="<?php p($l->t('manage_projects')); ?>">
                        <div class="helpdesk-card__body">
                            <div class="helpdesk-dashboard-metric-row">
                                <span class="helpdesk-dashboard-metric-icon helpdesk-dashboard-metric-icon--neutral helpdesk-dashboard-metric-icon--accent" aria-hidden="true">
                                    <?php print_unescaped(IconCatalog::render('clipboard')); ?>
                                </span>
                                <div class="helpdesk-dashboard-metric-content">
                                    <div class="helpdesk-dashboard-metric-label"><?php p($l->t('manage_projects')); ?></div>
                                    <div class="helpdesk-dashboard-metric-meta"><?php p($l->t('organize_customer_projects')); ?></div>
                                </div>
                            </div>
                        </div>
                    </a>
                <?php endif; ?>
            </div>
            </section>

<?php include __DIR__ . '/common/page-end.php'; ?>
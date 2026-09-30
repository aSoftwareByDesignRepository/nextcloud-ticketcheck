<?php

/**
 * Customer detail — overview, tickets, projects, guest users
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

$_['isGuest'] = false;
$_['isAdmin'] = $_['isAdmin'] ?? false;

$customer = $_['customer'];
$projects = is_array($_['projects'] ?? null) ? $_['projects'] : [];
$guestUsers = is_array($_['guestUsers'] ?? null) ? $_['guestUsers'] : [];
$analytics = $_['customerAnalytics'] ?? null;
$recentTickets = is_array($_['recentTickets'] ?? null) ? $_['recentTickets'] : [];

/** @var \OCP\IL10N $l */
$l = $_['l'];
/** @var \OCA\Ticketcheck\Service\LocaleFormatService|null $localeFormat */
$localeFormat = $_['localeFormat'] ?? null;

$formatDate = static function (?string $iso) use ($localeFormat, $l): string {
    if ($iso === null || $iso === '') {
        return '';
    }
    if ($localeFormat !== null) {
        return $localeFormat->formatDate($iso, 'medium', $l);
    }
    return $iso;
};

$cid = (int)($customer['id'] ?? 0);
$returnTo = $_['urlGenerator']->linkToRoute('ticketcheck.customer.show', ['id' => $cid]);
$customerTicketsUrl = $_['urlGenerator']->linkToRoute('ticketcheck.ticket.index', ['customer_id' => $cid]);
$customerEmail = (string)($customer['email'] ?? '');
$customerPhone = (string)($customer['phone'] ?? '');

$projectNamesById = [];
foreach ($projects as $proj) {
    $pid = (int)($proj['id'] ?? 0);
    if ($pid > 0) {
        $projectNamesById[$pid] = (string)($proj['name'] ?? '');
    }
}
?>

<?php include __DIR__ . '/../common/page-start.php'; ?>

            <span id="tc-customer-detail-context" hidden data-customer-id="<?php p((string)$cid); ?>"></span>

            <?php if ($analytics): ?>
            <section class="tc-section" aria-labelledby="customer-analytics-heading">
                <h2 id="customer-analytics-heading" class="tc-section__title"><?php p($l->t('performance_analytics')); ?></h2>
                <p class="tc-section__lead"><?php p($l->t('customer_detail_analytics_lead')); ?></p>
                <div class="helpdesk-grid helpdesk-grid--4">
                    <a href="<?php p($customerTicketsUrl); ?>"
                        class="helpdesk-metric-card helpdesk-metric-card--clickable">
                        <div class="helpdesk-metric-card__value"><?php p($analytics['total_tickets']); ?></div>
                        <div class="helpdesk-metric-card__label"><?php p($l->t('total_tickets')); ?></div>
                        <div class="helpdesk-metric-card__subtitle"><?php p($l->t('all_time')); ?></div>
                    </a>
                    <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.index', ['customer_id' => $cid, 'status' => 'open'])); ?>"
                        class="helpdesk-metric-card helpdesk-metric-card--clickable helpdesk-metric-card--warning">
                        <div class="helpdesk-metric-card__value"><?php p($analytics['open_tickets']); ?></div>
                        <div class="helpdesk-metric-card__label"><?php p($l->t('open_tickets')); ?></div>
                        <div class="helpdesk-metric-card__subtitle"><?php p($l->t('currently_active')); ?></div>
                    </a>
                    <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.index', ['customer_id' => $cid, 'status' => 'resolved'])); ?>"
                        class="helpdesk-metric-card helpdesk-metric-card--clickable helpdesk-metric-card--success">
                        <div class="helpdesk-metric-card__value"><?php p($analytics['resolved_tickets']); ?></div>
                        <div class="helpdesk-metric-card__label"><?php p($l->t('resolved_tickets')); ?></div>
                        <div class="helpdesk-metric-card__subtitle"><?php p($l->t('completed')); ?></div>
                    </a>
                    <?php
                    $resolutionMetricCardClass = '';
                    include __DIR__ . '/../common/resolution-metric-card.php';
                    ?>
                </div>
            </section>
            <?php endif; ?>

            <section class="tc-section" aria-labelledby="customer-overview-heading">
                <div class="tc-customer-detail__section-head">
                    <div>
                        <h2 id="customer-overview-heading" class="tc-section__title"><?php p($l->t('customer_information')); ?></h2>
                        <p class="tc-section__lead"><?php p($l->t('customer_detail_overview_lead')); ?></p>
                    </div>
                    <?php if (!empty($_['isAdmin'])): ?>
                        <div class="tc-customer-detail__section-actions" role="toolbar" aria-label="<?php p($l->t('customer_actions_toolbar')); ?>">
                            <?php if (!empty($_['invoicingCheckUrl'])): ?>
                                <a href="<?php p($_['invoicingCheckUrl']); ?>"
                                    class="helpdesk-btn helpdesk-btn--secondary"
                                    aria-label="<?php p($l->t('Open unpaid invoices for this customer in InvoiceCheck')); ?>">
                                    <?php p($l->t('Open InvoiceCheck')); ?>
                                </a>
                            <?php endif; ?>
                            <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customer.edit', ['id' => $cid])); ?>"
                                class="helpdesk-btn helpdesk-btn--secondary"><?php p($l->t('edit')); ?></a>
                            <button type="button"
                                class="helpdesk-btn helpdesk-btn--danger customer-delete-btn"
                                data-customer-id="<?php p((string)$cid); ?>"
                                data-customer-name="<?php p($customer['name'] ?? ''); ?>"
                                aria-label="<?php p($l->t('delete_customer')); ?>">
                                <?php p($l->t('delete')); ?>
                            </button>
                        </div>
                    <?php elseif (!empty($_['invoicingCheckUrl'])): ?>
                        <div class="tc-customer-detail__section-actions" role="toolbar" aria-label="<?php p($l->t('customer_actions_toolbar')); ?>">
                            <a href="<?php p($_['invoicingCheckUrl']); ?>"
                                class="helpdesk-btn helpdesk-btn--secondary"
                                aria-label="<?php p($l->t('Open unpaid invoices for this customer in InvoiceCheck')); ?>">
                                <?php p($l->t('Open InvoiceCheck')); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="helpdesk-card">
                    <div class="helpdesk-card__body">
                        <div class="helpdesk-grid helpdesk-grid--2">
                            <div>
                                <div class="helpdesk-label"><?php p($l->t('email_label')); ?></div>
                                <div class="helpdesk-value">
                                    <?php if ($customerEmail !== ''): ?>
                                        <a class="tc-link" href="mailto:<?php p($customerEmail); ?>"><?php p($customerEmail); ?></a>
                                    <?php else: ?>
                                        <span class="helpdesk-text-muted">—</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <div class="helpdesk-label"><?php p($l->t('phone_label')); ?></div>
                                <div class="helpdesk-value">
                                    <?php if ($customerPhone !== ''): ?>
                                        <a class="tc-link" href="tel:<?php p(preg_replace('/\s+/', '', $customerPhone)); ?>"><?php p($customerPhone); ?></a>
                                    <?php else: ?>
                                        <span class="helpdesk-text-muted">—</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <div class="helpdesk-label"><?php p($l->t('created_label')); ?></div>
                                <div class="helpdesk-value"><?php p($formatDate($customer['created_at'] ?? '')); ?></div>
                            </div>
                        </div>
                        <?php if (!empty($customer['notes'])): ?>
                            <div class="helpdesk-customer-detail-notes-block">
                                <div class="helpdesk-label"><?php p($l->t('internal_notes')); ?></div>
                                <div class="helpdesk-value"><?php p($customer['notes']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="tc-section" aria-labelledby="customer-tickets-heading">
                <div class="tc-customer-detail__section-head">
                    <div>
                        <h2 id="customer-tickets-heading" class="tc-section__title"><?php p($l->t('recent_tickets')); ?></h2>
                        <p class="tc-section__lead"><?php p($l->t('customer_detail_tickets_lead')); ?></p>
                    </div>
                    <div class="tc-customer-detail__section-actions">
                        <a href="<?php p($customerTicketsUrl); ?>" class="helpdesk-btn helpdesk-btn--primary"><?php p($l->t('view_all_tickets')); ?></a>
                    </div>
                </div>
                <?php if ($recentTickets === []): ?>
                    <div class="helpdesk-empty helpdesk-card helpdesk-card--static" role="region" aria-labelledby="customer-tickets-empty-title">
                        <h3 id="customer-tickets-empty-title" class="helpdesk-empty__title"><?php p($l->t('customer_detail_no_recent_tickets')); ?></h3>
                        <p class="helpdesk-empty__text"><?php p($l->t('customer_detail_no_recent_tickets_hint')); ?></p>
                        <?php if (!empty($_['isAdmin'])): ?>
                            <div class="helpdesk-empty__actions">
                                <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.create')); ?>" class="helpdesk-btn helpdesk-btn--primary"><?php p($l->t('create_ticket')); ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="tc-customer-detail-tickets">
                        <div class="tc-table-wrap">
                            <table class="helpdesk-table tc-table tc-customer-detail-tickets-table">
                                <caption class="tc-sr-only"><?php p($l->t('recent_tickets')); ?></caption>
                                <thead>
                                    <tr>
                                        <th scope="col"><?php p($l->t('title')); ?></th>
                                        <th scope="col"><?php p($l->t('project')); ?></th>
                                        <th scope="col"><?php p($l->t('status')); ?></th>
                                        <th scope="col"><?php p($l->t('priority')); ?></th>
                                        <th scope="col"><?php p($l->t('created')); ?></th>
                                        <th scope="col"><?php p($l->t('actions')); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentTickets as $ticket): ?>
                                        <?php
                                        $tid = $ticket->getId();
                                        $st = $ticket->getStatus();
                                        $statusCss = $st === 'working' ? 'in-progress' : str_replace('_', '-', $st);
                                        $statusL10nKey = $st === 'working' ? 'in_progress' : $st;
                                        $createdAt = $ticket->getCreatedAt();
                                        $createdLabel = $localeFormat !== null
                                            ? $localeFormat->formatDate($createdAt->format('Y-m-d'), 'medium', $l)
                                            : $createdAt->format('Y-m-d');
                                        $showTicketHref = $_['urlGenerator']->linkToRoute('ticketcheck.ticket.show', ['id' => $tid]);
                                        $ticketProjectId = (int)($ticket->getProjectId() ?? 0);
                                        $ticketProjectName = $ticketProjectId > 0 ? ($projectNamesById[$ticketProjectId] ?? '') : '';
                                        ?>
                                        <tr class="tc-customer-detail-ticket-row" data-customer-detail-ticket-row="<?php p((string)$tid); ?>">
                                            <th scope="row" class="tc-customer-detail-tickets-table__title">
                                                <a class="tc-link" href="<?php p($showTicketHref); ?>"><?php p($ticket->getTitle()); ?></a>
                                                <span class="tc-customer-detail-tickets-table__num" aria-hidden="true">#<?php p($ticket->getTicketNumber()); ?></span>
                                            </th>
                                            <td>
                                                <?php if ($ticketProjectName !== ''): ?>
                                                    <?php p($ticketProjectName); ?>
                                                <?php else: ?>
                                                    <span class="helpdesk-text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="helpdesk-badge helpdesk-badge--<?php p($statusCss); ?>"
                                                    aria-label="<?php p($l->t('status') . ': ' . $l->t('status_' . $statusL10nKey)); ?>">
                                                    <?php p($l->t('status_' . $statusL10nKey)); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="helpdesk-badge helpdesk-badge--priority-<?php p($ticket->getPriority()); ?>"
                                                    aria-label="<?php p($l->t('priority') . ': ' . $l->t('priority_' . $ticket->getPriority())); ?>">
                                                    <?php p($l->t('priority_' . $ticket->getPriority())); ?>
                                                </span>
                                            </td>
                                            <td><?php p($createdLabel); ?></td>
                                            <td>
                                                <a href="<?php p($showTicketHref); ?>" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm"><?php p($l->t('view')); ?></a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <ul class="tc-card-list tc-customer-detail-ticket-cards" aria-label="<?php p($l->t('recent_tickets')); ?>">
                            <?php foreach ($recentTickets as $ticket): ?>
                                <?php
                                $tid = $ticket->getId();
                                $st = $ticket->getStatus();
                                $statusCss = $st === 'working' ? 'in-progress' : str_replace('_', '-', $st);
                                $statusL10nKey = $st === 'working' ? 'in_progress' : $st;
                                $createdAt = $ticket->getCreatedAt();
                                $createdLabel = $localeFormat !== null
                                    ? $localeFormat->formatDate($createdAt->format('Y-m-d'), 'medium', $l)
                                    : $createdAt->format('Y-m-d');
                                $showTicketHref = $_['urlGenerator']->linkToRoute('ticketcheck.ticket.show', ['id' => $tid]);
                                $ticketProjectId = (int)($ticket->getProjectId() ?? 0);
                                $ticketProjectName = $ticketProjectId > 0 ? ($projectNamesById[$ticketProjectId] ?? '') : '';
                                ?>
                                <li class="tc-card helpdesk-card tc-customer-detail-ticket-card" data-customer-detail-ticket-row="<?php p((string)$tid); ?>">
                                    <div class="helpdesk-card__body">
                                        <h3 class="tc-customer-detail-ticket-card__title">
                                            <a class="tc-link" href="<?php p($showTicketHref); ?>"><?php p($ticket->getTitle()); ?></a>
                                        </h3>
                                        <p class="helpdesk-text-muted tc-customer-detail-ticket-card__num">#<?php p($ticket->getTicketNumber()); ?></p>
                                        <?php if ($ticketProjectName !== ''): ?>
                                            <p class="helpdesk-text-muted tc-customer-detail-ticket-card__project"><?php p($l->t('project')); ?>: <?php p($ticketProjectName); ?></p>
                                        <?php endif; ?>
                                        <div class="tc-customer-detail-ticket-card__badges">
                                            <span class="helpdesk-badge helpdesk-badge--<?php p($statusCss); ?>"><?php p($l->t('status_' . $statusL10nKey)); ?></span>
                                            <span class="helpdesk-badge helpdesk-badge--priority-<?php p($ticket->getPriority()); ?>"><?php p($l->t('priority_' . $ticket->getPriority())); ?></span>
                                        </div>
                                        <p class="helpdesk-text-muted tc-customer-detail-ticket-card__meta"><?php p($l->t('created')); ?>: <?php p($createdLabel); ?></p>
                                        <div class="tc-customer-detail-ticket-card__actions">
                                            <a href="<?php p($showTicketHref); ?>" class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--sm"><?php p($l->t('view_ticket_details')); ?></a>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </section>

            <section class="tc-section" aria-labelledby="customer-projects-heading">
                <div class="tc-customer-detail__section-head">
                    <div>
                        <h2 id="customer-projects-heading" class="tc-section__title"><?php p($l->t('projects')); ?> (<?php p((string)count($projects)); ?>)</h2>
                        <p class="tc-section__lead"><?php p($l->t('customer_detail_projects_lead')); ?></p>
                    </div>
                    <?php if (!empty($_['isAdmin'])): ?>
                        <div class="tc-customer-detail__section-actions">
                            <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.project.create', ['customer_id' => $cid, 'return_to' => $returnTo])); ?>"
                                class="helpdesk-btn helpdesk-btn--primary">
                                <?php p($l->t('new_project')); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($projects === []): ?>
                    <div class="helpdesk-empty helpdesk-card helpdesk-card--static" role="region" aria-labelledby="customer-projects-empty-title">
                        <h3 id="customer-projects-empty-title" class="helpdesk-empty__title"><?php p($l->t('no_projects_yet')); ?></h3>
                        <p class="helpdesk-empty__text"><?php p($l->t('create_project_for_customer_support_tickets')); ?></p>
                        <?php if (!empty($_['isAdmin'])): ?>
                            <div class="helpdesk-empty__actions">
                                <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.project.create', ['customer_id' => $cid, 'return_to' => $returnTo])); ?>"
                                    class="helpdesk-btn helpdesk-btn--primary"><?php p($l->t('new_project')); ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="tc-customer-detail-projects">
                        <div class="tc-table-wrap">
                            <table class="helpdesk-table tc-table tc-customer-detail-projects-table">
                                <caption class="tc-sr-only"><?php p($l->t('projects')); ?></caption>
                                <thead>
                                    <tr>
                                        <th scope="col"><?php p($l->t('project')); ?></th>
                                        <th scope="col"><?php p($l->t('status')); ?></th>
                                        <th scope="col"><?php p($l->t('tickets')); ?></th>
                                        <th scope="col"><?php p($l->t('members')); ?></th>
                                        <th scope="col"><?php p($l->t('actions')); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($projects as $project): ?>
                                        <?php
                                        $pid = (int)($project['id'] ?? 0);
                                        $isInactive = isset($project['active']) && (int)$project['active'] === 0;
                                        $showHref = $_['urlGenerator']->linkToRoute('ticketcheck.project.show', ['id' => $pid]);
                                        $canManageProject = !empty($project['can_manage']);
                                        ?>
                                        <tr class="tc-customer-detail-project-row" data-customer-detail-project-row="<?php p((string)$pid); ?>">
                                            <th scope="row" class="tc-customer-detail-projects-table__name">
                                                <a class="tc-link" href="<?php p($showHref); ?>"><?php p($project['name'] ?? ''); ?></a>
                                            </th>
                                            <td>
                                                <?php if ($isInactive): ?>
                                                    <span class="helpdesk-badge helpdesk-badge--inactive" aria-label="<?php p($l->t('project_inactive')); ?>"><?php p($l->t('inactive_status')); ?></span>
                                                <?php else: ?>
                                                    <span class="helpdesk-badge helpdesk-badge--success" aria-label="<?php p($l->t('active_status')); ?>"><?php p($l->t('active_status')); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php p((string)($project['ticket_count'] ?? 0)); ?></td>
                                            <td><?php p((string)($project['member_count'] ?? 0)); ?></td>
                                            <td>
                                                <div class="tc-customer-detail-inline-actions" role="group" aria-label="<?php p($l->t('actions')); ?>">
                                                    <a href="<?php p($showHref); ?>" class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--sm" aria-label="<?php p($l->t('view_project')); ?>"><?php p($l->t('view')); ?></a>
                                                    <?php if ($canManageProject): ?>
                                                        <button type="button"
                                                            class="helpdesk-btn helpdesk-btn--danger helpdesk-btn--sm"
                                                            data-delete-project-id="<?php p((string)$pid); ?>"
                                                            data-delete-project-name="<?php p($project['name'] ?? ''); ?>"
                                                            aria-label="<?php p($l->t('delete_project')); ?>">
                                                            <?php p($l->t('delete')); ?>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <ul class="tc-card-list tc-customer-detail-project-cards" aria-label="<?php p($l->t('projects')); ?>">
                            <?php foreach ($projects as $project): ?>
                                <?php
                                $pid = (int)($project['id'] ?? 0);
                                $isInactive = isset($project['active']) && (int)$project['active'] === 0;
                                $showHref = $_['urlGenerator']->linkToRoute('ticketcheck.project.show', ['id' => $pid]);
                                $canManageProject = !empty($project['can_manage']);
                                ?>
                                <li class="tc-card helpdesk-card tc-customer-detail-project-card<?php if ($isInactive) {
                                    p(' helpdesk-project-card is-inactive');
                                } ?>" data-customer-detail-project-row="<?php p((string)$pid); ?>">
                                    <div class="helpdesk-card__body">
                                        <h3 class="tc-customer-detail-project-card__title">
                                            <a class="tc-link" href="<?php p($showHref); ?>"><?php p($project['name'] ?? ''); ?></a>
                                        </h3>
                                        <?php if ($isInactive): ?>
                                            <span class="helpdesk-badge helpdesk-badge--inactive"><?php p($l->t('inactive_status')); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($project['description'])): ?>
                                            <p class="helpdesk-text-muted tc-customer-detail-project-card__desc"><?php p($project['description']); ?></p>
                                        <?php endif; ?>
                                        <div class="tc-customer-detail-project-card__stats">
                                            <span><?php p((string)($project['ticket_count'] ?? 0)); ?> <?php p(($project['ticket_count'] ?? 0) != 1 ? $l->t('tickets') : $l->t('ticket')); ?></span>
                                            <span><?php p((string)($project['member_count'] ?? 0)); ?> <?php p(($project['member_count'] ?? 0) != 1 ? $l->t('members') : $l->t('member')); ?></span>
                                        </div>
                                        <div class="tc-customer-detail-project-card__actions">
                                            <a href="<?php p($showHref); ?>" class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--sm"><?php p($l->t('view')); ?></a>
                                            <?php if ($canManageProject): ?>
                                                <button type="button"
                                                    class="helpdesk-btn helpdesk-btn--danger helpdesk-btn--sm"
                                                    data-delete-project-id="<?php p((string)$pid); ?>"
                                                    data-delete-project-name="<?php p($project['name'] ?? ''); ?>"
                                                    aria-label="<?php p($l->t('delete_project')); ?>">
                                                    <?php p($l->t('delete')); ?>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </section>

            <section class="tc-section" aria-labelledby="customer-guests-heading">
                <div class="tc-customer-detail__section-head">
                    <div>
                        <h2 id="customer-guests-heading" class="tc-section__title"><?php p($l->t('guest_users')); ?> (<?php p((string)count($guestUsers)); ?>)</h2>
                        <p class="tc-section__lead"><?php p($l->t('customer_detail_guests_lead')); ?></p>
                    </div>
                    <?php if (!empty($_['isAdmin'])): ?>
                        <div class="tc-customer-detail__section-actions">
                            <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.guestUser.create', ['customer_id' => $cid])); ?>"
                                class="helpdesk-btn helpdesk-btn--primary">
                                <?php p($l->t('add_guest_user')); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($guestUsers === []): ?>
                    <div class="helpdesk-empty helpdesk-card helpdesk-card--static" role="region" aria-labelledby="customer-guests-empty-title">
                        <h3 id="customer-guests-empty-title" class="helpdesk-empty__title"><?php p($l->t('no_guest_users')); ?></h3>
                        <p class="helpdesk-empty__text"><?php p($l->t('create_guest_accounts_for_customer_portal')); ?></p>
                    </div>
                <?php else: ?>
                    <div class="tc-customer-detail-guests">
                        <div class="tc-table-wrap">
                            <table class="helpdesk-table tc-table tc-customer-detail-guests-table">
                                <caption class="tc-sr-only"><?php p($l->t('guest_users')); ?></caption>
                                <thead>
                                    <tr>
                                        <th scope="col"><?php p($l->t('name')); ?></th>
                                        <th scope="col"><?php p($l->t('email_label')); ?></th>
                                        <th scope="col"><?php p($l->t('created_label')); ?></th>
                                        <th scope="col"><?php p($l->t('actions')); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($guestUsers as $guest): ?>
                                        <tr data-customer-detail-guest-row="<?php p($guest['user_id'] ?? ''); ?>">
                                            <th scope="row"><?php p($guest['display_name'] ?? ''); ?></th>
                                            <td>
                                                <?php if (!empty($guest['email'])): ?>
                                                    <a class="tc-link" href="mailto:<?php p($guest['email']); ?>"><?php p($guest['email']); ?></a>
                                                <?php else: ?>
                                                    <span class="helpdesk-text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php p($formatDate($guest['created_at'] ?? '')); ?></td>
                                            <td>
                                                <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.guestUser.edit', ['userId' => $guest['user_id']])); ?>"
                                                    class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm"
                                                    aria-label="<?php p($l->t('view_edit_guest')); ?>">
                                                    <?php p($l->t('view_details')); ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <ul class="tc-card-list tc-customer-detail-guest-cards" aria-label="<?php p($l->t('guest_users')); ?>">
                            <?php foreach ($guestUsers as $guest): ?>
                                <li class="tc-card helpdesk-card tc-customer-detail-guest-card" data-customer-detail-guest-row="<?php p($guest['user_id'] ?? ''); ?>">
                                    <div class="helpdesk-card__body">
                                        <h3 class="tc-customer-detail-guest-card__name"><?php p($guest['display_name'] ?? ''); ?></h3>
                                        <?php if (!empty($guest['email'])): ?>
                                            <p><a class="tc-link" href="mailto:<?php p($guest['email']); ?>"><?php p($guest['email']); ?></a></p>
                                        <?php endif; ?>
                                        <p class="helpdesk-text-muted"><?php p($l->t('created_label')); ?>: <?php p($formatDate($guest['created_at'] ?? '')); ?></p>
                                        <div class="tc-customer-detail-guest-card__actions">
                                            <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.guestUser.edit', ['userId' => $guest['user_id']])); ?>"
                                                class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--sm">
                                                <?php p($l->t('view_details')); ?>
                                            </a>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </section>
<?php include __DIR__ . '/../common/page-end.php'; ?>

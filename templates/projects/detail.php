<?php

/**
 * Project detail — overview, recent tickets, team, guest access
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

$_['isGuest'] = false;
$_['isAdmin'] = $_['isAdmin'] ?? false;

$project = $_['project'];
$customer = $_['customer'];
$members = $_['members'];
$availableUsers = $_['availableUsers'];
$analytics = $_['projectAnalytics'] ?? null;
$recentTickets = is_array($_['recentTickets'] ?? null) ? $_['recentTickets'] : [];
$adminCount = 0;
foreach ($members as $member) {
    if (($member['role'] ?? '') === 'Admin') {
        $adminCount++;
    }
}

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

$pid = (int)($project['id'] ?? 0);
$projTicketsUrl = $_['urlGenerator']->linkToRoute('ticketcheck.ticket.index', ['project_id' => $pid]);
$isProjectInactive = isset($project['active']) && (int)$project['active'] === 0;
$customerIdForLink = is_array($customer) ? (int)($customer['id'] ?? 0) : 0;
$customerDetailHref = ($customerIdForLink > 0 && !empty($_['isAdmin']))
    ? $_['urlGenerator']->linkToRoute('ticketcheck.customer.show', ['id' => $customerIdForLink])
    : '';

?>

<?php include __DIR__ . '/../common/page-start.php'; ?>

            <span id="tc-project-detail-context" hidden data-project-id="<?php p((string)$pid); ?>"></span>

            <?php if ($analytics): ?>
            <section class="tc-section" aria-labelledby="project-analytics-heading">
                <h2 id="project-analytics-heading" class="tc-section__title"><?php p($l->t('performance_analytics')); ?></h2>
                <p class="tc-section__lead"><?php p($l->t('project_detail_analytics_lead')); ?></p>
                <div class="helpdesk-grid helpdesk-grid--4">
                    <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.index', ['project_id' => $project['id']])); ?>"
                        class="helpdesk-metric-card helpdesk-metric-card--clickable">
                        <div class="helpdesk-metric-card__value"><?php p($analytics['total_tickets']); ?></div>
                        <div class="helpdesk-metric-card__label"><?php p($l->t('total_tickets')); ?></div>
                        <div class="helpdesk-metric-card__subtitle"><?php p($l->t('all_time')); ?></div>
                    </a>
                    <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.index', ['project_id' => $project['id'], 'status' => 'open'])); ?>"
                        class="helpdesk-metric-card helpdesk-metric-card--clickable helpdesk-metric-card--warning">
                        <div class="helpdesk-metric-card__value"><?php p($analytics['open_tickets']); ?></div>
                        <div class="helpdesk-metric-card__label"><?php p($l->t('open_tickets')); ?></div>
                        <div class="helpdesk-metric-card__subtitle"><?php p($l->t('currently_active')); ?></div>
                    </a>
                    <?php
                    $resolutionMetricCardClass = 'helpdesk-metric-card--success';
                    include __DIR__ . '/../common/resolution-metric-card.php';
                    ?>
                    <div class="helpdesk-metric-card">
                        <div class="helpdesk-metric-card__value"><?php p(is_array($members) ? count($members) : 0); ?></div>
                        <div class="helpdesk-metric-card__label"><?php p($l->t('team_members')); ?></div>
                        <div class="helpdesk-metric-card__subtitle"><?php p($l->t('active_agents')); ?></div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <section class="tc-section" aria-labelledby="project-overview-heading">
                <div class="tc-project-detail__section-head">
                    <div>
                        <h2 id="project-overview-heading" class="tc-section__title"><?php p($l->t('project_information')); ?></h2>
                        <p class="tc-section__lead"><?php p($l->t('project_detail_overview_lead')); ?></p>
                    </div>
                    <div class="tc-project-detail__section-actions" role="toolbar" aria-label="<?php p($l->t('project_actions_toolbar')); ?>">
                        <?php if (!empty($_['canManage'])): ?>
                            <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.project.edit', ['id' => $project['id']])); ?>"
                                class="helpdesk-btn helpdesk-btn--secondary"><?php p($l->t('edit')); ?></a>
                            <button type="button"
                                data-delete-project-id="<?php p($project['id']); ?>"
                                class="helpdesk-btn helpdesk-btn--danger"
                                aria-label="<?php p($l->t('delete_project')); ?>"><?php p($l->t('delete')); ?></button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="helpdesk-card">
                    <div class="helpdesk-card__body">
                        <?php if (!empty($project['description'])): ?>
                            <div class="helpdesk-project-detail-description-block">
                                <div class="helpdesk-label"><?php p($l->t('description')); ?></div>
                                <div class="helpdesk-value"><?php p($project['description']); ?></div>
                            </div>
                        <?php endif; ?>
                        <div class="helpdesk-grid helpdesk-grid--2">
                            <div>
                                <div class="helpdesk-label"><?php p($l->t('Customer')); ?></div>
                                <div class="helpdesk-value">
                                    <?php if (is_array($customer) && ($customer['name'] ?? '') !== ''): ?>
                                        <?php if ($customerDetailHref !== ''): ?>
                                            <a class="tc-link" href="<?php p($customerDetailHref); ?>"><?php p($customer['name']); ?></a>
                                        <?php else: ?>
                                            <?php p($customer['name']); ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="helpdesk-text-muted"><?php p($l->t('no_customer_assigned')); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <div class="helpdesk-label"><?php p($l->t('project_detail_workflow_column')); ?></div>
                                <div class="helpdesk-value helpdesk-project-detail-status-row">
                                    <span class="helpdesk-badge helpdesk-badge--<?php p(str_replace('_', '-', (string)($project['status'] ?? 'active'))); ?>">
                                        <?php p($l->t('status_' . ($project['status'] ?? 'active'))); ?>
                                    </span>
                                    <?php if ($isProjectInactive): ?>
                                        <span class="helpdesk-badge helpdesk-badge--inactive" aria-label="<?php p($l->t('project_inactive')); ?>"><?php p($l->t('inactive_status')); ?></span>
                                    <?php else: ?>
                                        <span class="helpdesk-badge helpdesk-badge--success" aria-label="<?php p($l->t('active_status')); ?>"><?php p($l->t('active_status')); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!empty($project['start_date'])): ?>
                                <div>
                                    <div class="helpdesk-label"><?php p($l->t('start_date')); ?></div>
                                    <div class="helpdesk-value"><?php p($formatDate($project['start_date'] ?? '')); ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($project['end_date'])): ?>
                                <div>
                                    <div class="helpdesk-label"><?php p($l->t('end_date')); ?></div>
                                    <div class="helpdesk-value"><?php p($formatDate($project['end_date'] ?? '')); ?></div>
                                </div>
                            <?php endif; ?>
                            <div>
                                <div class="helpdesk-label"><?php p($l->t('created_label')); ?></div>
                                <div class="helpdesk-value"><?php p($formatDate($project['created_at'] ?? '')); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="tc-section" aria-labelledby="project-tickets-heading">
                <div class="tc-project-detail__section-head">
                    <div>
                        <h2 id="project-tickets-heading" class="tc-section__title"><?php p($l->t('recent_tickets')); ?></h2>
                        <p class="tc-section__lead"><?php p($l->t('project_detail_tickets_lead')); ?></p>
                    </div>
                    <div class="tc-project-detail__section-actions">
                        <a href="<?php p($projTicketsUrl); ?>" class="helpdesk-btn helpdesk-btn--primary"><?php p($l->t('view_all_tickets')); ?></a>
                    </div>
                </div>
                <?php if ($recentTickets === []): ?>
                    <div class="helpdesk-empty helpdesk-card helpdesk-card--static" role="region" aria-labelledby="project-tickets-empty-title">
                        <h3 id="project-tickets-empty-title" class="helpdesk-empty__title"><?php p($l->t('project_detail_no_recent_tickets')); ?></h3>
                        <p class="helpdesk-empty__text"><?php p($l->t('project_detail_no_recent_tickets_hint')); ?></p>
                        <div class="helpdesk-empty__actions">
                            <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.create')); ?>" class="helpdesk-btn helpdesk-btn--primary"><?php p($l->t('create_ticket')); ?></a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="tc-project-detail-tickets">
                        <div class="tc-table-wrap">
                            <table class="helpdesk-table tc-table tc-project-detail-tickets-table">
                                <caption class="tc-sr-only"><?php p($l->t('recent_tickets')); ?></caption>
                                <thead>
                                    <tr>
                                        <th scope="col"><?php p($l->t('title')); ?></th>
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
                                        ?>
                                        <tr class="tc-project-detail-ticket-row" data-project-detail-ticket-row="<?php p((string)$tid); ?>">
                                            <th scope="row" class="tc-project-detail-tickets-table__title">
                                                <a class="tc-link" href="<?php p($showTicketHref); ?>"><?php p($ticket->getTitle()); ?></a>
                                                <span class="tc-project-detail-tickets-table__num" aria-hidden="true">#<?php p($ticket->getTicketNumber()); ?></span>
                                            </th>
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
                        <ul class="tc-card-list tc-project-detail-ticket-cards" aria-label="<?php p($l->t('recent_tickets')); ?>">
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
                                $viewLabel = $l->t('view_ticket_details');
                                ?>
                                <li class="tc-card helpdesk-card tc-project-detail-ticket-card tc-project-detail-ticket-card--clickable" data-project-detail-ticket-row="<?php p((string)$tid); ?>">
                                    <a class="tc-project-detail-ticket-card__hit" href="<?php p($showTicketHref); ?>" aria-label="<?php p($viewLabel . ': ' . $ticket->getTitle()); ?>">
                                        <span class="tc-project-detail-ticket-card__body">
                                            <span class="tc-project-detail-ticket-card__title"><?php p($ticket->getTitle()); ?></span>
                                            <span class="helpdesk-text-muted tc-project-detail-ticket-card__num">#<?php p($ticket->getTicketNumber()); ?></span>
                                            <span class="tc-project-detail-ticket-card__badges">
                                                <span class="helpdesk-badge helpdesk-badge--<?php p($statusCss); ?>"><?php p($l->t('status_' . $statusL10nKey)); ?></span>
                                                <span class="helpdesk-badge helpdesk-badge--priority-<?php p($ticket->getPriority()); ?>"><?php p($l->t('priority_' . $ticket->getPriority())); ?></span>
                                            </span>
                                            <span class="helpdesk-text-muted tc-project-detail-ticket-card__meta"><?php p($l->t('created')); ?>: <?php p($createdLabel); ?></span>
                                        </span>
                                        <span class="tc-project-detail-ticket-card__actions" aria-hidden="true">
                                            <span class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm helpdesk-btn--icon tc-project-detail-ticket-card__eye">
                                                <?php print_unescaped(IconCatalog::render('eye')); ?>
                                            </span>
                                        </span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </section>

            <section class="tc-section" aria-labelledby="project-members-heading">
                <h2 id="project-members-heading" class="tc-section__title"><?php p($l->t('team_members')); ?></h2>
                <p class="tc-section__lead"><?php p($l->t('project_detail_members_lead')); ?></p>
                <div class="helpdesk-alert helpdesk-alert--info" role="status" aria-live="polite">
                    <div class="helpdesk-alert__content">
                        <p class="helpdesk-alert__title"><?php p($l->t('team_role_access_sync_title')); ?></p>
                        <p class="helpdesk-alert__text"><?php p($l->t('team_role_access_sync_text')); ?></p>
                    </div>
                </div>

                <div class="tc-project-detail-team helpdesk-mb-md">
                    <div class="helpdesk-card tc-project-detail-team__members" aria-labelledby="project-members-list-title">
                        <div class="helpdesk-card__header">
                            <h3 id="project-members-list-title" class="helpdesk-card__title helpdesk-project-detail-card-title"><?php p($l->t('team_members')); ?></h3>
                        </div>
                        <div class="helpdesk-card__body">
                            <?php if (empty($members)): ?>
                                <div class="helpdesk-empty">
                                    <div class="helpdesk-empty__icon" aria-hidden="true">
                                        <?php print_unescaped(IconCatalog::render('users')); ?>
                                    </div>
                                    <h3 class="helpdesk-empty__title"><?php p($l->t('no_team_members_yet')); ?></h3>
                                    <p class="helpdesk-empty__text"><?php p($l->t('add_team_members_to_assign_tickets')); ?></p>
                                </div>
                            <?php else: ?>
                                <ul class="tc-project-detail-members-list">
                                    <?php foreach ($members as $member): ?>
                                        <?php $memberRole = $member['role'] ?? 'Support User'; ?>
                                        <?php $isLastProjectAdmin = $memberRole === 'Admin' && $adminCount <= 1; ?>
                                        <li class="helpdesk-member-card helpdesk-project-detail-member-card tc-project-detail-member-card--full">
                                            <div class="helpdesk-member-card__info">
                                                <div class="helpdesk-member-avatar" aria-hidden="true"><?php p(strtoupper(substr((string)($member['user_name'] ?? '?'), 0, 1))); ?></div>
                                                <div class="helpdesk-member-text">
                                                    <div class="helpdesk-member-topline">
                                                        <span class="helpdesk-member-name"><?php p($member['user_name']); ?></span>
                                                        <?php if (!empty($_['canManage'])): ?>
                                                            <button type="button" class="helpdesk-badge helpdesk-project-detail-role-badge" data-role-badge data-user-id="<?php p($member['user_id']); ?>" aria-haspopup="menu" aria-expanded="false" aria-label="<?php p($l->t('change_member_role')); ?>">
                                                                <?php p($memberRole); ?>
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="helpdesk-badge"><?php p($memberRole); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if (!empty($member['user_email'])): ?>
                                                        <div class="helpdesk-member-email"><?php p($member['user_email']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($_['canManage'])): ?>
                                                    <div class="helpdesk-popover helpdesk-hidden" data-role-popover data-user-id="<?php p($member['user_id']); ?>" role="menu">
                                                        <button type="button" class="helpdesk-popover__item" data-role-option value="Support User" role="menuitem" <?php if ($isLastProjectAdmin): ?>disabled aria-disabled="true"<?php endif; ?>><?php p($l->t('support_user')); ?></button>
                                                        <button type="button" class="helpdesk-popover__item" data-role-option value="Agent" role="menuitem" <?php if ($isLastProjectAdmin): ?>disabled aria-disabled="true"<?php endif; ?>><?php p($l->t('agent')); ?></button>
                                                        <button type="button" class="helpdesk-popover__item" data-role-option value="Admin" role="menuitem"><?php p($l->t('admin')); ?></button>
                                                        <div class="helpdesk-popover__divider"></div>
                                                        <div class="helpdesk-text-muted helpdesk-project-detail-role-help"><?php p($l->t('role_descriptions_short')); ?></div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($_['canManage']) && !empty($member['can_remove'])): ?>
                                                <div class="helpdesk-member-actions">
                                                    <button type="button" class="helpdesk-btn helpdesk-btn--sm helpdesk-btn--danger helpdesk-btn--icon remove-member-btn"
                                                        data-project-id="<?php p($project['id']); ?>" data-user-id="<?php p($member['user_id']); ?>"
                                                        aria-label="<?php p($l->t('remove_member')); ?>">
                                                        <?php print_unescaped(IconCatalog::render('x')); ?>
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($_['canManage']) && !empty($availableUsers)): ?>
                        <div class="helpdesk-card tc-project-detail-team__add" aria-labelledby="add-member-title">
                            <div class="helpdesk-card__header">
                                <h3 id="add-member-title" class="helpdesk-card__title helpdesk-project-detail-card-title"><?php p($l->t('add_team_member')); ?></h3>
                            </div>
                            <div class="helpdesk-card__body">
                                <div class="tc-project-detail-role-legend" aria-labelledby="role-legend-title">
                                    <h4 id="role-legend-title" class="tc-project-detail-role-legend__title"><?php p($l->t('roles_and_permissions')); ?></h4>
                                    <div class="helpdesk-project-detail-legend-row">
                                        <span class="helpdesk-badge"><?php p($l->t('support_user')); ?></span>
                                        <span class="helpdesk-text-muted"><?php p($l->t('work_on_tickets_assigned')); ?></span>
                                    </div>
                                    <div class="helpdesk-project-detail-legend-row">
                                        <span class="helpdesk-badge"><?php p($l->t('agent')); ?></span>
                                        <span class="helpdesk-text-muted"><?php p($l->t('view_all_project_tickets')); ?></span>
                                    </div>
                                    <div class="helpdesk-project-detail-legend-row">
                                        <span class="helpdesk-badge"><?php p($l->t('admin')); ?></span>
                                        <span class="helpdesk-text-muted"><?php p($l->t('manage_members_and_settings')); ?></span>
                                    </div>
                                </div>
                                <form id="add-member-form" data-project-id="<?php p($project['id']); ?>" class="tc-project-detail-add-member-form" aria-describedby="add-member-help">
                                    <div class="helpdesk-form-group">
                                        <label for="user-select" class="helpdesk-form-label"><?php p($l->t('user')); ?></label>
                                        <select id="user-select" name="user_id" required class="helpdesk-form-control" aria-required="true">
                                            <option value=""><?php p($l->t('select_a_user')); ?></option>
                                            <?php foreach ($availableUsers as $user): ?>
                                                <option value="<?php p($user['user_id']); ?>"><?php p($user['user_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="helpdesk-form-group">
                                        <label for="role-select" class="helpdesk-form-label"><?php p($l->t('role_label')); ?></label>
                                        <select id="role-select" name="role" required class="helpdesk-form-control" aria-required="true">
                                            <option value="Support User"><?php p($l->t('support_user')); ?></option>
                                            <option value="Agent"><?php p($l->t('agent')); ?></option>
                                            <option value="Admin"><?php p($l->t('admin')); ?></option>
                                        </select>
                                    </div>
                                    <div class="helpdesk-form-actions helpdesk-project-detail-form-actions">
                                        <button type="submit" class="helpdesk-btn helpdesk-btn--primary" aria-label="<?php p($l->t('add_team_member')); ?>"><?php p($l->t('add_member')); ?></button>
                                    </div>
                                    <div id="add-member-help" class="helpdesk-text-muted helpdesk-project-detail-form-help"><?php p($l->t('guests_cannot_be_team_members')); ?></div>
                                </form>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="helpdesk-card tc-project-detail-team__roles" aria-labelledby="role-legend-title">
                            <div class="helpdesk-card__header">
                                <h3 id="role-legend-title" class="helpdesk-card__title helpdesk-project-detail-card-title"><?php p($l->t('roles_and_permissions')); ?></h3>
                            </div>
                            <div class="helpdesk-card__body helpdesk-project-detail-legend-body">
                                <div class="helpdesk-project-detail-legend-row">
                                    <span class="helpdesk-badge"><?php p($l->t('support_user')); ?></span>
                                    <span class="helpdesk-text-muted"><?php p($l->t('work_on_tickets_assigned')); ?></span>
                                </div>
                                <div class="helpdesk-project-detail-legend-row">
                                    <span class="helpdesk-badge"><?php p($l->t('agent')); ?></span>
                                    <span class="helpdesk-text-muted"><?php p($l->t('view_all_project_tickets')); ?></span>
                                </div>
                                <div class="helpdesk-project-detail-legend-row">
                                    <span class="helpdesk-badge"><?php p($l->t('admin')); ?></span>
                                    <span class="helpdesk-text-muted"><?php p($l->t('manage_members_and_settings')); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if (!empty($_['canManage']) || !empty($_['guests'])): ?>
            <section class="tc-section" aria-labelledby="project-guests-heading">
                <div class="tc-project-detail__section-head">
                    <div>
                        <h2 id="project-guests-heading" class="tc-section__title"><?php p($l->t('guest_access')); ?></h2>
                        <p class="tc-section__lead"><?php p($l->t('project_detail_guests_lead')); ?></p>
                    </div>
                    <div class="tc-project-detail__section-actions">
                        <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.guestUser.create')); ?>" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm">
                            <?php p($l->t('create_guest_user')); ?>
                        </a>
                    </div>
                </div>

                <div class="helpdesk-card helpdesk-mb-md helpdesk-project-detail-guest-info-card">
                    <div class="helpdesk-card__body">
                        <div class="helpdesk-project-detail-guest-info-content">
                            <div class="helpdesk-project-detail-guest-info-icon-wrap" aria-hidden="true">
                                <?php print_unescaped(IconCatalog::render('info', 'helpdesk-project-detail-guest-info-icon')); ?>
                            </div>
                            <div class="helpdesk-project-detail-guest-info-main">
                                <h3 class="helpdesk-project-detail-guest-info-title">
                                    <?php p($l->t('what_are_guest_users')); ?>
                                </h3>
                                <p class="helpdesk-project-detail-guest-info-text">
                                    <?php p($l->t('guest_users_are_external')); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($_['canManageGuests']) && !empty($_['allGuests'])): ?>
                    <div class="helpdesk-card helpdesk-mb-md">
                        <div class="helpdesk-card__header">
                            <h3 class="helpdesk-card__title"><?php p($l->t('grant_guest_access')); ?></h3>
                        </div>
                        <div class="helpdesk-card__body">
                            <form id="grant-guest-access-form" data-project-id="<?php p($project['id']); ?>">
                                <div class="helpdesk-form-group">
                                    <label for="guest-select" class="helpdesk-form-label"><?php p($l->t('select_guest_user')); ?></label>
                                    <select id="guest-select" name="guest_user_id" required class="helpdesk-form-control" aria-required="true">
                                        <option value=""><?php p($l->t('choose_guest_user')); ?></option>
                                        <?php foreach ($_['allGuests'] as $guest): ?>
                                            <option value="<?php p($guest['user_id']); ?>">
                                                <?php p($guest['display_name']); ?> (<?php p($guest['email']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="helpdesk-form-actions">
                                    <button type="submit" class="helpdesk-btn helpdesk-btn--primary">
                                        <?php print_unescaped(IconCatalog::render('plus')); ?>
                                        <?php p($l->t('grant_access')); ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="helpdesk-card">
                    <div class="helpdesk-card__header">
                        <h3 class="helpdesk-card__title"><?php p($l->t('guests_with_access')); ?> (<?php p((string)count($_['guests'])); ?>)</h3>
                    </div>
                    <div class="helpdesk-card__body">
                        <?php if (empty($_['guests'])): ?>
                            <div class="helpdesk-empty">
                                <div class="helpdesk-empty__icon" aria-hidden="true">
                                    <?php print_unescaped(IconCatalog::render('user')); ?>
                                </div>
                                <h3 class="helpdesk-empty__title"><?php p($l->t('no_guest_access_yet')); ?></h3>
                                <p class="helpdesk-empty__text"><?php p($l->t('no_guests_access_project_description')); ?></p>
                            </div>
                        <?php else: ?>
                            <div class="helpdesk-grid helpdesk-grid--2">
                                <?php foreach ($_['guests'] as $guest): ?>
                                    <div class="helpdesk-member-card helpdesk-project-detail-member-card">
                                        <div class="helpdesk-member-card__info">
                                            <div class="helpdesk-member-avatar helpdesk-project-detail-guest-avatar" aria-hidden="true">
                                                <?php p(strtoupper(substr((string)($guest['display_name'] ?? '?'), 0, 1))); ?>
                                            </div>
                                            <div class="helpdesk-member-text">
                                                <div class="helpdesk-member-topline">
                                                    <span class="helpdesk-member-name"><?php p($guest['display_name']); ?></span>
                                                    <span class="helpdesk-badge helpdesk-project-detail-guest-badge"><?php p($l->t('guest')); ?></span>
                                                </div>
                                                <div class="helpdesk-member-email"><?php p($guest['email']); ?></div>
                                                <div class="helpdesk-member-meta">
                                                    <small class="helpdesk-text-muted">
                                                        <?php p($l->t('access_granted')); ?>: <?php p($formatDate($guest['granted_at'] ?? '')); ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="helpdesk-member-actions">
                                            <?php if (!empty($_['canManageGuests'])): ?>
                                            <button type="button" class="helpdesk-btn helpdesk-btn--sm helpdesk-btn--danger"
                                                data-revoke-guest-access
                                                data-user-id="<?php p($guest['user_id']); ?>"
                                                data-project-id="<?php p($project['id']); ?>"
                                                data-display-name="<?php p($guest['display_name']); ?>"
                                                aria-label="<?php p($l->t('revoke_access')); ?>">
                                                <?php print_unescaped(IconCatalog::render('trash-2')); ?>
                                                <span class="tc-project-detail-revoke-label"><?php p($l->t('revoke_access')); ?></span>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>
<?php include __DIR__ . '/../common/page-end.php'; ?>

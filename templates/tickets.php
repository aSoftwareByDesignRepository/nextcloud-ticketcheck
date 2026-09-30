<?php

/**
 * Ticket list template - Enhanced filters and better visual hierarchy
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

/** @var \OCP\IUser|null $currentUser */
$currentUser = $_['currentUser'] ?? null;
$currentUserId = $currentUser ? $currentUser->getUID() : null;

/** @var \OCP\IL10N $l */
$l = $_['l'];
/** @var \OCA\Ticketcheck\Service\LocaleFormatService|null $localeFormat */
$localeFormat = $_['localeFormat'] ?? null;
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

<?php include __DIR__ . '/common/tickets-filters.php'; ?>

            <section class="tc-section" aria-labelledby="tickets-list-heading">
                <h2 id="tickets-list-heading" class="tc-sr-only"><?php p($l->t('tickets_list_section')); ?></h2>

            <?php
            $ticketCount = count($_['tickets']);
            include __DIR__ . '/common/tickets-list-bar.php';
            ?>

            <!-- Ticket List -->
            <?php if (empty($_['tickets'])): ?>
                <div class="tc-empty helpdesk-empty tc-tickets-empty"
                    role="region"
                    aria-labelledby="tickets-empty-title">
                    <div class="helpdesk-empty__icon" aria-hidden="true">
                        <?php print_unescaped(IconCatalog::render('file-text')); ?>
                    </div>
                    <h3 id="tickets-empty-title" class="helpdesk-empty__title"><?php p($l->t('no_tickets_found')); ?></h3>
                    <p class="helpdesk-empty__text">
                        <?php if ($ticketsListHasActiveFilters): ?>
                            <?php p($l->t('try_adjusting_filters_or_create_ticket')); ?>
                        <?php else: ?>
                            <?php p($l->t('create_first_ticket_to_get_started')); ?>
                        <?php endif; ?>
                    </p>
                    <div class="tc-tickets-empty__actions">
                        <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.create')); ?>"
                            class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg">
                            <?php p($l->t('create_first_ticket')); ?>
                        </a>
                        <?php if ($ticketsListHasActiveFilters): ?>
                            <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.index')); ?>"
                                class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--lg">
                                <?php p($l->t('clear_filters')); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div id="bulk-actions-bar"
                    class="tc-bulk-bar"
                    role="region"
                    aria-label="<?php p($l->t('bulk_actions')); ?>"
                    hidden>
                    <p class="tc-bulk-bar__summary" id="bulk-selection-summary">
                        <span id="selected-count">0</span>
                        <?php p($l->t('tickets_selected')); ?>
                    </p>
                    <p id="bulk-export-hint" class="tc-sr-only"><?php p($l->t('export_bulk_selected_hint')); ?></p>
                    <div class="tc-bulk-bar__actions" role="group" aria-label="<?php p($l->t('bulk_actions')); ?>">
                        <button type="button" id="bulk-assign" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm">
                            <?php p($l->t('assign')); ?>
                        </button>
                        <button type="button" id="bulk-priority" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm">
                            <?php p($l->t('priority')); ?>
                        </button>
                        <button type="button" id="bulk-status" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm">
                            <?php p($l->t('status')); ?>
                        </button>
                        <button type="button" id="bulk-close" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm">
                            <?php p($l->t('close')); ?>
                        </button>
                        <?php if (!empty($_['canExportData'])): ?>
                            <button type="button"
                                id="bulk-export"
                                class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm"
                                data-tc-bulk-export
                                aria-describedby="bulk-export-hint">
                                <?php p($l->t('export_bulk_selected')); ?>
                            </button>
                        <?php endif; ?>
                        <?php if (!empty($_['canBulkDelete'])): ?>
                            <button type="button" id="bulk-delete" class="helpdesk-btn helpdesk-btn--danger helpdesk-btn--sm">
                                <?php p($l->t('delete')); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="helpdesk-ticket-list__toolbar helpdesk-mb-sm">
                    <label class="helpdesk-form-checkbox helpdesk-ticket-list__select-all" for="select-all-tickets">
                        <input type="checkbox" id="select-all-tickets">
                        <span><?php p($l->t('select_all_tickets')); ?></span>
                    </label>
                </div>

                <div class="tc-tickets-list">
                    <div class="tc-table-wrap">
                        <table class="helpdesk-table tc-table tc-tickets-table">
                            <caption class="tc-sr-only"><?php p($l->t('tickets_list_section')); ?></caption>
                            <colgroup>
                                <col class="tc-tickets-table__col-select">
                                <col class="tc-tickets-table__col-title">
                                <col class="tc-tickets-table__col-customer">
                                <col class="tc-tickets-table__col-created">
                                <col class="tc-tickets-table__col-category">
                                <col class="tc-tickets-table__col-status">
                                <col class="tc-tickets-table__col-priority">
                                <col class="tc-tickets-table__col-assignee">
                                <col class="tc-tickets-table__col-actions">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th scope="col" class="tc-tickets-table__select-col">
                                        <span class="tc-sr-only"><?php p($l->t('tickets_table_header_select')); ?></span>
                                    </th>
                                    <th scope="col"><?php p($l->t('title')); ?></th>
                                    <th scope="col"><?php p($l->t('customer')); ?></th>
                                    <th scope="col"><?php p($l->t('created')); ?></th>
                                    <th scope="col"><?php p($l->t('category')); ?></th>
                                    <th scope="col"><?php p($l->t('status')); ?></th>
                                    <th scope="col"><?php p($l->t('priority')); ?></th>
                                    <th scope="col"><?php p($l->t('assigned_to')); ?></th>
                                    <th scope="col"><?php p($l->t('actions')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($_['tickets'] as $ticket):
                                    $daysSinceUpdate = (new DateTime())->diff($ticket->getUpdatedAt())->days;
                                    $isOld = $daysSinceUpdate > 3 && !in_array($ticket->getStatus(), ['done', 'resolved', 'closed'], true);
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
                                    $ticketShowHref = $_['urlGenerator']->linkToRoute('ticketcheck.ticket.show', ['id' => $ticket->getId()]);
                                    ?>
                                    <tr class="tc-tickets-row tc-list-row--clickable <?php p($rowAccent); ?>"
                                        data-list-row-href="<?php p($ticketShowHref); ?>">
                                        <td class="tc-tickets-table__select tc-list-row__no-nav">
                                            <label class="helpdesk-form-checkbox helpdesk-ticket-card__select">
                                                <input type="checkbox"
                                                    class="ticket-checkbox"
                                                    data-ticket-id="<?php p((string)$ticket->getId()); ?>"
                                                    aria-label="<?php p(strtr($l->t('select_ticket'), ['{title}' => $ticket->getTitle()])); ?>">
                                            </label>
                                        </td>
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
                                            </div>
                                            <span class="tc-tickets-table__meta" aria-hidden="true">
                                                #<?php p($ticket->getTicketNumber()); ?>
                                            </span>
                                        </th>
                                        <td><?php p($ticket->getCustomerName()); ?></td>
                                        <td><?php p($createdLabel); ?></td>
                                        <td>
                                            <?php if ($ticket->getCategory()): ?>
                                                <span class="helpdesk-badge helpdesk-ticket-card__category-badge">
                                                    <?php p($l->t('category_' . strtolower($ticket->getCategory()))); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="helpdesk-text-muted"><?php p($l->t('none')); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="helpdesk-badge helpdesk-badge--<?php p(str_replace('_', '-', $ticket->getStatus())); ?>"
                                                title="<?php p($l->t('status_' . $ticket->getStatus())); ?>"
                                                aria-label="<?php p($l->t('status') . ': ' . $l->t('status_' . $ticket->getStatus())); ?>">
                                                <?php p($l->t('status_' . $ticket->getStatus())); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="helpdesk-badge helpdesk-badge--priority-<?php p($ticket->getPriority()); ?>">
                                                <?php p($l->t('priority_' . $ticket->getPriority())); ?>
                                            </span>
                                        </td>
                                        <td class="tc-tickets-table__assignee">
                                            <?php if ($ticket->getAssignedTo()): ?>
                                                <span class="helpdesk-text-muted">
                                                    <?php print_unescaped(IconCatalog::render('user', 'helpdesk-ticket-card__assignee-icon')); ?>
                                                    <?php p($ticket->getAssignedTo()); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="helpdesk-text-muted"><?php p($l->t('unassigned')); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="tc-tickets-table__actions tc-list-row__no-nav">
                                            <?php
                                            $listActionsViewHref = $ticketShowHref;
                                            $listActionsViewAriaLabel = $l->t('view_ticket_details');
                                            include __DIR__ . '/common/list-row-actions.php';
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <ul class="tc-card-list" aria-label="<?php p($l->t('tickets_list_section')); ?>">
                        <?php foreach ($_['tickets'] as $ticket):
                            $daysSinceUpdate = (new DateTime())->diff($ticket->getUpdatedAt())->days;
                            $isOld = $daysSinceUpdate > 3 && !in_array($ticket->getStatus(), ['done', 'resolved', 'closed'], true);
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
                            $ticketShowHref = $_['urlGenerator']->linkToRoute('ticketcheck.ticket.show', ['id' => $ticket->getId()]);
                            ?>
                            <li class="tc-card helpdesk-card tc-list-row--clickable <?php p($borderClass); ?>"
                                data-list-row-href="<?php p($ticketShowHref); ?>">
                                <div class="helpdesk-card__body">
                                    <label class="helpdesk-form-checkbox helpdesk-ticket-card__select tc-list-row__no-nav">
                                        <input type="checkbox"
                                            class="ticket-checkbox"
                                            data-ticket-id="<?php p((string)$ticket->getId()); ?>"
                                            aria-label="<?php p(strtr($l->t('select_ticket'), ['{title}' => $ticket->getTitle()])); ?>">
                                    </label>
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
                                            </h3>
                                            <div class="helpdesk-text-muted helpdesk-ticket-card__meta">
                                                #<?php p($ticket->getTicketNumber()); ?> •
                                                <?php p($ticket->getCustomerName()); ?> •
                                                <?php p($createdLabel); ?>
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
                                            <span class="helpdesk-badge helpdesk-badge--<?php p(str_replace('_', '-', $ticket->getStatus())); ?>"
                                                title="<?php p($l->t('status_' . $ticket->getStatus())); ?>"
                                                aria-label="<?php p($l->t('status') . ': ' . $l->t('status_' . $ticket->getStatus())); ?>">
                                                <?php p($l->t('status_' . $ticket->getStatus())); ?>
                                            </span>
                                            <span class="helpdesk-badge helpdesk-badge--priority-<?php p($ticket->getPriority()); ?>">
                                                <?php p($l->t('priority_' . $ticket->getPriority())); ?>
                                            </span>
                                            <?php if ($ticket->getAssignedTo()): ?>
                                                <div class="helpdesk-text-muted helpdesk-ticket-card__assignee">
                                                    <?php print_unescaped(IconCatalog::render('user', 'helpdesk-ticket-card__assignee-icon')); ?>
                                                    <?php p($ticket->getAssignedTo()); ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="helpdesk-text-muted helpdesk-ticket-card__unassigned">
                                                    <?php p($l->t('unassigned')); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="helpdesk-ticket-card__actions tc-list-row__no-nav">
                                        <?php
                                        $listActionsViewHref = $ticketShowHref;
                                        $listActionsViewAriaLabel = $l->t('view_ticket_details');
                                        include __DIR__ . '/common/list-row-actions.php';
                                        ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            </section>

<?php include __DIR__ . '/common/page-end.php'; ?>
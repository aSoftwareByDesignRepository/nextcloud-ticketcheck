<?php

/**
 * Ticket kanban board
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

/** @var \OCP\IL10N $l */
$l = $_['l'];
/** @var \OCA\Ticketcheck\Service\LocaleFormatService|null $localeFormat */
$localeFormat = $_['localeFormat'] ?? null;
$tickets = $_['tickets'] ?? [];
$columns = [
    'new' => [],
    'in_progress' => [],
    'waiting' => [],
    'done' => [],
];

foreach ($tickets as $ticket) {
    $status = $ticket->getStatus();
    if ($status === 'working' || $status === 'open') {
        $status = 'in_progress';
    } elseif ($status === 'resolved' || $status === 'closed') {
        $status = 'done';
    }
    if (!isset($columns[$status])) {
        $status = 'new';
    }
    $columns[$status][] = $ticket;
}

$priorityBadgeClass = static function (string $priority): string {
    return match ($priority) {
        'urgent' => 'tc-badge--critical',
        'high' => 'tc-badge--warning',
        'low' => 'tc-badge--muted',
        default => 'tc-badge--default',
    };
};

$statusBadgeClass = static function (string $status): string {
    if (in_array($status, ['working', 'open', 'in_progress'], true)) {
        return 'helpdesk-badge helpdesk-badge--in-progress';
    }
    if (in_array($status, ['resolved', 'closed', 'done'], true)) {
        return 'helpdesk-badge helpdesk-badge--done';
    }
    if ($status === 'waiting') {
        return 'helpdesk-badge helpdesk-badge--waiting';
    }

    return 'helpdesk-badge helpdesk-badge--new';
};

$kanbanColumnClass = static function (string $columnStatus): string {
    return 'tc-kanban__column tc-kanban__column--' . str_replace('_', '-', $columnStatus);
};
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

<?php include __DIR__ . '/common/tickets-filters.php'; ?>

            <?php
            $ticketCount = count($tickets);
            include __DIR__ . '/common/tickets-list-bar.php';
            ?>

            <div class="tc-kanban-scroll">
            <div class="tc-kanban" role="region" aria-label="<?php p($l->t('kanban_board')); ?>" aria-describedby="tc-kanban-dnd-hint">
                <p id="tc-kanban-dnd-hint" class="tc-sr-only"><?php p($l->t('kanban_dnd_hint')); ?></p>
                <?php foreach ($columns as $status => $items): ?>
                    <section class="<?php p($kanbanColumnClass($status)); ?>"
                        aria-labelledby="tc-kanban-col-<?php p($status); ?>"
                        data-kanban-status="<?php p($status); ?>">
                        <header class="tc-kanban__column-header">
                            <h2 id="tc-kanban-col-<?php p($status); ?>" class="tc-kanban__column-title">
                                <span class="tc-kanban__column-marker" aria-hidden="true"></span>
                                <span class="tc-kanban__column-label"><?php p($l->t('status_' . $status)); ?></span>
                                <span class="tc-kanban__count" data-kanban-count>(<?php p((string) count($items)); ?>)</span>
                            </h2>
                        </header>
                        <ul class="tc-kanban__cards" data-kanban-cards>
                            <li class="tc-kanban__empty" <?php if ($items !== []): ?>hidden<?php endif; ?>>
                                <p class="tc-muted"><?php p($l->t('none')); ?></p>
                            </li>
                            <?php if ($items !== []): ?>
                                <?php foreach ($items as $item):
                                    $ticketUrl = $_['urlGenerator']->linkToRoute('ticketcheck.ticket.show', ['id' => $item->getId()]);
                                    $priority = (string) $item->getPriority();
                                    $rawStatus = (string) $item->getStatus();
                                    $createdAt = $item->getCreatedAt();
                                    $createdLabel = $localeFormat !== null
                                        ? $localeFormat->formatDate($createdAt->format('Y-m-d'), 'medium', $l)
                                        : $createdAt->format('Y-m-d');
                                    ?>
                                    <li class="tc-kanban__card"
                                        data-ticket-id="<?php p((string) $item->getId()); ?>"
                                        data-ticket-title="<?php p($item->getTitle()); ?>"
                                        data-current-status="<?php p($status); ?>">
                                        <div class="tc-kanban__card-controls">
                                            <button type="button"
                                                class="tc-kanban__drag-handle"
                                                draggable="true"
                                                aria-label="<?php p(strtr($l->t('kanban_drag_handle'), [
                                                    '{title}' => $item->getTitle(),
                                                ])); ?>"
                                                title="<?php p($l->t('kanban_drag_handle_title')); ?>">
                                                <span aria-hidden="true"><?php print_unescaped(IconCatalog::render('grip-vertical')); ?></span>
                                            </button>
                                            <label class="tc-sr-only" for="tc-kanban-status-<?php p((string) $item->getId()); ?>">
                                                <?php p(strtr($l->t('kanban_change_status_for'), [
                                                    '{title}' => $item->getTitle(),
                                                ])); ?>
                                            </label>
                                            <span class="tc-kanban__status-wrap">
                                                <select id="tc-kanban-status-<?php p((string) $item->getId()); ?>"
                                                    class="tc-kanban__status-select"
                                                    data-kanban-status-select
                                                    data-ticket-id="<?php p((string) $item->getId()); ?>"
                                                    aria-label="<?php p(strtr($l->t('kanban_change_status_for'), [
                                                        '{title}' => $item->getTitle(),
                                                    ])); ?>">
                                                    <?php foreach (array_keys($columns) as $columnStatus): ?>
                                                        <option value="<?php p($columnStatus); ?>" <?php if ($columnStatus === $status): ?>selected<?php endif; ?>><?php p($l->t('status_' . $columnStatus)); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <span class="tc-kanban__status-chevron" aria-hidden="true"><?php print_unescaped(IconCatalog::render('chevron-right')); ?></span>
                                            </span>
                                        </div>
                                        <a href="<?php p($ticketUrl); ?>"
                                            class="tc-card tc-card--interactive"
                                            aria-label="<?php
                                                $kanbanAriaParts = [
                                                    strtr($l->t('view_ticket_number_title'), [
                                                        '{number}' => (string) $item->getTicketNumber(),
                                                        '{title}' => $item->getTitle(),
                                                    ]),
                                                    $l->t('priority_' . $priority),
                                                ];
                                                if ($item->getCategory()) {
                                                    $kanbanAriaParts[] = $l->t('category_' . strtolower((string) $item->getCategory()));
                                                }
                                                p(implode(', ', $kanbanAriaParts));
                                            ?>">
                                            <div class="tc-card__body">
                                                <h3 class="tc-kanban__card-title">
                                                    <span class="tc-sr-only"><?php p($l->t('ticket')); ?> </span>
                                                    #<?php p((string) $item->getTicketNumber()); ?> — <?php p($item->getTitle()); ?>
                                                </h3>
                                                <p class="tc-kanban__card-meta tc-muted">
                                                    <?php p($item->getCustomerName()); ?>
                                                    · <?php p($createdLabel); ?>
                                                </p>
                                                <div class="tc-kanban__card-footer">
                                                    <span class="<?php p($statusBadgeClass($rawStatus)); ?> tc-kanban__status-badge"
                                                        data-kanban-card-status
                                                        title="<?php p($l->t('status_' . $rawStatus)); ?>"
                                                        aria-hidden="true">
                                                        <?php p($l->t('status_' . $rawStatus)); ?>
                                                    </span>
                                                    <span class="tc-badge <?php p($priorityBadgeClass($priority)); ?>" aria-hidden="true">
                                                        <?php p($l->t('priority_' . $priority)); ?>
                                                    </span>
                                                    <?php if ($item->getCategory()): ?>
                                                        <span class="tc-badge tc-badge--muted" aria-hidden="true">
                                                            <?php p($l->t('category_' . strtolower((string) $item->getCategory()))); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </section>
                <?php endforeach; ?>
            </div>
            </div>

<?php include __DIR__ . '/common/page-end.php'; ?>

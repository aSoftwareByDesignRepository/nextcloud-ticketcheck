<?php

/**
 * Customer portal home - Enhanced design matching main app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

/** @var \OCP\IL10N $l */
$l = $_['l'];
/** @var \OCA\Ticketcheck\Service\LocaleFormatService|null $localeFormat */
$localeFormat = $_['localeFormat'] ?? null;
$stats = is_array($_['stats'] ?? null) ? $_['stats'] : [];
$showKnowledgeBase = !empty($_['showKnowledgeBase']);
$todayIso = (new \DateTimeImmutable())->format('Y-m-d');
$todayHuman = $localeFormat !== null
	? $localeFormat->formatDate($todayIso, 'long', $l)
	: $todayIso;
?>
<?php include __DIR__ . '/../common/page-start.php'; ?>

            <section class="tc-section" aria-labelledby="portal-stats-heading">
                <h2 id="portal-stats-heading" class="tc-sr-only"><?php p($l->t('dashboard_section_overview')); ?></h2>
                <?php
                $portalTicketsFilterBase = $_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.myTickets');
                $portalFilterUrl = static function (string $status) use ($portalTicketsFilterBase): string {
                    if ($status === '') {
                        return $portalTicketsFilterBase;
                    }
                    return $portalTicketsFilterBase . '?' . http_build_query(['status' => $status], '', '&', PHP_QUERY_RFC3986);
                };
                ?>
                <div class="tc-dashboard-kpis helpdesk-mb-lg" role="group" aria-label="<?php p($l->t('dashboard_section_overview')); ?>">
                    <?php if (($stats['waiting'] ?? 0) > 0): ?>
                        <a href="<?php p($portalFilterUrl('waiting')); ?>"
                            class="tc-dashboard-kpi helpdesk-card helpdesk-card--interactive portal-card-clickable"
                            aria-label="<?php p($l->t('filter_by')); ?>: <?php p($l->t('needs_response')); ?>">
                            <span class="tc-dashboard-kpi__icon tc-dashboard-kpi__icon--accent" aria-hidden="true">
                                <?php print_unescaped(IconCatalog::render('alert-triangle')); ?>
                            </span>
                            <span class="tc-dashboard-kpi__body">
                                <span class="tc-dashboard-kpi__value"><?php p((string)(int)$stats['waiting']); ?></span>
                                <span class="tc-dashboard-kpi__label"><?php p($l->t('needs_response')); ?></span>
                                <span class="tc-dashboard-kpi__meta"><?php p($l->t('action_required')); ?></span>
                            </span>
                        </a>
                    <?php endif; ?>

                    <a href="<?php p($portalFilterUrl('new')); ?>"
                        class="tc-dashboard-kpi helpdesk-card helpdesk-card--interactive portal-card-clickable<?php if ((int)($stats['new'] ?? 0) === 0) { ?> tc-dashboard-kpi--zero<?php } ?>"
                        aria-label="<?php p($l->t('filter_by')); ?>: <?php p($l->t('new_tickets')); ?>">
                        <span class="tc-dashboard-kpi__icon tc-dashboard-kpi__icon--primary" aria-hidden="true">
                            <?php print_unescaped(IconCatalog::render('plus-circle')); ?>
                        </span>
                        <span class="tc-dashboard-kpi__body">
                            <span class="tc-dashboard-kpi__value"><?php p((string)(int)($stats['new'] ?? 0)); ?></span>
                            <span class="tc-dashboard-kpi__label"><?php p($l->t('new_tickets')); ?></span>
                            <span class="tc-dashboard-kpi__meta"><?php p($l->t('recently_created')); ?></span>
                        </span>
                    </a>

                    <a href="<?php p($portalFilterUrl('in_progress')); ?>"
                        class="tc-dashboard-kpi helpdesk-card helpdesk-card--interactive portal-card-clickable<?php if ((int)($stats['in_progress'] ?? 0) === 0) { ?> tc-dashboard-kpi--zero<?php } ?>"
                        aria-label="<?php p($l->t('filter_by')); ?>: <?php p($l->t('in_progress_tickets')); ?>">
                        <span class="tc-dashboard-kpi__icon tc-dashboard-kpi__icon--accent" aria-hidden="true">
                            <?php print_unescaped(IconCatalog::render('clock')); ?>
                        </span>
                        <span class="tc-dashboard-kpi__body">
                            <span class="tc-dashboard-kpi__value"><?php p((string)(int)($stats['in_progress'] ?? 0)); ?></span>
                            <span class="tc-dashboard-kpi__label"><?php p($l->t('in_progress_tickets')); ?></span>
                            <span class="tc-dashboard-kpi__meta"><?php p($l->t('being_worked_on')); ?></span>
                        </span>
                    </a>

                    <a href="<?php p($portalFilterUrl('done')); ?>"
                        class="tc-dashboard-kpi helpdesk-card helpdesk-card--interactive portal-card-clickable<?php if ((int)($stats['done'] ?? 0) === 0) { ?> tc-dashboard-kpi--zero<?php } ?>"
                        aria-label="<?php p($l->t('filter_by')); ?>: <?php p($l->t('resolved_tickets')); ?>">
                        <span class="tc-dashboard-kpi__icon tc-dashboard-kpi__icon--success" aria-hidden="true">
                            <?php print_unescaped(IconCatalog::render('check-circle')); ?>
                        </span>
                        <span class="tc-dashboard-kpi__body">
                            <span class="tc-dashboard-kpi__value"><?php p((string)(int)($stats['done'] ?? 0)); ?></span>
                            <span class="tc-dashboard-kpi__label"><?php p($l->t('resolved_tickets')); ?></span>
                            <span class="tc-dashboard-kpi__meta"><?php p($l->t('completed_tickets')); ?></span>
                        </span>
                    </a>
                </div>
            </section>

            <nav class="portal-shortcuts helpdesk-mb-lg tc-section" aria-labelledby="portal-shortcuts-heading">
                <h2 id="portal-shortcuts-heading" class="tc-section__title"><?php p($l->t('quick_actions')); ?></h2>
                <p class="tc-section__lead"><?php p($l->t('get_help_from_team')); ?></p>
                <div class="portal-shortcuts__list">
                    <?php if (!empty($_['canCreateTicket'])): ?>
                        <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.createTicket')); ?>"
                            class="portal-shortcut portal-shortcut--primary">
                            <span class="portal-shortcut__icon portal-shortcut__icon--primary" aria-hidden="true"><?php print_unescaped(IconCatalog::render('plus-circle')); ?></span>
                            <span class="portal-shortcut__main">
                                <span class="portal-shortcut__title"><?php p($l->t('create_new_ticket')); ?></span>
                                <span class="portal-shortcut__desc helpdesk-text-muted"><?php p($l->t('start_new_support_request')); ?></span>
                            </span>
                        </a>
                    <?php else: ?>
                        <div class="portal-shortcut portal-shortcut--disabled"
                            role="status"
                            aria-label="<?php p($l->t('guest_create_ticket_unavailable_title')); ?>">
                            <span class="portal-shortcut__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('plus-circle')); ?></span>
                            <span class="portal-shortcut__main">
                                <span class="portal-shortcut__title"><?php p($l->t('create_new_ticket')); ?></span>
                                <span class="portal-shortcut__desc helpdesk-text-muted"><?php p($l->t('guest_create_ticket_requires_active_project')); ?></span>
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if ($showKnowledgeBase): ?>
                        <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.kpKnowledgeBase')); ?>"
                            class="portal-shortcut">
                            <span class="portal-shortcut__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('book')); ?></span>
                            <span class="portal-shortcut__main">
                                <span class="portal-shortcut__title"><?php p($l->t('knowledge_base')); ?></span>
                                <span class="portal-shortcut__desc helpdesk-text-muted"><?php p($l->t('find_answers_to_common_questions')); ?></span>
                            </span>
                        </a>
                    <?php endif; ?>
                </div>
            </nav>

            <!-- Your Tickets Section -->
            <section class="tc-section portal-tickets-section" aria-labelledby="portal-your-tickets-heading">
                <h2 id="portal-your-tickets-heading" class="tc-section__title helpdesk-section-title"><?php p($l->t('your_tickets')); ?></h2>

                <?php
                $filterMode = 'js';
                $currentStatusFilter = '';
                $filterBaseUrl = '';
                include __DIR__ . '/../common/portal-status-quick-filters.php';
                ?>

                <details class="tc-filter-more helpdesk-mb-md">
                    <summary class="tc-filter-more__summary tc-tickets-filters__panel-toggle" id="portal-home-filters-toggle">
                        <?php p($l->t('advanced_filters')); ?>
                    </summary>
                    <div class="tc-filter-more__body helpdesk-filter-bar">
                        <div class="helpdesk-filter-bar__group portal-filter-group tc-form-grid">
                            <div class="tc-field">
                                <label class="tc-field__label" for="filter-priority"><?php p($l->t('priority')); ?></label>
                                <select id="filter-priority" class="helpdesk-form-control portal-filter-select portal-filter-select--priority">
                                    <option value=""><?php p($l->t('all_urgency')); ?></option>
                                    <option value="urgent"><?php p($l->t('urgent_priority')); ?></option>
                                    <option value="high"><?php p($l->t('high')); ?></option>
                                    <option value="normal"><?php p($l->t('normal')); ?></option>
                                    <option value="low"><?php p($l->t('low')); ?></option>
                                </select>
                            </div>
                            <div class="tc-field">
                                <label class="tc-field__label" for="filter-category"><?php p($l->t('category')); ?></label>
                                <select id="filter-category" class="helpdesk-form-control portal-filter-select portal-filter-select--category">
                                    <option value=""><?php p($l->t('all_categories')); ?></option>
                                    <?php foreach ($_['categories'] as $category): ?>
                                        <option value="<?php p($category); ?>"><?php p($l->t('category_' . strtolower($category))); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="tc-field tc-field--checkbox">
                                <label class="helpdesk-form-checkbox portal-filter-checkbox" for="filter-hide-done">
                                    <input type="checkbox"
                                        id="filter-hide-done"
                                        class="portal-filter-checkbox__input">
                                    <span class="portal-filter-checkbox__label">
                                        <?php p($l->t('show_done_tickets')); ?>
                                    </span>
                                </label>
                            </div>
                        </div>
                        <div class="helpdesk-filter-bar__group portal-filter-group portal-filter-group--sort">
                            <div class="tc-field">
                                <label class="tc-field__label" for="sort-by"><?php p($l->t('sort_by')); ?></label>
                                <select id="sort-by" class="helpdesk-form-control portal-filter-select portal-filter-select--sort">
                                    <option value="newest"><?php p($l->t('newest')); ?></option>
                                    <option value="oldest"><?php p($l->t('oldest')); ?></option>
                                    <option value="priority"><?php p($l->t('priority_sort')); ?></option>
                                    <option value="status"><?php p($l->t('status_sort')); ?></option>
                                    <option value="title"><?php p($l->t('title_sort')); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                </details>
                <div id="portal-active-status-filter" class="tc-sr-only" aria-live="polite" aria-atomic="true"></div>

                <!-- Tickets List -->
                <?php if (empty($_['tickets'])): ?>
                    <div class="helpdesk-empty">
                        <div class="helpdesk-empty__icon" aria-hidden="true">
                            <?php print_unescaped(IconCatalog::render('file-text', 'helpdesk-empty__icon-svg')); ?>
                        </div>
                        <h3 class="helpdesk-empty__title"><?php p($l->t('no_tickets_yet')); ?></h3>
                        <p class="helpdesk-empty__text">
                            <?php p($l->t('thats_great_everything_working_fine')); ?>
                        </p>
                        <?php if (!empty($_['canCreateTicket'])): ?>
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
                    </div>
                <?php else: ?>
                    <?php
                    $projectNames = [];
                    if (!empty($_['projects'])) {
                        foreach ($_['projects'] as $proj) {
                            if (isset($proj['id']) && isset($proj['name'])) {
                                $projectNames[(int)$proj['id']] = $proj['name'];
                            }
                        }
                    }
                    $_['portalProjectNames'] = $projectNames;
                    $_['portalShowProjectColumn'] = count($projectNames) > 1;
                    $_['ticketsListHasActiveFilters'] = false;
                    $_['filterBaseUrl'] = $_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.index');
                    ?>
                    <div id="tickets-container"
                        class="portal-home-tickets"
                        data-empty-title="<?php p($l->t('no_tickets_match')); ?>"
                        data-empty-text="<?php p($l->t('try_changing_filters')); ?>">
                        <?php include __DIR__ . '/../common/portal-tickets-list.php'; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Help Tip -->
            <aside class="helpdesk-alert helpdesk-alert--info portal-help-tip" aria-live="polite" aria-labelledby="portal-help-tip-title">
                <div class="helpdesk-alert__icon" aria-hidden="true">
                    <?php print_unescaped(IconCatalog::render('info')); ?>
                </div>
                <div class="helpdesk-alert__content">
                    <div class="helpdesk-alert__title" id="portal-help-tip-title"><?php p($l->t('pro_tip')); ?></div>
                    <div class="helpdesk-alert__text">
                        <?php p($l->t('pro_tip_text')); ?>
                    </div>
                </div>
            </aside>

<?php include __DIR__ . '/../common/page-end.php'; ?>

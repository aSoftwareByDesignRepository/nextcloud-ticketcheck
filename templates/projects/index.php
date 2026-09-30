<?php

/**
 * Projects index — list, filters, responsive table + card layout
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

$_['isGuest'] = false;
$_['isAdmin'] = $_['isAdmin'] ?? false;

/** @var \OCP\IL10N $l */
$l = $_['l'];

$projects = is_array($_['projects'] ?? null) ? $_['projects'] : [];
$customers = is_array($_['customers'] ?? null) ? $_['customers'] : [];
$customerIdFilter = $_['customer_id_filter'] ?? null;
$includeInactive = !empty($_['includeInactive']);
$search = (string)($_['search'] ?? '');
$projectsIndexHasActiveFilters = $search !== '' || $includeInactive || ($customerIdFilter !== null && $customerIdFilter > 0);
$clearFiltersUrl = $_['urlGenerator']->linkToRoute('ticketcheck.project.index');
$canCreateProject = !empty($_['canManage']) || !empty($_['isAdmin']);
$createProjectUrl = $_['urlGenerator']->linkToRoute('ticketcheck.project.create');
?>

<?php include __DIR__ . '/../common/page-start.php'; ?>

            <section class="tc-section" aria-labelledby="projects-filters-heading">
                <h2 id="projects-filters-heading" class="tc-section__title"><?php p($l->t('projects_filters_title')); ?></h2>
                <p class="tc-section__lead"><?php p($l->t('projects_filters_lead')); ?></p>
                <form method="GET" action="" class="helpdesk-filter-bar helpdesk-filter-bar--projects helpdesk-list-page__filters tc-filter-grid" role="search" aria-label="<?php p($l->t('projects_filters_title')); ?>">
                    <div class="helpdesk-filter-bar__group tc-field">
                        <label for="customer_id" class="helpdesk-form-label"><?php p($l->t('Customer')); ?></label>
                        <select id="customer_id" name="customer_id" class="helpdesk-form-control">
                            <option value=""><?php p($l->t('all_customers')); ?></option>
                            <?php foreach ($customers as $cust): ?>
                                <?php $cid = (int)($cust['id'] ?? 0); ?>
                                <?php if ($cid <= 0) {
                                    continue;
                                } ?>
                                <option value="<?php p((string)$cid); ?>" <?php if ($customerIdFilter !== null && (int)$customerIdFilter === $cid) {
                                    p('selected');
                                } ?>><?php p((string)($cust['name'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="helpdesk-filter-bar__group tc-field">
                        <label for="project-search" class="helpdesk-form-label"><?php p($l->t('search_projects')); ?></label>
                        <span class="helpdesk-input-with-icon">
                            <?php print_unescaped(IconCatalog::render('search', 'helpdesk-input-icon')); ?>
                            <input id="project-search"
                                type="text"
                                name="search"
                                value="<?php p($search); ?>"
                                placeholder="<?php p($l->t('search_projects_dots')); ?>"
                                class="helpdesk-form-control helpdesk-search-input helpdesk-projects-search-input"
                                autocomplete="off"
                                list="project-search-suggestions"
                                aria-describedby="project-search-live-hint">
                        </span>
                        <datalist id="project-search-suggestions">
                            <?php foreach ($projects as $suggestion): ?>
                                <?php if (!empty($suggestion['name'])): ?>
                                    <option value="<?php p((string)$suggestion['name']); ?>"></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </datalist>
                        <p id="project-search-live-hint" class="tc-sr-only"><?php p($l->t('projects_search_live_hint')); ?></p>
                    </div>
                    <div class="helpdesk-filter-bar__group tc-field tc-field--checkbox">
                        <span class="helpdesk-form-label" id="include-inactive-label"><?php p($l->t('inactive')); ?></span>
                        <label class="helpdesk-form-checkbox tc-boolean-control" for="include-inactive">
                            <input type="checkbox"
                                id="include-inactive"
                                name="includeInactive"
                                value="1"
                                aria-labelledby="include-inactive-label"
                                <?php if ($includeInactive) {
                                    p('checked');
                                } ?>>
                            <span><?php p($l->t('show_inactive')); ?></span>
                        </label>
                    </div>
                    <div class="helpdesk-filter-bar__group helpdesk-filter-bar__group--actions tc-field tc-field--actions">
                        <a href="<?php p($clearFiltersUrl); ?>" class="helpdesk-btn helpdesk-btn--secondary"><?php p($l->t('clear_button')); ?></a>
                        <button type="submit" class="helpdesk-btn helpdesk-btn--primary"><?php p($l->t('apply_button')); ?></button>
                    </div>
                </form>
            </section>

            <div class="tc-projects-list-bar helpdesk-list-page__meta">
                <p class="helpdesk-text-muted helpdesk-projects-result-count tc-projects-list-bar__count"
                   role="status"
                   aria-live="polite"
                   aria-atomic="true">
                    <?php p($l->t('showing')); ?>
                    <strong><?php p(count($projects)); ?></strong>
                    <?php p(count($projects) !== 1 ? $l->t('projects_lowercase') : $l->t('project_lowercase')); ?>
                </p>
            </div>

            <?php if (empty($projects)): ?>
                <div class="helpdesk-empty helpdesk-list-page__content" role="region" aria-labelledby="projects-empty-title">
                    <div class="helpdesk-empty__icon" aria-hidden="true">
                        <?php print_unescaped(IconCatalog::render('clipboard')); ?>
                    </div>
                    <?php if ($projectsIndexHasActiveFilters): ?>
                        <h3 id="projects-empty-title" class="helpdesk-empty__title"><?php p($l->t('no_projects_match')); ?></h3>
                        <p class="helpdesk-empty__text"><?php p($l->t('projects_try_changing_filters')); ?></p>
                    <?php else: ?>
                        <h3 id="projects-empty-title" class="helpdesk-empty__title"><?php p($l->t('no_projects_yet')); ?></h3>
                        <p class="helpdesk-empty__text"><?php p($l->t('projects_empty_description')); ?></p>
                    <?php endif; ?>
                    <div class="helpdesk-form-actions helpdesk-form-actions--start helpdesk-empty__actions">
                        <?php if ($projectsIndexHasActiveFilters): ?>
                            <a href="<?php p($clearFiltersUrl); ?>" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--lg"><?php p($l->t('clear_filters')); ?></a>
                        <?php endif; ?>
                        <?php if ($canCreateProject): ?>
                            <a href="<?php p($createProjectUrl); ?>"
                                class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg">
                                <?php print_unescaped(IconCatalog::render('plus', 'tc-icon--inline')); ?>
                                <?php p($l->t('create_new_project')); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <section class="tc-section" aria-labelledby="projects-list-heading">
                    <h2 id="projects-list-heading" class="tc-sr-only"><?php p($l->t('projects_list_section')); ?></h2>
                    <div class="tc-projects-list">
                        <div class="tc-table-wrap">
                            <table class="helpdesk-table tc-table tc-projects-table">
                                <caption class="tc-sr-only"><?php p($l->t('projects_list_section')); ?></caption>
                                <thead>
                                    <tr>
                                        <th scope="col"><?php p($l->t('project')); ?></th>
                                        <th scope="col"><?php p($l->t('Customer')); ?></th>
                                        <th scope="col"><?php p($l->t('tickets')); ?></th>
                                        <th scope="col"><?php p($l->t('members')); ?></th>
                                        <th scope="col"><?php p($l->t('status')); ?></th>
                                        <th scope="col"><?php p($l->t('actions')); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($projects as $project): ?>
                                        <?php
                                        $pid = (int)($project['id'] ?? 0);
                                        $isInactive = isset($project['active']) && (int)$project['active'] === 0;
                                        $showHref = $_['urlGenerator']->linkToRoute('ticketcheck.project.show', ['id' => $pid]);
                                        $customerName = (string)($project['customer_name'] ?? '');
                                        ?>
                                        <tr class="tc-projects-row tc-list-row--clickable"
                                            data-list-row-href="<?php p($showHref); ?>"
                                            data-projects-index-row="<?php p((string)$pid); ?>">
                                            <th scope="row" class="tc-projects-table__name">
                                                <a class="tc-list-row__name tc-projects-table__title-link"
                                                    href="<?php p($showHref); ?>"
                                                    aria-label="<?php p(trim((string)($project['name'] ?? '') . ': ' . $l->t('view_project'))); ?>">
                                                    <?php p($project['name'] ?? ''); ?>
                                                </a>
                                            </th>
                                            <td>
                                                <?php if ($customerName !== ''): ?>
                                                    <?php p($customerName); ?>
                                                <?php else: ?>
                                                    <span class="helpdesk-text-muted"><?php p($l->t('no_customer_assigned')); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php p((string)($project['ticket_count'] ?? 0)); ?></td>
                                            <td><?php p((string)($project['member_count'] ?? 0)); ?></td>
                                            <td>
                                                <?php if ($isInactive): ?>
                                                    <span class="helpdesk-badge helpdesk-badge--inactive" aria-label="<?php p($l->t('project_inactive')); ?>"><?php p($l->t('inactive_status')); ?></span>
                                                <?php else: ?>
                                                    <span class="helpdesk-badge helpdesk-badge--success" aria-label="<?php p($l->t('active_status')); ?>"><?php p($l->t('active_status')); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="tc-projects-table__actions tc-list-row__no-nav">
                                                <?php
                                                $listActionsIconOnly = true;
                                                $listActionsViewHref = $showHref;
                                                $listActionsViewAriaLabel = $l->t('view_project');
                                                $listActionsEditButton = !empty($project['can_manage']) ? [
                                                    'class' => 'helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm',
                                                    'attrs' => ['data-edit-project-id' => (string)$pid],
                                                    'ariaLabel' => $l->t('edit_project'),
                                                ] : null;
                                                $listActionsDelete = !empty($project['can_manage']) ? [
                                                    'class' => 'helpdesk-btn helpdesk-btn--danger helpdesk-btn--sm helpdesk-project-card__delete-btn',
                                                    'attrs' => ['data-delete-project-id' => (string)$pid],
                                                    'ariaLabel' => $l->t('delete_project'),
                                                ] : null;
                                                include __DIR__ . '/../common/list-row-actions.php';
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <ul class="tc-card-list tc-projects-card-list" aria-label="<?php p($l->t('projects_list_section')); ?>">
                            <?php foreach ($projects as $project): ?>
                                <?php
                                $pid = (int)($project['id'] ?? 0);
                                $isInactive = isset($project['active']) && (int)$project['active'] === 0;
                                $showHref = $_['urlGenerator']->linkToRoute('ticketcheck.project.show', ['id' => $pid]);
                                $customerName = (string)($project['customer_name'] ?? '');
                                ?>
                                <li class="tc-card helpdesk-card tc-projects-card tc-list-row--clickable<?php if ($isInactive) {
                                    p(' helpdesk-project-card is-inactive');
                                } ?>"
                                    data-list-row-href="<?php p($showHref); ?>"
                                    data-projects-index-row="<?php p((string)$pid); ?>">
                                    <div class="helpdesk-card__body">
                                        <div class="helpdesk-project-card__header">
                                            <h3 class="helpdesk-project-card__title">
                                                <a class="helpdesk-project-card__title-link"
                                                    href="<?php p($showHref); ?>"
                                                    aria-label="<?php p(trim((string)($project['name'] ?? '') . ': ' . $l->t('view_project'))); ?>">
                                                    <span class="helpdesk-project-card__title-text"><?php p($project['name'] ?? ''); ?></span>
                                                </a>
                                            </h3>
                                            <?php if ($isInactive): ?>
                                                <span class="helpdesk-badge helpdesk-badge--inactive" aria-label="<?php p($l->t('project_inactive')); ?>"><?php p($l->t('inactive_status')); ?></span>
                                            <?php endif; ?>
                                            <?php if ($customerName !== ''): ?>
                                                <div class="helpdesk-text-muted helpdesk-project-card__customer">
                                                    <?php print_unescaped(IconCatalog::render('user', 'helpdesk-project-card__customer-icon')); ?>
                                                    <?php p($customerName); ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="helpdesk-text-muted helpdesk-project-card__customer-empty"><?php p($l->t('no_customer_assigned')); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="helpdesk-project-card__stats">
                                            <div class="helpdesk-project-card__stat">
                                                <div class="helpdesk-project-card__stat-value"><?php p((string)($project['ticket_count'] ?? 0)); ?></div>
                                                <div class="helpdesk-text-muted helpdesk-project-card__stat-label"><?php p(($project['ticket_count'] ?? 0) != 1 ? $l->t('tickets') : $l->t('ticket')); ?></div>
                                            </div>
                                            <div class="helpdesk-project-card__stat">
                                                <div class="helpdesk-project-card__stat-value"><?php p((string)($project['member_count'] ?? 0)); ?></div>
                                                <div class="helpdesk-text-muted helpdesk-project-card__stat-label"><?php p(($project['member_count'] ?? 0) != 1 ? $l->t('members') : $l->t('member')); ?></div>
                                            </div>
                                        </div>
                                        <div class="helpdesk-project-card__actions tc-list-row__no-nav">
                                            <?php
                                            $listActionsIconOnly = true;
                                            $listActionsViewHref = $showHref;
                                            $listActionsViewAriaLabel = $l->t('view_project');
                                            $listActionsEditButton = !empty($project['can_manage']) ? [
                                                'class' => 'helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm',
                                                'attrs' => ['data-edit-project-id' => (string)$pid],
                                                'ariaLabel' => $l->t('edit_project'),
                                            ] : null;
                                            $listActionsDelete = !empty($project['can_manage']) ? [
                                                'class' => 'helpdesk-btn helpdesk-btn--danger helpdesk-btn--sm helpdesk-project-card__delete-btn',
                                                'attrs' => ['data-delete-project-id' => (string)$pid],
                                                'ariaLabel' => $l->t('delete_project'),
                                            ] : null;
                                            include __DIR__ . '/../common/list-row-actions.php';
                                            ?>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </section>
            <?php endif; ?>
<?php include __DIR__ . '/../common/page-end.php'; ?>

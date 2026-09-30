<?php

/**
 * Customers index — search, responsive table + card layout
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

$_['isGuest'] = false;
$_['isAdmin'] = $_['isAdmin'] ?? false;

/** @var \OCP\IL10N $l */
$l = $_['l'];

$customers = is_array($_['customers'] ?? null) ? $_['customers'] : [];
$search = (string)($_['search'] ?? '');
$customersIndexHasActiveFilters = $search !== '';
$clearFiltersUrl = $_['urlGenerator']->linkToRoute('ticketcheck.customer.index');
?>

<?php include __DIR__ . '/../common/page-start.php'; ?>

            <section class="tc-section" aria-labelledby="customers-filters-heading">
                <h2 id="customers-filters-heading" class="tc-section__title"><?php p($l->t('customers_filters_title')); ?></h2>
                <p class="tc-section__lead"><?php p($l->t('customers_filters_lead')); ?></p>
                <form method="GET" action="" class="helpdesk-filter-bar helpdesk-filter-bar--customers helpdesk-list-page__filters tc-filter-grid" role="search" aria-label="<?php p($l->t('customers_filters_title')); ?>">
                    <div class="helpdesk-filter-bar__group tc-field">
                        <label for="customer-search" class="helpdesk-form-label"><?php p($l->t('search_customers')); ?></label>
                        <span class="helpdesk-input-with-icon">
                            <?php print_unescaped(IconCatalog::render('search', 'helpdesk-input-icon')); ?>
                            <input id="customer-search" type="search" name="search" value="<?php p($search); ?>" placeholder="<?php p($l->t('search_customers_dots')); ?>" class="helpdesk-form-control helpdesk-search-input helpdesk-customers-search-input" autocomplete="off">
                        </span>
                    </div>
                    <div class="helpdesk-filter-bar__group helpdesk-filter-bar__group--actions tc-field tc-field--actions">
                        <a href="<?php p($clearFiltersUrl); ?>" class="helpdesk-btn helpdesk-btn--secondary"><?php p($l->t('clear_button')); ?></a>
                        <button type="submit" class="helpdesk-btn helpdesk-btn--primary"><?php p($l->t('apply_button')); ?></button>
                    </div>
                </form>
            </section>

            <div class="tc-customers-list-bar helpdesk-list-page__meta">
                <p class="helpdesk-text-muted helpdesk-customers-result-count tc-customers-list-bar__count"
                   role="status"
                   aria-live="polite"
                   aria-atomic="true">
                    <?php p($l->t('showing')); ?>
                    <strong><?php p(count($customers)); ?></strong>
                    <?php p(count($customers) !== 1 ? $l->t('customers') : $l->t('customer')); ?>
                </p>
            </div>

            <?php if (empty($customers)): ?>
                <div class="helpdesk-empty helpdesk-list-page__content" role="region" aria-labelledby="customers-empty-title">
                    <div class="helpdesk-empty__icon" aria-hidden="true">
                        <?php print_unescaped(IconCatalog::render('users')); ?>
                    </div>
                    <?php if ($customersIndexHasActiveFilters): ?>
                        <h3 id="customers-empty-title" class="helpdesk-empty__title"><?php p($l->t('customers_no_match')); ?></h3>
                        <p class="helpdesk-empty__text"><?php p($l->t('customers_try_changing_filters')); ?></p>
                    <?php else: ?>
                        <h3 id="customers-empty-title" class="helpdesk-empty__title"><?php p($l->t('no_customers_yet')); ?></h3>
                        <p class="helpdesk-empty__text"><?php p($l->t('customers_empty_description')); ?></p>
                    <?php endif; ?>
                    <div class="helpdesk-form-actions helpdesk-form-actions--start helpdesk-empty__actions">
                        <?php if ($customersIndexHasActiveFilters): ?>
                            <a href="<?php p($clearFiltersUrl); ?>" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--lg"><?php p($l->t('clear_filters')); ?></a>
                        <?php endif; ?>
                        <?php if (!empty($_['isAdmin'])): ?>
                            <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customer.create')); ?>"
                                class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg">
                                <?php p($l->t('create_first_customer')); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <section class="tc-section" aria-labelledby="customers-list-heading">
                    <h2 id="customers-list-heading" class="tc-sr-only"><?php p($l->t('customers_list_section')); ?></h2>
                    <div class="tc-customers-list">
                        <div class="tc-table-wrap">
                            <table class="helpdesk-table tc-table tc-customers-table">
                                <caption class="tc-sr-only"><?php p($l->t('customers_list_section')); ?></caption>
                                <thead>
                                    <tr>
                                        <th scope="col"><?php p($l->t('customer')); ?></th>
                                        <th scope="col"><?php p($l->t('email')); ?></th>
                                        <th scope="col"><?php p($l->t('phone_label')); ?></th>
                                        <th scope="col"><?php p($l->t('projects')); ?></th>
                                        <th scope="col"><?php p($l->t('guest_users')); ?></th>
                                        <th scope="col"><?php p($l->t('actions')); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customers as $customer): ?>
                                        <?php
                                        $cid = (int)($customer['id'] ?? 0);
                                        $showHref = $_['urlGenerator']->linkToRoute('ticketcheck.customer.show', ['id' => $cid]);
                                        $email = (string)($customer['email'] ?? '');
                                        $phone = (string)($customer['phone'] ?? '');
                                        $projectCount = (int)($customer['project_count'] ?? 0);
                                        $guestCount = (int)($customer['guest_count'] ?? 0);
                                        ?>
                                        <tr class="tc-customers-row tc-list-row--clickable"
                                            data-list-row-href="<?php p($showHref); ?>"
                                            data-customers-index-row="<?php p((string)$cid); ?>">
                                            <th scope="row" class="tc-customers-table__name">
                                                <a class="tc-list-row__name tc-customers-table__title-link"
                                                    href="<?php p($showHref); ?>"
                                                    aria-label="<?php p(trim((string)($customer['name'] ?? '') . ': ' . $l->t('view_customer_details'))); ?>">
                                                    <?php p($customer['name'] ?? ''); ?>
                                                </a>
                                            </th>
                                            <td class="tc-list-row__no-nav">
                                                <?php if ($email !== ''): ?>
                                                    <a class="tc-link" href="mailto:<?php p($email); ?>"><?php p($email); ?></a>
                                                <?php else: ?>
                                                    <span class="helpdesk-text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="tc-list-row__no-nav">
                                                <?php if ($phone !== ''): ?>
                                                    <a class="tc-link" href="tel:<?php p(preg_replace('/\s+/', '', $phone)); ?>"><?php p($phone); ?></a>
                                                <?php else: ?>
                                                    <span class="helpdesk-text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php p((string)$projectCount); ?></td>
                                            <td><?php p((string)$guestCount); ?></td>
                                            <td class="tc-customers-table__actions tc-list-row__no-nav">
                                                <?php
                                                $listActionsIconOnly = true;
                                                $listActionsViewHref = $showHref;
                                                $listActionsViewAriaLabel = $l->t('view_customer_details');
                                                $listActionsEditHref = !empty($_['isAdmin'])
                                                    ? $_['urlGenerator']->linkToRoute('ticketcheck.customer.edit', ['id' => $cid])
                                                    : null;
                                                $listActionsEditAriaLabel = $l->t('edit_customer');
                                                $listActionsDelete = !empty($_['isAdmin']) ? [
                                                    'class' => 'helpdesk-btn helpdesk-btn--danger helpdesk-btn--sm customer-delete-btn',
                                                    'attrs' => ['data-customer-id' => (string)$cid],
                                                    'ariaLabel' => $l->t('delete_customer'),
                                                ] : null;
                                                include __DIR__ . '/../common/list-row-actions.php';
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <ul class="tc-card-list tc-customers-card-list" aria-label="<?php p($l->t('customers_list_section')); ?>">
                            <?php foreach ($customers as $customer): ?>
                                <?php
                                $cid = (int)($customer['id'] ?? 0);
                                $showHref = $_['urlGenerator']->linkToRoute('ticketcheck.customer.show', ['id' => $cid]);
                                $email = (string)($customer['email'] ?? '');
                                $phone = (string)($customer['phone'] ?? '');
                                $projectCount = (int)($customer['project_count'] ?? 0);
                                $guestCount = (int)($customer['guest_count'] ?? 0);
                                ?>
                                <li class="tc-card helpdesk-card tc-customers-card tc-list-row--clickable"
                                    data-list-row-href="<?php p($showHref); ?>"
                                    data-customers-index-row="<?php p((string)$cid); ?>">
                                    <div class="helpdesk-card__body">
                                        <h3 class="helpdesk-customer-card__title">
                                            <a class="helpdesk-customer-card__title-link"
                                                href="<?php p($showHref); ?>"
                                                aria-label="<?php p(trim((string)($customer['name'] ?? '') . ': ' . $l->t('view_customer_details'))); ?>">
                                                <span class="helpdesk-customer-card__title-text"><?php p($customer['name'] ?? ''); ?></span>
                                            </a>
                                        </h3>
                                        <?php if ($email !== '' || $phone !== ''): ?>
                                            <div class="helpdesk-customer-card__contact-list tc-list-row__no-nav">
                                                <?php if ($email !== ''): ?>
                                                    <div class="helpdesk-customer-card__contact-item">
                                                        <?php print_unescaped(IconCatalog::render('mail', 'helpdesk-customer-card__contact-icon')); ?>
                                                        <a class="tc-link helpdesk-customer-card__contact-text" href="mailto:<?php p($email); ?>"><?php p($email); ?></a>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($phone !== ''): ?>
                                                    <div class="helpdesk-customer-card__contact-item">
                                                        <?php print_unescaped(IconCatalog::render('phone', 'helpdesk-customer-card__contact-icon')); ?>
                                                        <a class="tc-link helpdesk-customer-card__contact-text" href="tel:<?php p(preg_replace('/\s+/', '', $phone)); ?>"><?php p($phone); ?></a>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="helpdesk-customer-card__stats tc-customers-card__stats">
                                            <div class="helpdesk-customer-card__stat">
                                                <div class="helpdesk-customer-card__stat-value"><?php p((string)$projectCount); ?></div>
                                                <div class="helpdesk-text-muted helpdesk-customer-card__stat-label"><?php p($projectCount !== 1 ? $l->t('projects_count') : $l->t('project_count')); ?></div>
                                            </div>
                                            <div class="helpdesk-customer-card__stat">
                                                <div class="helpdesk-customer-card__stat-value"><?php p((string)$guestCount); ?></div>
                                                <div class="helpdesk-text-muted helpdesk-customer-card__stat-label"><?php p($l->t('guest_users')); ?></div>
                                            </div>
                                        </div>
                                        <div class="helpdesk-customer-card__actions tc-list-row__no-nav">
                                            <?php
                                            $listActionsIconOnly = true;
                                            $listActionsViewHref = $showHref;
                                            $listActionsViewAriaLabel = $l->t('view_customer_details');
                                            $listActionsEditHref = !empty($_['isAdmin'])
                                                ? $_['urlGenerator']->linkToRoute('ticketcheck.customer.edit', ['id' => $cid])
                                                : null;
                                            $listActionsEditAriaLabel = $l->t('edit_customer');
                                            $listActionsDelete = !empty($_['isAdmin']) ? [
                                                'class' => 'helpdesk-btn helpdesk-btn--danger helpdesk-btn--sm customer-delete-btn',
                                                'attrs' => ['data-customer-id' => (string)$cid],
                                                'ariaLabel' => $l->t('delete_customer'),
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

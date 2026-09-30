<?php

/**
 * Customer portal knowledge base template
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;
use OCA\Ticketcheck\Service\KbSearchTextHelper;

/** @var \OCP\IL10N $l */
$l = $_['l'];
$canCreateTicket = !empty($_['canCreateTicket']);
?>

<?php include __DIR__ . '/../common/page-start.php'; ?>

            <section class="portal-kb-hero tc-hero" aria-label="<?php p($l->t('knowledge_base')); ?>">
                <div class="portal-kb-hero__inner">
                    <div class="portal-kb-hero__icon-wrap tc-hero__icon" aria-hidden="true">
                        <?php print_unescaped(IconCatalog::render('book', 'portal-kb-hero__icon')); ?>
                    </div>
                    <p class="portal-kb-hero__title tc-hero__title" role="doc-subtitle">
                        <?php p($l->t('knowledge_base')); ?>
                    </p>
                    <p class="portal-kb-hero__subtitle tc-hero__lead">
                        <?php p($l->t('find_answers')); ?>
                    </p>

                    <!-- Search Box -->
                    <form method="GET"
                        action="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.kpKnowledgeBase')); ?>"
                        role="search"
                        aria-label="<?php p($l->t('search_knowledge_base')); ?>">
                        <div class="portal-kb-hero__search-row">
                            <input type="search"
                                id="kb-search-input"
                                name="q"
                                value="<?php p($_['query'] ?? ''); ?>"
                                autocomplete="off"
                                aria-label="<?php p($l->t('kb_search_by_keyword')); ?>"
                                aria-describedby="portal-kb-search-hint"
                                maxlength="<?php p((string)KbSearchTextHelper::MAX_QUERY_LENGTH); ?>"
                                placeholder="<?php p($l->t('kb_search_by_keyword')); ?>"
                                class="helpdesk-form-control portal-kb-hero__search-input">
                            <button type="submit" class="helpdesk-btn helpdesk-btn--primary portal-kb-hero__search-btn">
                                <span aria-hidden="true"><?php print_unescaped(IconCatalog::render('search')); ?></span>
                                <?php p($l->t('search_button')); ?>
                            </button>
                        </div>
                        <p id="portal-kb-search-hint" class="helpdesk-form-help portal-kb-hero__search-hint"><?php p($l->t('kb_search_hint')); ?></p>
                    </form>
                </div>
            </section>

            <div class="portal-kb__body">
            <!-- Search Status -->
            <?php if ($_['searchActive'] ?? false): ?>
                <div role="status" aria-live="polite" aria-atomic="true" class="sr-only">
                    <?php
                    $resultCount = count($_['articles'] ?? []);
                    $articleKey = $resultCount === 1 ? 'article_count' : 'article_count_plural';
                    $articleText = strtr($l->t($articleKey), ['{count}' => (string)$resultCount]);
                    p($articleText . ' ' . $l->t('found') . ' ' . $l->t('search_results_for') . ' "' . $_['query'] . '"');
                    ?>
                </div>
            <?php endif; ?>

            <!-- Category Filter Bar -->
            <?php if (!empty($_['categories'])): ?>
                <div id="kb-filters" class="kb-filter-bar portal-kb__filter-bar" role="group" aria-label="<?php p($l->t('filter_by_category')); ?>">
                    <div class="kb-filter-bar__label portal-kb__filter-label">
                        <span class="portal-kb__filter-label-icon" aria-hidden="true">
                            <?php print_unescaped(IconCatalog::render('folder')); ?>
                        </span>
                        <?php p($l->t('filter_by_category')); ?>
                    </div>

                    <div class="kb-filter-pills portal-kb__filter-pills">
                        <!-- Show All Button -->
                        <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.kpKnowledgeBase')); ?>"
                            class="kb-filter-pill kb-filter-pill--show-all portal-kb__filter-pill <?php echo empty($_['categoryFilter']) ? 'kb-filter-pill--active' : ''; ?>"
                            aria-current="<?php echo empty($_['categoryFilter']) ? 'page' : 'false'; ?>"
                            aria-label="<?php p($l->t('show_all_categories')); ?>"
                            >
                            <span class="portal-kb__filter-pill-icon" aria-hidden="true">
                                <?php print_unescaped(IconCatalog::render('menu')); ?>
                            </span>
                            <?php p($l->t('all_categories')); ?>
                        </a>

                        <!-- Category Pills -->
                        <?php foreach ($_['categories'] as $category => $count): ?>
                            <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.kpKnowledgeBase', ['category' => $category])); ?>"
                                class="kb-filter-pill portal-kb__filter-pill <?php echo $_['categoryFilter'] === $category ? 'kb-filter-pill--active' : ''; ?>"
                                aria-current="<?php echo $_['categoryFilter'] === $category ? 'page' : 'false'; ?>"
                                aria-label="<?php p($l->t('%n article in %s', [$count, $category])); ?>"
                                >
                                <span class="kb-filter-pill__count portal-kb__filter-pill-count"><?php p($count); ?></span>
                                <?php p($category); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($_['categoryFilter'])): ?>
                        <div class="kb-filter-state portal-kb__filter-state">
                            <span class="kb-filter-state__icon portal-kb__filter-state-icon" aria-hidden="true">
                                <?php print_unescaped(IconCatalog::render('package')); ?>
                            </span>
                            <?php p($l->t('showing_category', [$_['categoryFilter']])); ?>
                            <button type="button"
                                class="helpdesk-btn helpdesk-btn--icon helpdesk-btn--ghost kb-filter-clear"
                                data-kb-clear-filter
                                data-kb-clear-url="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.kpKnowledgeBase')); ?>"
                                aria-label="<?php p($l->t('reset_filter')); ?>"
                                    >
                                <span class="portal-kb__filter-clear-icon" aria-hidden="true">
                                    <?php print_unescaped(IconCatalog::render('x')); ?>
                                </span>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Articles -->
            <section id="kb-results-region" aria-live="polite" aria-busy="false">
                <?php if (empty($_['articles'])): ?>
                    <?php if ($_['searchActive'] ?? false): ?>
                        <!-- No search results -->
                        <div class="helpdesk-empty">
                            <div class="helpdesk-empty__icon" aria-hidden="true">
                                <?php print_unescaped(IconCatalog::render('search', 'helpdesk-empty__icon-svg')); ?>
                            </div>
                            <h3 class="helpdesk-empty__title"><?php p($l->t('no_articles_found')); ?></h3>
                            <p class="helpdesk-empty__text">
                                <?php p($l->t('no_articles_found_desc')); ?>
                            </p>
                            <p class="helpdesk-empty__text">
                                <?php p($l->t('try_different_keywords')); ?>
                            </p>
                            <div class="helpdesk-empty__actions">
                                <a href="<?php p($_['urlGenerator']->linkToRoute($canCreateTicket ? 'ticketcheck.customerPortal.createTicket' : 'ticketcheck.customerPortal.kpKnowledgeBase')); ?>"
                                    class="helpdesk-btn helpdesk-btn--primary">
                                    <span aria-hidden="true"><?php print_unescaped(IconCatalog::render('message-square')); ?></span>
                                    <?php p($canCreateTicket ? $l->t('create_support_ticket') : $l->t('knowledge_base')); ?>
                                </a>
                                <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.kpKnowledgeBase')); ?>"
                                    class="helpdesk-btn helpdesk-btn--secondary">
                                    <span aria-hidden="true"><?php print_unescaped(IconCatalog::render('arrow-left')); ?></span>
                                    <?php p($l->t('back_to_knowledge_base')); ?>
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- No articles at all -->
                        <div class="helpdesk-empty">
                            <div class="helpdesk-empty__icon" aria-hidden="true">
                                <?php print_unescaped(IconCatalog::render('book', 'helpdesk-empty__icon-svg')); ?>
                            </div>
                            <h3 class="helpdesk-empty__title"><?php p($l->t('no_articles_yet')); ?></h3>
                            <p class="helpdesk-empty__text">
                                <?php p($l->t('no_articles_yet_desc')); ?>
                            </p>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="kb-articles-transition" id="kb-articles-container">
                    <?php
                    // If filtering by category, show only that category's articles in a simple list
                    if (!empty($_['categoryFilter'])): ?>
                        <div class="portal-kb__articles-grid">
                            <?php foreach ($_['articles'] as $article): ?>
                                <?php
                                $_['article'] = $article;
                                include __DIR__ . '/../common/portal-kb-article-card.php';
                                ?>
                            <?php endforeach; ?>
                        </div>
                    <?php else:
                        // Group by category for "Show All" view
                        $groupedCategories = [];
                        foreach ($_['articles'] as $article) {
                            $cat = $article->getCategory() ?: 'General';
                            if (!isset($groupedCategories[$cat])) $groupedCategories[$cat] = [];
                            $groupedCategories[$cat][] = $article;
                        }
                    ?>

                        <?php foreach ($groupedCategories as $category => $articles): ?>
                            <div class="helpdesk-card helpdesk-mb-md portal-kb__category-block">
                                <div class="helpdesk-card__header portal-kb__category-toggle">
                                    <h2 class="helpdesk-card__title portal-kb__category-title">
                                        <span class="portal-kb__category-icon" aria-hidden="true">
                                            <?php print_unescaped(IconCatalog::render('folder')); ?>
                                        </span>
                                        <?php p($category); ?>
                                        <span class="helpdesk-badge portal-kb__category-badge">
                                            <?php p(count($articles)); ?>
                                        </span>
                                    </h2>
                                </div>

                                <div class="helpdesk-card__body portal-kb__category-body">
                                    <div class="portal-kb__category-grid">
                                        <?php foreach ($articles as $article): ?>
                                            <?php
                                            $_['article'] = $article;
                                            include __DIR__ . '/../common/portal-kb-article-card.php';
                                            ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Help Footer -->
            <div class="helpdesk-card portal-kb__help-footer">
                <div class="helpdesk-card__body portal-kb__help-footer-body">
                    <div class="portal-kb__help-footer-icon-wrap" aria-hidden="true">
                        <?php print_unescaped(IconCatalog::render('help-circle', 'portal-kb__help-footer-icon')); ?>
                    </div>
                    <h3 class="portal-kb__help-footer-title">
                        <?php p($l->t('still_need_help')); ?>
                    </h3>
                    <p class="helpdesk-text-muted portal-kb__help-footer-text">
                        <?php p($l->t('create_ticket_for_help')); ?>
                    </p>
                    <a href="<?php p($_['urlGenerator']->linkToRoute($canCreateTicket ? 'ticketcheck.customerPortal.createTicket' : 'ticketcheck.customerPortal.kpKnowledgeBase')); ?>"
                        class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg">
                        <span aria-hidden="true"><?php print_unescaped(IconCatalog::render('message-square')); ?></span>
                        <?php p($canCreateTicket ? $l->t('create_support_ticket') : $l->t('knowledge_base')); ?>
                    </a>
                </div>
            </div>
            </div>

<?php include __DIR__ . '/../common/page-end.php'; ?>

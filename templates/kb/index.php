<?php

/**
 * Knowledge base index — search hero, category filters, article cards
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Db\KBArticle;
use OCA\Ticketcheck\Service\IconCatalog;
use OCA\Ticketcheck\Service\KbSearchTextHelper;

$_['isGuest'] = $_['isGuest'] ?? false;
$_['isAdmin'] = $_['isAdmin'] ?? false;

/** @var \OCP\IL10N $l */
$l = $_['l'];

$articles = is_array($_['articles'] ?? null) ? $_['articles'] : [];
$categories = is_array($_['categories'] ?? null) ? $_['categories'] : [];
$categoryFilter = (string)($_['categoryFilter'] ?? '');
$canManage = !empty($_['canManage']);
$indexUrl = $_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.kpIndex');
$searchUrl = $_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.kpSearch');
$createArticleUrl = $_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.create');

$renderArticleCard = static function (KBArticle $article) use ($l, $_): void {
    $articleUrl = $_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.kpArticle', ['id' => $article->getId()]);
    // Content is sanitized HTML: strip tags, then decode entities (&nbsp; etc.)
    // so p() does not render them literally (same normalization as portal card).
    $content = html_entity_decode(strip_tags((string)$article->getContent()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $content = str_replace("\xc2\xa0", ' ', $content);
    $content = trim(preg_replace('/\s+/u', ' ', $content) ?? '');
    $excerpt = mb_strlen($content, 'UTF-8') > 120 ? mb_substr($content, 0, 120, 'UTF-8') . '…' : $content;
    $views = (int)$article->getViews();
    $helpful = (int)$article->getHelpfulCount();
    ?>
    <li class="tc-kb-article-card">
        <a href="<?php p($articleUrl); ?>"
            class="tc-card tc-card--interactive tc-kb-article-card__link"
            aria-label="<?php p(strtr($l->t('view_kb_article_title'), ['{title}' => $article->getTitle()])); ?>">
            <?php if (!$article->getPublished()): ?>
                <div class="tc-kb-article-card__badge" aria-hidden="true">
                    <span class="helpdesk-badge helpdesk-badge--priority-high"><?php p($l->t('draft')); ?></span>
                </div>
            <?php endif; ?>
            <div class="tc-card__body">
                <h3 class="tc-kb-article-card__title"><?php p($article->getTitle()); ?></h3>
                <?php if ($excerpt !== ''): ?>
                    <p class="helpdesk-text-muted tc-kb-article-card__excerpt"><?php p($excerpt); ?></p>
                <?php endif; ?>
                <ul class="tc-kb-article-card__meta" aria-hidden="true">
                    <li>
                        <span class="tc-kb-article-card__meta-item">
                            <?php print_unescaped(IconCatalog::render('eye', 'tc-icon--inline')); ?>
                            <?php p((string)$views); ?>
                            <?php p($views === 1 ? $l->t('view') : $l->t('views')); ?>
                        </span>
                    </li>
                    <li>
                        <span class="tc-kb-article-card__meta-item">
                            <?php print_unescaped(IconCatalog::render('thumbs-up', 'tc-icon--inline')); ?>
                            <?php p((string)$helpful); ?>
                            <?php p($l->t('helpful')); ?>
                        </span>
                    </li>
                </ul>
            </div>
        </a>
    </li>
    <?php
};
?>

<?php include __DIR__ . '/../common/page-start.php'; ?>

            <section class="tc-section tc-kb-hero-section" aria-labelledby="kb-hero-heading">
                <div class="tc-hero tc-kb-hero">
                    <div class="tc-hero__inner">
                        <div class="tc-hero__icon" aria-hidden="true">
                            <?php print_unescaped(IconCatalog::render('book')); ?>
                        </div>
                        <h2 id="kb-hero-heading" class="tc-hero__title"><?php p($l->t('knowledge_base')); ?></h2>
                        <form method="GET"
                            action="<?php p($searchUrl); ?>"
                            class="tc-kb-search"
                            role="search"
                            aria-label="<?php p($l->t('search_knowledge_base')); ?>">
                            <label for="kb-search-input" class="helpdesk-form-label"><?php p($l->t('kb_search_by_keyword')); ?></label>
                            <div class="tc-kb-search__row">
                                <input type="search"
                                    id="kb-search-input"
                                    name="q"
                                    autocomplete="off"
                                    class="helpdesk-form-control"
                                    aria-describedby="kb-search-hint"
                                    maxlength="<?php p((string)KbSearchTextHelper::MAX_QUERY_LENGTH); ?>"
                                    placeholder="<?php p($l->t('kb_search_by_keyword')); ?>">
                                <button type="submit" class="helpdesk-btn helpdesk-btn--primary tc-kb-search__submit">
                                    <?php print_unescaped(IconCatalog::render('search')); ?>
                                    <span><?php p($l->t('search_button')); ?></span>
                                </button>
                            </div>
                            <p id="kb-search-hint" class="helpdesk-form-help"><?php p($l->t('kb_search_hint')); ?></p>
                        </form>
                    </div>
                </div>
            </section>

            <?php if ($categories !== []): ?>
                <section class="tc-section" aria-labelledby="kb-filters-heading">
                    <h2 id="kb-filters-heading" class="tc-section__title"><?php p($l->t('filter_by_category')); ?></h2>
                    <p class="tc-section__lead"><?php p($l->t('kb_filters_lead')); ?></p>
                    <div id="kb-filters" class="tc-kb-filters" role="navigation" aria-label="<?php p($l->t('filter_by_category')); ?>">
                        <div class="tc-kb-filters__pills">
                            <a href="<?php p($indexUrl); ?>#kb-filters"
                                class="tc-kb-filter-pill<?php echo $categoryFilter === '' ? ' tc-kb-filter-pill--active' : ''; ?>"
                                <?php if ($categoryFilter === '') {
                                    echo ' aria-current="page"';
                                } ?>>
                                <?php p($l->t('all_categories')); ?>
                            </a>
                            <?php foreach ($categories as $category => $count): ?>
                                <?php
                                $catName = (string)$category;
                                $isActive = $categoryFilter === $catName;
                                ?>
                                <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.kpIndex', ['category' => $catName])); ?>#kb-filters"
                                    class="tc-kb-filter-pill<?php echo $isActive ? ' tc-kb-filter-pill--active' : ''; ?>"
                                    <?php if ($isActive) {
                                        echo ' aria-current="page"';
                                    } ?>
                                    aria-label="<?php p($l->t('%n article in %s', [$count, $catName])); ?>">
                                    <span class="tc-kb-filter-pill__count" aria-hidden="true"><?php p((string)$count); ?></span>
                                    <?php p($catName); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($categoryFilter !== ''): ?>
                            <p class="tc-kb-filters__state" role="status">
                                <?php p($l->t('showing_category', [$categoryFilter])); ?>
                                <a href="<?php p($indexUrl); ?>#kb-filters"
                                    class="helpdesk-btn helpdesk-btn--sm helpdesk-btn--secondary tc-kb-filters__clear"
                                    data-kb-clear-filter
                                    data-kb-clear-url="<?php p($indexUrl); ?>#kb-filters">
                                    <?php p($l->t('reset_filter')); ?>
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section id="kb-results-region" class="tc-section" aria-labelledby="kb-articles-heading" aria-live="polite" aria-busy="false">
                <h2 id="kb-articles-heading" class="tc-section__title">
                    <?php p($categoryFilter !== '' ? $l->t('kb_articles_in_category', [$categoryFilter]) : $l->t('knowledge_base_articles')); ?>
                </h2>

                <?php if ($articles === []): ?>
                    <div class="helpdesk-empty">
                        <div class="helpdesk-empty__icon" aria-hidden="true">
                            <?php print_unescaped(IconCatalog::render('book')); ?>
                        </div>
                        <h3 class="helpdesk-empty__title"><?php p($l->t('no_articles_yet')); ?></h3>
                        <p class="helpdesk-empty__text"><?php p($l->t('help_center_empty')); ?></p>
                        <?php if ($canManage): ?>
                            <a href="<?php p($createArticleUrl); ?>"
                                class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg">
                                <?php p($l->t('create_first_article')); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div id="kb-articles-container" class="tc-kb-articles">
                        <?php if ($categoryFilter !== ''): ?>
                            <ul class="tc-kb-articles-grid">
                                <?php foreach ($articles as $article): ?>
                                    <?php $renderArticleCard($article); ?>
                                <?php endforeach; ?>
                            </ul>
                        <?php else:
                            $groupedCategories = [];
                            foreach ($articles as $article) {
                                $cat = $article->getCategory() ?: 'General';
                                if (!isset($groupedCategories[$cat])) {
                                    $groupedCategories[$cat] = [];
                                }
                                $groupedCategories[$cat][] = $article;
                            }
                            foreach ($groupedCategories as $categoryName => $categoryArticles): ?>
                                <div class="tc-kb-category-group">
                                    <h3 class="tc-kb-category-group__title">
                                        <?php p((string)$categoryName); ?>
                                        <span class="helpdesk-badge"><?php p((string)count($categoryArticles)); ?></span>
                                    </h3>
                                    <ul class="tc-kb-articles-grid">
                                        <?php foreach ($categoryArticles as $article): ?>
                                            <?php $renderArticleCard($article); ?>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach;
                        endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="tc-section tc-kb-help-footer" aria-labelledby="kb-help-footer-title">
                <div class="helpdesk-card">
                    <div class="helpdesk-card__body tc-kb-help-footer__body">
                        <div class="tc-kb-help-footer__icon" aria-hidden="true">
                            <?php print_unescaped(IconCatalog::render('help-circle')); ?>
                        </div>
                        <h2 id="kb-help-footer-title" class="tc-section__title"><?php p($l->t('didnt_find_what_looking_for')); ?></h2>
                        <p class="helpdesk-text-muted"><?php p($l->t('no_problem_create_ticket')); ?></p>
                        <a href="<?php p($_['urlGenerator']->linkToRoute($_['isGuest'] ? 'ticketcheck.customerPortal.createTicket' : 'ticketcheck.ticket.create')); ?>"
                            class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg">
                            <?php p($l->t('create_support_ticket')); ?>
                        </a>
                    </div>
                </div>
            </section>

<?php include __DIR__ . '/../common/page-end.php'; ?>

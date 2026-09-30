<?php

/**
 * Knowledge base search results
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

$query = trim((string)($_['query'] ?? ''));
$categoryFilter = (string)($_['categoryFilter'] ?? '');
$articles = is_array($_['articles'] ?? null) ? $_['articles'] : [];
$categories = is_array($_['categories'] ?? null) ? $_['categories'] : [];
$searchUrl = $_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.kpSearch');
$indexUrl = $_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.kpIndex');

$renderResultCard = static function (KBArticle $article, string $searchQuery) use ($l, $_): void {
    $articleUrl = $_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.kpArticle', ['id' => $article->getId()]);
    // Decode entities after strip_tags so &nbsp; etc. never show literally
    // (highlightMatches re-escapes safely before highlighting).
    $rawContent = html_entity_decode(strip_tags((string)$article->getContent()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $rawContent = str_replace("\xc2\xa0", ' ', $rawContent);
    $rawContent = trim(preg_replace('/\s+/u', ' ', $rawContent) ?? '');
    $excerpt = mb_strlen($rawContent, 'UTF-8') > 120 ? mb_substr($rawContent, 0, 120, 'UTF-8') . '…' : $rawContent;
    $titleHtml = KbSearchTextHelper::highlightMatches((string)$article->getTitle(), $searchQuery);
    $excerptHtml = KbSearchTextHelper::highlightMatches($excerpt, $searchQuery);
    ?>
    <li class="tc-kb-article-card">
        <a href="<?php p($articleUrl); ?>"
            class="tc-card tc-card--interactive tc-kb-article-card__link"
            aria-label="<?php p(strtr($l->t('view_kb_article_title'), ['{title}' => $article->getTitle()])); ?>">
            <div class="tc-card__body">
                <h3 class="tc-kb-article-card__title"><?php print_unescaped($titleHtml); ?></h3>
                <?php if ($excerpt !== ''): ?>
                    <p class="helpdesk-text-muted tc-kb-article-card__excerpt"><?php print_unescaped($excerptHtml); ?></p>
                <?php endif; ?>
                <ul class="tc-kb-article-card__meta" aria-hidden="true">
                    <li>
                        <span class="tc-kb-article-card__meta-item">
                            <?php p((string)$article->getViews()); ?>
                            <?php p($article->getViews() === 1 ? $l->t('view') : $l->t('views')); ?>
                        </span>
                    </li>
                    <li>
                        <span class="tc-kb-article-card__meta-item">
                            <?php p((string)$article->getHelpfulCount()); ?>
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

            <section class="tc-section" aria-labelledby="kb-search-form-heading">
                <h2 id="kb-search-form-heading" class="tc-section__title"><?php p($l->t('search_knowledge_base')); ?></h2>
                <div class="helpdesk-card">
                    <div class="helpdesk-card__body">
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
                                    value="<?php p($query); ?>"
                                    autocomplete="off"
                                    class="helpdesk-form-control"
                                    aria-describedby="kb-search-results-hint"
                                    maxlength="<?php p((string)KbSearchTextHelper::MAX_QUERY_LENGTH); ?>"
                                    placeholder="<?php p($l->t('kb_search_by_keyword')); ?>">
                                <button type="submit" class="helpdesk-btn helpdesk-btn--primary tc-kb-search__submit">
                                    <?php p($l->t('search_button')); ?>
                                </button>
                            </div>
                            <p id="kb-search-results-hint" class="helpdesk-form-help"><?php p($l->t('kb_search_hint')); ?></p>
                        </form>
                    </div>
                </div>
            </section>

            <?php if ($categories !== []): ?>
                <section class="tc-section" aria-labelledby="kb-search-filters-heading">
                    <h2 id="kb-search-filters-heading" class="tc-section__title"><?php p($l->t('filter_by_category')); ?></h2>
                    <p class="tc-section__lead"><?php p($l->t('kb_filters_lead')); ?></p>
                    <div id="kb-filters" class="tc-kb-filters" role="navigation" aria-label="<?php p($l->t('filter_by_category')); ?>">
                        <div class="tc-kb-filters__pills">
                            <a href="<?php p($searchUrl . ($query !== '' ? '?q=' . rawurlencode($query) : '')); ?>#kb-filters"
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
                                $href = $searchUrl . '?';
                                $params = [];
                                if ($query !== '') {
                                    $params[] = 'q=' . rawurlencode($query);
                                }
                                $params[] = 'category=' . rawurlencode($catName);
                                $href .= implode('&', $params);
                                ?>
                                <a href="<?php p($href); ?>#kb-filters"
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
                                <a href="<?php p($searchUrl . ($query !== '' ? '?q=' . rawurlencode($query) : '')); ?>#kb-filters"
                                    class="helpdesk-btn helpdesk-btn--sm helpdesk-btn--secondary"
                                    data-kb-clear-filter
                                    data-kb-clear-url="<?php p($searchUrl . ($query !== '' ? '?q=' . rawurlencode($query) : '')); ?>#kb-filters">
                                    <?php p($l->t('reset_filter')); ?>
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="tc-section" aria-labelledby="kb-search-results-heading">
                <?php if ($query !== ''): ?>
                    <p class="tc-kb-search__back">
                        <a href="<?php p($indexUrl); ?>" class="helpdesk-btn helpdesk-btn--secondary">
                            <span aria-hidden="true"><?php print_unescaped(IconCatalog::render('arrow-left')); ?></span>
                            <?php p($l->t('back_to_knowledge_base')); ?>
                        </a>
                    </p>
                <?php endif; ?>
                <h2 id="kb-search-results-heading" class="tc-section__title">
                    <?php if ($query !== ''): ?>
                        <?php p($l->t('kb_search_results_for', [$query])); ?>
                    <?php else: ?>
                        <?php p($l->t('search_results')); ?>
                    <?php endif; ?>
                </h2>

                <?php if ($query === ''): ?>
                    <div class="helpdesk-empty">
                        <h3 class="helpdesk-empty__title"><?php p($l->t('Enter a search term')); ?></h3>
                        <p class="helpdesk-empty__text"><?php p($l->t('Search for articles, topics, or keywords in the knowledge base')); ?></p>
                    </div>
                <?php elseif ($articles === []): ?>
                    <div class="helpdesk-empty">
                        <h3 class="helpdesk-empty__title"><?php p($l->t('no_results_found')); ?></h3>
                        <p class="helpdesk-empty__text"><?php p($l->t('no_articles_match_search')); ?></p>
                        <a href="<?php p($indexUrl); ?>" class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg">
                            <?php p($l->t('kb_browse_all_articles')); ?>
                        </a>
                    </div>
                <?php else: ?>
                    <p class="helpdesk-text-muted" role="status">
                        <?php $count = count($articles); ?>
                        <?php p($l->n('search_result_count_singular', 'search_result_count_plural', $count)); ?>
                    </p>
                    <ul class="tc-kb-articles-grid" id="kb-articles-container">
                        <?php foreach ($articles as $article): ?>
                            <?php $renderResultCard($article, $query); ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

<?php include __DIR__ . '/../common/page-end.php'; ?>

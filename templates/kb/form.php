<?php

/**
 * Knowledge base article form — create / edit
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

$_['isGuest'] = false;
$_['isAdmin'] = $_['isAdmin'] ?? false;

$isEdit = !empty($_['isEdit']);
$article = $_['article'] ?? null;

/** @var \OCP\IL10N $l */
$l = $_['l'];
require __DIR__ . '/resolveFormCommentsFlag.php';

$cancelHref = $_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.kpIndex');
$categories = is_array($_['categories'] ?? null) ? $_['categories'] : [];
$currentName = $article ? (string)$article->getCategory() : '';
$renderedNames = [];
?>

<?php include __DIR__ . '/../common/page-start.php'; ?>

            <form id="kb-article-form" class="tc-kb-form" novalidate aria-describedby="kb-form-required-hint">
                <p id="kb-form-required-hint" class="helpdesk-form-help helpdesk-customer-form__legend">
                    <span class="helpdesk-form-label--required"><?php p($l->t('required_fields')); ?></span>
                    — <?php p($l->t('required_to_save')); ?>
                </p>

                <?php if ($isEdit && $article): ?>
                    <input type="hidden" name="article_id" value="<?php p((string)$article->getId()); ?>">
                <?php endif; ?>

                <section class="tc-section" aria-labelledby="kb-form-info-heading">
                    <h2 id="kb-form-info-heading" class="tc-section__title"><?php p($l->t('article_information')); ?></h2>
                    <div class="helpdesk-card">
                        <div class="helpdesk-card__body">
                            <div class="tc-form-grid">
                                <div class="helpdesk-form-group tc-field tc-field--full-width">
                                    <label for="title" class="helpdesk-form-label helpdesk-form-label--required">
                                        <?php p($l->t('title_label')); ?>
                                    </label>
                                    <input type="text"
                                        id="title"
                                        name="title"
                                        required
                                        aria-required="true"
                                        value="<?php p($article ? $article->getTitle() : ''); ?>"
                                        placeholder="<?php p($l->t('how_to_placeholder')); ?>"
                                        maxlength="200"
                                        class="helpdesk-form-control"
                                        aria-describedby="title-hint">
                                    <span id="title-hint" class="helpdesk-form-help"><?php p($l->t('clear_descriptive_title_help')); ?></span>
                                </div>

                                <div class="helpdesk-form-group tc-field tc-field--full-width">
                                    <label for="categoryId" class="helpdesk-form-label">
                                        <?php p($l->t('category')); ?>
                                    </label>
                                    <select id="categoryId" name="categoryId" class="helpdesk-form-control" aria-describedby="category-hint">
                                        <?php
                                        $hasSelected = false;
                                        foreach ($categories as $cat) {
                                            $nameRaw = (string)$cat->getName();
                                            if (isset($renderedNames[$nameRaw])) {
                                                continue;
                                            }
                                            $renderedNames[$nameRaw] = true;
                                            $id = (int)$cat->getId();
                                            $selected = ($currentName !== '' && $nameRaw === $currentName)
                                                || ($currentName === '' && strcasecmp($nameRaw, 'General') === 0);
                                            if ($selected) {
                                                $hasSelected = true;
                                            }
                                            ?>
                                            <option value="<?php p((string)$id); ?>"<?php if ($selected) {
                                                p(' selected');
                                            } ?>><?php p($nameRaw); ?></option>
                                            <?php
                                        }
                                        if (!$hasSelected && $categories === []): ?>
                                            <option value="" selected><?php p($l->t('General')); ?></option>
                                        <?php endif; ?>
                                    </select>
                                    <span id="category-hint" class="helpdesk-form-help">
                                        <?php p($l->t('Group similar articles with a category.')); ?>
                                        <?php if (!empty($_['isAdmin'])): ?>
                                            <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.settings.section', ['section' => 'kb-categories'])); ?>"
                                                class="js-kb-manage-categories-link"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                data-unsaved-warning="<?php p($l->t('unsaved_changes_manage_categories_warning')); ?>">
                                                <?php p($l->t('manage_categories')); ?>
                                            </a>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="tc-section" aria-labelledby="kb-form-content-heading">
                    <h2 id="kb-form-content-heading" class="tc-section__title"><?php p($l->t('article_content')); ?></h2>
                    <p class="tc-section__lead"><?php p($l->t('use_clear_language_help')); ?></p>
                    <div class="helpdesk-card">
                        <div class="helpdesk-card__body">
                            <div class="helpdesk-form-group tc-field tc-field--full-width">
                                <label id="kb-content-label" for="kb-content" class="helpdesk-form-label helpdesk-form-label--required">
                                    <?php p($l->t('content_label')); ?>
                                </label>
                                <textarea id="kb-content"
                                    name="content"
                                    required
                                    aria-required="true"
                                    aria-labelledby="kb-content-label"
                                    rows="20"
                                    placeholder="<?php p($l->t('write_article_content_placeholder')); ?>"
                                    class="helpdesk-form-control"><?php
                                    if ($article && isset($_['sanitizer'])) {
                                        $content = $_['sanitizer']->sanitize($article->getContent() ?? '');
                                        $content = str_replace('</textarea>', '&lt;/textarea&gt;', $content);
                                        print_unescaped($content);
                                    }
                                    ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="tc-section" aria-labelledby="kb-form-publish-heading">
                    <h2 id="kb-form-publish-heading" class="tc-section__title"><?php p($l->t('publishing_options')); ?></h2>
                    <p class="tc-section__lead"><?php p($l->t('unpublished_articles_note')); ?></p>
                    <div class="helpdesk-card">
                        <div class="helpdesk-card__body">
                            <fieldset class="tc-kb-form__options">
                                <legend class="tc-sr-only"><?php p($l->t('publishing_options')); ?></legend>
                                <div class="tc-field tc-field--checkbox">
                                    <label class="helpdesk-checkbox" for="published">
                                        <input type="checkbox"
                                            id="published"
                                            name="published"
                                            <?php if (($article && $article->getPublished()) || !$isEdit) {
                                                p('checked');
                                            } ?>>
                                        <span><?php p($l->t('publish_this_article')); ?></span>
                                    </label>
                                </div>
                                <div class="tc-field tc-field--checkbox">
                                    <label class="helpdesk-checkbox" for="pinned">
                                        <input type="checkbox"
                                            id="pinned"
                                            name="pinned"
                                            <?php if ($article && $article->getPinned()) {
                                                p('checked');
                                            } ?>>
                                        <span><?php p($l->t('pin_to_top')); ?></span>
                                    </label>
                                </div>
                                <div class="tc-field tc-field--checkbox">
                                    <label class="helpdesk-checkbox" for="allow_comments">
                                        <input type="checkbox"
                                            id="allow_comments"
                                            name="allow_comments"
                                            <?php if ($article && $article->getAllowComments()) {
                                                p('checked');
                                            } ?>
                                            <?php if ($kbCommentsEnabled !== true) {
                                                p(' disabled');
                                            } ?>>
                                        <span><?php p($l->t('allow_comments')); ?></span>
                                    </label>
                                </div>
                            </fieldset>
                            <?php if ($kbCommentsEnabled !== true): ?>
                                <p class="helpdesk-form-help"><?php p($l->t('knowledge_base_comments_disabled')); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <div class="helpdesk-form-actions tc-kb-form__actions">
                    <a href="<?php p($cancelHref); ?>" class="helpdesk-btn helpdesk-btn--secondary"><?php p($l->t('cancel')); ?></a>
                    <button type="submit" id="kb-form-submit" class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg">
                        <span class="tc-kb-form-submit__text"><?php p($isEdit ? $l->t('update_article') : $l->t('create_article')); ?></span>
                    </button>
                </div>
            </form>

<?php include __DIR__ . '/../common/page-end.php'; ?>

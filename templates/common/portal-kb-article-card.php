<?php

declare(strict_types=1);

/**
 * Guest portal KB article card (grid tile).
 *
 * @var array $_
 * @var \OCA\Ticketcheck\Db\KBArticle $article
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 */

use OCA\Ticketcheck\Db\KBArticle;
use OCA\Ticketcheck\Service\IconCatalog;

/** @var KBArticle $article */
$article = $_['article'];
/** @var \OCP\IL10N $l */
$l = $_['l'];
/** @var \OCP\IURLGenerator $urlGenerator */
$urlGenerator = $_['urlGenerator'];

$articleUrl = $urlGenerator->linkToRoute('ticketcheck.customerPortal.kpArticle', ['id' => $article->getId()]);
$content = html_entity_decode(
	strip_tags((string)$article->getContent()),
	ENT_QUOTES | ENT_HTML5,
	'UTF-8',
);
$content = str_replace("\xc2\xa0", ' ', $content);
$content = preg_replace('/\s+/u', ' ', $content ?? '') ?? '';
$content = trim($content);
$excerpt = mb_strlen($content, 'UTF-8') > 120
	? mb_substr($content, 0, 120, 'UTF-8') . '...'
	: $content;
?>
<a href="<?php p($articleUrl); ?>"
	class="portal-kb__article-card"
	aria-label="<?php p(strtr($l->t('view_kb_article_title'), ['{title}' => $article->getTitle()])); ?>">
	<h3 class="portal-kb__article-title"><?php p($article->getTitle()); ?></h3>
	<?php if ($excerpt !== ''): ?>
		<p class="portal-kb__article-excerpt"><?php p($excerpt); ?></p>
	<?php endif; ?>
	<div class="portal-kb__article-meta">
		<span class="portal-kb__article-meta-item">
			<span class="portal-kb__article-meta-icon" aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('eye')); ?>
			</span>
			<span class="portal-kb__article-meta-stat">
				<span class="portal-kb__article-meta-value"><?php p((string)$article->getViews()); ?></span>
				<span class="portal-kb__article-meta-label"><?php p($l->t('views')); ?></span>
			</span>
		</span>
		<span class="portal-kb__article-meta-item">
			<span class="portal-kb__article-meta-icon" aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('thumbs-up')); ?>
			</span>
			<span class="portal-kb__article-meta-stat">
				<span class="portal-kb__article-meta-value"><?php p((string)$article->getHelpfulCount()); ?></span>
				<span class="portal-kb__article-meta-label"><?php p($l->t('helpful')); ?></span>
			</span>
		</span>
	</div>
</a>

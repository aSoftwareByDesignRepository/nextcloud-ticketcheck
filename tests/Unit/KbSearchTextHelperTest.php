<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\KBArticle;
use OCA\Ticketcheck\Service\KbSearchTextHelper;
use PHPUnit\Framework\TestCase;

class KbSearchTextHelperTest extends TestCase
{
    public function testNormalizeQueryTrimsAndCapsLength(): void
    {
        $long = str_repeat('a', KbSearchTextHelper::MAX_QUERY_LENGTH + 50);
        $normalized = KbSearchTextHelper::normalizeQuery('  ' . $long . '  ');

        $this->assertSame(KbSearchTextHelper::MAX_QUERY_LENGTH, mb_strlen($normalized));
        $this->assertSame(str_repeat('a', KbSearchTextHelper::MAX_QUERY_LENGTH), $normalized);
    }

    public function testNormalizeQueryReturnsEmptyForWhitespace(): void
    {
        $this->assertSame('', KbSearchTextHelper::normalizeQuery("  \t\n  "));
    }

    public function testExtractSearchTokensRemovesGermanStopWords(): void
    {
        $tokens = KbSearchTextHelper::extractSearchTokens('Wie finde ich mehr Gelassenheit');

        $this->assertContains('gelassenheit', $tokens);
        $this->assertNotContains('wie', $tokens);
        $this->assertNotContains('ich', $tokens);
        $this->assertNotContains('mehr', $tokens);
    }

    public function testExtractSearchTokensAddsGermanStemVariant(): void
    {
        $tokens = KbSearchTextHelper::extractSearchTokens('Gelassenheit');

        $this->assertContains('gelassenheit', $tokens);
        $this->assertContains('gelassen', $tokens);
    }

    public function testRankArticlesPrefersTitleMatchForNaturalLanguageQuery(): void
    {
        $best = $this->createArticle(1, 'Der Weg zu mehr Gelassenheit', 'Tipps für den Alltag.');
        $other = $this->createArticle(2, 'Projektplanung', 'Wie man Projekte findet und mehr Zeit spart.');

        $ranked = KbSearchTextHelper::rankArticles(
            [$other, $best],
            'Wie finde ich mehr Gelassenheit',
        );

        $this->assertCount(1, $ranked);
        $this->assertSame('Der Weg zu mehr Gelassenheit', $ranked[0]->getTitle());
    }

    public function testRankArticlesDropsIrrelevantCandidates(): void
    {
        $relevant = $this->createArticle(1, 'Tree house basics', 'How to build safely.');
        $irrelevant = $this->createArticle(2, 'Billing FAQ', 'Invoices and payments.');

        $ranked = KbSearchTextHelper::rankArticles(
            [$irrelevant, $relevant],
            'tree house',
        );

        $this->assertCount(1, $ranked);
        $this->assertSame('Tree house basics', $ranked[0]->getTitle());
    }

    public function testHighlightMatchesEscapesHtmlAndHighlightsTokens(): void
    {
        $html = KbSearchTextHelper::highlightMatches('<script>tree</script> house', 'tree house');

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<mark class="kb-search-highlight">tree</mark>', $html);
        $this->assertStringContainsString('<mark class="kb-search-highlight">house</mark>', $html);
    }

    public function testHighlightMatchesIgnoresStopWords(): void
    {
        $html = KbSearchTextHelper::highlightMatches('Der Weg zu mehr Gelassenheit', 'Wie finde ich mehr Gelassenheit');

        $this->assertStringContainsString('<mark class="kb-search-highlight">Gelassenheit</mark>', $html);
        $this->assertStringNotContainsString('<mark class="kb-search-highlight">mehr</mark>', $html);
    }

    public function testHighlightMatchesReturnsEscapedTextWhenQueryEmpty(): void
    {
        $this->assertSame('Safe &amp; sound', KbSearchTextHelper::highlightMatches('Safe & sound', ''));
    }

    private function createArticle(int $id, string $title, string $content): KBArticle
    {
        $article = new KBArticle();
        $article->setId($id);
        $article->setTitle($title);
        $article->setContent($content);
        $article->setCategory('General');
        $article->setPublished(true);
        $article->setPinned(false);
        $article->setViews(0);
        $article->setHelpfulCount(0);
        $article->setCreatedAt(new \DateTime('2025-01-01'));
        $article->setUpdatedAt(new \DateTime('2025-01-01'));

        return $article;
    }
}

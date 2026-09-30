<?php

declare(strict_types=1);

/**
 * Knowledge base search query normalization, tokenization, ranking, and safe highlighting.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\KBArticle;

/**
 * Stateless helpers for KB keyword search display and relevance ranking.
 */
final class KbSearchTextHelper
{
	public const MAX_QUERY_LENGTH = 200;

	private const MIN_TOKEN_LENGTH = 3;

	/** @var list<string> */
	private const STOP_WORDS = [
		// German
		'als', 'also', 'andere', 'anderen', 'bei', 'beim', 'bin', 'bis', 'bist', 'da', 'damit', 'dann',
		'das', 'dass', 'dein', 'deine', 'dem', 'den', 'denn', 'der', 'des', 'die', 'dies', 'diese',
		'dieser', 'dieses', 'doch', 'du', 'ein', 'eine', 'einem', 'einen', 'einer', 'eines', 'er',
		'es', 'euch', 'finde', 'finden', 'findest', 'für', 'habe', 'haben', 'hat', 'hier', 'ich',
		'ihr', 'ihre', 'ihren', 'ihrer', 'ihres', 'ist', 'kann', 'können', 'man', 'mehr', 'mein',
		'meine', 'mir', 'mit', 'muss', 'nach', 'nicht', 'noch', 'nur', 'oder', 'ohne', 'schon',
		'sehr', 'sein', 'seine', 'sich', 'sie', 'sind', 'so', 'soll', 'sollte', 'suche', 'suchen',
		'über', 'um', 'und', 'uns', 'unser', 'vom', 'von', 'vor', 'war', 'was', 'weil', 'wenn',
		'wer', 'wie', 'wird', 'wo', 'zu', 'zum', 'zur',
		// English
		'a', 'an', 'and', 'are', 'as', 'at', 'be', 'been', 'but', 'by', 'can', 'could', 'did', 'do',
		'does', 'find', 'for', 'from', 'get', 'had', 'has', 'have', 'he', 'her', 'here', 'him', 'his',
		'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'just', 'me', 'more', 'my', 'no', 'not',
		'of', 'on', 'or', 'our', 'out', 'search', 'she', 'should', 'so', 'some', 'than', 'that',
		'the', 'their', 'them', 'then', 'there', 'these', 'they', 'this', 'to', 'too', 'up', 'us',
		'was', 'we', 'were', 'what', 'when', 'where', 'which', 'who', 'why', 'will', 'with', 'you',
		'your',
	];

	/** @var list<string> */
	private const GERMAN_SUFFIXES = ['heit', 'keit', 'ung', 'chen', 'lich', 'igkeit', 'ismus', 'ieren'];

	public static function normalizeQuery(string $query): string
	{
		$normalized = trim($query);
		if ($normalized === '') {
			return '';
		}
		if (mb_strlen($normalized) > self::MAX_QUERY_LENGTH) {
			$normalized = mb_substr($normalized, 0, self::MAX_QUERY_LENGTH);
		}
		return $normalized;
	}

	/**
	 * Tokens used for SQL candidate fetch (meaningful words + light German stem variants).
	 *
	 * @return list<string>
	 */
	public static function extractSearchTokens(string $query): array
	{
		$meaningful = self::extractMeaningfulTokens($query);
		if ($meaningful === []) {
			return [];
		}

		$expanded = [];
		foreach ($meaningful as $token) {
			foreach (self::expandTokenVariants($token) as $variant) {
				$expanded[$variant] = true;
			}
		}

		return array_keys($expanded);
	}

	/**
	 * Rank and filter KB articles by relevance to the meaningful query tokens.
	 *
	 * @param list<KBArticle> $articles
	 * @return list<KBArticle>
	 */
	public static function rankArticles(array $articles, string $query): array
	{
		$meaningful = self::extractMeaningfulTokens($query);
		if ($meaningful === []) {
			return [];
		}

		$scored = [];
		foreach ($articles as $article) {
			$score = self::scoreArticle($article, $meaningful);
			if ($score > 0) {
				$scored[] = ['article' => $article, 'score' => $score];
			}
		}

		usort($scored, static function (array $left, array $right): int {
			if ($left['score'] !== $right['score']) {
				return $right['score'] <=> $left['score'];
			}

			/** @var KBArticle $articleA */
			$articleA = $left['article'];
			/** @var KBArticle $articleB */
			$articleB = $right['article'];

			if ($articleA->getPinned() !== $articleB->getPinned()) {
				return (int)$articleB->getPinned() <=> (int)$articleA->getPinned();
			}
			if ($articleA->getHelpfulCount() !== $articleB->getHelpfulCount()) {
				return $articleB->getHelpfulCount() <=> $articleA->getHelpfulCount();
			}
			if ($articleA->getViews() !== $articleB->getViews()) {
				return $articleB->getViews() <=> $articleA->getViews();
			}

			return $articleB->getCreatedAt() <=> $articleA->getCreatedAt();
		});

		return array_map(static fn (array $row): KBArticle => $row['article'], $scored);
	}

	/**
	 * Escape text for HTML, then wrap each meaningful search token in <mark> (WCAG: not color-only).
	 */
	public static function highlightMatches(string $text, string $searchQuery): string
	{
		$escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
		$tokens = self::extractMeaningfulTokens($searchQuery);
		if ($tokens === []) {
			return $escaped;
		}

		$highlightTokens = [];
		foreach ($tokens as $token) {
			$highlightTokens[$token] = true;
			foreach (self::expandTokenVariants($token) as $variant) {
				if (mb_strlen($variant) >= self::MIN_TOKEN_LENGTH) {
					$highlightTokens[$variant] = true;
				}
			}
		}

		$quoted = array_map(static fn (string $token): string => preg_quote($token, '/'), array_keys($highlightTokens));
		usort($quoted, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
		$pattern = '/' . implode('|', $quoted) . '/iu';
		$replaced = preg_replace(
			$pattern,
			'<mark class="kb-search-highlight">$0</mark>',
			$escaped,
		);

		return $replaced ?? $escaped;
	}

	/**
	 * @return list<string>
	 */
	private static function extractMeaningfulTokens(string $query): array
	{
		$normalized = self::normalizeQuery($query);
		if ($normalized === '') {
			return [];
		}

		$raw = preg_split('/\s+/u', mb_strtolower($normalized, 'UTF-8')) ?: [];
		$meaningful = [];
		$fallback = [];

		foreach ($raw as $part) {
			$token = trim($part, ".,;:!?\"'()[]{}«»„“”“");
			if ($token === '' || mb_strlen($token) < self::MIN_TOKEN_LENGTH) {
				continue;
			}
			$fallback[$token] = true;
			if (!self::isStopWord($token)) {
				$meaningful[$token] = true;
			}
		}

		if ($meaningful !== []) {
			return array_keys($meaningful);
		}

		// Query was only stop words — use remaining words so search is not a dead end.
		return array_keys($fallback);
	}

	private static function isStopWord(string $token): bool
	{
		return in_array($token, self::STOP_WORDS, true);
	}

	/**
	 * @return list<string>
	 */
	private static function expandTokenVariants(string $token): array
	{
		$variants = [$token];
		foreach (self::GERMAN_SUFFIXES as $suffix) {
			if (mb_strlen($token) <= mb_strlen($suffix) + self::MIN_TOKEN_LENGTH) {
				continue;
			}
			if (str_ends_with($token, $suffix)) {
				$stem = mb_substr($token, 0, mb_strlen($token) - mb_strlen($suffix));
				if (mb_strlen($stem) >= self::MIN_TOKEN_LENGTH) {
					$variants[] = $stem;
				}
			}
		}

		return array_values(array_unique($variants));
	}

	/**
	 * @param list<string> $tokens meaningful query tokens
	 */
	private static function scoreArticle(KBArticle $article, array $tokens): int
	{
		$title = mb_strtolower(strip_tags((string)$article->getTitle()), 'UTF-8');
		$content = mb_strtolower(strip_tags((string)$article->getContent()), 'UTF-8');
		$titleWords = self::splitWords($title);

		$score = 0;
		$matchedTokens = 0;

		foreach ($tokens as $token) {
			$tokenScore = 0;
			$matched = false;

			if (self::containsToken($title, $token)) {
				$matched = true;
				$tokenScore += 40;
				if (in_array($token, $titleWords, true)) {
					$tokenScore += 25;
				}
				if ($title === $token) {
					$tokenScore += 35;
				}
			} elseif (self::containsStemVariant($title, $token)) {
				$matched = true;
				$tokenScore += 28;
			}

			if (self::containsToken($content, $token)) {
				$matched = true;
				$tokenScore += 8;
			} elseif (self::containsStemVariant($content, $token)) {
				$matched = true;
				$tokenScore += 5;
			}

			if ($matched) {
				$matchedTokens++;
				$score += $tokenScore;
			}
		}

		if ($matchedTokens === 0) {
			return 0;
		}

		if ($matchedTokens === count($tokens)) {
			$score += 20;
		}

		if ($article->getPinned()) {
			$score += 5;
		}

		return $score;
	}

	private static function containsToken(string $haystack, string $token): bool
	{
		return mb_strpos($haystack, $token) !== false;
	}

	private static function containsStemVariant(string $haystack, string $token): bool
	{
		foreach (self::expandTokenVariants($token) as $variant) {
			if ($variant !== $token && mb_strlen($variant) >= self::MIN_TOKEN_LENGTH && mb_strpos($haystack, $variant) !== false) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<string>
	 */
	private static function splitWords(string $text): array
	{
		$parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		return array_values($parts);
	}
}

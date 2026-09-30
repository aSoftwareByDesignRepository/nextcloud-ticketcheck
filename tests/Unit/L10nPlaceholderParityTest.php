<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Footer-locale catalogs must keep printf and named placeholders from English.
 * Dropping %1$s / {count} makes sprintf and UI counters lie or warn.
 */
final class L10nPlaceholderParityTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	public function testFooterLocalesKeepEnglishPlaceholders(): void
	{
		$locales = ['de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb', 'pt_BR'];
		$en = $this->loadTranslations('en');
		self::assertNotEmpty($en);
		foreach ($locales as $locale) {
			$tr = $this->loadTranslations($locale);
			foreach ($en as $key => $english) {
				if (!is_string($english) || !isset($tr[$key]) || !is_string($tr[$key])) {
					continue;
				}
				$needPrintf = $this->printfTokens($english);
				$needNamed = $this->namedTokens($english);
				if ($needPrintf === [] && $needNamed === []) {
					continue;
				}
				self::assertSame(
					$needPrintf,
					$this->printfTokens($tr[$key]),
					$locale . ' dropped printf placeholders on ' . $key,
				);
				self::assertSame(
					$needNamed,
					$this->namedTokens($tr[$key]),
					$locale . ' dropped named placeholders on ' . $key,
				);
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function loadTranslations(string $locale): array
	{
		$path = $this->root . '/l10n/' . $locale . '.json';
		self::assertFileExists($path);
		$decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);
		$tr = $decoded['translations'] ?? null;
		self::assertIsArray($tr);
		return $tr;
	}

	/**
	 * @return list<string>
	 */
	private function printfTokens(string $s): array
	{
		preg_match_all('/%%|%(?:\d+\$)?[sd]/', $s, $m);
		return $m[0];
	}

	/**
	 * @return list<string>
	 */
	private function namedTokens(string $s): array
	{
		preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', $s, $m);
		return $m[0];
	}
}

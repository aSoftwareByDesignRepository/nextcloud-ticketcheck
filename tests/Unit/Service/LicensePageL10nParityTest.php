<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * The shared license page (_shared/license-page, synced copy under
 * templates/common/license-page.php) renders through the app catalog — every
 * msgid the synced template emits must have EN+DE entries, or a DE session
 * silently falls back to English (the exact bug operators reported).
 *
 * Sources the LIVE msgid set from the synced template itself: the $i18nMsgids
 * block (JS-visible keys) plus direct $l->t() calls, the app-side wrapper
 * (parts/license-panel.php), and the live settings-section heading
 * ('Mobile license' via SettingsSectionCatalog).
 */
final class LicensePageL10nParityTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	/** @return list<string> */
	private function liveLicenseMsgids(): array
	{
		$ids = [];
		foreach (['/templates/common/license-page.php', '/templates/parts/license-panel.php'] as $rel) {
			$tpl = (string)file_get_contents($this->root . $rel);
			if (preg_match('/\$i18nMsgids = \[\n(.*?)\n\];/s', $tpl, $b)) {
				preg_match_all("/=>\s*'((?:\\\\.|[^'\\\\])*)'/", $b[1], $m);
				foreach ($m[1] as $raw) {
					$ids[] = str_replace("\\'", "'", $raw);
				}
			}
			preg_match_all("/\\\$l->t\('((?:\\\\.|[^'\\\\])*)'/", $tpl, $m2);
			foreach ($m2[1] as $raw) {
				$ids[] = str_replace("\\'", "'", $raw);
			}
		}
		// Live settings-section heading (SettingsSectionCatalog 'license' entry).
		$ids[] = 'Mobile license';
		$ids = array_values(array_unique($ids));
		self::assertNotEmpty($ids, 'license page msgid extraction must not come back empty');
		return $ids;
	}

	/** @return array<string, string> */
	private function loadTranslations(string $locale): array
	{
		$path = $this->root . '/l10n/' . $locale . '.json';
		self::assertFileExists($path, "l10n/{$locale}.json must exist");
		$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($data['translations'] ?? null);
		/** @var array<string, string> $t */
		$t = $data['translations'];
		return $t;
	}

	public function testEveryLicensePageMsgidExistsInEnAndDe(): void
	{
		$msgids = $this->liveLicenseMsgids();

		$en = $this->loadTranslations('en');
		$de = $this->loadTranslations('de');

		$missingEn = [];
		$missingDe = [];
		$englishInDe = [];
		foreach ($msgids as $id) {
			if (!array_key_exists($id, $en)) {
				$missingEn[] = $id;
			}
			if (!array_key_exists($id, $de)) {
				$missingDe[] = $id;
			} elseif ($de[$id] === $id && !in_array($id, ['TKC2.…', 'Person', 'Status'], true)) {
				$englishInDe[] = $id;
			}
		}

		self::assertSame([], $missingEn, 'Missing EN catalog entries for license UI');
		self::assertSame([], $missingDe, 'Missing DE catalog entries for license UI');
		self::assertSame([], $englishInDe, 'DE translations must not leave English source text (except known identical words)');
	}

	public function testDeJsRegistersSameLicenseKeys(): void
	{
		$msgids = $this->liveLicenseMsgids();
		$js = (string)file_get_contents($this->root . '/l10n/de.js');
		$missing = [];
		foreach ($msgids as $id) {
			$needle = json_encode($id, JSON_UNESCAPED_UNICODE);
			self::assertIsString($needle);
			if (!str_contains($js, $needle)) {
				$missing[] = $id;
			}
		}
		self::assertSame([], $missing, 'de.js must register every live license-page msgid');
	}

	public function testGermanMobileLicenseHeadingIsTranslated(): void
	{
		$de = $this->loadTranslations('de');
		self::assertSame('Mobile-Lizenz', $de['Mobile license'] ?? null);
		self::assertStringContainsString('kostenlos', $de['The TicketCheck web app always stays free. A TKC2 license unlocks named seats for the official TicketCheck mobile companion app for your organisation.'] ?? '');
		self::assertSame('Mobile-Sitze', $de['Mobile seats'] ?? null);
	}
}

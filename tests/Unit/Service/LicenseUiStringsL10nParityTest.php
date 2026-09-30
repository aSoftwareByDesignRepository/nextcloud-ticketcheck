<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\LicenseUiStrings;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * TKC2 mobile license panel must ship EN+DE for every {@see LicenseUiStrings} msgid.
 * Missing DE entries fall back to English in the UI — the exact bug operators reported.
 */
final class LicenseUiStringsL10nParityTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	/** @return list<string> */
	private function catalogMsgids(): array
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s): string => $s);
		return array_values(LicenseUiStrings::forPanel($l));
	}

	/** @return list<string> */
	private function panelExtraMsgids(): array
	{
		$panel = (string)file_get_contents($this->root . '/templates/parts/license-panel.php');
		preg_match_all("/\\\$l->t\\('((?:\\\\'|[^'])*)'\\)/", $panel, $m);
		$out = [];
		foreach ($m[1] as $raw) {
			$out[] = str_replace("\\'", "'", $raw);
		}
		return array_values(array_unique($out));
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

	public function testEveryLicenseUiStringExistsInEnAndDe(): void
	{
		$msgids = array_values(array_unique(array_merge($this->catalogMsgids(), $this->panelExtraMsgids())));
		self::assertNotEmpty($msgids);

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
			} elseif ($de[$id] === $id && !in_array($id, ['TKC2.…', 'Person'], true)) {
				$englishInDe[] = $id;
			}
		}

		self::assertSame([], $missingEn, 'Missing EN catalog entries for license UI');
		self::assertSame([], $missingDe, 'Missing DE catalog entries for license UI');
		self::assertSame([], $englishInDe, 'DE translations must not leave English source text (except known identical words)');
	}

	public function testDeJsRegistersSameLicenseKeys(): void
	{
		$msgids = $this->catalogMsgids();
		$js = (string)file_get_contents($this->root . '/l10n/de.js');
		$missing = [];
		foreach ($msgids as $id) {
			$needle = json_encode($id, JSON_UNESCAPED_UNICODE);
			self::assertIsString($needle);
			if (!str_contains($js, $needle)) {
				$missing[] = $id;
			}
		}
		self::assertSame([], $missing, 'de.js must register every LicenseUiStrings msgid');
	}

	public function testGermanMobileLicenseHeadingIsTranslated(): void
	{
		$de = $this->loadTranslations('de');
		self::assertSame('Mobile-Lizenz', $de['Mobile license'] ?? null);
		self::assertStringContainsString('kostenlos', $de['The TicketCheck web app always stays free. A TKC2 license unlocks named seats for the official TicketCheck mobile companion app for your organisation.'] ?? '');
		self::assertSame('Mobile-Sitze', $de['Mobile seats'] ?? null);
	}
}

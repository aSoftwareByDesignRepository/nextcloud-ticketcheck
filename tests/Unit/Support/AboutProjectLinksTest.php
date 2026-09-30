<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Support;

use OCA\Ticketcheck\Support\AboutProjectLinks;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the About-page funding links + logo assets.
 */
final class AboutProjectLinksTest extends TestCase {
	private function urlGenerator(): IURLGenerator {
		$u = $this->createMock(IURLGenerator::class);
		$u->method('imagePath')->willReturnCallback(
			static fn (string $app, string $img) => "/custom_apps/{$app}/img/{$img}"
		);
		return $u;
	}

	public function testLogoFileIsLocaleKeyedAllowlist(): void {
		$links = new AboutProjectLinks();
		self::assertSame(AboutProjectLinks::LOGO_FILE_DE, $links->logoFile('de'));
		self::assertSame(AboutProjectLinks::LOGO_FILE_DE, $links->logoFile('de_DE'));
		self::assertSame(AboutProjectLinks::LOGO_FILE_EN, $links->logoFile('en'));
		self::assertSame(AboutProjectLinks::LOGO_FILE_EN, $links->logoFile('fr'));
	}

	public function testAssetUrlCarriesContentHashBuster(): void {
		$links = new AboutProjectLinks();
		$url = $links->assetUrl($this->urlGenerator(), AboutProjectLinks::LOGO_FILE_DE);

		self::assertMatchesRegularExpression(
			'#^/custom_apps/ticketcheck/img/funding/BMFTR_de_Web_RGB_gef_durch\.svg\?v=[0-9a-f]{8}$#',
			$url,
			'logo URL must carry a short sha1 of the file bytes'
		);

		// The buster equals the actual file content hash — it changes exactly
		// when the artwork bytes change (stale-cache kill for fixed assets).
		$file = dirname(__DIR__, 3) . '/img/' . AboutProjectLinks::LOGO_FILE_DE;
		$expected = substr((string) sha1_file($file), 0, 8);
		self::assertStringEndsWith('?v=' . $expected, $url);
	}

	public function testAssetUrlForMissingFileFallsBackToZeroHash(): void {
		$links = new AboutProjectLinks();
		$url = $links->assetUrl($this->urlGenerator(), 'funding/does-not-exist.svg');
		self::assertStringEndsWith('?v=0', $url);
	}

	public function testForLocalePayloadIsComplete(): void {
		$de = (new AboutProjectLinks())->forLocale('de');
		foreach (['grantProjectName', 'fundingProgramme', 'fundingPeriod', 'licenseId',
			'prototypeFundUrl', 'bmftrUrl', 'repositoryUrl', 'issuesUrl', 'licenseUrl'] as $key) {
			self::assertNotSame('', (string) $de[$key], "payload key {$key} must be non-empty");
		}
		self::assertTrue($de['isGerman']);
	}
}

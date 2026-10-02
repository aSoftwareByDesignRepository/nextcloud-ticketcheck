<?php

declare(strict_types=1);

namespace OCA\TicketCheck\Tests\Unit\AppInfo;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * App Store screenshot metadata contract:
 * - screenshots use HTTPS URLs on a host known to work with usercontent.apps.nextcloud.com
 * - small-thumbnail, if present, is identical to the main URL (avoids mismatched cache keys)
 * - URLs point at committed repository assets so releases stay reproducible
 */
final class ScreenshotStoreContractTest extends TestCase
{
	private const EXPECTED_ORIGIN_PREFIX = 'https://raw.githubusercontent.com/aSoftwareByDesignRepository/nextcloud-ticketcheck/main/screenshots/appstore/';

	private const EXPECTED_SCREENSHOT_COUNT = 6;

	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testInfoXmlContainsExpectedScreenshotUrls(): void
	{
		$xml = (string)file_get_contents($this->root . '/appinfo/info.xml');

		$dom = new DOMDocument();
		$this->assertTrue($dom->loadXML($xml), 'info.xml must be valid XML');

		$xp = new DOMXPath($dom);
		/** @var list<DOMElement> $screenshots */
		$screenshots = iterator_to_array($xp->query('/info/screenshot'));

		$this->assertCount(self::EXPECTED_SCREENSHOT_COUNT, $screenshots, 'Screenshot count must match the committed appstore assets');

		foreach ($screenshots as $index => $screenshot) {
			$url = $this->normalizeUrl((string)$screenshot->textContent);
			$position = $index + 1;
			$expectedUrl = self::EXPECTED_ORIGIN_PREFIX . sprintf('%02d', $position) . '.png';

			$this->assertStringStartsWith('https://', $url, "Screenshot {$position} must use HTTPS");
			$this->assertSame(
				$expectedUrl,
				$url,
				"Screenshot {$position} must point at the committed appstore asset",
			);

			$smallThumbnail = $screenshot->getAttribute('small-thumbnail');
			if ($smallThumbnail !== '') {
				$this->assertSame(
					$url,
					$this->normalizeUrl($smallThumbnail),
					"Screenshot {$position} small-thumbnail must match the main URL to avoid stale cache keys",
				);
			}
		}
	}

	public function testScreenshotAssetsExistInRepository(): void
	{
		for ($i = 1; $i <= self::EXPECTED_SCREENSHOT_COUNT; $i++) {
			$path = $this->root . '/screenshots/appstore/' . sprintf('%02d', $i) . '.png';
			$this->assertFileExists($path, "Screenshot asset {$i} must exist in the repository");
			$this->assertSame('image/png', mime_content_type($path), "Screenshot asset {$i} must be a PNG");
		}
	}

	private function normalizeUrl(string $url): string
	{
		return trim($url);
	}
}

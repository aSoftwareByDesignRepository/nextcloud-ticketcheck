<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The synced check-dates module ships js + css as a pair
 * (scripts/check-dates/README.md §24-25). FrontEndAssetService must register
 * BOTH — the stylesheet hides native date-input text for the .tc-date-field
 * chrome; without it the injected overlay + picker render over visible
 * native text (script-style-parity gate, 2026-10-22).
 */
final class CheckDatesAssetsContractTest extends TestCase
{
	private string $src;

	protected function setUp(): void
	{
		parent::setUp();
		$root = dirname(__DIR__, 2);
		$this->src = (string) file_get_contents($root . '/lib/Service/FrontEndAssetService.php');

		self::assertFileExists($root . '/js/common/check-dates.js');
		self::assertFileExists($root . '/css/common/check-dates.css');
	}

	public function testPageRegistersCheckDatesStyle(): void
	{
		self::assertMatchesRegularExpression(
			'/^\s*Util::addStyle\(\s*Application::APP_ID\s*,\s*\'common\/check-dates\'\s*\);/m',
			$this->src,
			'check-dates stylesheet must be registered alongside its script'
		);
	}

	public function testCheckDatesScriptLoadsBeforeDatesAdapter(): void
	{
		self::assertMatchesRegularExpression(
			'/^\s*Util::addScript\(\s*Application::APP_ID\s*,\s*\'common\/check-dates\'\s*\);/m',
			$this->src,
			'check-dates script must be registered'
		);
		$checkDates = strpos($this->src, "addScript(Application::APP_ID, 'common/check-dates')");
		$adapter = strpos($this->src, "addScript(Application::APP_ID, 'common/dates')");
		self::assertNotFalse($checkDates);
		self::assertNotFalse($adapter);
		self::assertLessThan(
			$adapter,
			$checkDates,
			'common/check-dates must load before common/dates — the adapter destructures window.CheckDates at init'
		);
	}
}

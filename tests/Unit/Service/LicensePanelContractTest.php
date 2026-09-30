<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * File-content contracts for the TKC2 mobile license admin panel.
 *
 * TicketCheck is seats-only: no terminal/kiosk product. These guards catch copy-paste
 * drift from other Check apps and missing form-control styling that shrinks the key field.
 */
final class LicensePanelContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	private function read(string $relativePath): string
	{
		$path = $this->root . '/' . ltrim($relativePath, '/');
		self::assertFileExists($path, "Expected file to exist: {$relativePath}");
		return (string)file_get_contents($path);
	}

	public function testPanelHasAnchorIdAndDataWiring(): void
	{
		$tpl = $this->read('templates/parts/license-panel.php');
		self::assertStringContainsString('id="ticketcheck-license"', $tpl);
		self::assertStringContainsString('id="tc-license-panel"', $tpl);
		self::assertStringContainsString('data-api-license="', $tpl);
		self::assertStringContainsString('data-api-clear-license="', $tpl);
		self::assertStringContainsString('data-api-seats="', $tpl);
		self::assertStringContainsString('data-api-assign-seat="', $tpl);
		self::assertStringContainsString('data-api-remove-seat-base="', $tpl);
		self::assertStringContainsString('data-api-search-users="', $tpl);
		self::assertStringContainsString('data-requesttoken="', $tpl);
		self::assertStringContainsString('data-i18n="', $tpl);
	}

	public function testPanelHasAccessibleLicenseKeyField(): void
	{
		$tpl = $this->read('templates/parts/license-panel.php');
		self::assertStringContainsString('id="tc-license-key"', $tpl);
		self::assertStringContainsString('class="ticketcheck-textarea tc-license-key"', $tpl);
		self::assertStringContainsString('aria-describedby="tc-license-key-hint"', $tpl);
		self::assertStringContainsString('id="tc-license-key-hint"', $tpl);
		self::assertStringContainsString('class="ticketcheck-hint"', $tpl);
		self::assertStringContainsString('TKC2', $tpl);
	}

	public function testPanelHasLiveRegionsAndFeedbackBanner(): void
	{
		$tpl = $this->read('templates/parts/license-panel.php');
		self::assertStringContainsString('id="tc-license-live"', $tpl);
		self::assertStringContainsString('id="tc-license-alert"', $tpl);
		self::assertStringContainsString('aria-live="polite"', $tpl);
		self::assertStringContainsString('aria-live="assertive"', $tpl);
		self::assertStringContainsString('id="tc-license-feedback"', $tpl);
		self::assertStringContainsString('role="alert"', $tpl);
	}

	public function testPanelHasAccessibleWidgets(): void
	{
		$tpl = $this->read('templates/parts/license-panel.php');
		self::assertStringContainsString('role="meter"', $tpl);
		self::assertStringContainsString('aria-valuemin="0"', $tpl);
		self::assertStringContainsString('aria-valuemax=', $tpl);
		self::assertStringContainsString('role="dialog"', $tpl);
		self::assertStringContainsString('aria-modal="true"', $tpl);
		self::assertStringContainsString('role="combobox"', $tpl);
		self::assertStringContainsString('aria-haspopup="listbox"', $tpl);
	}

	public function testPanelHasNoTerminalOrKioskMarkup(): void
	{
		$tpl = strtolower($this->read('templates/parts/license-panel.php'));
		self::assertStringNotContainsString('terminal', $tpl);
		self::assertStringNotContainsString('kiosk', $tpl);
		self::assertStringNotContainsString('room display', $tpl);
	}

	public function testLicenseSettingsJsUsesTicketCheckFlashKey(): void
	{
		$js = $this->read('js/license-settings.js');
		self::assertStringContainsString("'tcLicensePanelFlash'", $js);
		self::assertStringNotContainsString("'pcLicensePanelFlash'", $js);
	}

	public function testLicenseSettingsJsHandlesSeatConflictsAndAvoidsInnerHtmlForNames(): void
	{
		$js = $this->read('js/license-settings.js');
		self::assertStringContainsString('409', $js);
		self::assertStringContainsString('seat_busy', $js);
		self::assertStringContainsString('seatAssignBusy', $js);
		self::assertStringContainsString('displayName', $js);
		self::assertStringContainsString('textContent', $js);
		self::assertStringNotContainsString('.innerHTML =', $js);
		self::assertStringContainsString('UNIX seconds', $js);
		self::assertStringContainsString('* 1000', $js);
	}

	public function testLicenseSettingsCssDefinesFullWidthTextarea(): void
	{
		$css = $this->read('css/license-settings.css');
		self::assertMatchesRegularExpression(
			'/\.ticketcheck-textarea,\s*\n\.ticketcheck-input\s*\{[^}]*(?<![\w-])width:\s*100%;/s',
			$css,
		);
		self::assertMatchesRegularExpression('/\.ticketcheck-textarea\s*\{[^}]*min-height:\s*6rem/s', $css);
		self::assertMatchesRegularExpression('/\.ticketcheck-textarea:focus-visible/s', $css);
		self::assertStringContainsString('.tc-license-panel textarea:focus-visible', $css);
		self::assertStringContainsString('.tc-license-actions .helpdesk-btn', $css);
	}

	public function testFrontEndAssetServiceShipsLicenseAssetsOnLicenseSectionOnly(): void
	{
		$service = $this->read('lib/Service/FrontEndAssetService.php');
		self::assertStringContainsString('if ($settingsSection === \'license\') {', $service);
		self::assertStringContainsString("Util::addStyle(Application::APP_ID, 'license-settings');", $service);
		self::assertStringContainsString("Util::addScript(Application::APP_ID, 'license-settings');", $service);
	}

	public function testLicenseSectionPartialIncludesPanel(): void
	{
		$partial = $this->read('templates/parts/settings/license.php');
		self::assertStringContainsString("include dirname(__DIR__) . '/license-panel.php';", $partial);
	}
}

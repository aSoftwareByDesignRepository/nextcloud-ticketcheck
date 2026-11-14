<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * File-content contracts for the unified license page (TKC2 mobile seats).
 *
 * The license UX is the canonical shared Check-family license page synced from
 * _shared/license-page. These contracts guard the wiring: the shared assets are
 * present and registered, the host wrapper feeds the canonical params, and the
 * license API routes exist. TicketCheck is seats-only — no terminal markup may
 * be enabled in the host wrapper.
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

	public function testSharedLicensePageAssetsExist(): void
	{
		$php = $this->read('templates/common/license-page.php');
		self::assertStringContainsString('data-license-root="1"', $php);
		self::assertStringContainsString('data-prefix="tc"', $php);
		self::assertStringContainsString('id="tc-license-page"', $php);
		$this->read('js/common/license-page.js');
		$this->read('css/common/license-page.css');
	}

	public function testHostWrapperFeedsCanonicalParams(): void
	{
		$tpl = $this->read('templates/parts/license-panel.php');
		self::assertStringContainsString("include __DIR__ . '/../common/license-page.php'", $tpl);
		foreach ([
			'productCode',
			'mobileSeatsUsed', 'mobileSeatsLimit', 'mobileSeats', 'showMobileSeats',
			'apiLicenseUrl', 'apiClearLicenseUrl', 'apiSeatsUrl',
			'apiRemoveSeatUrl', 'apiSearchUsersUrl', 'requesttoken',
		] as $param) {
			self::assertStringContainsString('$_[\'' . $param . '\']', $tpl, 'wrapper must set $_[' . $param . ']');
		}
		self::assertStringContainsString("'TKC2'", $tpl);
		self::assertStringContainsString('removeSeatPost', $tpl);
		self::assertStringContainsString('id="ticketcheck-license"', $tpl);
		// Seats-only app: never enable the device card.
		self::assertStringNotContainsString("'showTerminal'] = true", $tpl);
		self::assertStringNotContainsString('apiRegisterDeviceUrl', $tpl);
	}

	public function testSharedPageHasAccessibleWidgets(): void
	{
		$tpl = $this->read('templates/common/license-page.php');
		self::assertStringContainsString('role="status" aria-live="polite"', $tpl);
		self::assertStringContainsString('role="alert" aria-live="assertive"', $tpl);
		self::assertStringContainsString('role="meter"', $tpl);
		self::assertStringContainsString('<dialog id="tc-license-', $tpl);
		self::assertStringContainsString('role="combobox"', $tpl);
	}

	public function testSettingsSubPageDelegatesToWrapper(): void
	{
		$sub = $this->read('templates/parts/settings/license.php');
		self::assertStringContainsString("include dirname(__DIR__) . '/license-panel.php';", $sub);
		self::assertStringNotContainsString('license-settings', $sub, 'old per-app license assets must be gone');
	}

	public function testFrontEndAssetServiceWiresSharedAssets(): void
	{
		$service = $this->read('lib/Service/FrontEndAssetService.php');
		self::assertStringContainsString("Util::addStyle(Application::APP_ID, 'common/license-page');", $service);
		self::assertStringContainsString("Util::addScript(Application::APP_ID, 'common/license-page');", $service);
		self::assertStringNotContainsString("license-settings'", $service, 'old per-app license assets must be gone');
	}

	public function testOldBespokeAssetsRemoved(): void
	{
		self::assertFileDoesNotExist($this->root . '/js/license-settings.js');
		self::assertFileDoesNotExist($this->root . '/css/license-settings.css');
	}

	public function testControllerLicenseApiContract(): void
	{
		$ctrl = $this->read('lib/Controller/LicenseController.php');
		self::assertStringContainsString('function removeSeat(', $ctrl);
		self::assertStringContainsString('function removeSeatPost(', $ctrl);
		self::assertStringContainsString('function searchUsers(', $ctrl);
		// Shared JS requires an explicit ok flag on mutations.
		self::assertStringContainsString("'ok' => true", $ctrl);
	}

	public function testRoutesStillExposeLicenseApis(): void
	{
		$routes = $this->read('appinfo/routes.php');
		self::assertStringContainsString("'name' => 'license#show'", $routes);
		self::assertStringContainsString("'name' => 'license#apply'", $routes);
		self::assertStringContainsString("'name' => 'license#remove'", $routes);
		self::assertStringContainsString("'name' => 'license#seats'", $routes);
		self::assertStringContainsString("'name' => 'license#assignSeat'", $routes);
		self::assertStringContainsString("'name' => 'license#removeSeat'", $routes);
		self::assertStringContainsString("'name' => 'license#removeSeatPost'", $routes);
		self::assertStringContainsString('/api/license', $routes);
	}
}

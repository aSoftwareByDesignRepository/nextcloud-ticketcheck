<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Controller;

use OCA\Ticketcheck\Service\SettingsSectionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Cross-artifact drift protection for the split settings sub-pages.
 */
final class SettingsPagesContractTest extends TestCase
{
	private static function appRoot(): string
	{
		return dirname(__DIR__, 3);
	}

	private static function read(string $relative): string
	{
		$path = self::appRoot() . '/' . $relative;
		self::assertFileExists($path);
		return (string) file_get_contents($path);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function routes(): array
	{
		$config = require self::appRoot() . '/appinfo/routes.php';
		self::assertIsArray($config['routes'] ?? null);
		return $config['routes'];
	}

	private static function routeByName(string $name): array
	{
		foreach (self::routes() as $route) {
			if (($route['name'] ?? '') === $name) {
				return $route;
			}
		}
		self::fail("Route '{$name}' is not registered");
	}

	public function testLegacySettingsRouteIsPreserved(): void
	{
		$route = self::routeByName('settings#index');
		self::assertSame('/settings', $route['url']);
		self::assertSame('GET', $route['verb']);
	}

	public function testSectionRouteRequirementMatchesCatalog(): void
	{
		$route = self::routeByName('settings#section');
		self::assertSame('/settings/{section}', $route['url']);
		self::assertSame('GET', $route['verb']);
		self::assertSame(
			SettingsSectionCatalog::routeRequirement(),
			$route['requirements']['section'] ?? null,
			'Route allowlist drifted from SettingsSectionCatalog::routeRequirement()',
		);
	}

	public function testDispatcherMapCoversExactlyTheRoutableCatalogInOrder(): void
	{
		$dispatcher = self::read('templates/settings.php');
		self::assertSame(
			1,
			preg_match('/\$tcSettingsSectionFiles\s*=\s*\[(.*?)\];/s', $dispatcher, $m),
			'Dispatcher must declare the literal slug → file map',
		);
		preg_match_all("/'([a-z-]+)'\s*=>\s*'([a-z.-]+)'/", $m[1], $pairs, PREG_SET_ORDER);
		$map = [];
		foreach ($pairs as $pair) {
			$map[$pair[1]] = $pair[2];
		}
		self::assertSame(SettingsSectionCatalog::routableSections(), array_keys($map), 'Dispatcher slugs drifted from the routable catalog');
		foreach (SettingsSectionCatalog::HIDDEN_SECTIONS as $hidden) {
			self::assertArrayNotHasKey($hidden, $map, "Hidden section '{$hidden}' must not be dispatched");
		}
		foreach ($map as $slug => $file) {
			self::assertSame($slug . '.php', $file, 'Dispatcher file names must mirror slugs (auditability)');
			self::assertFileExists(
				self::appRoot() . '/templates/parts/settings/' . $file,
				"Partial for section '{$slug}' is missing",
			);
		}
	}

	public function testDispatcherFailsClosedAndNeverBuildsPathsFromInput(): void
	{
		$dispatcher = self::read('templates/settings.php');
		self::assertStringContainsString(
			'if (!isset($tcSettingsSectionFiles[$tcRequestedSection]))',
			$dispatcher,
			'Unknown sections must fail closed before include',
		);
		self::assertStringContainsString(
			'TicketCheck settings: unknown section reached the template dispatcher.',
			$dispatcher,
			'Unknown sections must throw rather than soft-fall to another page',
		);
		self::assertStringNotContainsString(
			'$tcRequestedSection . ',
			$dispatcher,
			'The request value must never be concatenated into an include path',
		);
		self::assertStringNotContainsString(
			' . $tcRequestedSection',
			str_replace('$tcSettingsSectionFiles[$tcRequestedSection]', '', $dispatcher),
			'The request value must never be concatenated into an include path',
		);
	}

	public function testDispatcherHasNoSoftDenialCard(): void
	{
		$dispatcher = self::read('templates/settings.php');
		self::assertStringContainsString('hard-denied', $dispatcher);
		self::assertStringNotContainsString('Only app administrators may change these settings.', $dispatcher);
		self::assertStringNotContainsString('if (!$canAdminApp)', $dispatcher);
	}

	/**
	 * @return array<string, string>
	 */
	private static function jsAnchorSections(): array
	{
		$js = self::read('js/settings-legacy-redirect.js');
		self::assertSame(
			1,
			preg_match('/ANCHOR_SECTIONS\s*=\s*Object\.freeze\(\{(.*?)\}\)/s', $js, $m),
			'settings-legacy-redirect.js must declare a frozen ANCHOR_SECTIONS map',
		);
		preg_match_all("/'([a-z0-9-]+)'\s*:\s*'([a-z-]+)'/", $m[1], $pairs, PREG_SET_ORDER);
		$map = [];
		foreach ($pairs as $pair) {
			self::assertArrayNotHasKey($pair[1], $map, "Duplicate anchor '{$pair[1]}' in JS map");
			$map[$pair[1]] = $pair[2];
		}
		return $map;
	}

	public function testJsAnchorMapMirrorsCatalogLegacyAnchorsExactly(): void
	{
		self::assertSame(
			SettingsSectionCatalog::LEGACY_ANCHORS,
			self::jsAnchorSections(),
			'js/settings-legacy-redirect.js drifted from SettingsSectionCatalog::LEGACY_ANCHORS',
		);
	}

	public function testSettingsJsConsumesTheLegacyRedirectBeforeWiringSections(): void
	{
		$js = self::read('js/settings.js');
		$bootPos = strpos($js, "addEventListener('DOMContentLoaded'");
		self::assertNotFalse($bootPos, 'settings.js must boot on DOMContentLoaded');
		$boot = substr($js, $bootPos);
		$resolvePos = strpos($boot, 'TicketCheckSettingsLegacyRedirect');
		self::assertNotFalse($resolvePos, 'settings.js must consult the legacy redirect module at boot');
		self::assertStringContainsString('window.location.replace(redirectUrl)', $boot);
		self::assertMatchesRegularExpression(
			'/window\.location\.replace\(redirectUrl\);\s*return;/',
			$boot,
			'Boot must stop after scheduling the redirect (no wasted requests)',
		);
	}

	public function testFrontEndAssetServiceShipsTheRedirectScriptWithSettingsPages(): void
	{
		$service = self::read('lib/Service/FrontEndAssetService.php');
		self::assertMatchesRegularExpression(
			"/if \(\\\$pageScript === 'settings'\) \{\s*\/\/ Legacy[^\n]*\n\s*Util::addScript\(Application::APP_ID, 'settings-legacy-redirect'\);/s",
			$service,
			'FrontEndAssetService must load settings-legacy-redirect.js on settings pages',
		);
		$legacyPos = strpos($service, "'settings-legacy-redirect'");
		$settingsMapPos = strpos($service, "'settings' => 'settings'");
		self::assertNotFalse($legacyPos);
		self::assertNotFalse($settingsMapPos);
		// Script registration of legacy runs in the settings block; map entry is earlier —
		// assert the settings extras block mentions legacy before license extras.
		$settingsExtras = strpos($service, "if (\$pageScript === 'settings') {\n\t\t\t\$settingsSection");
		self::assertNotFalse($settingsExtras);
		self::assertLessThan($settingsExtras, $legacyPos, 'Legacy redirect must be registered before section-scoped extras');
	}

	public function testEveryLegacyAnchorTargetStillExistsInItsOwningPartial(): void
	{
		$generated = ['tc-support-us' => 'templates/parts/support-us-section.php', 'tc-support-us-title' => 'templates/parts/support-us-section.php'];
		$sharedPartialsBySection = [
			'license' => 'templates/parts/license-panel.php',
			'support' => 'templates/parts/support-us-section.php',
		];
		foreach (SettingsSectionCatalog::LEGACY_ANCHORS as $anchor => $section) {
			if (isset($generated[$anchor])) {
				self::assertStringContainsString(
					"'-support-us'",
					self::read($generated[$anchor]),
					"Anchor '{$anchor}' generator disappeared",
				);
				continue;
			}
			$haystack = self::read('templates/parts/settings/' . $section . '.php');
			if (isset($sharedPartialsBySection[$section])) {
				$haystack .= self::read($sharedPartialsBySection[$section]);
			}
			self::assertMatchesRegularExpression(
				'/\sid="' . preg_quote($anchor, '/') . '"/',
				$haystack,
				"Anchor #{$anchor} must exist on the '{$section}' sub-page so the forwarded fragment still scrolls",
			);
		}
	}

	public function testNavigationBuildsSubListFromControllerData(): void
	{
		$nav = self::read('templates/common/navigation.php');
		self::assertStringContainsString('tc-nav__sublist', $nav);
		self::assertStringContainsString('tc-nav__sublink', $nav);
		self::assertStringContainsString('$parentAriaCurrent = $active && $children === [];', $nav);
		self::assertMatchesRegularExpression(
			'/if \(\$childActive\): \?>aria-current="page"/',
			$nav,
			'Active settings sub-page must carry aria-current="page"',
		);

		$navService = self::read('lib/Service/NavigationContextService.php');
		self::assertStringContainsString('SettingsSectionCatalog::routableSections()', $navService);
		self::assertStringNotContainsString('SettingsSectionCatalog::SECTIONS', $navService, 'Sidebar/URLs must iterate routable sections, not the full registry');
		self::assertStringContainsString("settingsSections", $navService);
	}

	public function testInPageSettingsNavIsIncludedBeforeSectionDispatch(): void
	{
		$dispatcher = self::read('templates/settings.php');
		$navInclude = strpos($dispatcher, "include __DIR__ . '/parts/settings-nav.php'");
		$sectionInclude = strpos($dispatcher, "include __DIR__ . '/parts/settings/'");
		self::assertNotFalse($navInclude, 'settings.php must include the in-page chip bar');
		self::assertNotFalse($sectionInclude);
		self::assertGreaterThan($navInclude, $sectionInclude, 'Chip bar must render above the section body');

		$nav = self::read('templates/parts/settings-nav.php');
		self::assertStringContainsString('tc-settings-nav', $nav);
		self::assertStringContainsString('tc-settings-nav__link', $nav);
		self::assertStringContainsString('id="tc-settings-pages"', $nav);
		self::assertStringContainsString("\$_['settingsSectionLabels']", $nav);
		self::assertStringContainsString("\$_['urls']['settingsSections']", $nav);
		self::assertStringContainsString("if (\$href === '' || \$href === '#')", $nav, 'Chip bar must never emit href="#"');
		self::assertMatchesRegularExpression(
			'/if \(\$active\): \?>aria-current="page"/',
			$nav,
			'Active chip must carry aria-current="page"',
		);
	}

	public function testSettingsControllerFeedsNavLabelsNotPageTitlesIntoTheSidebar(): void
	{
		$controller = self::read('lib/Controller/SettingsController.php');
		self::assertStringContainsString(
			'$this->settingsSections->navLabel($l, $sectionId)',
			$controller,
			'Sidebar/chip labels must use navLabel() (short DeskCheck-style names)',
		);
		self::assertStringContainsString(
			'$this->settingsSections->label($l, $section)',
			$controller,
			'Page H1 must keep the longer label()',
		);
		self::assertStringContainsString('RedirectResponse', $controller);
		self::assertStringContainsString('canManageSettings()', $controller);
	}

	public function testPageChromeExposesTheCurrentSectionToClientScripts(): void
	{
		$pageStart = self::read('templates/common/page-start.php');
		self::assertStringContainsString('data-tc-settings-section="<?php p($settingsSection); ?>"', $pageStart);
		self::assertStringContainsString("\$_['settingsSection'] ?? ''", $pageStart);
	}

	public function testPageChromeRendersTheParentBreadcrumbForSubPages(): void
	{
		$pageStart = self::read('templates/common/page-start.php');
		self::assertStringContainsString('tc-breadcrumb__parent', $pageStart);
		self::assertStringContainsString("\$_['breadcrumbParent']", $pageStart);
	}

	public function testKbFormManageCategoriesLinksToSectionRoute(): void
	{
		$form = self::read('templates/kb/form.php');
		self::assertStringContainsString(
			"settings.section', ['section' => 'kb-categories']",
			$form,
			'Manage-categories CTA must deep-link to the kb-categories section, not /settings#…',
		);
		self::assertStringNotContainsString(
			"settings.index')); ?>#kb-categories",
			$form,
			'Legacy /settings#kb-categories deep link must not remain after multipage migration',
		);
	}

	public function testPostApiRoutesRemainUnchanged(): void
	{
		$names = array_column(self::routes(), 'name');
		foreach ([
			'settings#updateEmail',
			'settings#updateKnowledgeBase',
			'settings#updateAppAccess',
			'settings#createKBCategory',
			'settings#createEscalationRule',
		] as $name) {
			self::assertContains($name, $names, "POST API route {$name} must remain registered");
		}
	}
}

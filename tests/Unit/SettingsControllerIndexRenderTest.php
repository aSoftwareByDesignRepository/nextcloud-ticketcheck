<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\SettingsController;
use OCA\Ticketcheck\Db\EscalationRuleMapper;
use OCA\Ticketcheck\Service\CategoryService;
use OCA\Ticketcheck\Service\EmailHeaderSanitizer;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\SettingsSectionCatalog;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ARCH-01/09: settings HTML must go through PageRenderTrait::renderAppPage().
 * Multipage: index redirects; section() renders.
 */
class SettingsControllerIndexRenderTest extends TestCase
{
	private function makeController(
		PermissionService $permissionService,
		?FrontEndAssetService $frontEndAssets = null,
		?NavigationContextService $navigationContext = null,
		?CategoryService $categoryService = null,
		?IUserManager $userManager = null,
		?IGroupManager $groupManager = null,
		?IURLGenerator $urlGenerator = null,
		?IConfig $config = null,
		?\OCA\Ticketcheck\Service\LicenseService $licenseService = null,
	): SettingsController {
		$l10nFactory = $this->createMock(IFactory::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $key): string => $key);
		$l10nFactory->method('get')->willReturn($l10n);

		$localeFormat = $this->createMock(LocaleFormatService::class);
		$localeFormat->method('clientHints')->willReturn([
			'htmlLang' => 'en',
			'locale' => 'en',
			'timezone' => 'UTC',
		]);

		$nav = $navigationContext ?? $this->createMock(NavigationContextService::class);
		$nav->method('buildNavigation')->willReturn(['groups' => []]);
		$nav->method('buildCanonicalUrls')->willReturn([
			'settings' => '/apps/ticketcheck/settings',
			'settingsSections' => [
				'access' => '/apps/ticketcheck/settings/access',
			],
		]);
		$nav->method('buildScopeContext')->willReturn([
			'roleLabel' => 'scope_role_admin',
			'roleBadge' => 'admin',
			'contextLine' => '',
		]);
		$nav->method('buildDefaultHeaderActions')->willReturn('');
		$nav->method('buildSidebarFooterStats')->willReturn(['total' => 0, 'open' => 0]);

		$assets = $frontEndAssets ?? $this->createMock(FrontEndAssetService::class);
		$cats = $categoryService ?? $this->createMock(CategoryService::class);
		$users = $userManager ?? $this->createMock(IUserManager::class);
		$groups = $groupManager ?? $this->createMock(IGroupManager::class);
		$url = $urlGenerator ?? $this->createMock(IURLGenerator::class);
		$url->method('linkToRoute')->willReturnCallback(
			static function (string $route, array $params = []): string {
				if ($route === 'ticketcheck.settings.section') {
					return '/apps/ticketcheck/settings/' . ($params['section'] ?? '');
				}
				if ($route === 'ticketcheck.settings.index') {
					return '/apps/ticketcheck/settings';
				}
				return '/' . $route;
			}
		);
		$url->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route): string => 'https://example.test/' . $route
		);

		return new SettingsController(
			'ticketcheck',
			$this->createMock(IRequest::class),
			$permissionService,
			$this->createMock(EmailService::class),
			$config ?? $this->createMock(IConfig::class),
			$url,
			$cats,
			$this->createMock(EmailHeaderSanitizer::class),
			$l10nFactory,
			$this->createMock(LoggerInterface::class),
			$this->createMock(EscalationRuleMapper::class),
			$users,
			$groups,
			$localeFormat,
			$nav,
			$assets,
			$licenseService ?? $this->createMock(\OCA\Ticketcheck\Service\LicenseService::class),
			new SettingsSectionCatalog(),
			$this->createMock(IUserSession::class),
		);
	}

	public function testIndexDeniedUsesErrorRenderAppPageShell(): void
	{
		$permission = $this->createMock(PermissionService::class);
		$permission->method('canManageSettings')->willReturn(false);
		$permission->method('isGuest')->willReturn(false);
		$permission->method('isHelpdeskAdmin')->willReturn(false);
		$permission->method('isAgent')->willReturn(false);
		$permission->method('canExportData')->willReturn(false);
		$permission->method('isKnowledgeBaseIndexAvailable')->willReturn(true);
		$permission->method('isKnowledgeBasePortalContentEnabled')->willReturn(true);

		$assets = $this->createMock(FrontEndAssetService::class);
		$assets->expects(self::once())->method('registerForPage')
			->with('error', 'staff', self::isType('array'));

		$response = $this->makeController($permission, $assets)->index();

		self::assertInstanceOf(TemplateResponse::class, $response);
		self::assertSame('error', $response->getTemplateName());
		$params = $response->getParams();
		self::assertSame('error', $params['pageId'] ?? null);
		self::assertIsArray($params['navigation'] ?? null);
		self::assertArrayHasKey('clientHints', $params);
	}

	public function testIndexAllowedRedirectsToDefaultSection(): void
	{
		$permission = $this->createMock(PermissionService::class);
		$permission->method('canManageSettings')->willReturn(true);

		$assets = $this->createMock(FrontEndAssetService::class);
		$assets->expects(self::never())->method('registerForPage');

		$response = $this->makeController($permission, $assets)->index();

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame(
			'/apps/ticketcheck/settings/' . SettingsSectionCatalog::DEFAULT_SECTION,
			$response->getRedirectURL(),
		);
	}

	public function testSectionAccessRegistersSettingsAssetsAndShell(): void
	{
		$permission = $this->createMock(PermissionService::class);
		$permission->method('canManageSettings')->willReturn(true);
		$permission->method('isGuest')->willReturn(false);
		$permission->method('isHelpdeskAdmin')->willReturn(true);
		$permission->method('isAgent')->willReturn(true);
		$permission->method('canExportData')->willReturn(true);
		$permission->method('isKnowledgeBaseIndexAvailable')->willReturn(true);
		$permission->method('isKnowledgeBasePortalContentEnabled')->willReturn(true);
		$permission->method('getAppAccessSettings')->willReturn([
			'extra_groups' => [],
			'allow_helpdesk_admins' => true,
			'allow_helpdesk_agents' => true,
			'allow_helpdesk_customers' => true,
			'access_restriction_enabled' => false,
			'access_allowed_user_ids' => [],
			'app_admin_user_ids' => [],
		]);

		$cats = $this->createMock(CategoryService::class);
		$cats->expects(self::never())->method('ensureDefaultExists');

		$assets = $this->createMock(FrontEndAssetService::class);
		$assets->expects(self::once())->method('registerForPage')
			->with('settings', 'staff', self::callback(static function (array $params): bool {
				return ($params['pageId'] ?? null) === 'settings'
					&& ($params['settingsSection'] ?? null) === 'access'
					&& isset($params['appAccessSettings'])
					&& !isset($params['emailSettings'])
					&& !isset($params['licenseStatus']);
			}));

		$response = $this->makeController($permission, $assets, null, $cats)->section('access');

		self::assertInstanceOf(TemplateResponse::class, $response);
		self::assertSame('settings', $response->getTemplateName());
		$params = $response->getParams();
		self::assertSame('settings', $params['pageId'] ?? null);
		self::assertSame('access', $params['settingsSection'] ?? null);
		self::assertSame('Access control', $params['pageTitle'] ?? null);
		self::assertIsArray($params['settingsSectionLabels'] ?? null);
		self::assertTrue((bool)($params['isAdmin'] ?? false));
	}

	public function testSectionKbCategoriesEnsuresDefaultAndSkipsLicenseData(): void
	{
		$permission = $this->createMock(PermissionService::class);
		$permission->method('canManageSettings')->willReturn(true);
		$permission->method('isGuest')->willReturn(false);
		$permission->method('isHelpdeskAdmin')->willReturn(true);
		$permission->method('isAgent')->willReturn(false);
		$permission->method('canExportData')->willReturn(false);
		$permission->method('isKnowledgeBaseIndexAvailable')->willReturn(true);
		$permission->method('isKnowledgeBasePortalContentEnabled')->willReturn(true);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('yes');

		$cats = $this->createMock(CategoryService::class);
		$cats->expects(self::once())->method('ensureDefaultExists');
		$cats->method('list')->willReturn([]);

		$license = $this->createMock(\OCA\Ticketcheck\Service\LicenseService::class);
		$license->expects(self::never())->method('status');
		$license->expects(self::never())->method('listSeats');

		$assets = $this->createMock(FrontEndAssetService::class);
		$assets->expects(self::once())->method('registerForPage');

		$response = $this->makeController(
			$permission,
			$assets,
			null,
			$cats,
			null,
			null,
			null,
			$config,
			$license,
		)->section('kb-categories');

		self::assertInstanceOf(TemplateResponse::class, $response);
		$params = $response->getParams();
		self::assertSame('kb-categories', $params['settingsSection'] ?? null);
		self::assertArrayHasKey('kbCategories', $params);
		self::assertArrayNotHasKey('licenseStatus', $params);
	}

	public function testSectionUnknownReturnsNotFound(): void
	{
		$permission = $this->createMock(PermissionService::class);
		$permission->method('canManageSettings')->willReturn(true);

		$response = $this->makeController($permission)->section('../access');
		self::assertSame(404, $response->getStatus());
	}
}

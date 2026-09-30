<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Portfolio access door: Open/Restricted + allowed users + dedicated app admins;
 * never ask for raw UIDs in settings UI.
 */
final class AccessDoorContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	public function testSettingsExposeModeUsersAndAppAdminsPickers(): void
	{
		// Multipage settings: access door UI lives in the section partial, not the shell dispatcher.
		$shell = (string) file_get_contents($this->root . '/templates/settings.php');
		$this->assertStringContainsString("'access' => 'access.php'", $shell);
		$html = (string) file_get_contents($this->root . '/templates/parts/settings/access.php');
		$this->assertStringContainsString('id="app-access-mode"', $html);
		$this->assertStringContainsString('app-access-allowed-users-search', $html);
		$this->assertStringContainsString('app-access-app-admins-search', $html);
		$this->assertStringContainsString('role="combobox"', $html);
		$this->assertStringContainsString('app-access-allowed-users-help', $html);
		$this->assertStringContainsString('app_access_allowed_users_help', $html);
		$this->assertStringContainsString('app_access_app_admins_help', $html);
		$this->assertStringContainsString('app-access-users-picker', (string) file_get_contents($this->root . '/lib/Service/FrontEndAssetService.php'));
	}

	public function testPermissionServiceUsesOrAppAdminAndDirectoryDoor(): void
	{
		$src = (string) file_get_contents($this->root . '/lib/Service/PermissionService.php');
		$this->assertStringContainsString('function isAppAdmin', $src);
		$this->assertStringContainsString('access_restriction_enabled', $src);
		$this->assertStringContainsString('access_allowed_user_ids', $src);
		$this->assertStringContainsString('app_admin_user_ids', $src);
		$this->assertStringContainsString('function hasEnabledHelpdeskScope', $src);
		$this->assertStringContainsString('function needsRoleEnrollment', $src);
		$this->assertStringContainsString('door ≠ role', $src);
		$this->assertStringContainsString('passesDirectoryDoor', $src);
	}

	public function testSettingsControllerPersistsDoorFieldsAndUserSearch(): void
	{
		$src = (string) file_get_contents($this->root . '/lib/Controller/SettingsController.php');
		$this->assertStringContainsString('function searchNextcloudUsers', $src);
		$this->assertStringContainsString('access_restriction_enabled', $src);
		$this->assertStringContainsString('app_access_admin_self_lockout', $src);
		$routes = (string) file_get_contents($this->root . '/appinfo/routes.php');
		$this->assertStringContainsString('settings#searchNextcloudUsers', $routes);
	}
}

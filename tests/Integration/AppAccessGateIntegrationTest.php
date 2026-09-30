<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Integration;

use OCA\Ticketcheck\AppInfo\Application;
use OCA\Ticketcheck\Controller\DashboardController;
use OCA\Ticketcheck\Exception\AppAccessDeniedException;
use OCA\Ticketcheck\Middleware\AppAccessMiddleware;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Test\TestCase;

/** Helpdesk group policy gate against live app config. */
final class AppAccessGateIntegrationTest extends TestCase
{
	private const ALLOWED = 'tc_gate_allowed';
	private const DENIED = 'tc_gate_denied';
	private const PASSWORD = 'tc-test-pass-9xK!';

	private ?string $prevAdminsAccess = null;
	private ?string $prevAgentsAccess = null;
	private ?string $prevCustomersAccess = null;
	private ?string $prevExtraGroups = null;
	private ?string $prevRestriction = null;
	private ?string $prevAllowedUsers = null;

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped (run inside Docker with NEXTCLOUD_ROOT).');
		}
		if (class_exists(\OCP\Util::class) && \OCP\Util::needUpgrade()) {
			$this->markTestSkipped('Nextcloud requires occ upgrade before access-gate integration tests (incognito mode hides session users).');
		}
		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$this->prevAdminsAccess = $config->getAppValue(Application::APP_ID, 'app_access_helpdesk_admins', 'yes');
		$this->prevAgentsAccess = $config->getAppValue(Application::APP_ID, 'app_access_helpdesk_agents', 'yes');
		$this->prevCustomersAccess = $config->getAppValue(Application::APP_ID, 'app_access_helpdesk_customers', 'yes');
		$this->prevExtraGroups = $config->getAppValue(Application::APP_ID, 'app_access_extra_groups', '');
		$this->prevRestriction = $config->getAppValue(Application::APP_ID, 'access_restriction_enabled', '0');
		$this->prevAllowedUsers = $config->getAppValue(Application::APP_ID, 'access_allowed_user_ids', '[]');

		/** @var IUserManager $userManager */
		$userManager = \OC::$server->get(IUserManager::class);
		foreach ([self::ALLOWED, self::DENIED] as $uid) {
			if (!$userManager->userExists($uid)) {
				continue;
			}
			try {
				$userManager->get($uid)?->delete();
			} catch (\Throwable $e) {
				fwrite(STDERR, '[AppAccessGateIntegrationTest] setUp delete failed for ' . $uid . ': ' . $e->getMessage() . "\n");
			}
		}
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}
		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		if ($this->prevAdminsAccess !== null) {
			$config->setAppValue(Application::APP_ID, 'app_access_helpdesk_admins', $this->prevAdminsAccess);
		}
		if ($this->prevAgentsAccess !== null) {
			$config->setAppValue(Application::APP_ID, 'app_access_helpdesk_agents', $this->prevAgentsAccess);
		}
		if ($this->prevCustomersAccess !== null) {
			$config->setAppValue(Application::APP_ID, 'app_access_helpdesk_customers', $this->prevCustomersAccess);
		}
		if ($this->prevExtraGroups !== null) {
			$config->setAppValue(Application::APP_ID, 'app_access_extra_groups', $this->prevExtraGroups);
		}
		if ($this->prevRestriction !== null) {
			$config->setAppValue(Application::APP_ID, 'access_restriction_enabled', $this->prevRestriction);
		}
		if ($this->prevAllowedUsers !== null) {
			$config->setAppValue(Application::APP_ID, 'access_allowed_user_ids', $this->prevAllowedUsers);
		}
		/** @var IUserManager $userManager */
		$userManager = \OC::$server->get(IUserManager::class);
		foreach ([self::ALLOWED, self::DENIED] as $uid) {
			if (!$userManager->userExists($uid)) {
				continue;
			}
			try {
				$userManager->get($uid)?->delete();
			} catch (\Throwable $e) {
				// Sibling apps (e.g. ProjectCheck) may throw from UserDeletedListener DI;
				// never leave the gate suite red for unrelated container wiring.
				fwrite(STDERR, '[AppAccessGateIntegrationTest] cleanup delete failed for ' . $uid . ': ' . $e->getMessage() . "\n");
			}
		}
		$this->clearTestSession();
	}

	public function testUserWithoutHelpdeskRoleBlockedByMiddleware(): void
	{
		/** @var IUserManager $userManager */
		$userManager = \OC::$server->get(IUserManager::class);
		$userManager->createUser(self::DENIED, self::PASSWORD);

		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		// Restricted empty allow-list fails closed for role-less staff (door gate).
		$config->setAppValue(Application::APP_ID, 'access_restriction_enabled', '1');
		$config->setAppValue(Application::APP_ID, 'access_allowed_user_ids', '[]');
		$config->setAppValue(Application::APP_ID, 'app_access_helpdesk_admins', 'no');
		$config->setAppValue(Application::APP_ID, 'app_access_helpdesk_agents', 'no');
		$config->setAppValue(Application::APP_ID, 'app_access_helpdesk_customers', 'no');
		$config->setAppValue(Application::APP_ID, 'app_access_extra_groups', '');

		$this->loginAsTestUser(self::DENIED);

		/** @var DashboardController $controller */
		$controller = \OC::$server->get(DashboardController::class);
		$middleware = $this->middlewareWithMockRequest();

		try {
			$middleware->beforeController($controller, 'index');
			$this->fail('Expected AppAccessDeniedException for gated user');
		} catch (AppAccessDeniedException) {
			$this->addToAssertionCount(1);
		}

		// Terminal deny UX is owned by GuestSecurityMiddleware — AppAccess rethrows.
		$this->expectException(AppAccessDeniedException::class);
		$middleware->afterException($controller, 'index', new AppAccessDeniedException());
	}

	public function testOpenModeAllowsRoleLessUserThroughMiddlewareDoor(): void
	{
		/** @var IUserManager $userManager */
		$userManager = \OC::$server->get(IUserManager::class);
		$userManager->createUser(self::DENIED, self::PASSWORD);

		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$config->setAppValue(Application::APP_ID, 'access_restriction_enabled', '0');
		$config->setAppValue(Application::APP_ID, 'access_allowed_user_ids', '[]');
		$config->setAppValue(Application::APP_ID, 'app_access_helpdesk_admins', 'yes');
		$config->setAppValue(Application::APP_ID, 'app_access_helpdesk_agents', 'yes');
		$config->setAppValue(Application::APP_ID, 'app_access_helpdesk_customers', 'yes');
		$config->setAppValue(Application::APP_ID, 'app_access_extra_groups', '');

		$this->loginAsTestUser(self::DENIED);

		/** @var PermissionService $permissions */
		$permissions = \OC::$server->get(PermissionService::class);
		self::assertTrue($permissions->canAccessApp(), 'Open mode must open the door without a helpdesk role');
		self::assertTrue($permissions->needsRoleEnrollment(), 'Role-less Open users need enrollment UX');

		/** @var DashboardController $controller */
		$controller = \OC::$server->get(DashboardController::class);
		$this->middlewareWithMockRequest()->beforeController($controller, 'index');
		$this->addToAssertionCount(1);
	}

	public function testHelpdeskAgentPassesGate(): void
	{
		/** @var IUserManager $userManager */
		$userManager = \OC::$server->get(IUserManager::class);
		$userManager->createUser(self::ALLOWED, self::PASSWORD);

		/** @var IGroupManager $groupManager */
		$groupManager = \OC::$server->get(IGroupManager::class);
		if (!$groupManager->groupExists(PermissionService::GROUP_HELPDESK_AGENTS)) {
			$groupManager->createGroup(PermissionService::GROUP_HELPDESK_AGENTS);
		}
		$groupManager->get(PermissionService::GROUP_HELPDESK_AGENTS)?->addUser($userManager->get(self::ALLOWED));

		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$config->setAppValue(Application::APP_ID, 'app_access_helpdesk_admins', 'no');
		$config->setAppValue(Application::APP_ID, 'app_access_helpdesk_agents', 'yes');
		$config->setAppValue(Application::APP_ID, 'app_access_helpdesk_customers', 'no');

		$this->loginAsTestUser(self::ALLOWED);

		/** @var DashboardController $controller */
		$controller = \OC::$server->get(DashboardController::class);
		$this->middlewareWithMockRequest()->beforeController($controller, 'index');
		$this->addToAssertionCount(1);
	}

	/**
	 * CLI boots Nextcloud in incognito mode (e.g. during upgrade). Incognito makes
	 * IUserSession::getUser() always null, which would false-pass the agent gate
	 * and false-fail the deny gate. Disable it for the simulated login.
	 */
	private function loginAsTestUser(string $uid): void
	{
		/** @var IUserManager $userManager */
		$userManager = \OC::$server->get(IUserManager::class);
		$user = $userManager->get($uid);
		self::assertNotNull($user, 'Test user must exist: ' . $uid);
		\OC_User::setIncognitoMode(false);
		/** @var IUserSession $session */
		$session = \OC::$server->get(IUserSession::class);
		$session->setUser($user);
		self::assertSame($uid, $session->getUser()?->getUID());
	}

	private function clearTestSession(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}
		/** @var IUserSession $session */
		$session = \OC::$server->get(IUserSession::class);
		$session->setUser(null);
		\OC_User::setIncognitoMode(true);
	}

	private function middlewareWithMockRequest(): AppAccessMiddleware
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/');
		$request->method('getMethod')->willReturn('GET');
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => match (strtolower($name)) {
				'accept' => 'application/json',
				default => '',
			},
		);

		return new AppAccessMiddleware(
			\OC::$server->get(IUserSession::class),
			\OC::$server->get(PermissionService::class),
			$request,
			\OC::$server->get(\OCP\IURLGenerator::class),
			\OC::$server->get(\OCP\L10N\IFactory::class),
			\OC::$server->get(\Psr\Log\LoggerInterface::class),
		);
	}
}

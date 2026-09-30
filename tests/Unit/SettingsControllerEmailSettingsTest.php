<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\SettingsController;
use OCA\Ticketcheck\Db\EscalationRuleMapper;
use OCA\Ticketcheck\Service\CategoryService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\SettingsSectionCatalog;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\EmailHeaderSanitizer;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SettingsControllerEmailSettingsTest extends TestCase
{
    private IRequest $request;
    private PermissionService $permissionService;
    private EmailService $emailService;
    private IConfig $config;
    private IURLGenerator $urlGenerator;
    private CategoryService $categoryService;
    private EmailHeaderSanitizer $emailHeaderSanitizer;
    private IFactory $l10nFactory;
    private LoggerInterface $logger;
    private EscalationRuleMapper $escalationRuleMapper;
    private IUserManager $userManager;
    private IGroupManager $groupManager;

    private SettingsController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->emailService = $this->createMock(EmailService::class);
        $this->config = $this->createMock(IConfig::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->categoryService = $this->createMock(CategoryService::class);
        $this->emailHeaderSanitizer = $this->createMock(EmailHeaderSanitizer::class);
        $this->l10nFactory = $this->createMock(IFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->escalationRuleMapper = $this->createMock(EscalationRuleMapper::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $key): string => $key);
        $this->l10nFactory->method('get')->with('ticketcheck')->willReturn($l10n);

        $this->permissionService->method('canManageSettings')->willReturn(true);
        $this->emailHeaderSanitizer->method('sanitizeWithDefault')->willReturn('Helpdesk');
        $this->config->method('getAppValue')->willReturn('yes');

        $this->controller = new SettingsController(
            'ticketcheck',
            $this->request,
            $this->permissionService,
            $this->emailService,
            $this->config,
            $this->urlGenerator,
            $this->categoryService,
            $this->emailHeaderSanitizer,
            $this->l10nFactory,
            $this->logger,
            $this->escalationRuleMapper,
            $this->userManager,
            $this->groupManager,
            $this->createMock(LocaleFormatService::class),
            $this->createMock(NavigationContextService::class),
            $this->createMock(FrontEndAssetService::class),
            $this->createMock(\OCA\Ticketcheck\Service\LicenseService::class),
			new SettingsSectionCatalog(),
			$this->createMock(IUserSession::class),
		);
    }

    public function testUpdateEmailRejectsInvalidInboundAddress(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['from_name', null, 'Helpdesk'],
            ['enabled', 'yes', 'yes'],
            ['daily_digest_enabled', 'yes', 'yes'],
            ['weekly_digest_enabled', 'yes', 'yes'],
            ['inbound_email_enabled', 'no', 'yes'],
            ['inbound_email_address', null, 'not-an-email'],
        ]);
        $this->config->expects(self::never())->method('setAppValue');

        $response = $this->controller->updateEmail();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(422, $response->getStatus());
        self::assertSame(['error' => 'invalid_email_address'], $response->getData());
    }

    public function testUpdateEmailRequiresInboundAddressWhenInboundEnabled(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['from_name', null, 'Helpdesk'],
            ['enabled', 'yes', 'yes'],
            ['daily_digest_enabled', 'yes', 'yes'],
            ['weekly_digest_enabled', 'yes', 'yes'],
            ['inbound_email_enabled', 'no', 'yes'],
            ['inbound_email_address', null, ''],
        ]);
        $this->config->expects(self::never())->method('setAppValue');

        $response = $this->controller->updateEmail();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(422, $response->getStatus());
        self::assertSame(['error' => 'inbound_email_address_required_when_enabled'], $response->getData());
    }

    public function testUpdateEmailForcesDependentFlagsOffWhenGlobalDisabled(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['from_name', null, 'Helpdesk'],
            ['enabled', 'yes', 'no'],
            ['daily_digest_enabled', 'yes', 'yes'],
            ['weekly_digest_enabled', 'yes', 'yes'],
            ['inbound_email_enabled', 'no', 'yes'],
            ['inbound_email_address', null, 'support@example.com'],
        ]);

        $captured = [];
        $this->config->method('setAppValue')->willReturnCallback(
            static function (string $app, string $key, string $value) use (&$captured): void {
                $captured[$key] = $value;
            }
        );
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = '') use (&$captured): string {
                return $captured[$key] ?? $default;
            }
        );

        $response = $this->controller->updateEmail();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(200, $response->getStatus());
        self::assertSame('no', $captured['email_notifications_enabled'] ?? null);
        self::assertSame('no', $captured['daily_digest_enabled'] ?? null);
        self::assertSame('no', $captured['weekly_digest_enabled'] ?? null);
        self::assertSame('no', $captured['inbound_email_enabled'] ?? null);
        self::assertSame('support@example.com', $captured['inbound_email_address'] ?? null);
    }

    public function testUpdateAppAccessRejectsUnknownGroups(): void
    {
        $this->userManager->method('search')->willReturn([]);
        $this->request->method('getParam')->willReturnMap([
            ['allow_helpdesk_admins', 'yes', 'yes'],
            ['allow_helpdesk_agents', 'yes', 'yes'],
            ['allow_helpdesk_customers', 'yes', 'yes'],
            ['extra_groups', '', 'missing_group'],
        ]);
        $this->groupManager->method('get')->with('missing_group')->willReturn(null);
        $this->config->expects(self::never())->method('setAppValue');

        $response = $this->controller->updateAppAccess();
        self::assertSame(422, $response->getStatus());
        self::assertSame(['error' => 'app_access_unknown_groups'], $response->getData());
    }

    public function testUpdateAppAccessPersistsValues(): void
    {
        $this->userManager->method('search')->willReturn([]);
        $this->request->method('getParam')->willReturnMap([
            ['allow_helpdesk_admins', 'yes', 'no'],
            ['allow_helpdesk_agents', 'yes', 'yes'],
            ['allow_helpdesk_customers', 'yes', 'no'],
            ['extra_groups', '', 'qa_team, qa_team,ops'],
        ]);
        $group = $this->createMock(IGroup::class);
        $this->groupManager->method('get')->willReturnMap([
            ['qa_team', $group],
            ['ops', $group],
        ]);

        $saved = [];
        $this->config->method('setAppValue')->willReturnCallback(static function (string $app, string $key, string $value) use (&$saved): void {
            $saved[$key] = $value;
        });

        $response = $this->controller->updateAppAccess();
        self::assertSame(200, $response->getStatus());
        self::assertSame('no', $saved['app_access_helpdesk_admins'] ?? null);
        self::assertSame('yes', $saved['app_access_helpdesk_agents'] ?? null);
        self::assertSame('no', $saved['app_access_helpdesk_customers'] ?? null);
        self::assertSame('qa_team,ops', $saved['app_access_extra_groups'] ?? null);
    }

    public function testUpdateAppAccessRequiresAcknowledgementWhenItWouldBlockCurrentNonAdmins(): void
    {
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('agent1');
        $user->method('getDisplayName')->willReturn('Agent One');

        $this->userManager->method('search')->willReturn([$user]);
        $this->permissionService->method('getCurrentUserId')->willReturn('admin1');
        $this->permissionService->method('getAppAccessSettings')->willReturn([
            'allow_helpdesk_admins' => true,
            'allow_helpdesk_agents' => true,
            'allow_helpdesk_customers' => true,
            'extra_groups' => [],
        ]);
        $callIndex = 0;
        $this->permissionService->method('canUserAccessAppWithSettings')->willReturnCallback(
            static function (string $uid, array $settings) use (&$callIndex): bool {
                if ($uid !== 'agent1') {
                    return false;
                }
                $callIndex++;
                // calculatePolicyImpact checks current first, then proposed.
                return $callIndex % 2 === 1;
            }
        );
        $this->groupManager->method('isAdmin')->with('agent1')->willReturn(false);

        $this->request->method('getParam')->willReturnMap([
            ['allow_helpdesk_admins', 'yes', 'yes'],
            ['allow_helpdesk_agents', 'yes', 'no'],
            ['allow_helpdesk_customers', 'yes', 'yes'],
            ['extra_groups', '', ''],
            ['acknowledge_non_admin_lockout_risk', 'no', 'no'],
        ]);

        $response = $this->controller->updateAppAccess();
        self::assertSame(409, $response->getStatus());
    }

    public function testUpdateAppAccessPersistsAfterAcknowledgement(): void
    {
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('agent1');
        $user->method('getDisplayName')->willReturn('Agent One');

        $this->userManager->method('search')->willReturn([$user]);
        $this->permissionService->method('getCurrentUserId')->willReturn('admin1');
        $this->permissionService->method('getAppAccessSettings')->willReturn([
            'allow_helpdesk_admins' => true,
            'allow_helpdesk_agents' => true,
            'allow_helpdesk_customers' => true,
            'extra_groups' => [],
        ]);
        $callIndex = 0;
        $this->permissionService->method('canUserAccessAppWithSettings')->willReturnCallback(
            static function (string $uid, array $settings) use (&$callIndex): bool {
                if ($uid !== 'agent1') {
                    return false;
                }
                $callIndex++;
                // calculatePolicyImpact checks current first, then proposed.
                return $callIndex % 2 === 1;
            }
        );
        $this->groupManager->method('isAdmin')->with('agent1')->willReturn(false);

        $this->request->method('getParam')->willReturnMap([
            ['allow_helpdesk_admins', 'yes', 'yes'],
            ['allow_helpdesk_agents', 'yes', 'no'],
            ['allow_helpdesk_customers', 'yes', 'yes'],
            ['extra_groups', '', ''],
            ['acknowledge_non_admin_lockout_risk', 'no', 'yes'],
        ]);

        $saved = [];
        $this->config->method('setAppValue')->willReturnCallback(static function (string $app, string $key, string $value) use (&$saved): void {
            $saved[$key] = $value;
        });

        $response = $this->controller->updateAppAccess();
        self::assertSame(200, $response->getStatus());
        self::assertSame('no', $saved['app_access_helpdesk_agents'] ?? null);
    }
}


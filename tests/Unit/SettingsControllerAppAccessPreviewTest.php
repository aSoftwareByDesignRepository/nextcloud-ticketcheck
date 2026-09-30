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
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SettingsControllerAppAccessPreviewTest extends TestCase
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
        $l10n->method('t')->willReturnCallback(static function (string $text, $parameters = []): string {
            // Mirror production: translations use {name} placeholders substituted via strtr().
            return match ($text) {
                'app_access_preview_summary' => 'SUM:{total}/{allowed}/{blocked}',
                'app_access_preview_live_announce' => 'ANN:{total}/{allowed}/{blocked}',
                'app_access_preview_user_sample_capped' => 'CAP:{limit}',
                'app_access_preview_names_limited_notice' => 'LISTCAP:{limit}',
                default => $text,
            };
        });
        $this->l10nFactory->method('get')->with('ticketcheck')->willReturn($l10n);

        $this->permissionService->method('canManageSettings')->willReturn(true);
        $this->permissionService->method('getAppAccessSettings')->willReturn([
            'allow_helpdesk_admins' => true,
            'allow_helpdesk_agents' => true,
            'allow_helpdesk_customers' => true,
            'extra_groups' => [],
        ]);
        $this->emailHeaderSanitizer->method('sanitizeWithDefault')->willReturn('Helpdesk');
        $this->config->method('getAppValue')->willReturn('yes');

        $this->controller = $this->newSettingsController($this->permissionService);
    }

    private function newSettingsController(PermissionService $permissionService): SettingsController
    {
        return new SettingsController(
            'ticketcheck',
            $this->request,
            $permissionService,
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

    public function testGetAppAccessPreviewForbidsWhenCannotManageSettings(): void
    {
        $denyPermission = $this->createMock(PermissionService::class);
        $denyPermission->method('canManageSettings')->willReturn(false);

        $this->userManager->expects(self::never())->method('search');

        $response = $this->newSettingsController($denyPermission)->getAppAccessPreview();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(403, $response->getStatus());
        self::assertArrayHasKey('error', $response->getData());
    }

    public function testGetAppAccessPreviewReturnsStringsPreviewLimitsAndSummary(): void
    {
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $alice->method('getDisplayName')->willReturn('Alice A');
        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $bob->method('getDisplayName')->willReturn('Bob B');

        $this->userManager->method('search')->with('', 1000)->willReturn([$alice, $bob]);
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->permissionService->method('canUserAccessAppWithSettings')->willReturnCallback(
            static function (string $uid, array $settings): bool {
                return $uid === 'alice';
            }
        );

        $response = $this->controller->getAppAccessPreview();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(200, $response->getStatus());
        $data = $response->getData();
        self::assertTrue($data['success']);
        self::assertSame(2, $data['summary']['total_users']);
        self::assertSame(1, $data['summary']['allowed_users']);
        self::assertSame(1, $data['summary']['blocked_users']);

        self::assertArrayHasKey('preview_limits', $data);
        self::assertSame(1000, $data['preview_limits']['user_search_limit']);
        self::assertSame(200, $data['preview_limits']['display_list_limit']);
        self::assertFalse($data['preview_limits']['user_sample_reached_cap']);

        self::assertArrayHasKey('strings', $data);
        self::assertSame('SUM:2/1/1', $data['strings']['summary_line']);
        self::assertSame('ANN:2/1/1', $data['strings']['live_announce']);
        self::assertSame('none', $data['strings']['none']);
        self::assertSame('list_truncated', $data['strings']['list_truncated']);
        self::assertSame('', $data['strings']['user_sample_warning']);
        self::assertSame('', $data['strings']['names_list_cap_notice']);

        self::assertCount(1, $data['allowed_users']);
        self::assertSame('alice', $data['allowed_users'][0]['uid']);
        self::assertFalse($data['truncated']['allowed']);
        self::assertFalse($data['truncated']['blocked']);
    }

    public function testGetAppAccessPreviewSetsUserSampleReachedCapWhenSearchReturnsLimit(): void
    {
        $users = [];
        for ($i = 0; $i < 1000; $i++) {
            $u = $this->createMock(IUser::class);
            $u->method('getUID')->willReturn('u' . $i);
            $u->method('getDisplayName')->willReturn('User ' . $i);
            $users[] = $u;
        }

        $this->userManager->method('search')->with('', 1000)->willReturn($users);
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->permissionService->method('canUserAccessAppWithSettings')->willReturn(true);

        $response = $this->controller->getAppAccessPreview();
        $data = $response->getData();

        self::assertTrue($data['preview_limits']['user_sample_reached_cap']);
        self::assertSame('CAP:1000', $data['strings']['user_sample_warning']);
        self::assertSame(1000, $data['summary']['total_users']);
    }

    public function testGetAppAccessPreviewTruncatesNameListsPerDisplayLimit(): void
    {
        $users = [];
        for ($i = 0; $i < 250; $i++) {
            $u = $this->createMock(IUser::class);
            $u->method('getUID')->willReturn('u' . $i);
            $u->method('getDisplayName')->willReturn('User ' . $i);
            $users[] = $u;
        }

        $this->userManager->method('search')->with('', 1000)->willReturn($users);
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->permissionService->method('canUserAccessAppWithSettings')->willReturn(true);

        $response = $this->controller->getAppAccessPreview();
        $data = $response->getData();

        self::assertTrue($data['truncated']['allowed']);
        self::assertFalse($data['truncated']['blocked']);
        self::assertCount(200, $data['allowed_users']);
        self::assertCount(0, $data['blocked_users']);
        self::assertSame('LISTCAP:200', $data['strings']['names_list_cap_notice']);
    }
}

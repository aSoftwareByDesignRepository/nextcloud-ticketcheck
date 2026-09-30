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
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SettingsControllerKnowledgeBaseGuardTest extends TestCase
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
        $this->config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = ''): string => $key === 'kb_enabled' ? 'no' : $default
        );

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

    public function testListKBCategoriesReturnsForbiddenWhenKnowledgeBaseDisabled(): void
    {
        $this->categoryService->expects(self::never())->method('list');

        $response = $this->controller->listKBCategories();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(403, $response->getStatus());
        self::assertSame(['error' => 'knowledge_base_disabled_message'], $response->getData());
    }

    public function testCreateKBCategoryReturnsForbiddenWhenKnowledgeBaseDisabled(): void
    {
        $this->categoryService->expects(self::never())->method('create');

        $response = $this->controller->createKBCategory();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(403, $response->getStatus());
        self::assertSame(['error' => 'knowledge_base_disabled_message'], $response->getData());
    }

    public function testUpdateKBCategoryReturnsForbiddenWhenKnowledgeBaseDisabled(): void
    {
        $this->categoryService->expects(self::never())->method('update');

        $response = $this->controller->updateKBCategory(11);

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(403, $response->getStatus());
        self::assertSame(['error' => 'knowledge_base_disabled_message'], $response->getData());
    }

    public function testDeleteKBCategoryReturnsForbiddenWhenKnowledgeBaseDisabled(): void
    {
        $this->categoryService->expects(self::never())->method('list');
        $this->categoryService->expects(self::never())->method('delete');

        $response = $this->controller->deleteKBCategory(11);

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(403, $response->getStatus());
        self::assertSame(['error' => 'knowledge_base_disabled_message'], $response->getData());
    }
}


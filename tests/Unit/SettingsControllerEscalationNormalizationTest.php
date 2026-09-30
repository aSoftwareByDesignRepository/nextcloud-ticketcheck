<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\SettingsController;
use OCA\Ticketcheck\Db\EscalationRule;
use OCA\Ticketcheck\Db\EscalationRuleMapper;
use OCA\Ticketcheck\Service\CategoryService;
use OCA\Ticketcheck\Service\EmailHeaderSanitizer;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\SettingsSectionCatalog;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\AppFramework\Http\JSONResponse;
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

class SettingsControllerEscalationNormalizationTest extends TestCase
{
    public function testCreateEscalationRuleNormalizesLegacyStatusesAndInvalidPriority(): void
    {
        $request = $this->createMock(IRequest::class);
        $permissionService = $this->createMock(PermissionService::class);
        $emailService = $this->createMock(EmailService::class);
        $config = $this->createMock(IConfig::class);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $categoryService = $this->createMock(CategoryService::class);
        $emailHeaderSanitizer = $this->createMock(EmailHeaderSanitizer::class);
        $l10nFactory = $this->createMock(IFactory::class);
        $logger = $this->createMock(LoggerInterface::class);
        $escalationRuleMapper = $this->createMock(EscalationRuleMapper::class);
        $userManager = $this->createMock(IUserManager::class);
        $groupManager = $this->createMock(IGroupManager::class);

        $permissionService->method('canManageSettings')->willReturn(true);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $key): string => $key);
        $l10nFactory->method('get')->with('ticketcheck')->willReturn($l10n);

        $request->method('getParam')->willReturnMap([
            ['name', '', 'Escalate stale tickets'],
            ['conditions', [], [
                'age_hours' => 24,
                'min_priority' => 'not-a-priority',
                'statuses' => ['working', 'waiting_customer', 'resolved', 'unknown'],
            ]],
            ['action', [], [
                'set_priority' => null,
                'assign_to' => null,
            ]],
            ['is_active', null, null],
        ]);

        $escalationRuleMapper->expects(self::once())
            ->method('insertRule')
            ->with(self::callback(static function (EscalationRule $rule): bool {
                $conditions = $rule->getConditionsDecoded();
                return $conditions['age_hours'] === 24
                    && $conditions['min_priority'] === null
                    && $conditions['statuses'] === ['in_progress', 'waiting', 'done'];
            }))
            ->willReturnCallback(static fn (EscalationRule $rule): EscalationRule => $rule);

        $controller = new SettingsController(
            'ticketcheck',
            $request,
            $permissionService,
            $emailService,
            $config,
            $urlGenerator,
            $categoryService,
            $emailHeaderSanitizer,
            $l10nFactory,
            $logger,
            $escalationRuleMapper,
            $userManager,
            $groupManager,
            $this->createMock(LocaleFormatService::class),
            $this->createMock(NavigationContextService::class),
            $this->createMock(FrontEndAssetService::class),
            $this->createMock(\OCA\Ticketcheck\Service\LicenseService::class),
			new SettingsSectionCatalog(),
			$this->createMock(IUserSession::class),
		);

        $response = $controller->createEscalationRule();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(200, $response->getStatus());
        self::assertTrue((bool)($response->getData()['success'] ?? false));
    }
}


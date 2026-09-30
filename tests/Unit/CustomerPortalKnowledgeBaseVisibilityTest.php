<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\CustomerPortalController;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\KBArticleMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\GuestLayoutParamsProvider;
use OCA\Ticketcheck\Service\GuestPortalPageService;
use OCA\Ticketcheck\Service\HtmlSanitizerService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCA\Ticketcheck\Service\SurveyService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCA\Theming\Service\ThemesService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies that the controller forwards the KB visibility flags
 * (`kbEnabled`, `kbPortalEnabled`, `showKnowledgeBase`) into the rendered
 * guest portal templates through the single render path provided by
 * {@see GuestPortalPageService}. We use a callback-driven stub so the test
 * does not need the OC server bootstrap.
 */
class CustomerPortalKnowledgeBaseVisibilityTest extends TestCase
{
    public function testIndexRendersKbDisabledFlagsWhenPortalKbDisabled(): void
    {
        $captured = [];
        $controller = $this->buildController(false, false, $captured);

        $response = $controller->index();
        $this->assertInstanceOf(TemplateResponse::class, $response);

        $this->assertNotEmpty($captured, 'GuestPortalPageService::render must be invoked.');
        $params = $captured[0]['params'];
        $this->assertSame('portal/index', $captured[0]['template']);
        $this->assertSame(false, $params['kbEnabled']);
        $this->assertSame(false, $params['kbPortalEnabled']);
        $this->assertSame(false, $params['showKnowledgeBase']);
    }

    public function testIndexRendersKbEnabledFlagsWhenPortalKbEnabled(): void
    {
        $captured = [];
        $controller = $this->buildController(true, true, $captured);

        $response = $controller->index();
        $this->assertInstanceOf(TemplateResponse::class, $response);

        $this->assertNotEmpty($captured, 'GuestPortalPageService::render must be invoked.');
        $params = $captured[0]['params'];
        $this->assertSame(true, $params['kbEnabled']);
        $this->assertSame(true, $params['kbPortalEnabled']);
        $this->assertSame(true, $params['showKnowledgeBase']);
    }

    /**
     * @param array<int, array{template: string, pageId: string, params: array<string, mixed>}> $captured
     */
    private function buildController(bool $kbEnabled, bool $kbPortalEnabled, array &$captured): CustomerPortalController
    {
        $request = $this->createMock(IRequest::class);
        $ticketService = $this->createMock(TicketService::class);
        $permissionService = $this->createMock(PermissionService::class);
        $projectService = $this->createMock(ProjectService::class);
        $emailService = $this->createMock(EmailService::class);
        $ticketMapper = $this->createMock(TicketMapper::class);
        $kbArticleMapper = $this->createMock(KBArticleMapper::class);
        $attachmentMapper = $this->createMock(AttachmentMapper::class);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $userSession = $this->createMock(IUserSession::class);
        $groupManager = $this->createMock(IGroupManager::class);
        $userManager = $this->createMock(IUserManager::class);
        $themesService = $this->createMock(ThemesService::class);
        $session = $this->createMock(\OCP\ISession::class);
        $emailPreferences = $this->createMock(EmailPreferencesService::class);
        $safeFilenameService = $this->createMock(SafeFilenameService::class);
        $config = $this->createMock(IConfig::class);
        $l10nFactory = $this->createMock(IFactory::class);
        $htmlSanitizerService = $this->createMock(HtmlSanitizerService::class);
        $mailer = $this->createMock(IMailer::class);
        $logger = $this->createMock(LoggerInterface::class);
        $guestLayoutParamsProvider = $this->createMock(GuestLayoutParamsProvider::class);
        $surveyService = $this->createMock(SurveyService::class);
        $ticketLinkService = $this->createMock(TicketLinkService::class);
        $attachmentUploadService = $this->createMock(AttachmentUploadService::class);
        $navigationContext = $this->createMock(NavigationContextService::class);

        $permissionService->method('isGuest')->willReturn(true);
        $permissionService->method('getAccessibleProjectIds')->willReturn([]);
        $permissionService->method('getCurrentUserDisplayName')->willReturn('Guest');
        $permissionService->method('isKnowledgeBasePortalContentEnabled')->willReturn(
            $kbEnabled && $kbPortalEnabled,
        );
        $guestLayoutParamsProvider->method('getParams')->willReturn([]);
        $navigationContext->method('canGuestCreateTicket')->willReturn(true);

        $config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, string $default = '') use ($kbEnabled, $kbPortalEnabled): string {
            if ($app !== 'ticketcheck') {
                return $default;
            }
            return match ($key) {
                'kb_enabled' => $kbEnabled ? 'yes' : 'no',
                'kb_portal_enabled' => $kbPortalEnabled ? 'yes' : 'no',
                default => $default,
            };
        });

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $key) => $key);
        $l10nFactory->method('get')->willReturn($l10n);

        $portalPageService = $this->createMock(GuestPortalPageService::class);
        $portalPageService->method('render')->willReturnCallback(function (
            string $template,
            string $pageId,
            array $bodyParams = [],
            string $pageTitle = '',
            string $pageHelp = '',
            array $scopeContext = [],
        ) use (&$captured, $permissionService, $navigationContext): TemplateResponse {
            $params = array_merge(
                [
                    'pageId' => $pageId,
                    'pageTitle' => $pageTitle,
                    'pageHelp' => $pageHelp,
                    'showKnowledgeBase' => $permissionService->isKnowledgeBasePortalContentEnabled(),
                    'canCreateTicket' => $navigationContext->canGuestCreateTicket(),
                    'scopeContext' => $scopeContext,
                ],
                $bodyParams,
            );
            $captured[] = ['template' => $template, 'pageId' => $pageId, 'params' => $params];
            return new TemplateResponse('ticketcheck', $template, $params, 'guest');
        });

        return new CustomerPortalController(
            'ticketcheck',
            $request,
            $ticketService,
            $permissionService,
            $projectService,
            $emailService,
            $ticketMapper,
            $kbArticleMapper,
            $attachmentMapper,
            $urlGenerator,
            $userSession,
            $groupManager,
            $userManager,
            $themesService,
            $session,
            $emailPreferences,
            $safeFilenameService,
            $config,
            $l10nFactory,
            $htmlSanitizerService,
            $mailer,
            $logger,
            $guestLayoutParamsProvider,
            $surveyService,
            $ticketLinkService,
            $attachmentUploadService,
            $navigationContext,
            $portalPageService,
            new \OCA\Ticketcheck\Service\GuestPasswordPolicyService(),
            new \OCA\Ticketcheck\Service\PortalTicketListFilterService(),
            new AttachmentDeliveryService($this->createMock(IConfig::class), new SafeFilenameService()),
        );
    }
}

<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Theming\Service\ThemesService;
use OCA\Ticketcheck\Controller\CustomerPortalController;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\KBArticleMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\GuestLayoutParamsProvider;
use OCA\Ticketcheck\Service\HtmlSanitizerService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCA\Ticketcheck\Service\SurveyService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCA\Ticketcheck\Service\TicketService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CustomerPortalControllerStoreTicketProjectNormalizationTest extends TestCase
{
    private CustomerPortalController $controller;
    /** @var PermissionService&MockObject */
    private $permissionService;
    /** @var TicketService&MockObject */
    private $ticketService;
    /** @var TicketMapper&MockObject */
    private $ticketMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $request = $this->createMock(IRequest::class);
        $this->ticketService = $this->createMock(TicketService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $projectService = $this->createMock(ProjectService::class);
        $emailService = $this->createMock(EmailService::class);
        $this->ticketMapper = $this->createMock(TicketMapper::class);
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
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $key): string => $key);
        $l10nFactory->method('get')->willReturn($l10n);
        $htmlSanitizerService = $this->createMock(HtmlSanitizerService::class);
        $mailer = $this->createMock(IMailer::class);
        $logger = $this->createMock(LoggerInterface::class);
        $guestLayoutParamsProvider = $this->createMock(GuestLayoutParamsProvider::class);
        $surveyService = $this->createMock(SurveyService::class);
        $ticketLinkService = $this->createMock(TicketLinkService::class);
        $attachmentUploadService = $this->createMock(AttachmentUploadService::class);

        $request->method('getParam')->willReturnCallback(static function (string $name, $default = null) {
            return match ($name) {
                'title' => 'Portal ticket title',
                'description' => 'Portal ticket description',
                'priority' => 'normal',
                'category' => 'general',
                'project_id' => '12',
                default => $default,
            };
        });

        $this->permissionService->method('getCurrentUserId')->willReturn('guest-user');
        $this->permissionService->method('checkRateLimit')->willReturn(true);
        $this->permissionService->method('isGuest')->willReturn(true);
        $this->permissionService->method('getAccessibleProjectIds')->willReturn(['12', '27']);
        $this->permissionService->method('canAccessProject')->with(12)->willReturn(true);
        $this->permissionService->method('getCurrentUserDisplayName')->willReturn('Guest User');
        $this->permissionService->method('getCurrentUserEmail')->willReturn('guest@example.com');

        $this->ticketMapper->method('countRecentByUser')->willReturn(0);

        $ticket = new Ticket();
        $ticket->setId(123);
        $this->ticketService->method('createTicket')->willReturn($ticket);
        $this->ticketService->method('withGuestActivityGate')->willReturnCallback(
            static function (string $userId, callable $callback) {
                return $callback();
            }
        );
        $urlGenerator->method('linkToRoute')->willReturn('/apps/ticketcheck/portal/tickets/123');

        $navigationContext = $this->createMock(\OCA\Ticketcheck\Service\NavigationContextService::class);
        $navigationContext->method('canGuestCreateTicket')->willReturn(true);

        $this->controller = new CustomerPortalController(
            'ticketcheck',
            $request,
            $this->ticketService,
            $this->permissionService,
            $projectService,
            $emailService,
            $this->ticketMapper,
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
            $this->createMock(\OCA\Ticketcheck\Service\GuestPortalPageService::class),
            new \OCA\Ticketcheck\Service\GuestPasswordPolicyService(),
            new \OCA\Ticketcheck\Service\PortalTicketListFilterService(),
            new AttachmentDeliveryService($this->createMock(IConfig::class), new SafeFilenameService()),
        );
    }

    public function testStoreTicketAcceptsStringProjectIdWhenAccessibleIdsAreStrings(): void
    {
        $response = $this->controller->storeTicket();

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue((bool)($data['success'] ?? false));
        $this->assertSame('/apps/ticketcheck/portal/tickets/123', $data['redirect'] ?? null);
    }

    public function testStoreTicketReturnsBadRequestWhenProjectRequiredButMissing(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static function (string $name, $default = null) {
            return match ($name) {
                'title' => 'Portal ticket title',
                'description' => 'Portal ticket description',
                'priority' => 'normal',
                'category' => 'general',
                'project_id' => null,
                default => $default,
            };
        });

        $l10nFactory = $this->createMock(IFactory::class);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $key): string => $key);
        $l10nFactory->method('get')->willReturn($l10n);

        $navigationContext = $this->createMock(\OCA\Ticketcheck\Service\NavigationContextService::class);
        $navigationContext->method('canGuestCreateTicket')->willReturn(true);

        $controller = new CustomerPortalController(
            'ticketcheck',
            $request,
            $this->ticketService,
            $this->permissionService,
            $this->createMock(ProjectService::class),
            $this->createMock(EmailService::class),
            $this->ticketMapper,
            $this->createMock(KBArticleMapper::class),
            $this->createMock(AttachmentMapper::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(IUserSession::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(ThemesService::class),
            $this->createMock(\OCP\ISession::class),
            $this->createMock(EmailPreferencesService::class),
            $this->createMock(SafeFilenameService::class),
            $this->createMock(IConfig::class),
            $l10nFactory,
            $this->createMock(HtmlSanitizerService::class),
            $this->createMock(IMailer::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(GuestLayoutParamsProvider::class),
            $this->createMock(SurveyService::class),
            $this->createMock(TicketLinkService::class),
            $this->createMock(AttachmentUploadService::class),
            $navigationContext,
            $this->createMock(\OCA\Ticketcheck\Service\GuestPortalPageService::class),
            new \OCA\Ticketcheck\Service\GuestPasswordPolicyService(),
            new \OCA\Ticketcheck\Service\PortalTicketListFilterService(),
            new AttachmentDeliveryService($this->createMock(IConfig::class), new SafeFilenameService()),
        );

        $response = $controller->storeTicket();

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('project_required_for_ticket', $response->getData()['error'] ?? null);
    }

    public function testStoreTicketReturnsForbiddenWhenGuestCannotCreateTickets(): void
    {
        $navigationContext = $this->createMock(\OCA\Ticketcheck\Service\NavigationContextService::class);
        $navigationContext->method('canGuestCreateTicket')->willReturn(false);

        $this->controller = $this->buildController($navigationContext);

        $response = $this->controller->storeTicket();

        $this->assertSame(403, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('guest_no_active_projects_message', $data['error'] ?? null);
    }

    /**
     * @param \OCA\Ticketcheck\Service\NavigationContextService&MockObject $navigationContext
     */
    private function buildController($navigationContext): CustomerPortalController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static function (string $name, $default = null) {
            return match ($name) {
                'title' => 'Portal ticket title',
                'description' => 'Portal ticket description',
                'priority' => 'normal',
                'category' => 'general',
                'project_id' => '12',
                default => $default,
            };
        });

        $l10nFactory = $this->createMock(IFactory::class);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $key): string => $key);
        $l10nFactory->method('get')->willReturn($l10n);

        return new CustomerPortalController(
            'ticketcheck',
            $request,
            $this->ticketService,
            $this->permissionService,
            $this->createMock(ProjectService::class),
            $this->createMock(EmailService::class),
            $this->ticketMapper,
            $this->createMock(KBArticleMapper::class),
            $this->createMock(AttachmentMapper::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(IUserSession::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(ThemesService::class),
            $this->createMock(\OCP\ISession::class),
            $this->createMock(EmailPreferencesService::class),
            $this->createMock(SafeFilenameService::class),
            $this->createMock(IConfig::class),
            $l10nFactory,
            $this->createMock(HtmlSanitizerService::class),
            $this->createMock(IMailer::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(GuestLayoutParamsProvider::class),
            $this->createMock(SurveyService::class),
            $this->createMock(TicketLinkService::class),
            $this->createMock(AttachmentUploadService::class),
            $navigationContext,
            $this->createMock(\OCA\Ticketcheck\Service\GuestPortalPageService::class),
            new \OCA\Ticketcheck\Service\GuestPasswordPolicyService(),
            new \OCA\Ticketcheck\Service\PortalTicketListFilterService(),
            new AttachmentDeliveryService($this->createMock(IConfig::class), new SafeFilenameService()),
        );
    }
}


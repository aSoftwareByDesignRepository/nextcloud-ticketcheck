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

class CustomerPortalControllerTicketLinksAccessTest extends TestCase
{
    private CustomerPortalController $controller;
    /** @var PermissionService&MockObject */
    private $permissionService;
    /** @var TicketMapper&MockObject */
    private $ticketMapper;
    /** @var TicketService&MockObject */
    private $ticketService;
    /** @var TicketLinkService&MockObject */
    private $ticketLinkService;

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
        $this->ticketLinkService = $this->createMock(TicketLinkService::class);
        $attachmentUploadService = $this->createMock(AttachmentUploadService::class);

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
            $this->ticketLinkService,
            $attachmentUploadService,
            $this->createMock(\OCA\Ticketcheck\Service\NavigationContextService::class),
            $this->createMock(\OCA\Ticketcheck\Service\GuestPortalPageService::class),
            new \OCA\Ticketcheck\Service\GuestPasswordPolicyService(),
            new \OCA\Ticketcheck\Service\PortalTicketListFilterService(),
            new AttachmentDeliveryService($this->createMock(IConfig::class), new SafeFilenameService()),
        );
    }

    public function testGetTicketLinksReturnsNotFoundWhenUserHasNoTicketAccess(): void
    {
        // SECURITY: missing vs forbidden ticket ids collapse to the same
        // 404 response so the link endpoint cannot be used to enumerate
        // other guests' tickets.
        $ticket = $this->createMock(Ticket::class);
        $this->ticketMapper->method('find')->with(23)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(false);
        $this->ticketLinkService->expects($this->never())->method('getLinksForTicket');

        $response = $this->controller->getTicketLinks(23);

        $this->assertSame(404, $response->getStatus());
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertSame('ticket_not_found', $data['error'] ?? null);
    }

    public function testGetTicketLinksReturnsLinksWhenUserHasTicketAccess(): void
    {
        $ticket = new Ticket();
        $ticket->setId(23);
        $links = [
            ['id' => 91, 'type' => 'related'],
        ];
        $this->ticketMapper->method('find')->with(23)->willReturn($ticket);
        $this->ticketService->method('getActiveTicket')->with(23)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(true);
        $this->ticketLinkService->method('getLinksForTicket')->with(23)->willReturn($links);

        $response = $this->controller->getTicketLinks(23);

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertSame($links, $data['links'] ?? null);
    }
}


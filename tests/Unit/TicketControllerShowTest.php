<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\TicketController;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\DeletionService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\MergeService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCA\Ticketcheck\Service\SplitService;
use OCA\Ticketcheck\Service\ActivityEventService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWatcherService;
use OCP\AppFramework\Db\DoesNotExistException;
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

class TicketControllerShowTest extends TestCase
{
    private IRequest $request;
    private TicketService $ticketService;
    private PermissionService $permissionService;
    private MergeService $mergeService;
    private EmailService $emailService;
    private ProjectService $projectService;
    private DeletionService $deletionService;
    private TicketMapper $ticketMapper;
    private AttachmentMapper $attachmentMapper;
    private IURLGenerator $urlGenerator;
    private IUserSession $userSession;
    private SafeFilenameService $safeFilenameService;
    private IFactory $l10nFactory;
    private IConfig $config;
    private IGroupManager $groupManager;
    private IUserManager $userManager;
    private LoggerInterface $logger;
    private TicketLinkService $ticketLinkService;
    private TicketWatcherService $ticketWatcherService;
    private SplitService $splitService;
    private ActivityEventService $activityEventService;
    private AttachmentUploadService $attachmentUploadService;

    private TicketController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->ticketService = $this->createMock(TicketService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->mergeService = $this->createMock(MergeService::class);
        $this->emailService = $this->createMock(EmailService::class);
        $this->projectService = $this->createMock(ProjectService::class);
        $this->deletionService = $this->createMock(DeletionService::class);
        $this->ticketMapper = $this->createMock(TicketMapper::class);
        $this->attachmentMapper = $this->createMock(AttachmentMapper::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->safeFilenameService = $this->createMock(SafeFilenameService::class);
        $this->l10nFactory = $this->createMock(IFactory::class);
        $this->config = $this->createMock(IConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->ticketLinkService = $this->createMock(TicketLinkService::class);
        $this->ticketWatcherService = $this->createMock(TicketWatcherService::class);
        $this->splitService = $this->createMock(SplitService::class);
        $this->activityEventService = $this->createMock(ActivityEventService::class);
        $this->attachmentUploadService = $this->createMock(AttachmentUploadService::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
        $this->l10nFactory->method('get')->with('ticketcheck')->willReturn($l10n);

        $this->permissionService->method('isHelpdeskAdmin')->willReturn(false);
        $this->permissionService->method('isAgent')->willReturn(true);
        $this->permissionService->method('isGuest')->willReturn(false);
        $this->permissionService->method('isKnowledgeBaseIndexAvailable')->willReturn(false);
        $this->permissionService->method('isKnowledgeBasePortalContentEnabled')->willReturn(false);
        $this->permissionService->method('canViewInternalNotes')->willReturn(false);
        $this->permissionService->method('canCreateInternalNotes')->willReturn(false);
        $this->permissionService->method('canEditTicket')->willReturn(false);
        $this->permissionService->method('canDeleteTicket')->willReturn(false);
        $this->ticketService->method('getComments')->willReturn([]);
        $this->ticketService->method('getAttachments')->willReturn([]);
        $this->ticketLinkService->method('getLinksForTicket')->willReturn([]);
        $this->ticketWatcherService->method('getWatchersWithDisplay')->willReturn([]);
        $this->userSession->method('getUser')->willReturn(null);

        $localeFormat = $this->createMock(LocaleFormatService::class);
        $localeFormat->method('clientHints')->willReturn([
            'locale' => 'en',
            'htmlLang' => 'en',
            'timezone' => 'UTC',
        ]);

        $navigationContext = $this->createMock(NavigationContextService::class);
        $navigationContext->method('buildNavigation')->willReturn([]);
        $navigationContext->method('buildCanonicalUrls')->willReturn([
            'dashboard' => '/apps/ticketcheck/',
            'tickets' => '/apps/ticketcheck/tickets',
        ]);
        $navigationContext->method('buildScopeContext')->willReturnCallback(static function (array $scopeContext): array {
            return array_merge([
                'roleLabel' => 'scope_role_agent',
                'roleBadge' => 'agent',
                'projectName' => '',
                'customerName' => '',
                'organizationName' => '',
                'contextLine' => '',
            ], $scopeContext);
        });

        $this->controller = new TicketController(
            'ticketcheck',
            $this->request,
            $this->ticketService,
            $this->permissionService,
            $this->mergeService,
            $this->emailService,
            $this->projectService,
            $this->deletionService,
            $this->ticketMapper,
            $this->attachmentMapper,
            $this->urlGenerator,
            $this->userSession,
            $this->safeFilenameService,
            $this->l10nFactory,
            $this->config,
            $this->groupManager,
            $this->userManager,
            $this->logger,
            $this->ticketLinkService,
            $this->ticketWatcherService,
            $this->splitService,
            $this->activityEventService,
            $this->attachmentUploadService,
            $localeFormat,
            $navigationContext,
            $this->createMock(FrontEndAssetService::class),
            new AttachmentDeliveryService($this->createMock(IConfig::class), new SafeFilenameService()),
        );
    }

    public function testShowReturnsTicketDetailForValidTicket(): void
    {
        $ticket = new Ticket();
        $ticket->setProjectId(null);
        $this->ticketMapper->method('find')->with(53)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(true);

        $response = $this->controller->show(53);

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('ticket-detail', $response->getTemplateName());
    }

    public function testShowStillReturnsTicketDetailWhenLinkedProjectLookupFails(): void
    {
        $ticket = new Ticket();
        $ticket->setProjectId(777);
        $this->ticketMapper->method('find')->with(53)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(true);
        $this->projectService->method('getProject')->with(777)->willThrowException(new \RuntimeException('missing project'));

        $response = $this->controller->show(53);

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('ticket-detail', $response->getTemplateName());
    }

    public function testShowReturnsAccessDeniedWhenAccessForbidden(): void
    {
        $ticket = new Ticket();
        $ticket->setProjectId(null);
        $this->ticketMapper->method('find')->with(53)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(false);

        $response = $this->controller->show(53);

        // Sealed contract: AuthZ deny is an explicit 403 — never 404-as-deny.
        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame(403, $response->getStatus());
        $this->assertSame('error', $response->getTemplateName());
        $params = $response->getParams();
        $this->assertSame('access_denied', $params['message']);
    }

    public function testShowReturnsTicketNotFoundForInvalidId(): void
    {
        $this->ticketMapper->method('find')->with(999)->willThrowException(new DoesNotExistException('missing'));

        $response = $this->controller->show(999);

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('error', $response->getTemplateName());
        $params = $response->getParams();
        $this->assertSame('ticket_not_found', $params['message']);
    }
}


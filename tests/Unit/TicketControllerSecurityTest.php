<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\TicketController;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\ActivityEventService;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\DeletionService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\MergeService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCA\Ticketcheck\Service\SplitService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWatcherService;
use OCP\AppFramework\Db\DoesNotExistException;
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

class TicketControllerSecurityTest extends TestCase
{
    private PermissionService $permissionService;
    private TicketMapper $ticketMapper;
    private TicketLinkService $ticketLinkService;
    private TicketWatcherService $ticketWatcherService;
    private TicketController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->permissionService = $this->createMock(PermissionService::class);
        $this->ticketMapper = $this->createMock(TicketMapper::class);
        $this->ticketLinkService = $this->createMock(TicketLinkService::class);
        $this->ticketWatcherService = $this->createMock(TicketWatcherService::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $key): string => $key);
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->with('ticketcheck')->willReturn($l10n);

        $localeFormat = $this->createMock(LocaleFormatService::class);
        $localeFormat->method('clientHints')->willReturn([
            'locale' => 'en',
            'htmlLang' => 'en',
            'timezone' => 'UTC',
        ]);

        $navigationContext = $this->createMock(NavigationContextService::class);
        $navigationContext->method('buildNavigation')->willReturn([]);
        $navigationContext->method('buildCanonicalUrls')->willReturn([]);
        $navigationContext->method('buildScopeContext')->willReturn([]);

        $this->controller = new TicketController(
            'ticketcheck',
            $this->createMock(IRequest::class),
            $this->createMock(TicketService::class),
            $this->permissionService,
            $this->createMock(MergeService::class),
            $this->createMock(EmailService::class),
            $this->createMock(ProjectService::class),
            $this->createMock(DeletionService::class),
            $this->ticketMapper,
            $this->createMock(AttachmentMapper::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(IUserSession::class),
            $this->createMock(SafeFilenameService::class),
            $l10nFactory,
            $this->createMock(IConfig::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(LoggerInterface::class),
            $this->ticketLinkService,
            $this->ticketWatcherService,
            $this->createMock(SplitService::class),
            $this->createMock(ActivityEventService::class),
            $this->createMock(AttachmentUploadService::class),
            $localeFormat,
            $navigationContext,
            $this->createMock(FrontEndAssetService::class),
            new AttachmentDeliveryService($this->createMock(IConfig::class), new SafeFilenameService()),
        );
    }

    public function testGetLinksReturnsNotFoundWhenTicketMissing(): void
    {
        $this->ticketMapper->method('find')->with(99)->willThrowException(new DoesNotExistException('missing'));
        $this->ticketLinkService->expects(self::never())->method('getLinksForTicket');

        $response = $this->controller->getLinks(99);

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('ticket_not_found', $response->getData()['error']);
    }

    // Sealed AuthZ contract (AtlasApiEndpointHappyAuthzTest): deny is an
    // explicit 403 access_denied — never 404-as-deny. Missing tickets still 404.
    public function testGetLinksReturnsForbiddenWhenAccessDenied(): void
    {
        $ticket = new Ticket();
        $this->ticketMapper->method('find')->with(12)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(false);
        $this->ticketLinkService->expects(self::never())->method('getLinksForTicket');

        $response = $this->controller->getLinks(12);

        $this->assertSame(403, $response->getStatus());
        $this->assertSame('access_denied', $response->getData()['error']);
    }

    public function testGetWatchersReturnsForbiddenWhenAccessDenied(): void
    {
        $ticket = new Ticket();
        $this->ticketMapper->method('find')->with(12)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(false);
        $this->ticketWatcherService->expects(self::never())->method('getWatchersWithDisplay');

        $response = $this->controller->getWatchers(12);

        $this->assertSame(403, $response->getStatus());
        $this->assertSame('access_denied', $response->getData()['error']);
    }

    public function testApiShowReturnsForbiddenWhenAccessDenied(): void
    {
        $ticket = new Ticket();
        $this->ticketMapper->method('find')->with(12)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(false);

        $response = $this->controller->apiShow(12);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(403, $response->getStatus());
        $this->assertSame('access_denied', $response->getData()['error']);
    }

    public function testDownloadAttachmentReturnsForbiddenWhenAccessDenied(): void
    {
        $ticket = new Ticket();
        $ticket->setId(12);
        $this->ticketMapper->method('find')->with(12)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->with($ticket)->willReturn(false);

        $response = $this->controller->downloadAttachment(12, 5);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(403, $response->getStatus());
        $this->assertSame('access_denied', $response->getData()['error']);
    }
}

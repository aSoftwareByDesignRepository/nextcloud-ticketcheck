<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\KnowledgeBaseController;
use OCA\Ticketcheck\Service\CategoryService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\HtmlSanitizerService;
use OCA\Ticketcheck\Service\KnowledgeBaseService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\TicketService;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class KnowledgeBaseControllerPortalEndpointsTest extends TestCase
{
    /** @var IRequest&MockObject */
    private $request;

    /** @var KnowledgeBaseService&MockObject */
    private $knowledgeBaseService;

    /** @var IUserSession&MockObject */
    private $userSession;

    private KnowledgeBaseController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->knowledgeBaseService = $this->createMock(KnowledgeBaseService::class);
        $permissionService = $this->createMock(PermissionService::class);
        $categoryService = $this->createMock(CategoryService::class);
        $htmlSanitizerService = $this->createMock(HtmlSanitizerService::class);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $l10nFactory = $this->createMock(IFactory::class);
        $logger = $this->createMock(LoggerInterface::class);

        $permissionService->method('isGuest')->willReturn(true);
        $permissionService->method('isKnowledgeBaseIndexAvailable')->willReturn(true);
        $permissionService->method('isKnowledgeBaseFeedbackEnabled')->willReturn(true);
        $permissionService->method('isKnowledgeBaseCommentsEnabled')->willReturn(true);
        $permissionService->method('checkRateLimit')->willReturn(true);

        $l10n = $this->createMock(\OCP\IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $key): string => $key);
        $l10nFactory->method('get')->willReturn($l10n);

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
        $frontEndAssets = $this->createMock(FrontEndAssetService::class);
        $ticketService = $this->createMock(TicketService::class);
        $ticketService->method('withGuestActivityGate')->willReturnCallback(
            static function (string $userId, callable $cb) {
                return $cb();
            }
        );
        $ticketService->method('countRecentPortalActionsByUser')->willReturn(0);
        $this->knowledgeBaseService->method('countRecentPortalKbActionsByUser')->willReturn(0);

        $this->controller = new KnowledgeBaseController(
            'ticketcheck',
            $this->request,
            $this->knowledgeBaseService,
            $permissionService,
            $categoryService,
            $htmlSanitizerService,
            $urlGenerator,
            $this->userSession,
            $l10nFactory,
            $logger,
            $localeFormat,
            $navigationContext,
            $frontEndAssets,
            $ticketService,
        );
    }

    public function testPortalMarkHelpfulReturnsUnauthorizedWithoutUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getParam')->willReturn(true);

        $response = $this->controller->portalMarkHelpful(4);
        $data = $response->getData();

        $this->assertSame(401, $response->getStatus());
        $this->assertSame('authentication_required', $data['error']);
    }

    public function testPortalGetCommentsReturnsKnowledgeBaseComments(): void
    {
        $this->knowledgeBaseService->method('getComments')->with(4)->willReturn([]);

        $response = $this->controller->portalGetComments(4);
        $data = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
        $this->assertSame([], $data['comments']);
    }
}


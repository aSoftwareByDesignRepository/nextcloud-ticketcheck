<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\KnowledgeBaseController;
use OCA\Ticketcheck\Db\KBArticle;
use OCA\Ticketcheck\Service\CategoryService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\HtmlSanitizerService;
use OCA\Ticketcheck\Service\KnowledgeBaseService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\TicketService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class KnowledgeBaseToggleMatrixTest extends TestCase
{
    /**
     * @dataProvider kbToggleMatrixProvider
     */
    public function testIndexAccessMatchesKbAndPortalFlags(
        bool $kbEnabled,
        bool $kbPortalEnabled,
        bool $kbFeedbackEnabled,
        bool $kbCommentsEnabled,
        bool $isGuest
    ): void {
        $controller = $this->buildController(
            $kbEnabled,
            $kbPortalEnabled,
            $kbFeedbackEnabled,
            $kbCommentsEnabled,
            $isGuest
        );

        $response = $controller->index();
        self::assertInstanceOf(TemplateResponse::class, $response);

        $kbAvailableForCurrentUser = $kbEnabled && (!$isGuest || $kbPortalEnabled);
        if ($kbAvailableForCurrentUser) {
            self::assertSame(200, $response->getStatus(), 'Index should be accessible for this matrix case');
            self::assertSame('kb/index', $response->getTemplateName());
            return;
        }

        self::assertSame(403, $response->getStatus(), 'Index should be forbidden for this matrix case');
        self::assertSame('error', $response->getTemplateName());
    }

    /**
     * @dataProvider kbToggleMatrixProvider
     */
    public function testFeedbackEndpointMatchesKbAndFeedbackFlags(
        bool $kbEnabled,
        bool $kbPortalEnabled,
        bool $kbFeedbackEnabled,
        bool $kbCommentsEnabled,
        bool $isGuest
    ): void {
        $controller = $this->buildController(
            $kbEnabled,
            $kbPortalEnabled,
            $kbFeedbackEnabled,
            $kbCommentsEnabled,
            $isGuest
        );

        $response = $controller->markHelpful(1001);
        self::assertInstanceOf(JSONResponse::class, $response);

        $kbAvailableForCurrentUser = $kbEnabled && (!$isGuest || $kbPortalEnabled);
        $feedbackExpected = $kbAvailableForCurrentUser && $kbFeedbackEnabled;
        if ($feedbackExpected) {
            self::assertSame(200, $response->getStatus(), 'Feedback endpoint should be enabled for this matrix case');
            return;
        }

        self::assertSame(403, $response->getStatus(), 'Feedback endpoint should be forbidden for this matrix case');
    }

    /**
     * @dataProvider kbToggleMatrixProvider
     */
    public function testCommentsEndpointMatchesKbAndCommentsFlags(
        bool $kbEnabled,
        bool $kbPortalEnabled,
        bool $kbFeedbackEnabled,
        bool $kbCommentsEnabled,
        bool $isGuest
    ): void {
        $controller = $this->buildController(
            $kbEnabled,
            $kbPortalEnabled,
            $kbFeedbackEnabled,
            $kbCommentsEnabled,
            $isGuest
        );

        $response = $controller->getComments(1001);
        self::assertInstanceOf(JSONResponse::class, $response);

        $kbAvailableForCurrentUser = $kbEnabled && (!$isGuest || $kbPortalEnabled);
        $commentsExpected = $kbAvailableForCurrentUser && $kbCommentsEnabled;
        if ($commentsExpected) {
            self::assertSame(200, $response->getStatus(), 'Comments endpoint should be enabled for this matrix case');
            return;
        }

        self::assertSame(403, $response->getStatus(), 'Comments endpoint should be forbidden for this matrix case');
    }

    public function testArticleTemplateCarriesFeatureFlagsWhenAccessible(): void
    {
        $controller = $this->buildController(
            true,
            true,
            false,
            true,
            false
        );

        $response = $controller->article(1001);
        self::assertInstanceOf(TemplateResponse::class, $response);
        self::assertSame(200, $response->getStatus());
        self::assertSame('kb/article', $response->getTemplateName());

        $params = $response->getParams();
        self::assertSame(false, $params['kbFeedbackEnabled'] ?? null);
        self::assertSame(true, $params['kbCommentsEnabled'] ?? null);
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: bool, 3: bool, 4: bool}>
     */
    public static function kbToggleMatrixProvider(): array
    {
        $cases = [];
        foreach ([false, true] as $kbEnabled) {
            foreach ([false, true] as $kbPortalEnabled) {
                foreach ([false, true] as $kbFeedbackEnabled) {
                    foreach ([false, true] as $kbCommentsEnabled) {
                        foreach ([false, true] as $isGuest) {
                            $cases[sprintf(
                                'kb:%s portal:%s feedback:%s comments:%s user:%s',
                                $kbEnabled ? 'on' : 'off',
                                $kbPortalEnabled ? 'on' : 'off',
                                $kbFeedbackEnabled ? 'on' : 'off',
                                $kbCommentsEnabled ? 'on' : 'off',
                                $isGuest ? 'guest' : 'internal'
                            )] = [$kbEnabled, $kbPortalEnabled, $kbFeedbackEnabled, $kbCommentsEnabled, $isGuest];
                        }
                    }
                }
            }
        }

        return $cases;
    }

    private function buildController(
        bool $kbEnabled,
        bool $kbPortalEnabled,
        bool $kbFeedbackEnabled,
        bool $kbCommentsEnabled,
        bool $isGuest
    ): KnowledgeBaseController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturn('');

        $knowledgeBaseService = $this->createMock(KnowledgeBaseService::class);
        $knowledgeBaseService->method('getIndexData')->willReturn([
            'articles' => [],
            'categories' => [],
            'categoryFilter' => '',
            'canManage' => true,
            'isAdmin' => true,
        ]);
        $knowledgeBaseService->method('getArticleForView')->willReturn($this->createMock(KBArticle::class));
        $knowledgeBaseService->method('getComments')->willReturn([]);
        $knowledgeBaseService->method('markHelpful')->willReturn(true);

        $kbAvailableForCurrentUser = $kbEnabled && (!$isGuest || $kbPortalEnabled);
        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('isGuest')->willReturn($isGuest);
        $permissionService->method('isKnowledgeBaseIndexAvailable')->willReturn($kbAvailableForCurrentUser);
        $permissionService->method('isKnowledgeBaseFeedbackEnabled')->willReturn($kbFeedbackEnabled);
        $permissionService->method('isKnowledgeBaseCommentsEnabled')->willReturn($kbCommentsEnabled);
        $permissionService->method('checkRateLimit')->willReturn(true);

        $categoryService = $this->createMock(CategoryService::class);
        $htmlSanitizerService = $this->createMock(HtmlSanitizerService::class);
        $urlGenerator = $this->createMock(IURLGenerator::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('tester');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $key): string => $key);

        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->with('ticketcheck')->willReturn($l10n);

        $logger = $this->createMock(LoggerInterface::class);

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
        $knowledgeBaseService->method('countRecentPortalKbActionsByUser')->willReturn(0);

        return new KnowledgeBaseController(
            'ticketcheck',
            $request,
            $knowledgeBaseService,
            $permissionService,
            $categoryService,
            $htmlSanitizerService,
            $urlGenerator,
            $userSession,
            $l10nFactory,
            $logger,
            $localeFormat,
            $navigationContext,
            $frontEndAssets,
            $ticketService,
        );
    }
}


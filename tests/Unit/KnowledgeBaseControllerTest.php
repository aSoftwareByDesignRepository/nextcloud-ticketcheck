<?php

declare(strict_types=1);

/**
 * Knowledge Base controller unit tests
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\KnowledgeBaseController;
use OCA\Ticketcheck\Exception\ArticleNotFoundException;
use OCA\Ticketcheck\Service\CategoryService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\HtmlSanitizerService;
use OCA\Ticketcheck\Service\KnowledgeBaseService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\TicketService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class KnowledgeBaseControllerTest extends TestCase
{
    private IRequest $request;
    private KnowledgeBaseService $knowledgeBaseService;
    private PermissionService $permissionService;
    private CategoryService $categoryService;
    private HtmlSanitizerService $htmlSanitizerService;
    private IURLGenerator $urlGenerator;
    private IUserSession $userSession;
    private IFactory $l10nFactory;
    private LoggerInterface $logger;

    private KnowledgeBaseController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->knowledgeBaseService = $this->createMock(KnowledgeBaseService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->categoryService = $this->createMock(CategoryService::class);
        $this->htmlSanitizerService = $this->createMock(HtmlSanitizerService::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->l10nFactory = $this->createMock(IFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(fn (string $s) => $s);
        $this->l10nFactory->method('get')->with('ticketcheck')->willReturn($l10n);
        $this->permissionService->method('isGuest')->willReturn(false);
        $this->permissionService->method('isKnowledgeBaseIndexAvailable')->willReturn(true);
        $this->permissionService->method('isKnowledgeBaseFeedbackEnabled')->willReturn(true);
        $this->permissionService->method('isKnowledgeBaseCommentsEnabled')->willReturn(true);

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

        $this->controller = new KnowledgeBaseController(
            'ticketcheck',
            $this->request,
            $this->knowledgeBaseService,
            $this->permissionService,
            $this->categoryService,
            $this->htmlSanitizerService,
            $this->urlGenerator,
            $this->userSession,
            $this->l10nFactory,
            $this->logger,
            $localeFormat,
            $navigationContext,
            $frontEndAssets,
            $ticketService,
        );
    }

    public function testMarkHelpfulReturnsUnauthorizedWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $response = $this->controller->markHelpful(1);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
    }

    public function testMarkHelpfulReturnsSuccess(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->request->method('getParam')->with('helpful', true)->willReturn(true);
        $this->knowledgeBaseService->expects($this->once())->method('markHelpful')->with(1, 'agent1', false)->willReturn(true);

        $response = $this->controller->markHelpful(1);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
        $this->assertFalse($data['already_voted']);
    }

    public function testMarkHelpfulReturnsNotFoundWhenArticleNotFound(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->request->method('getParam')->with('helpful', true)->willReturn(true);
        $this->knowledgeBaseService->method('markHelpful')->willThrowException(new ArticleNotFoundException(999));

        $response = $this->controller->markHelpful(999);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    public function testDeleteReturnsForbiddenWhenNoPermission(): void
    {
        $this->permissionService->method('canManageKnowledgeBase')->willReturn(false);

        $response = $this->controller->delete(1);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testDeleteReturnsSuccess(): void
    {
        $this->permissionService->method('canManageKnowledgeBase')->willReturn(true);
        $this->knowledgeBaseService->expects($this->once())->method('deleteArticle')->with(1);

        $response = $this->controller->delete(1);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
    }

    public function testDeleteReturnsNotFoundWhenArticleNotFound(): void
    {
        $this->permissionService->method('canManageKnowledgeBase')->willReturn(true);
        $this->knowledgeBaseService->method('deleteArticle')->willThrowException(new ArticleNotFoundException(999));

        $response = $this->controller->delete(999);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    public function testIndexReturnsTemplateResponse(): void
    {
        $this->request->method('getParam')->with('category', '')->willReturn('');
        $this->knowledgeBaseService->method('getIndexData')->with('')->willReturn([
            'articles' => [],
            'categories' => [],
            'categoryFilter' => '',
            'canManage' => false,
            'isAdmin' => false,
        ]);

        $response = $this->controller->index();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('kb/index', $response->getTemplateName());
    }

    public function testStoreReturnsForbiddenWhenNoPermission(): void
    {
        $this->permissionService->method('canManageKnowledgeBase')->willReturn(false);
        $this->request->method('getParam')->willReturnMap([
            ['title', null, 'Title'],
            ['content', null, 'Content'],
            ['category', null, 'General'],
            ['categoryId', null, null],
            ['published', false, false],
            ['pinned', false, false],
            ['allow_comments', false, false],
        ]);

        $response = $this->controller->store();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testUpdateReturnsForbiddenWhenNoPermission(): void
    {
        $this->permissionService->method('canManageKnowledgeBase')->willReturn(false);

        $response = $this->controller->update(1);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testAddCommentReturnsUnauthorizedWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getParam')->with('content')->willReturn('A comment');

        $response = $this->controller->addComment(1);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testAddCommentReturnsBadRequestWhenContentEmpty(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->request->method('getParam')->with('content')->willReturn('   ');

        $response = $this->controller->addComment(1);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testGetCommentsReturnsNotFoundWhenArticleNotFound(): void
    {
        $this->knowledgeBaseService->method('getComments')->with(999)
            ->willThrowException(new ArticleNotFoundException(999));

        $response = $this->controller->getComments(999);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    public function testUploadImageReturnsForbiddenWhenNoPermission(): void
    {
        $this->permissionService->method('canManageKnowledgeBase')->willReturn(false);

        $response = $this->controller->uploadImage();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testUploadImageReturnsBadRequestWhenNoFile(): void
    {
        $this->permissionService->method('canManageKnowledgeBase')->willReturn(true);
        $_FILES = [];

        $response = $this->controller->uploadImage();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testGetImageReturnsNotFoundWhenContentNull(): void
    {
        $this->knowledgeBaseService->method('getImageContent')->with('missing.png')->willReturn(null);

        $response = $this->controller->getImage('missing.png');

        $this->assertInstanceOf(\OCP\AppFramework\Http\NotFoundResponse::class, $response);
    }
}

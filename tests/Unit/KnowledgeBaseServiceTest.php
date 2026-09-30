<?php

declare(strict_types=1);

/**
 * Knowledge Base service unit tests
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\KBArticle;
use OCA\Ticketcheck\Db\KBArticleMapper;
use OCA\Ticketcheck\Db\KBComment;
use OCA\Ticketcheck\Db\KBCommentMapper;
use OCA\Ticketcheck\Db\KBHelpfulVoteMapper;
use OCA\Ticketcheck\Exception\ArticleNotFoundException;
use OCA\Ticketcheck\Service\CategoryService;
use OCA\Ticketcheck\Service\HtmlSanitizerService;
use OCA\Ticketcheck\Service\KnowledgeBaseService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class KnowledgeBaseServiceTest extends TestCase
{
    private KBArticleMapper $articleMapper;
    private KBCommentMapper $commentMapper;
    private KBHelpfulVoteMapper $helpfulVoteMapper;
    private CategoryService $categoryService;
    private PermissionService $permissionService;
    private SafeFilenameService $safeFilenameService;
    private HtmlSanitizerService $htmlSanitizerService;
    private IAppDataFactory $appDataFactory;
    private LoggerInterface $logger;

    private KnowledgeBaseService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->articleMapper = $this->createMock(KBArticleMapper::class);
        $this->commentMapper = $this->createMock(KBCommentMapper::class);
        $this->helpfulVoteMapper = $this->createMock(KBHelpfulVoteMapper::class);
        $this->categoryService = $this->createMock(CategoryService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->safeFilenameService = $this->createMock(SafeFilenameService::class);
        $this->htmlSanitizerService = $this->createMock(HtmlSanitizerService::class);
        $this->appDataFactory = $this->createMock(IAppDataFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new KnowledgeBaseService(
            $this->articleMapper,
            $this->commentMapper,
            $this->helpfulVoteMapper,
            $this->categoryService,
            $this->permissionService,
            $this->safeFilenameService,
            $this->htmlSanitizerService,
            $this->appDataFactory,
            $this->logger
        );
    }

    public function testGetIndexDataReturnsArticlesAndCategories(): void
    {
        $article = $this->createArticle(1, 'Test Article', 'General', true);
        $this->permissionService->method('isGuest')->willReturn(false);
        $this->permissionService->method('canManageKnowledgeBase')->willReturn(true);
        $this->permissionService->method('canManageSettings')->willReturn(false);
        $this->articleMapper->method('findAll')->willReturn([$article]);
        $this->articleMapper->method('findPublished')->willReturn([$article]);

        $data = $this->service->getIndexData('');

        $this->assertIsArray($data);
        $this->assertArrayHasKey('articles', $data);
        $this->assertArrayHasKey('categories', $data);
        $this->assertArrayHasKey('categoryFilter', $data);
        $this->assertArrayHasKey('canManage', $data);
        $this->assertArrayHasKey('isAdmin', $data);
        $this->assertCount(1, $data['articles']);
        $this->assertSame('Test Article', $data['articles'][0]->getTitle());
        $this->assertSame(['General' => 1], $data['categories']);
    }

    public function testGetSearchDataUsesSearchResultCategoryCounts(): void
    {
        $articleGeneral = $this->createArticle(1, 'Tree house', 'General', true);
        $articleTech = $this->createArticle(2, 'Tree trimming', 'Technical', true);
        $this->permissionService->method('isGuest')->willReturn(false);
        $this->permissionService->method('canManageKnowledgeBase')->willReturn(true);
        $this->permissionService->method('canManageSettings')->willReturn(false);
        $this->articleMapper->method('search')->with('tree', false)->willReturn([$articleGeneral, $articleTech]);
        $this->articleMapper->method('findAll')->willReturn([$articleGeneral, $articleTech]);
        $this->articleMapper->method('findPublished')->willReturn([$articleGeneral, $articleTech]);

        $data = $this->service->getSearchData('tree', '');

        $this->assertSame('tree', $data['query']);
        $this->assertCount(2, $data['articles']);
        $this->assertSame(['General' => 1, 'Technical' => 1], $data['categories']);
    }

    public function testGetSearchDataTruncatesOverlongQuery(): void
    {
        $this->permissionService->method('isGuest')->willReturn(false);
        $this->permissionService->method('canManageKnowledgeBase')->willReturn(false);
        $this->permissionService->method('canManageSettings')->willReturn(false);
        $this->articleMapper->method('findAll')->willReturn([]);
        $this->articleMapper->method('findPublished')->willReturn([]);
        $this->articleMapper->expects($this->once())
            ->method('search')
            ->with($this->callback(static function (string $query): bool {
                return mb_strlen($query) === 200;
            }), true)
            ->willReturn([]);

        $data = $this->service->getSearchData(str_repeat('z', 250), '');

        $this->assertSame(200, mb_strlen($data['query']));
    }

    public function testGetIndexDataFiltersByCategory(): void
    {
        $article1 = $this->createArticle(1, 'General Article', 'General', true);
        $article2 = $this->createArticle(2, 'Tech Article', 'Technical', true);
        $this->permissionService->method('isGuest')->willReturn(false);
        $this->permissionService->method('canManageKnowledgeBase')->willReturn(true);
        $this->permissionService->method('canManageSettings')->willReturn(false);
        $this->articleMapper->method('findAll')->willReturn([$article1, $article2]);
        $this->articleMapper->method('findPublished')->willReturn([$article1, $article2]);

        $data = $this->service->getIndexData('Technical');

        $this->assertCount(1, $data['articles']);
        $this->assertSame('Tech Article', $data['articles'][0]->getTitle());
    }

    public function testGetArticleForViewThrowsWhenNotFound(): void
    {
        $this->articleMapper->method('find')->with(999)->willThrowException(new DoesNotExistException('not found'));
        $this->expectException(ArticleNotFoundException::class);
        $this->service->getArticleForView(999);
    }

    public function testGetArticleForViewIncrementsViews(): void
    {
        $article = $this->createArticle(1, 'Test', 'General', true);
        $article->setViews(5);
        $updated = $this->createArticle(1, 'Test', 'General', true);
        $updated->setViews(6);
        $this->permissionService->method('isGuest')->willReturn(false);
        $this->articleMapper->expects($this->exactly(2))
            ->method('find')
            ->with(1)
            ->willReturnOnConsecutiveCalls($article, $updated);
        $this->articleMapper->expects($this->once())->method('incrementViewsAtomic')->with(1)->willReturn(1);
        $this->articleMapper->expects($this->never())->method('update');

        $result = $this->service->getArticleForView(1);

        $this->assertSame(6, $result->getViews());
    }

    public function testMarkHelpfulIncrementsCount(): void
    {
        $article = $this->createArticle(1, 'Test', 'General', true);
        $article->setHelpfulCount(3);
        $this->articleMapper->method('find')->with(1)->willReturn($article);
        $this->helpfulVoteMapper->expects($this->once())->method('tryInsert')->with(1, 'user1')->willReturn(true);
        $this->articleMapper->expects($this->once())->method('incrementHelpfulCountAtomic')->with(1)->willReturn(1);
        $this->articleMapper->expects($this->never())->method('update');

        self::assertTrue($this->service->markHelpful(1, 'user1'));
    }

    public function testMarkHelpfulIdempotentWhenAlreadyVoted(): void
    {
        $article = $this->createArticle(1, 'Test', 'General', true);
        $this->articleMapper->method('find')->with(1)->willReturn($article);
        $this->helpfulVoteMapper->expects($this->once())->method('tryInsert')->with(1, 'user1')->willReturn(false);
        $this->articleMapper->expects($this->never())->method('incrementHelpfulCountAtomic');

        self::assertFalse($this->service->markHelpful(1, 'user1'));
    }

    public function testMarkHelpfulThrowsWhenNotFound(): void
    {
        $this->articleMapper->method('find')->with(999)->willThrowException(new DoesNotExistException('not found'));
        $this->expectException(ArticleNotFoundException::class);
        $this->service->markHelpful(999, 'user1');
    }

    public function testMarkHelpfulPublishedOnlyRejectsDraft(): void
    {
        $draft = $this->createArticle(8, 'Draft', 'General', false);
        $this->articleMapper->method('find')->with(8)->willReturn($draft);
        $this->helpfulVoteMapper->expects($this->never())->method('tryInsert');
        $this->articleMapper->expects($this->never())->method('update');
        $this->expectException(ArticleNotFoundException::class);
        $this->service->markHelpful(8, 'user1', true);
    }

    public function testGetCommentsPublishedOnlyRejectsDraft(): void
    {
        $draft = $this->createArticle(9, 'Draft', 'General', false);
        $draft->setAllowComments(true);
        $this->articleMapper->method('find')->with(9)->willReturn($draft);
        $this->expectException(ArticleNotFoundException::class);
        $this->service->getComments(9, true);
    }

    public function testAddCommentPublishedOnlyRejectsDraft(): void
    {
        $draft = $this->createArticle(10, 'Draft', 'General', false);
        $draft->setAllowComments(true);
        $this->articleMapper->method('find')->with(10)->willReturn($draft);
        $this->commentMapper->expects($this->never())->method('insert');
        $this->expectException(ArticleNotFoundException::class);
        $this->service->addComment(10, 'Hello', 'user1', 'User One', 'user@example.com', true);
    }

    public function testDeleteArticleRemovesArticle(): void
    {
        $article = $this->createArticle(1, 'Test', 'General', true);
        $this->articleMapper->method('find')->with(1)->willReturn($article);
        $this->commentMapper->expects($this->once())->method('deleteByArticleId')->with(1);
        $this->articleMapper->expects($this->once())->method('delete')->with($article);

        $this->service->deleteArticle(1);
    }

    public function testDeleteArticleThrowsWhenNotFound(): void
    {
        $this->articleMapper->method('find')->with(999)->willThrowException(new DoesNotExistException('not found'));
        $this->expectException(ArticleNotFoundException::class);
        $this->service->deleteArticle(999);
    }

    public function testGetImageContentReturnsNullForPathTraversal(): void
    {
        $this->safeFilenameService->method('sanitizeForContentDisposition')
            ->willReturnCallback(function ($name) {
                return $name === '..' || $name === '../etc/passwd' ? 'download' : $name;
            });
        $result = $this->service->getImageContent('../../../etc/passwd');
        $this->assertNull($result);
    }

    public function testGetImageContentRequiresPublishedReferenceWhenAsked(): void
    {
        $filename = 'kb_20260101120000_' . str_repeat('ab', 16) . '.png';
        $this->safeFilenameService->method('sanitizeForContentDisposition')->willReturn($filename);
        $this->articleMapper->expects($this->once())
            ->method('isFilenameReferencedByPublishedArticle')
            ->with($filename)
            ->willReturn(false);

        $this->assertNull($this->service->getImageContent($filename, true));
    }

    public function testGetImageContentRejectsUnexpectedFilenameShape(): void
    {
        $this->safeFilenameService->method('sanitizeForContentDisposition')->willReturn('../evil.png');
        $this->articleMapper->expects($this->never())->method('isFilenameReferencedByPublishedArticle');
        $this->assertNull($this->service->getImageContent('../evil.png', true));
    }

    public function testGetImageContentAcceptsLegacyUniqidFilenames(): void
    {
        $filename = 'kb_20260101120000_67890abcdef.123456.png';
        $this->safeFilenameService->method('sanitizeForContentDisposition')->willReturn($filename);
        $this->articleMapper->expects($this->once())
            ->method('isFilenameReferencedByPublishedArticle')
            ->with($filename)
            ->willReturn(false);

        $this->assertNull($this->service->getImageContent($filename, true));
    }

    public function testGetImageContentReturnsNullForEmptyFilename(): void
    {
        $this->safeFilenameService->expects($this->once())
            ->method('sanitizeForContentDisposition')
            ->with('')
            ->willReturn('download');
        $result = $this->service->getImageContent('');
        $this->assertNull($result);
    }

    public function testAddCommentThrowsWhenCommentsNotAllowed(): void
    {
        $article = $this->createArticle(1, 'Test', 'General', true);
        $article->setAllowComments(false);
        $this->articleMapper->method('find')->with(1)->willReturn($article);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Comments are not allowed on this article');
        $this->service->addComment(1, 'Hello', 'user1', 'User One', 'user@example.com');
    }

    public function testAddCommentThrowsWhenContentEmpty(): void
    {
        $article = $this->createArticle(1, 'Test', 'General', true);
        $article->setAllowComments(true);
        $this->articleMapper->method('find')->with(1)->willReturn($article);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Comment content is required');
        $this->service->addComment(1, '   ', 'user1', 'User One', 'user@example.com');
    }

    private function createArticle(int $id, string $title, string $category, bool $published): KBArticle
    {
        $article = new KBArticle();
        $article->setId($id);
        $article->setTitle($title);
        $article->setContent('');
        $article->setCategory($category);
        $article->setPublished($published);
        $article->setViews(0);
        $article->setHelpfulCount(0);
        return $article;
    }
}

<?php

declare(strict_types=1);

/**
 * Knowledge base business logic — articles, comments, categories, image uploads
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\KBArticle;
use OCA\Ticketcheck\Db\KBArticleMapper;
use OCA\Ticketcheck\Db\KBComment;
use OCA\Ticketcheck\Db\KBCommentMapper;
use OCA\Ticketcheck\Db\KBHelpfulVoteMapper;
use OCA\Ticketcheck\Exception\ArticleNotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;

class KnowledgeBaseService
{
    private const KB_IMAGES_FOLDER = 'kb-images';
    private const MAX_IMAGE_SIZE = 5 * 1024 * 1024; // 5MB
    private const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function __construct(
        private readonly KBArticleMapper $kbArticleMapper,
        private readonly KBCommentMapper $kbCommentMapper,
        private readonly KBHelpfulVoteMapper $kbHelpfulVoteMapper,
        private readonly CategoryService $categoryService,
        private readonly PermissionService $permissionService,
        private readonly SafeFilenameService $safeFilenameService,
        private readonly HtmlSanitizerService $htmlSanitizerService,
        private readonly IAppDataFactory $appDataFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Data for KB index page: articles (optionally filtered), categories with counts, flags
     *
     * @return array{articles: list<KBArticle>, categories: array<string, int>, categoryFilter: string, canManage: bool, isAdmin: bool}
     */
    public function getIndexData(string $categoryFilter): array
    {
        $publishedOnly = !$this->permissionService->canManageKnowledgeBase();
        /** @var list<KBArticle> $articles */
        $articles = $publishedOnly
            ? $this->kbArticleMapper->findPublished()
            : $this->kbArticleMapper->findAll();

        if ($categoryFilter !== '') {
            $articles = array_values(array_filter($articles, function (KBArticle $a) use ($categoryFilter): bool {
                $cat = $a->getCategory() ?: 'General';
                return $cat === $categoryFilter;
            }));
        }

        /** @var list<KBArticle> $allArticles */
        $allArticles = $publishedOnly
            ? $this->kbArticleMapper->findPublished()
            : $this->kbArticleMapper->findAll();
        $categories = $this->buildCategoryCounts($allArticles);

        return [
            'articles' => $articles,
            'categories' => $categories,
            'categoryFilter' => $categoryFilter,
            'canManage' => $this->permissionService->canManageKnowledgeBase(),
            'isAdmin' => $this->permissionService->canManageSettings(),
        ];
    }

    /**
     * Get article for view; increments view count. Throws if not found or guest and unpublished.
     */
    public function getArticleForView(int $id): KBArticle
    {
        try {
            $article = $this->kbArticleMapper->find($id);
        } catch (DoesNotExistException) {
            throw new ArticleNotFoundException($id);
        }

        if (!$article->getPublished() && !$this->permissionService->canManageKnowledgeBase()) {
            throw new ArticleNotFoundException($id, 'Article not found');
        }

        $this->kbArticleMapper->incrementViewsAtomic($id);
        try {
            return $this->kbArticleMapper->find($id);
        } catch (DoesNotExistException) {
            throw new ArticleNotFoundException($id);
        }
    }

    /**
     * Data for KB search page
     *
     * @return array{query: string, articles: list<KBArticle>, categories: array<string, int>, categoryFilter: string, canManage: bool, isAdmin: bool}
     */
    public function getSearchData(string $query, string $categoryFilter): array
    {
        $query = KbSearchTextHelper::normalizeQuery($query);
        $publishedOnly = !$this->permissionService->canManageKnowledgeBase();
        /** @var list<KBArticle> $allArticles */
        $allArticles = $publishedOnly
            ? $this->kbArticleMapper->findPublished()
            : $this->kbArticleMapper->findAll();

        /** @var list<KBArticle> $articles */
        $articles = $query !== ''
            ? $this->kbArticleMapper->search($query, $publishedOnly)
            : [];

        // Category pills reflect the active search result set, not the whole KB.
        $categories = $this->buildCategoryCounts($query !== '' ? $articles : $allArticles);
        if ($categoryFilter !== '' && !isset($categories[$categoryFilter])) {
            $categories[$categoryFilter] = 0;
        }

        if ($categoryFilter !== '') {
            $articles = array_values(array_filter($articles, function (KBArticle $a) use ($categoryFilter): bool {
                $cat = $a->getCategory() ?: 'General';
                return $cat === $categoryFilter;
            }));
        }

        return [
            'query' => $query,
            'articles' => $articles,
            'categories' => $categories,
            'categoryFilter' => $categoryFilter,
            'canManage' => $this->permissionService->canManageKnowledgeBase(),
            'isAdmin' => $this->permissionService->canManageSettings(),
        ];
    }

    /**
     * Create a new KB article. Returns the saved entity.
     *
     * @param array{title?: mixed, content?: mixed, category?: mixed, categoryId?: mixed, published?: mixed, pinned?: mixed, allow_comments?: mixed} $params
     */
    public function createArticle(array $params): KBArticle
    {
        $this->categoryService->ensureDefaultExists();

        $category = $this->resolveCategoryFromParams($params);
        $userId = $this->permissionService->getCurrentUserId();
        if ($userId === null || $userId === '') {
            throw new \RuntimeException('User context required to create article');
        }

        $article = new KBArticle();
        $article->setTitle($this->requireTitle($params['title'] ?? ''));
        $article->setContent($this->htmlSanitizerService->sanitize((string)($params['content'] ?? '')));
        $article->setCategory($category);
        $article->setPublished((bool)($params['published'] ?? false));
        $article->setPinned((bool)($params['pinned'] ?? false));
        $article->setAllowComments((bool)($params['allow_comments'] ?? false));
        $article->setViews(0);
        $article->setHelpfulCount(0);
        $article->setCreatedBy($userId);
        $now = new \DateTime();
        $article->setCreatedAt($now);
        $article->setUpdatedAt($now);

        /** @var KBArticle $saved */
        $saved = $this->kbArticleMapper->insert($article);
        return $saved;
    }

    /**
     * Update an existing KB article. Returns the updated entity.
     *
     * @param array{title?: mixed, content?: mixed, category?: mixed, categoryId?: mixed, published?: mixed, pinned?: mixed, allow_comments?: mixed} $params
     */
    public function updateArticle(int $id, array $params): KBArticle
    {
        try {
            $article = $this->kbArticleMapper->find($id);
        } catch (DoesNotExistException) {
            throw new ArticleNotFoundException($id);
        }

        $article->setTitle($this->requireTitle($params['title'] ?? $article->getTitle()));
        $oldContent = (string) $article->getContent();
        $article->setContent($this->htmlSanitizerService->sanitize((string)($params['content'] ?? $article->getContent())));
        $article->setCategory($this->resolveCategoryFromParams($params) ?: $article->getCategory());
        $article->setPublished((bool)($params['published'] ?? $article->getPublished()));
        $article->setPinned((bool)($params['pinned'] ?? $article->getPinned()));
        $article->setAllowComments((bool)($params['allow_comments'] ?? $article->getAllowComments()));
        $article->setUpdatedAt(new \DateTime());

        $this->kbArticleMapper->update($article);
        $this->gcKbImagesRemovedFromContent($oldContent, (string) $article->getContent());
        return $article;
    }

    /**
     * Record a helpful vote for $userId. Returns true when the count was incremented,
     * false when this user already voted (idempotent success for the client).
     */
    public function markHelpful(int $id, string $userId, bool $publishedOnly = false): bool
    {
        try {
            $article = $this->kbArticleMapper->find($id);
        } catch (DoesNotExistException) {
            throw new ArticleNotFoundException($id);
        }
        $this->assertPublishedWhenRequired($article, $publishedOnly);

        if (!$this->kbHelpfulVoteMapper->tryInsert($id, $userId)) {
            return false;
        }

        if ($this->kbArticleMapper->incrementHelpfulCountAtomic($id) < 1) {
            throw new ArticleNotFoundException($id);
        }

        return true;
    }

    public function deleteArticle(int $id): void
    {
        try {
            $article = $this->kbArticleMapper->find($id);
        } catch (DoesNotExistException) {
            throw new ArticleNotFoundException($id);
        }
        $referenced = $this->extractReferencedKbImageFilenames((string) $article->getContent());
        // No FK cascade: purge comments and votes before the article row.
        $this->kbCommentMapper->deleteByArticleId($id);
        $this->kbHelpfulVoteMapper->deleteByArticleId($id);
        $this->kbArticleMapper->delete($article);
        foreach ($referenced as $filename) {
            $this->deleteKbImageIfUnreferenced($filename);
        }
    }

    /**
     * Delete unreferenced KB image files older than $minAgeSeconds (in-flight upload safety).
     */
    public function cleanupUnreferencedKbImages(int $minAgeSeconds = 7200): int
    {
        $deleted = 0;
        try {
            $folder = $this->getKbImagesFolder();
        } catch (\Throwable $e) {
            $this->logger->warning('KB image GC: folder unavailable', ['exception' => $e]);
            return 0;
        }

        $now = time();
        foreach ($folder->getDirectoryListing() as $node) {
            $name = $node->getName();
            if (preg_match('/^kb_[A-Za-z0-9._-]+\.(jpe?g|png|gif|webp)$/i', $name) !== 1) {
                continue;
            }
            try {
                if (($now - $node->getMTime()) < $minAgeSeconds) {
                    continue;
                }
                if ($this->kbArticleMapper->isFilenameReferencedByAnyArticle($name)) {
                    continue;
                }
                $node->delete();
                $deleted++;
            } catch (\Throwable $e) {
                // best-effort: GC of unreferenced images; failure leaves an orphan file, not corrupt state
                $this->logger->warning('KB image GC failed for file', [
                    'filename' => $name,
                    'exception' => $e,
                ]);
            }
        }

        return $deleted;
    }

    /**
     * Portal KB comments + helpful votes in the activity window (rate-limit metering).
     */
    public function countRecentPortalKbActionsByUser(string $userId, \DateTimeInterface $since): int
    {
        return $this->kbCommentMapper->countRecentByUserId($userId, $since)
            + $this->kbHelpfulVoteMapper->countRecentByUserId($userId, $since);
    }

    /**
     * Upload image for KB. Returns ['success' => true, 'imageUrl' => ..., 'filename' => ...] or error payload.
     */
    /**
     * Message keys for i18n (controller translates)
     */
    private const MSG_NO_IMAGE = 'no_image_uploaded';
    private const MSG_UPLOAD_FAILED = 'image_upload_failed';
    private const MSG_TOO_LARGE = 'image_too_large';
    private const MSG_INVALID_FORMAT = 'invalid_image_format';
    private const MSG_INVALID_TYPE = 'invalid_image_type';
    private const MSG_SAVE_FAILED = 'failed_to_save_image';
    private const MSG_SUCCESS = 'image_uploaded_successfully';

    /**
     * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $file
     * @return array{success: bool, messageKey: string, filename?: string}
     */
    public function uploadImage(array $file): array
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'messageKey' => self::MSG_NO_IMAGE];
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'messageKey' => self::MSG_UPLOAD_FAILED];
        }
        $fileSize = (int)($file['size'] ?? 0);
        if ($fileSize > self::MAX_IMAGE_SIZE) {
            return ['success' => false, 'messageKey' => self::MSG_TOO_LARGE];
        }

        $originalName = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_IMAGE_EXTENSIONS, true)) {
            return ['success' => false, 'messageKey' => self::MSG_INVALID_FORMAT];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        if (!in_array($mimeType, self::ALLOWED_IMAGE_MIMES, true)) {
            return ['success' => false, 'messageKey' => self::MSG_INVALID_TYPE];
        }

        $folder = $this->getKbImagesFolder();
        $uniqueFilename = $this->uniqueImageFilename($ext);
        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            $this->logger->error('KB image upload: failed to read uploaded file');
            return ['success' => false, 'messageKey' => self::MSG_SAVE_FAILED];
        }

        try {
            $folder->newFile($uniqueFilename, $content);
        } catch (\Throwable $e) {
            $this->logger->error('KB image upload failed', ['exception' => $e]);
            return ['success' => false, 'messageKey' => self::MSG_SAVE_FAILED];
        }

        return [
            'success' => true,
            'messageKey' => self::MSG_SUCCESS,
            'filename' => $uniqueFilename,
        ];
    }

    /**
     * Get image content by filename from app data. Returns null if not found.
     *
     * @param bool $requirePublishedReference When true, only serve if a published
     *                                        article body references this filename.
     *                                        KB managers skip this so the editor can
     *                                        preview freshly uploaded (unsaved) images.
     */
    public function getImageContent(string $filename, bool $requirePublishedReference = false): ?string
    {
        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, "\0")) {
            return null;
        }
        $safeName = $this->safeFilenameService->sanitizeForContentDisposition($filename);
        if ($safeName === '' || $safeName === 'download') {
            return null;
        }
        // Accept our upload naming schemes (current random_bytes and legacy uniqid).
        if (preg_match('/^kb_[A-Za-z0-9._-]+\.(jpe?g|png|gif|webp)$/i', $safeName) !== 1) {
            return null;
        }

        if ($requirePublishedReference
            && !$this->kbArticleMapper->isFilenameReferencedByPublishedArticle($safeName)
        ) {
            return null;
        }

        try {
            $folder = $this->getKbImagesFolder();
            if (!$folder->fileExists($safeName)) {
                return null;
            }
            $file = $folder->getFile($safeName);
            return $file->getContent();
        } catch (\Throwable $e) {
            $this->logger->warning('KB image get failed', ['filename' => $filename, 'exception' => $e]);
            return null;
        }
    }

    /**
     * Get MIME type for a stored image filename (by extension).
     */
    public function getImageMimeType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    /**
     * Add comment to article. Returns the saved comment.
     *
     * @param bool $publishedOnly When true (portal), unpublished drafts are treated as not found.
     */
    public function addComment(
        int $articleId,
        string $content,
        string $userId,
        string $displayName,
        string $email,
        bool $publishedOnly = false
    ): KBComment {
        try {
            $article = $this->kbArticleMapper->find($articleId);
        } catch (DoesNotExistException) {
            throw new ArticleNotFoundException($articleId);
        }
        $this->assertPublishedWhenRequired($article, $publishedOnly);
        if (!$article->getAllowComments()) {
            throw new \InvalidArgumentException('Comments are not allowed on this article');
        }

        $content = trim($content);
        if ($content === '') {
            throw new \InvalidArgumentException('Comment content is required');
        }

        $comment = new KBComment();
        $comment->setArticleId($articleId);
        $comment->setUserId($userId);
        $comment->setAuthorName($displayName);
        $comment->setAuthorEmail($email);
        $comment->setContent($this->htmlSanitizerService->sanitize($content));
        $comment->setCreatedAt(new \DateTime());

        /** @var KBComment $saved */
        $saved = $this->kbCommentMapper->insert($comment);
        return $saved;
    }

    /**
     * Get comments for article as array of [id, author, content, created_at].
     *
     * @param bool $publishedOnly When true (portal), unpublished drafts are treated as not found.
     * @return list<array{id: int, author: string, content: string, created_at: string}>
     */
    public function getComments(int $articleId, bool $publishedOnly = false): array
    {
        try {
            $article = $this->kbArticleMapper->find($articleId);
        } catch (DoesNotExistException) {
            throw new ArticleNotFoundException($articleId);
        }
        $this->assertPublishedWhenRequired($article, $publishedOnly);
        if (!$article->getAllowComments()) {
            return [];
        }

        $comments = $this->kbCommentMapper->findByArticleId($articleId);
        $result = [];
        foreach ($comments as $c) {
            $result[] = [
                'id' => $c->getId(),
                'author' => $c->getAuthorName(),
                'content' => $c->getContent(),
                'created_at' => $c->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        }
        return $result;
    }

    /**
     * Portal callers must not learn about unpublished drafts (uniform not-found).
     */
    private function assertPublishedWhenRequired(KBArticle $article, bool $publishedOnly): void
    {
        if ($publishedOnly && !$article->isPublished()) {
            throw new ArticleNotFoundException((int) $article->getId());
        }
    }

    /**
     * Find article by ID (no view increment). Throws ArticleNotFoundException.
     */
    public function getArticle(int $id): KBArticle
    {
        try {
            return $this->kbArticleMapper->find($id);
        } catch (DoesNotExistException) {
            throw new ArticleNotFoundException($id);
        }
    }

    /**
     * @param list<KBArticle> $articles
     * @return array<string, int>
     */
    private function buildCategoryCounts(array $articles): array
    {
        $categories = [];
        foreach ($articles as $article) {
            $cat = $article->getCategory() ?: 'General';
            $categories[$cat] = ($categories[$cat] ?? 0) + 1;
        }
        return $categories;
    }

    /**
     * @param array{category?: mixed, categoryId?: mixed} $params
     */
    private function resolveCategoryFromParams(array $params): string
    {
        $category = trim((string)($params['category'] ?? ''));
        $categoryId = $params['categoryId'] ?? null;

        if ($categoryId !== null && $categoryId !== '') {
            $list = $this->categoryService->list();
            foreach ($list as $c) {
                if ((string)$c->getId() === (string)$categoryId) {
                    return $c->getName();
                }
            }
        }

        if ($category !== '') {
            try {
                $created = $this->categoryService->create($category);
                return $created->getName();
            } catch (\Throwable) {
                // ignore, use name as-is
            }
        }

        return 'General';
    }

    /**
     * An article must have a real, human-visible title. Blank or whitespace-only
     * input is a hard validation failure — never silently persist "Untitled"
     * articles that pollute lists and search results.
     */
    private function requireTitle(mixed $title): string
    {
        $clean = trim(strip_tags((string)$title));
        if ($clean === '') {
            throw new \OCA\Ticketcheck\Exception\ValidationException(['title']);
        }
        return $clean;
    }

    private function getKbImagesFolder(): ISimpleFolder
    {
        $appData = $this->appDataFactory->get('ticketcheck');
        try {
            return $appData->getFolder(self::KB_IMAGES_FOLDER);
        } catch (NotFoundException) {
            return $appData->newFolder(self::KB_IMAGES_FOLDER);
        }
    }

    private function uniqueImageFilename(string $ext): string
    {
        return sprintf('kb_%s_%s.%s', date('YmdHis'), bin2hex(random_bytes(16)), $ext);
    }

    /**
     * @return list<string>
     */
    private function extractReferencedKbImageFilenames(string $content): array
    {
        if ($content === '') {
            return [];
        }
        if (preg_match_all('/\b(kb_[A-Za-z0-9._-]+\.(?:jpe?g|png|gif|webp))\b/i', $content, $matches) < 1) {
            return [];
        }
        /** @var list<string> $names */
        $names = array_values(array_unique($matches[1]));
        return $names;
    }

    private function gcKbImagesRemovedFromContent(string $before, string $after): void
    {
        $removed = array_diff(
            $this->extractReferencedKbImageFilenames($before),
            $this->extractReferencedKbImageFilenames($after)
        );
        foreach ($removed as $filename) {
            $this->deleteKbImageIfUnreferenced($filename);
        }
    }

    private function deleteKbImageIfUnreferenced(string $filename): void
    {
        if ($this->kbArticleMapper->isFilenameReferencedByAnyArticle($filename)) {
            return;
        }
        try {
            $folder = $this->getKbImagesFolder();
            if (!$folder->fileExists($filename)) {
                return;
            }
            $folder->getFile($filename)->delete();
        } catch (\Throwable $e) {
            // best-effort: GC of unreferenced images; failure leaves an orphan file, not corrupt state
            $this->logger->warning('KB image orphan delete failed', [
                'filename' => $filename,
                'exception' => $e,
            ]);
        }
    }
}

<?php

declare(strict_types=1);

/**
 * Knowledge Base controller — HTTP only, delegates to KnowledgeBaseService
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Exception\ArticleNotFoundException;
use OCA\Ticketcheck\Exception\ValidationException;
use OCA\Ticketcheck\Service\CategoryService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\HtmlSanitizerService;
use OCA\Ticketcheck\Service\KnowledgeBaseService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

class KnowledgeBaseController extends Controller
{
    use CSPTrait;
    use PageRenderTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly KnowledgeBaseService $knowledgeBaseService,
        private readonly PermissionService $permissionService,
        private readonly CategoryService $categoryService,
        private readonly HtmlSanitizerService $htmlSanitizerService,
        private readonly IURLGenerator $urlGenerator,
        private readonly IUserSession $userSession,
        private readonly IFactory $l10nFactory,
        private readonly LoggerInterface $logger,
        private readonly LocaleFormatService $localeFormat,
        private readonly NavigationContextService $navigationContext,
        private readonly FrontEndAssetService $frontEndAssets,
        private readonly TicketService $ticketService,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        if (!$this->isKnowledgeBaseAvailableForCurrentUser()) {
            return $this->kbDisabledTemplateResponse();
        }
        $categoryFilter = (string)($this->request->getParam('category', ''));
        $data = $this->knowledgeBaseService->getIndexData($categoryFilter);
        $l = $this->l10nFactory->get('ticketcheck');
        $mode = $this->permissionService->isGuest() ? 'guest' : 'staff';

        $response = $this->renderAppPage(
            'kb/index',
            [
                'articles' => $data['articles'],
                'categories' => $data['categories'],
                'categoryFilter' => $data['categoryFilter'],
                'canManage' => $data['canManage'],
            ],
            'kb',
            $l->t('knowledge_base'),
            $l->t('help_center_subtitle'),
            'kb',
            $mode,
            ['contextLine' => $l->t('help_center_subtitle')],
        );

        return $this->configureCSPWithNonce($response, 'main');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function kpIndex(): TemplateResponse
    {
        return $this->index();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function article(int $id): TemplateResponse|NotFoundResponse
    {
        if (!$this->isKnowledgeBaseAvailableForCurrentUser()) {
            return $this->kbDisabledTemplateResponse();
        }
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            $article = $this->knowledgeBaseService->getArticleForView($id);
        } catch (ArticleNotFoundException) {
            $response = new TemplateResponse($this->appName, 'error', [
                'message' => $l->t('article_not_found'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $response->setStatus(Http::STATUS_NOT_FOUND);
            return $response;
        }

        $data = $this->knowledgeBaseService->getIndexData('');
        $mode = $this->permissionService->isGuest() ? 'guest' : 'staff';
        $pageTitle = (string)$article->getTitle();

        $response = $this->renderAppPage(
            'kb/article',
            [
                'article' => $article,
                'canManage' => $data['canManage'],
                'kbFeedbackEnabled' => $this->permissionService->isKnowledgeBaseFeedbackEnabled(),
                'kbCommentsEnabled' => $this->permissionService->isKnowledgeBaseCommentsEnabled(),
                'sanitizer' => $this->htmlSanitizerService,
            ],
            'kb-article',
            $pageTitle,
            '',
            'kb-article',
            $mode,
            ['contextLine' => $pageTitle],
        );

        return $this->configureCSPWithNonce($response, 'main');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function kpArticle(int $id): TemplateResponse|NotFoundResponse
    {
        return $this->article($id);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function search(): TemplateResponse
    {
        if (!$this->isKnowledgeBaseAvailableForCurrentUser()) {
            return $this->kbDisabledTemplateResponse();
        }
        $query = (string)($this->request->getParam('q', ''));
        $categoryFilter = trim((string)($this->request->getParam('category', '')));
        $data = $this->knowledgeBaseService->getSearchData($query, $categoryFilter);
        $l = $this->l10nFactory->get('ticketcheck');
        $mode = $this->permissionService->isGuest() ? 'guest' : 'staff';
        $searchLabel = $query !== '' ? $l->t('kb_search_results_for', [$query]) : $l->t('search_knowledge_base');

        $response = $this->renderAppPage(
            'kb/search',
            [
                'query' => $data['query'],
                'articles' => $data['articles'],
                'categories' => $data['categories'],
                'categoryFilter' => $data['categoryFilter'],
                'canManage' => $data['canManage'],
            ],
            'kb-search',
            $l->t('search_knowledge_base'),
            $searchLabel,
            'kb-search',
            $mode,
            ['contextLine' => $searchLabel],
        );

        return $this->configureCSPWithNonce($response, 'main');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function kpSearch(): TemplateResponse
    {
        return $this->search();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(): TemplateResponse|NotFoundResponse
    {
        if (!$this->isKnowledgeBaseAvailableForCurrentUser()) {
            return $this->kbDisabledTemplateResponse();
        }
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageKnowledgeBase()) {
            $response = new TemplateResponse($this->appName, 'error', [
                'message' => $l->t('permission_denied'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $response->setStatus(Http::STATUS_FORBIDDEN);
            return $response;
        }

        $this->categoryService->ensureDefaultExists();

        $response = $this->renderAppPage(
            'kb/form',
            [
                'article' => null,
                'isEdit' => false,
                'kbCommentsEnabled' => $this->permissionService->isKnowledgeBaseCommentsEnabled(),
                'categories' => $this->categoryService->list(),
                'sanitizer' => $this->htmlSanitizerService,
            ],
            'kb-form',
            $l->t('create_knowledge_base_article'),
            $l->t('create_knowledge_base_article'),
            'kb-form',
            'staff',
            ['contextLine' => $l->t('create_knowledge_base_article')],
        );

        return $this->configureCSPWithNonce($response, 'main');
    }

    #[NoAdminRequired]
    public function store(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->isKnowledgeBaseAvailableForCurrentUser()) {
            return new JSONResponse(['error' => $l->t('knowledge_base_disabled_message')], Http::STATUS_FORBIDDEN);
        }
        if (!$this->permissionService->canManageKnowledgeBase()) {
            return new JSONResponse(['error' => $l->t('permission_denied')], Http::STATUS_FORBIDDEN);
        }

        try {
            $params = [
                'title' => $this->request->getParam('title'),
                'content' => $this->request->getParam('content'),
                'category' => $this->request->getParam('category'),
                'categoryId' => $this->request->getParam('categoryId'),
                'published' => $this->request->getParam('published', false),
                'pinned' => $this->request->getParam('pinned', false),
                'allow_comments' => $this->request->getParam('allow_comments', false),
            ];
            if (!$this->permissionService->isKnowledgeBaseCommentsEnabled()) {
                $params['allow_comments'] = false;
            }
            $saved = $this->knowledgeBaseService->createArticle($params);
            return new JSONResponse([
                'success' => true,
                'article' => ['id' => $saved->getId()],
            ], Http::STATUS_CREATED);
        } catch (ValidationException $e) {
            return new JSONResponse([
                'error' => $l->t('validation_failed'),
                'fields' => array_fill_keys($e->getErrors(), $l->t('field_required')),
            ], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('Knowledge base create failed', ['exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function edit(int $id): TemplateResponse|NotFoundResponse
    {
        if (!$this->isKnowledgeBaseAvailableForCurrentUser()) {
            return $this->kbDisabledTemplateResponse();
        }
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageKnowledgeBase()) {
            $response = new TemplateResponse($this->appName, 'error', [
                'message' => $l->t('permission_denied'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $response->setStatus(Http::STATUS_FORBIDDEN);
            return $response;
        }

        try {
            $article = $this->knowledgeBaseService->getArticle($id);
        } catch (ArticleNotFoundException) {
            $response = new TemplateResponse($this->appName, 'error', [
                'message' => $l->t('article_not_found'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $response->setStatus(Http::STATUS_NOT_FOUND);
            return $response;
        }

        $pageTitle = (string)$article->getTitle();

        $response = $this->renderAppPage(
            'kb/form',
            [
                'article' => $article,
                'isEdit' => true,
                'kbCommentsEnabled' => $this->permissionService->isKnowledgeBaseCommentsEnabled(),
                'categories' => $this->categoryService->list(),
                'sanitizer' => $this->htmlSanitizerService,
            ],
            'kb-form',
            $l->t('update_knowledge_base_article'),
            $l->t('update_knowledge_base_article'),
            'kb-form',
            'staff',
            ['contextLine' => $pageTitle],
        );

        return $this->configureCSPWithNonce($response, 'main');
    }

    #[NoAdminRequired]
    public function update(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->isKnowledgeBaseAvailableForCurrentUser()) {
            return new JSONResponse(['error' => $l->t('knowledge_base_disabled_message')], Http::STATUS_FORBIDDEN);
        }
        if (!$this->permissionService->canManageKnowledgeBase()) {
            return new JSONResponse(['error' => $l->t('permission_denied')], Http::STATUS_FORBIDDEN);
        }

        try {
            $params = [
                'title' => $this->request->getParam('title'),
                'content' => $this->request->getParam('content'),
                'category' => $this->request->getParam('category'),
                'categoryId' => $this->request->getParam('categoryId'),
                'published' => $this->request->getParam('published', false),
                'pinned' => $this->request->getParam('pinned', false),
                'allow_comments' => $this->request->getParam('allow_comments', false),
            ];
            if (!$this->permissionService->isKnowledgeBaseCommentsEnabled()) {
                $params['allow_comments'] = false;
            }
            $article = $this->knowledgeBaseService->updateArticle($id, $params);
            return new JSONResponse([
                'success' => true,
                'article' => ['id' => $article->getId()],
            ]);
        } catch (ArticleNotFoundException) {
            return new JSONResponse(['error' => $l->t('article_not_found')], Http::STATUS_NOT_FOUND);
        } catch (ValidationException $e) {
            return new JSONResponse([
                'error' => $l->t('validation_failed'),
                'fields' => array_fill_keys($e->getErrors(), $l->t('field_required')),
            ], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('Knowledge base update failed', ['exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function markHelpful(int $id, bool $publishedOnly = false): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->isKnowledgeBaseAvailableForCurrentUser() || !$this->permissionService->isKnowledgeBaseFeedbackEnabled()) {
            return new JSONResponse(['error' => $l->t('knowledge_base_feedback_disabled')], Http::STATUS_FORBIDDEN);
        }
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $l->t('authentication_required')], Http::STATUS_UNAUTHORIZED);
        }

        $helpful = $this->request->getParam('helpful', true);
        if ($helpful !== true && $helpful !== 'true' && $helpful !== 1) {
            return new JSONResponse(['success' => true]);
        }

        try {
            $alreadyVoted = !$this->knowledgeBaseService->markHelpful($id, $user->getUID(), $publishedOnly);
            return new JSONResponse([
                'success' => true,
                'already_voted' => $alreadyVoted,
            ]);
        } catch (ArticleNotFoundException) {
            return new JSONResponse(['error' => $l->t('article_not_found')], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function portalMarkHelpful(int $id): JSONResponse
    {
        if (!$this->permissionService->isGuest()) {
            return $this->markHelpful($id, true);
        }
        return $this->runGuestKbAction(fn (): JSONResponse => $this->markHelpful($id, true));
    }

    #[NoAdminRequired]
    public function delete(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->isKnowledgeBaseAvailableForCurrentUser()) {
            return new JSONResponse(['error' => $l->t('knowledge_base_disabled_message')], Http::STATUS_FORBIDDEN);
        }
        if (!$this->permissionService->canManageKnowledgeBase()) {
            return new JSONResponse(['error' => $l->t('permission_denied')], Http::STATUS_FORBIDDEN);
        }

        try {
            $this->knowledgeBaseService->deleteArticle($id);
            return new JSONResponse(['success' => true]);
        } catch (ArticleNotFoundException) {
            return new JSONResponse(['error' => $l->t('article_not_found')], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function uploadImage(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->isKnowledgeBaseAvailableForCurrentUser()) {
            return new JSONResponse(['error' => $l->t('knowledge_base_disabled_message')], Http::STATUS_FORBIDDEN);
        }
        if (!$this->permissionService->canManageKnowledgeBase()) {
            return new JSONResponse(['error' => $l->t('permission_denied')], Http::STATUS_FORBIDDEN);
        }

        $file = $_FILES['image'] ?? null;
        if (!$file || !is_array($file)) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('no_image_uploaded'),
            ], Http::STATUS_BAD_REQUEST);
        }

        $result = $this->knowledgeBaseService->uploadImage($file);
        if (!$result['success']) {
            $result['message'] = $l->t($result['messageKey'] ?? 'an_error_occurred');
            unset($result['messageKey']);
            return new JSONResponse($result, Http::STATUS_BAD_REQUEST);
        }

        $imageUrl = $this->urlGenerator->linkToRoute('ticketcheck.knowledgeBase.getImage', [
            'filename' => $result['filename'],
        ]);
        $result['imageUrl'] = $imageUrl;
        $result['message'] = $l->t($result['messageKey'] ?? 'image_uploaded_successfully');
        unset($result['messageKey']);
        return new JSONResponse($result);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getImage(string $filename): DataDisplayResponse|NotFoundResponse
    {
        if (!$this->isKnowledgeBaseAvailableForCurrentUser()) {
            return new NotFoundResponse();
        }
        // Guests and non-managers only see images referenced by published articles.
        // Managers may preview freshly uploaded images before the article is saved.
        $requirePublishedReference = !$this->permissionService->canManageKnowledgeBase();
        $content = $this->knowledgeBaseService->getImageContent($filename, $requirePublishedReference);
        if ($content === null) {
            return new NotFoundResponse();
        }
        $mimeType = $this->knowledgeBaseService->getImageMimeType($filename);
        $response = new DataDisplayResponse($content);
        $response->addHeader('Content-Type', $mimeType);
        $response->addHeader('X-Content-Type-Options', 'nosniff');
        // Auth-gated content must never be cached by shared proxies.
        $response->addHeader('Cache-Control', 'private, no-store, must-revalidate');
        $response->addHeader('Pragma', 'no-cache');
        return $response;
    }

    #[NoAdminRequired]
    public function addComment(int $id, bool $publishedOnly = false): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->isKnowledgeBaseAvailableForCurrentUser() || !$this->permissionService->isKnowledgeBaseCommentsEnabled()) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('knowledge_base_comments_disabled'),
            ], Http::STATUS_FORBIDDEN);
        }
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('authentication_required'),
            ], Http::STATUS_UNAUTHORIZED);
        }

        $content = $this->request->getParam('content');
        if ($content === null || trim((string)$content) === '') {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('comment_content_required'),
            ], Http::STATUS_BAD_REQUEST);
        }

        try {
            $comment = $this->knowledgeBaseService->addComment(
                $id,
                trim((string)$content),
                $user->getUID(),
                $user->getDisplayName() ?? $user->getUID(),
                $user->getEMailAddress() ?? '',
                $publishedOnly
            );
            return new JSONResponse([
                'success' => true,
                'message' => $l->t('comment_added'),
                'comment' => [
                    'id' => $comment->getId(),
                    'author' => $comment->getAuthorName(),
                    'content' => $comment->getContent(),
                    'created_at' => $comment->getCreatedAt()->format('Y-m-d H:i:s'),
                ],
            ]);
        } catch (ArticleNotFoundException) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('article_not_found'),
            ], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage() === 'Comments are not allowed on this article'
                ? $l->t('comments_not_allowed')
                : $e->getMessage();
            return new JSONResponse([
                'success' => false,
                'message' => $message,
            ], $e->getMessage() === 'Comments are not allowed on this article' ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('KB add comment failed', ['exception' => $e]);
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('an_error_occurred'),
            ], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function portalAddComment(int $id): JSONResponse
    {
        if (!$this->permissionService->isGuest()) {
            return $this->addComment($id, true);
        }
        return $this->runGuestKbAction(fn (): JSONResponse => $this->addComment($id, true));
    }

    #[NoAdminRequired]
    public function getComments(int $id, bool $publishedOnly = false): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->isKnowledgeBaseAvailableForCurrentUser() || !$this->permissionService->isKnowledgeBaseCommentsEnabled()) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('knowledge_base_comments_disabled'),
            ], Http::STATUS_FORBIDDEN);
        }
        try {
            $comments = $this->knowledgeBaseService->getComments($id, $publishedOnly);
            return new JSONResponse([
                'success' => true,
                'comments' => $comments,
            ]);
        } catch (ArticleNotFoundException) {
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('article_not_found'),
            ], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('KB get comments failed', ['exception' => $e]);
            return new JSONResponse([
                'success' => false,
                'message' => $l->t('an_error_occurred'),
            ], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function portalGetComments(int $id): JSONResponse
    {
        return $this->getComments($id, true);
    }

    private function isKnowledgeBaseAvailableForCurrentUser(): bool
    {
        return $this->permissionService->isKnowledgeBaseIndexAvailable();
    }

    /**
     * Guests share the portal daily activity ceiling for KB comments/votes.
     * Rate check + mutation run under the same exclusive per-user gate.
     *
     * @param callable(): JSONResponse $action
     */
    private function runGuestKbAction(callable $action): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $action();
        }

        $userId = $user->getUID();
        $rateLimited = false;
        try {
            $result = $this->ticketService->withGuestActivityGate($userId, function () use ($userId, &$rateLimited, $action) {
                $since = new \DateTime('-24 hours');
                $recent = $this->ticketService->countRecentPortalActionsByUser($userId, $since)
                    + $this->knowledgeBaseService->countRecentPortalKbActionsByUser($userId, $since);
                if (!$this->permissionService->checkRateLimit($recent)) {
                    $rateLimited = true;
                    return null;
                }
                return $action();
            });
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse([
                'success' => false,
                'error' => $l->t('too_many_requests'),
                'message' => $l->t('too_many_requests'),
            ], Http::STATUS_TOO_MANY_REQUESTS);
        }

        if ($rateLimited || !$result instanceof JSONResponse) {
            return new JSONResponse([
                'success' => false,
                'error' => $l->t('too_many_requests'),
                'message' => $l->t('too_many_requests'),
            ], Http::STATUS_TOO_MANY_REQUESTS);
        }

        return $result;
    }

    private function kbDisabledTemplateResponse(): TemplateResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        $response = new TemplateResponse($this->appName, 'error', [
            'message' => $l->t('knowledge_base_disabled_message'),
            'l' => $l,
            'urlGenerator' => $this->urlGenerator,
        ]);
        $response->setStatus(Http::STATUS_FORBIDDEN);
        return $response;
    }

    protected function getFrontEndAssetService(): FrontEndAssetService
    {
        return $this->frontEndAssets;
    }

    protected function getPermissionService(): PermissionService
    {
        return $this->permissionService;
    }

    protected function getUrlGenerator(): IURLGenerator
    {
        return $this->urlGenerator;
    }

    protected function getLocaleFormatService(): LocaleFormatService
    {
        return $this->localeFormat;
    }

    protected function getPageL10n(): IL10N
    {
        return $this->l10nFactory->get($this->appName);
    }

    protected function getNavigationContextService(): NavigationContextService
    {
        return $this->navigationContext;
    }
}

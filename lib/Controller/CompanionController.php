<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\AppInfo\Application;
use OCA\Ticketcheck\Exception\CompanionConflictException;
use OCA\Ticketcheck\Exception\CompanionNotFoundException;
use OCA\Ticketcheck\Exception\ArticleNotFoundException;
use OCA\Ticketcheck\Exception\CompanionUnauthorizedException;
use OCA\Ticketcheck\Exception\CompanionValidationException;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\CompanionGateService;
use OCA\Ticketcheck\Service\CompanionPushService;
use OCA\Ticketcheck\Service\CompanionTicketService;
use OCA\Ticketcheck\Service\IdempotencyService;
use OCA\Ticketcheck\Service\KnowledgeBaseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Companion mobile JSON API (/companion/api/v1/*).
 * License/role gates: ClientLicenseMiddleware. CSRF waived for app-password clients.
 */
class CompanionController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly CompanionGateService $gate,
		private readonly CompanionTicketService $tickets,
		private readonly IConfig $config,
		private readonly AttachmentUploadService $attachmentUpload,
		private readonly AttachmentDeliveryService $attachmentDelivery,
		private readonly IdempotencyService $idempotency,
		private readonly CompanionPushService $push,
		private readonly KnowledgeBaseService $knowledgeBase,
		private readonly ?\OCA\Ticketcheck\Service\CommentLifecycleService $commentLifecycle = null,
		private readonly ?\OCA\Ticketcheck\Service\TemplateService $templates = null,
		private readonly ?\OCP\L10N\IFactory $l10nFactory = null,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function bootstrap(): JSONResponse
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error('NOT_AUTHENTICATED', Http::STATUS_UNAUTHORIZED);
		}
		$version = $this->config->getAppValue(Application::APP_ID, 'installed_version', '0.0.0');
		return new JSONResponse($this->gate->bootstrapPayload($user->getUID(), $user->getDisplayName(), $version));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function inbox(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$queue = (string)$this->request->getParam('queue', 'open');
			$cursor = $this->request->getParam('cursor');
			$cursor = is_string($cursor) ? $cursor : null;
			$limit = (int)$this->request->getParam('limit', 25);
			$projectId = $this->optionalPositiveInt('projectId');
			$priority = $this->request->getParam('priority');
			$priority = is_string($priority) && $priority !== '' ? $priority : null;
			$result = $this->tickets->inbox($uid, $queue, $cursor, $limit, $projectId, $priority);
			return new JSONResponse(['ok' => true] + $result);
		} catch (CompanionValidationException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_UNPROCESSABLE_ENTITY, $e->getMessage());
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function filterOptions(): JSONResponse
	{
		$this->requireUid();
		return new JSONResponse(['ok' => true] + $this->tickets->filterOptions());
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function queueCounts(): JSONResponse
	{
		$uid = $this->requireUid();
		return new JSONResponse(['ok' => true, 'counts' => $this->tickets->queueCounts($uid)]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			return new JSONResponse(['ok' => true] + $this->tickets->detail($id, $uid));
		} catch (CompanionNotFoundException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$projectId = $this->optionalPositiveInt('projectId');
			$title = (string)$this->request->getParam('title', '');
			$description = (string)$this->request->getParam('description', '');
			$priority = (string)$this->request->getParam('priority', \OCA\Ticketcheck\Db\Ticket::PRIORITY_NORMAL);
			$categoryParam = $this->request->getParam('category');
			$resourcesParam = $this->request->getParam('resources');
			$category = is_string($categoryParam) && trim($categoryParam) !== '' ? trim($categoryParam) : null;
			// Optional idempotency: offline-queue flush / lost-200 retry with the
			// same key (body idempotencyKey or X-TC-Idempotency-Key) returns the
			// original ticket instead of a duplicate row. Scope includes project
			// so keys cannot collide across projects (mobilitycheck convention).
			$result = $this->idempotency->run(
				$uid,
				'companion.ticket.create.' . ($projectId !== null && $projectId > 0 ? (string)$projectId : 'noproject'),
				$this->idempotencyKey(),
				fn (): array => $this->tickets->create($uid, $projectId, $title, $description, $priority, $category, is_array($resourcesParam) ? array_values(array_filter($resourcesParam, 'is_string')) : null),
			);
			return new JSONResponse(['ok' => true] + $result, Http::STATUS_CREATED);
		} catch (CompanionConflictException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_CONFLICT, $e->getMessage());
		} catch (CompanionValidationException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_UNPROCESSABLE_ENTITY, $e->getMessage());
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function bulkStatus(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$ticketIds = $this->request->getParam('ticketIds', []);
			if (!is_array($ticketIds)) {
				return $this->error('invalid_ticket_ids', Http::STATUS_UNPROCESSABLE_ENTITY);
			}
			$status = (string)$this->request->getParam('status', '');
			$versions = $this->parseVersionsMap($this->request->getParam('versions', []));
			$result = $this->tickets->bulkStatus($uid, $ticketIds, $status, $versions);
			return new JSONResponse(['ok' => true] + $result);
		} catch (CompanionValidationException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_UNPROCESSABLE_ENTITY, $e->getMessage());
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function watch(int $id): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$watching = filter_var($this->request->getParam('watching', true), FILTER_VALIDATE_BOOLEAN);
			$version = $this->requireVersion();
			$result = $this->tickets->setWatching($id, $uid, $watching, $version);
			return new JSONResponse(['ok' => true] + $result);
		} catch (CompanionNotFoundException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_NOT_FOUND);
		} catch (CompanionConflictException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_CONFLICT, $e->getMessage());
		} catch (CompanionValidationException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_UNPROCESSABLE_ENTITY, $e->getMessage());
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function downloadAttachment(int $id, int $attachmentId): \OCP\AppFramework\Http\Response
	{
		try {
			$this->requireUid();
			$attachment = $this->tickets->attachmentForDownload($id, $attachmentId);
		} catch (CompanionNotFoundException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_NOT_FOUND);
		}

		$inline = $this->request->getParam('inline') === '1';
		$result = $this->attachmentDelivery->buildStreamResponse($attachment, $id, $inline);
		if (!($result['ok'] ?? false)) {
			return $this->error('attachment_unavailable', Http::STATUS_NOT_FOUND);
		}
		return $result['response'];
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function uploadAttachment(int $id): JSONResponse
	{
		try {
			$this->requireUid();
			$file = $_FILES['file'] ?? null;
			if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
				return $this->error('no_file_uploaded', Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			$maxBytes = (int)$this->config->getAppValue(Application::APP_ID, 'max_attachment_size', (string)(10 * 1024 * 1024));
			if ($maxBytes < 1) {
				$maxBytes = 10 * 1024 * 1024;
			}
			if ((int)($file['size'] ?? 0) > $maxBytes) {
				return $this->error('attachment_too_large', Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
			}

			$validation = $this->attachmentUpload->validateUploadedFile($file);
			if (($validation['success'] ?? false) !== true) {
				return $this->error('invalid_attachment', Http::STATUS_UNPROCESSABLE_ENTITY, (string)($validation['message'] ?? ''));
			}
			$mimeType = (string)($validation['mimeType'] ?? '');
			$originalName = (string)($validation['originalName'] ?? '');
			if ($mimeType === '' || $originalName === '') {
				return $this->error('invalid_attachment', Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			$result = $this->tickets->addUploadedAttachment(
				$id,
				(string)$file['tmp_name'],
				$originalName,
				(int)$file['size'],
				$mimeType,
				$this->requireVersion(),
			);
			return new JSONResponse(['ok' => true] + $result, Http::STATUS_CREATED);
		} catch (CompanionNotFoundException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_NOT_FOUND);
		} catch (CompanionConflictException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_CONFLICT, $e->getMessage());
		} catch (CompanionValidationException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_UNPROCESSABLE_ENTITY, $e->getMessage());
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function addComment(int $id): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$body = (string)$this->request->getParam('body', '');
			$visibility = (string)$this->request->getParam('visibility', 'public');
			$version = $this->requireVersion();
			$result = $this->tickets->addComment($id, $uid, $body, $visibility, $version);
			return new JSONResponse(['ok' => true] + $result);
		} catch (CompanionNotFoundException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_NOT_FOUND);
		} catch (CompanionConflictException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_CONFLICT, $e->getMessage());
		} catch (CompanionValidationException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_UNPROCESSABLE_ENTITY, $e->getMessage());
		}
	}

	/**
	 * Companion comment update (edit body and/or visibility toggle).
	 * version is required — the CAS check runs inside the lifecycle lock so
	 * stale offline replays fail closed (spec MH-C05).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function updateComment(int $id, int $cid): JSONResponse
	{
		try {
			$this->requireUid();
			if ($this->commentLifecycle === null) {
				return $this->error('not_supported', Http::STATUS_NOT_IMPLEMENTED);
			}
			$version = $this->requireVersion();
			$bodyRaw = $this->request->getParam('body');
			$body = $bodyRaw === null ? null : (string)$bodyRaw;
			$visibilityRaw = $this->request->getParam('visibility');
			$isInternal = null;
			if ($visibilityRaw !== null) {
				if (!in_array($visibilityRaw, ['public', 'internal'], true)) {
					throw new CompanionValidationException('invalid_visibility', 'visibility must be public or internal');
				}
				$isInternal = $visibilityRaw === 'internal';
			}
			$comment = $this->commentLifecycle->updateComment(
				$id,
				$cid,
				$body,
				$isInternal,
				fn (\OCA\Ticketcheck\Db\Ticket $t) => $this->tickets->assertEditableAtVersion($t, $version)
			);
			$fresh = $this->tickets->requireTicketForRead($id);
			return new JSONResponse([
				'ok' => true,
				'comment' => $this->tickets->serializeCommentData($comment, $fresh),
				'ticket' => ['id' => $id, 'version' => $this->tickets->ticketVersion($fresh)],
			]);
		} catch (\OCA\Ticketcheck\Exception\CommentLifecycleException $e) {
			return $this->lifecycleError($e);
		} catch (CompanionUnauthorizedException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_UNAUTHORIZED);
		} catch (CompanionNotFoundException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_NOT_FOUND);
		} catch (CompanionConflictException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_CONFLICT, $e->getMessage());
		} catch (CompanionValidationException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_UNPROCESSABLE_ENTITY, $e->getMessage());
		}
	}

	/**
	 * Companion comment delete (soft delete + bound attachment wipe). No
	 * purge route in companion v1 — staff-web only (spec §C).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function deleteComment(int $id, int $cid): JSONResponse
	{
		try {
			$this->requireUid();
			if ($this->commentLifecycle === null) {
				return $this->error('not_supported', Http::STATUS_NOT_IMPLEMENTED);
			}
			$version = $this->requireVersion();
			$this->commentLifecycle->softDeleteComment(
				$id,
				$cid,
				fn (\OCA\Ticketcheck\Db\Ticket $t) => $this->tickets->assertEditableAtVersion($t, $version)
			);
			$fresh = $this->tickets->requireTicketForRead($id);
			return new JSONResponse([
				'ok' => true,
				'ticket' => ['id' => $id, 'version' => $this->tickets->ticketVersion($fresh)],
			]);
		} catch (\OCA\Ticketcheck\Exception\CommentLifecycleException $e) {
			return $this->lifecycleError($e);
		} catch (CompanionUnauthorizedException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_UNAUTHORIZED);
		} catch (CompanionNotFoundException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_NOT_FOUND);
		} catch (CompanionConflictException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_CONFLICT, $e->getMessage());
		} catch (CompanionValidationException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_UNPROCESSABLE_ENTITY, $e->getMessage());
		}
	}

	/**
	 * Companion selective move — same lifecycle service as staff-web; the
	 * idempotency key is mandatory (offline queues can re-fire).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function moveComments(int $id): JSONResponse
	{
		try {
			$this->requireUid();
			if ($this->commentLifecycle === null) {
				return $this->error('not_supported', Http::STATUS_NOT_IMPLEMENTED);
			}
			$targetId = $this->optionalPositiveInt('targetTicketId');
			if ($targetId === null) {
				throw new CompanionValidationException('invalid_target', 'targetTicketId is required');
			}
			$commentIds = $this->idListParam('commentIds');
			$attachmentIds = $this->idListParam('attachmentIds');
			// The key is mandatory on this route: offline queues re-fire, and a
			// move is not retry-safe without dedup (spec MH-C07).
			$idemKey = $this->idempotencyKey();
			if ($idemKey === null) {
				throw new CompanionValidationException('idempotency_key_required', 'idempotencyKey is required');
			}
			$receipt = $this->commentLifecycle->moveCommentsAndAttachments(
				$id,
				$targetId,
				$commentIds,
				$attachmentIds,
				$idemKey
			);
			return new JSONResponse(['ok' => true] + [
				'batchId' => $receipt['batch_id'],
				'commentsMoved' => $receipt['comments_moved'],
				'attachmentsMoved' => $receipt['attachments_moved'],
				'targetTicketId' => $receipt['target_ticket_id'],
			]);
		} catch (\OCA\Ticketcheck\Exception\CommentLifecycleException $e) {
			return $this->lifecycleError($e);
		} catch (CompanionUnauthorizedException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_UNAUTHORIZED);
		} catch (CompanionConflictException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_CONFLICT, $e->getMessage());
		} catch (CompanionValidationException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_UNPROCESSABLE_ENTITY, $e->getMessage());
		}
	}

	/**
	 * Companion move-target search — editable, unmerged, non-source tickets.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function moveTargets(): JSONResponse
	{
		try {
			$this->requireUid();
			$sourceId = $this->optionalPositiveInt('sourceTicketId');
			$query = trim((string)$this->request->getParam('query', ''));
			return new JSONResponse([
				'ok' => true,
				'items' => $this->tickets->searchMoveTargets($sourceId, $query),
			]);
		} catch (CompanionUnauthorizedException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_UNAUTHORIZED);
		} catch (CompanionValidationException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_UNPROCESSABLE_ENTITY, $e->getMessage());
		}
	}

	/**
	 * Map lifecycle failures onto the companion error envelope — the codes
	 * are stable contract values the app keys its UX on (spec §C wire codes).
	 */
	private function lifecycleError(\OCA\Ticketcheck\Exception\CommentLifecycleException $e): JSONResponse
	{
		$status = match ($e->getHttpStatus()) {
			404 => Http::STATUS_NOT_FOUND,
			403 => Http::STATUS_FORBIDDEN,
			409 => Http::STATUS_CONFLICT,
			default => Http::STATUS_BAD_REQUEST,
		};
		return $this->error($e->getErrorCode(), $status, $e->getMessage() !== '' ? $e->getMessage() : $e->getErrorCode());
	}

	/**
	 * @return array<int, int>
	 */
	private function idListParam(string $param): array
	{
		$raw = $this->request->getParam($param);
		if (is_string($raw)) {
			$raw = $raw === '' ? [] : explode(',', $raw);
		}
		if (!is_array($raw)) {
			return [];
		}
		$out = [];
		foreach ($raw as $value) {
			if (is_int($value) && $value > 0) {
				$out[] = $value;
			} elseif (is_string($value) && ctype_digit($value) && (int)$value > 0) {
				$out[] = (int)$value;
			}
		}
		return array_values(array_unique($out));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function changeStatus(int $id): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$status = (string)$this->request->getParam('status', '');
			$version = $this->requireVersion();
			$result = $this->tickets->changeStatus($id, $uid, $status, $version);
			return new JSONResponse(['ok' => true] + $result);
		} catch (CompanionNotFoundException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_NOT_FOUND);
		} catch (CompanionConflictException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_CONFLICT, $e->getMessage());
		} catch (CompanionValidationException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_UNPROCESSABLE_ENTITY, $e->getMessage());
		} catch (\Exception $e) {
			if (str_contains($e->getMessage(), 'Invalid status')) {
				return $this->error('STATUS_TRANSITION_DENIED', Http::STATUS_UNPROCESSABLE_ENTITY, $e->getMessage());
			}
			throw $e;
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function assign(int $id): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$assignee = $this->request->getParam('assignedTo');
			if ($assignee === null) {
				$assignee = $this->request->getParam('assignee');
			}
			if ($assignee === null) {
				$assignee = $this->request->getParam('userId');
			}
			if (is_string($assignee) && $assignee === '') {
				$assignee = null;
			}
			if ($assignee !== null && !is_string($assignee)) {
				return $this->error('invalid_assignee', Http::STATUS_UNPROCESSABLE_ENTITY);
			}
			$version = $this->requireVersion();
			$result = $this->tickets->assign($id, $uid, $assignee, $version);
			return new JSONResponse(['ok' => true] + $result);
		} catch (CompanionNotFoundException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_NOT_FOUND);
		} catch (CompanionConflictException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_CONFLICT, $e->getMessage());
		} catch (CompanionValidationException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_UNPROCESSABLE_ENTITY, $e->getMessage());
		} catch (\Exception $e) {
			if (str_contains(strtolower($e->getMessage()), 'assign')) {
				return $this->error('invalid_assignee', Http::STATUS_UNPROCESSABLE_ENTITY, $e->getMessage());
			}
			throw $e;
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function assignableUsers(int $id): JSONResponse
	{
		try {
			$this->requireUid();
			$query = (string)$this->request->getParam('query', '');
			$limit = (int)$this->request->getParam('limit', 25);
			$users = $this->tickets->assignableUsers($id, $query, $limit);
			return new JSONResponse(['ok' => true, 'users' => $users]);
		} catch (CompanionNotFoundException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * WP-113 (Gate G-KB): read-only agent KB. Search over published articles
	 * (or all when the agent may manage KB — same rule as the web surface).
	 * No CRUD, no comments, no portal KB.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function kbSearch(): JSONResponse
	{
		$this->requireUid();
		$query = (string)$this->request->getParam('q', '');
		$category = (string)$this->request->getParam('category', '');
		$data = $this->knowledgeBase->getSearchData($query, $category);
		$articles = is_array($data['articles'] ?? null) ? $data['articles'] : [];
		$items = array_map(
			static function (\OCA\Ticketcheck\Db\KBArticle $a): array {
				return [
					'id' => $a->getId(),
					'title' => $a->getTitle(),
					'category' => $a->getCategory() ?: 'General',
					'excerpt' => self::excerpt($a->getContent()),
					'pinned' => (bool)$a->getPinned(),
					'updatedAt' => $a->getUpdatedAt() instanceof \DateTimeInterface
						? $a->getUpdatedAt()->format('c')
						: null,
				];
			},
			$articles,
		);
		return new JSONResponse([
			'ok' => true,
			'items' => $items,
			'categories' => array_keys(is_array($data['categories'] ?? null) ? $data['categories'] : []),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function kbArticle(int $id): JSONResponse
	{
		$this->requireUid();
		try {
			$article = $this->knowledgeBase->getArticleForView($id);
		} catch (ArticleNotFoundException) {
			// Uniform 404 — no existence oracle (same rule as ticket_not_found).
			return $this->error('kb_article_not_found', Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse([
			'ok' => true,
			'article' => [
				'id' => $article->getId(),
				'title' => $article->getTitle(),
				'category' => $article->getCategory() ?: 'General',
				'content' => $article->getContent(),
				'pinned' => (bool)$article->getPinned(),
				'views' => $article->getViews(),
				'updatedAt' => $article->getUpdatedAt() instanceof \DateTimeInterface
					? $article->getUpdatedAt()->format('c')
					: null,
			],
		]);
	}

	private static function excerpt(string $content, int $max = 160): string
	{
		$text = trim((string)preg_replace('/\s+/u', ' ', strip_tags($content)));
		if ($text === '') {
			return '';
		}
		if (mb_strlen($text) <= $max) {
			return $text;
		}
		return rtrim(mb_substr($text, 0, $max)) . '…';
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pushRegister(): JSONResponse
	{
		$uid = $this->requireUid();
		$pushToken = trim((string)$this->request->getParam('pushToken', ''));
		$platform = (string)$this->request->getParam('platform', '');
		$deviceId = trim((string)$this->request->getParam('deviceId', ''));
		if ($pushToken === '') {
			// Legacy clients send deviceId only for correlation. Without a push
			// token there is nothing to register — answer honestly instead of
			// claiming success (capabilities.pushAvailable stays the source of
			// truth for whether delivery is enabled).
			if ($deviceId === '' || strlen($deviceId) > 255) {
				return $this->error('invalid_device', Http::STATUS_UNPROCESSABLE_ENTITY);
			}
			return new JSONResponse([
				'ok' => true,
				'registered' => false,
				'reason' => 'push_token_required',
			]);
		}
		try {
			$this->push->register($uid, $pushToken, $platform, $deviceId);
		} catch (CompanionValidationException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_UNPROCESSABLE_ENTITY, $e->getMessage());
		}
		return new JSONResponse([
			'ok' => true,
			'registered' => true,
			'delivery' => $this->push->isFeatureEnabled() ? 'enabled' : 'deferred',
		]);
	}

	/**
	 * Canned replies — metadata list only (TC-COMP-QUICKACT MH-06).
	 * Content is fetched per-template via processTemplate so the list stays
	 * cheap and a deleted row cannot ship a stale body (AC-B01-1).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function templates(): JSONResponse
	{
		$this->requireUid();
		if ($this->templates === null) {
			return $this->error('not_supported', Http::STATUS_NOT_IMPLEMENTED);
		}
		$items = [];
		foreach ($this->templates->getAllTemplates() as $row) {
			if (!is_array($row)) {
				continue;
			}
			$category = trim((string)($row['category'] ?? ''));
			$items[] = [
				'id' => (int)($row['id'] ?? 0),
				'name' => (string)($row['name'] ?? ''),
				'category' => $category !== '' ? $category : null,
			];
		}
		return new JSONResponse(['ok' => true, 'items' => $items]);
	}

	/**
	 * Render a canned reply for a ticket — all {{vars}} are derived
	 * server-side from the ticket + acting agent (NN-07: a caller-supplied
	 * `variables` map is never read; spoofed customer_name/agent_name is the
	 * injection vector OC-2 exists to prevent). Unknown template and
	 * invisible ticket collapse to the SAME uniform-404 body (TH-01).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function processTemplate(int $id): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			if ($this->templates === null) {
				return $this->error('not_supported', Http::STATUS_NOT_IMPLEMENTED);
			}
			// Accept int JSON and digit-string form posts alike (same
			// coercion convention as the other companion endpoints).
			$ticketRaw = $this->request->getParam('ticket_id');
			$ticketId = is_int($ticketRaw)
				? $ticketRaw
				: (is_string($ticketRaw) && ctype_digit($ticketRaw) ? (int)$ticketRaw : 0);
			if ($ticketId < 1) {
				return $this->error('invalid_ticket_id', Http::STATUS_UNPROCESSABLE_ENTITY);
			}
			$template = $this->templates->getTemplate($id);
			if (!is_array($template) || (int)($template['is_active'] ?? 0) !== 1) {
				return $this->error('template_not_found', Http::STATUS_NOT_FOUND);
			}
			// A deleted/inactive template and an invisible ticket must produce
			// the SAME body — the pair is never a two-step oracle.
			try {
				$ticket = $this->tickets->requireTicketForRead($ticketId);
			} catch (CompanionNotFoundException) {
				// Same body as an unknown template — process is not a
				// ticket-existence oracle (spec §9.3).
				return $this->error('template_not_found', Http::STATUS_NOT_FOUND);
			}
			$statusKey = \OCA\Ticketcheck\Db\Ticket::normalizeStatus($ticket->getStatus())
				?? (string)$ticket->getStatus();
			$variables = [
				'ticket_number' => (string)$ticket->getTicketNumber(),
				'title' => (string)$ticket->getTitle(),
				'customer_name' => $ticket->getCustomerName() !== ''
					? $ticket->getCustomerName()
					: (string)$ticket->getCustomerEmail(),
				'customer_email' => (string)$ticket->getCustomerEmail(),
				'agent_name' => trim((string)($this->userSession->getUser()?->getDisplayName() ?? '')) !== ''
					? (string)$this->userSession->getUser()->getDisplayName()
					: $uid,
				'status' => $this->l10nFactory !== null
					? $this->l10nFactory->get(Application::APP_ID)->t('status_' . $statusKey)
					: $statusKey,
			];
			try {
				// Plain substitution — the composer is plain text and comments
				// are stored raw and escaped at render (p()). The HTML-escaped
				// variant would double-escape for the customer.
				$content = $this->templates->processTemplatePlain($id, $variables);
			} catch (\Throwable) {
				// Row deleted between the metadata check and the render —
				// identical uniform-404, no partial body.
				return $this->error('template_not_found', Http::STATUS_NOT_FOUND);
			}
			return new JSONResponse(['ok' => true, 'content' => $content]);
		} catch (CompanionNotFoundException $e) {
			return $this->error($e->getErrorCode(), Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pushUnregister(): JSONResponse
	{
		$uid = $this->requireUid();
		$pushToken = $this->request->getParam('pushToken');
		$this->push->unregister($uid, is_string($pushToken) ? $pushToken : null);
		return new JSONResponse(['ok' => true, 'registered' => false]);
	}

	/**
	 * Optional companion idempotency key (body `idempotencyKey`/`idempotency_key`
	 * or X-TC-Idempotency-Key header). Null → mutation runs uncached.
	 */
	private function idempotencyKey(): ?string
	{
		foreach (['idempotencyKey', 'idempotency_key'] as $param) {
			$raw = $this->request->getParam($param);
			if (is_scalar($raw) && trim((string)$raw) !== '') {
				return trim((string)$raw);
			}
		}
		$header = trim($this->request->getHeader(IdempotencyService::HEADER));
		return $header === '' ? null : $header;
	}

	private function requireUid(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new CompanionUnauthorizedException('NOT_AUTHENTICATED');
		}
		return $user->getUID();
	}

	private function optionalPositiveInt(string $param): ?int
	{
		$raw = $this->request->getParam($param);
		if ($raw === null || $raw === '') {
			return null;
		}
		if (is_int($raw)) {
			return $raw;
		}
		if (is_string($raw) && ctype_digit($raw)) {
			return (int)$raw;
		}
		throw new CompanionValidationException('invalid_' . strtolower($param), $param . ' must be a positive integer');
	}

	private function requireVersion(): int
	{
		$version = $this->request->getParam('version');
		if ($version === null || $version === '' || !is_numeric($version)) {
			throw new CompanionValidationException('version_required', 'version is required');
		}
		return (int)$version;
	}

	/**
	 * @param mixed $raw
	 * @return array<int, int>
	 */
	private function parseVersionsMap(mixed $raw): array
	{
		if (!is_array($raw)) {
			return [];
		}
		$out = [];
		foreach ($raw as $id => $ver) {
			if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
				continue;
			}
			if (!is_numeric($ver)) {
				continue;
			}
			$ticketId = (int)$id;
			if ($ticketId < 1) {
				continue;
			}
			$out[$ticketId] = (int)$ver;
		}
		return $out;
	}

	/**
	 * @param Http::STATUS_* $status
	 */
	private function error(string $code, int $status, string $message = ''): JSONResponse
	{
		return new JSONResponse([
			'ok' => false,
			'error' => [
				'code' => $code,
				'message' => $message !== '' ? $message : $code,
			],
		], $status);
	}
}

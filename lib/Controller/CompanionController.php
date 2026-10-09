<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\AppInfo\Application;
use OCA\Ticketcheck\Exception\CompanionConflictException;
use OCA\Ticketcheck\Exception\CompanionNotFoundException;
use OCA\Ticketcheck\Exception\CompanionUnauthorizedException;
use OCA\Ticketcheck\Exception\CompanionValidationException;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\CompanionGateService;
use OCA\Ticketcheck\Service\CompanionTicketService;
use OCA\Ticketcheck\Service\IdempotencyService;
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
			// Optional idempotency: offline-queue flush / lost-200 retry with the
			// same key (body idempotencyKey or X-TC-Idempotency-Key) returns the
			// original ticket instead of a duplicate row. Scope includes project
			// so keys cannot collide across projects (mobilitycheck convention).
			$result = $this->idempotency->run(
				$uid,
				'companion.ticket.create.' . ($projectId !== null && $projectId > 0 ? (string)$projectId : 'noproject'),
				$this->idempotencyKey(),
				fn (): array => $this->tickets->create($uid, $projectId, $title, $description, $priority),
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

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pushRegister(): JSONResponse
	{
		$this->requireUid();
		$deviceId = trim((string)$this->request->getParam('deviceId', ''));
		if ($deviceId === '' || strlen($deviceId) > 255) {
			return $this->error('invalid_device', Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		// Device-token push is deferred (capabilities.pushAvailable=false). Do not claim success.
		return new JSONResponse([
			'ok' => true,
			'registered' => false,
			'reason' => 'push_unavailable',
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pushUnregister(): JSONResponse
	{
		$uid = $this->requireUid();
		$this->config->deleteUserValue($uid, Application::APP_ID, 'companion_push_device');
		$this->config->deleteUserValue($uid, Application::APP_ID, 'companion_push_at');
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

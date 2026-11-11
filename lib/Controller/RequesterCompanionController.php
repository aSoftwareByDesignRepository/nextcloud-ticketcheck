<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Exception\CompanionConflictException;
use OCA\Ticketcheck\Exception\CompanionNotFoundException;
use OCA\Ticketcheck\Exception\CompanionValidationException;
use OCA\Ticketcheck\Exception\RoleDeniedException;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\IdempotencyService;
use OCA\Ticketcheck\Service\RequesterTicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class RequesterCompanionController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly RequesterTicketService $tickets,
        private readonly CustomerPortalController $portal,
        private readonly IdempotencyService $idempotency,
        private readonly AttachmentDeliveryService $delivery,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): Response
    {
        return $this->respond(function (): Response {
            $cursor = $this->request->getParam('cursor');
            if ($cursor !== null && !is_string($cursor)) {
                throw new CompanionValidationException('invalid_cursor');
            }
            return new JSONResponse(['ok' => true] + $this->tickets->listing($cursor, (int)$this->request->getParam('limit', 25)));
        });
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(int $id): Response
    {
        return $this->respond(fn (): Response => new JSONResponse(['ok' => true] + $this->tickets->detail($id)));
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(): Response
    {
        return $this->respond(function (): Response {
            $uid = $this->tickets->requireUid();
            $result = $this->idempotency->run($uid, 'requester.create', $this->key(), function (): array {
                $response = $this->portal->storeTicket();
                $data = $this->successfulWrite($response);
                return ['id' => (int)$data['ticket']->getId()];
            });
            return new JSONResponse(['ok' => true] + $this->tickets->detail((int)$result['id']), 201);
        });
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function reply(int $id): Response
    {
        return $this->respond(function () use ($id): Response {
            $uid = $this->tickets->requireUid();
            $this->tickets->visibleTicket($id);
            $this->idempotency->run($uid, 'requester.reply.' . $id, $this->key(), function () use ($id): array {
                $this->successfulWrite($this->portal->addReply($id));
                return ['success' => true];
            });
            return new JSONResponse(['ok' => true] + $this->tickets->detail($id));
        });
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function download(int $id, int $attachmentId): Response
    {
        return $this->respond(function () use ($id, $attachmentId): Response {
            $attachment = $this->tickets->attachment($id, $attachmentId);
            $result = $this->delivery->buildStreamResponse($attachment, (int)$attachment->getTicketId(), false);
            if (!($result['ok'] ?? false)) {
                throw new CompanionNotFoundException('NOT_FOUND');
            }
            return $result['response'];
        });
    }

    private function key(): string
    {
        $key = $this->idempotency->normalizeKey((string)$this->request->getHeader(IdempotencyService::HEADER));
        if ($key === null) {
            throw new CompanionValidationException('idempotency_key_required');
        }
        return $key;
    }

    private function successfulWrite(JSONResponse $response): array
    {
        $data = $response->getData();
        if ($response->getStatus() >= 400 || !is_array($data) || empty($data['success'])) {
            if ($response->getStatus() === 404) {
                throw new CompanionNotFoundException('NOT_FOUND');
            }
            $message = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : 'request_failed';
            throw new CompanionValidationException('request_failed', $message, max(400, $response->getStatus()));
        }
        return $data;
    }

    private function respond(callable $action): Response
    {
        try {
            $this->tickets->requireUid();
            return $action();
        } catch (CompanionNotFoundException) {
            return new JSONResponse(['success' => false, 'error' => 'NOT_FOUND', 'message' => 'Not found.', 'fields' => (object)[]], 404);
        } catch (RoleDeniedException) {
            return new JSONResponse(['ok' => false, 'error' => ['code' => 'ROLE_DENIED']], 403);
        } catch (CompanionConflictException $e) {
            return new JSONResponse(['ok' => false, 'error' => ['code' => $e->getErrorCode()]], 409);
        } catch (CompanionValidationException $e) {
            return new JSONResponse(['ok' => false, 'error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage()]], $e->getCode());
        } catch (\Throwable $e) {
            $this->logger->error('Requester companion action failed', ['exception' => $e, 'app' => 'ticketcheck']);
            return new JSONResponse(['ok' => false, 'error' => ['code' => 'operation_failed']], 500);
        }
    }
}

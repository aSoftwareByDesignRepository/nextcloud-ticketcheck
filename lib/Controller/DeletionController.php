<?php

declare(strict_types=1);

/**
 * Controller for deletion analysis endpoints
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\DeletionService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Controller for deletion analysis
 */
class DeletionController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DeletionService $deletionService,
        private readonly PermissionService $permissionService,
        private readonly TicketMapper $ticketMapper,
        private readonly TicketService $ticketService,
        private readonly IFactory $l10nFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Analyze ticket dependencies
     */
    #[NoAdminRequired]
    public function analyzeTicket(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            try {
                $ticket = $this->ticketMapper->find($id);
            } catch (DoesNotExistException|MultipleObjectsReturnedException) {
                return new JSONResponse(['error' => $l->t('ticket_not_found')], Http::STATUS_NOT_FOUND);
            }

            if (!$this->permissionService->canDeleteTicket($ticket)) {
                return new JSONResponse(['error' => $l->t('ticket_not_found')], Http::STATUS_NOT_FOUND);
            }

            // Analyze the survivor — conversation/attachments live there after merge.
            try {
                $active = $this->ticketService->getActiveTicket($id);
            } catch (\Throwable $e) {
                return new JSONResponse(['error' => $l->t('ticket_not_found')], Http::STATUS_NOT_FOUND);
            }
            $activeId = (int) $active->getId();
            if ($activeId !== $id) {
                if (!$this->permissionService->canDeleteTicket($active)) {
                    return new JSONResponse(['error' => $l->t('ticket_not_found')], Http::STATUS_NOT_FOUND);
                }
            }

            $dependencies = $this->deletionService->analyzeDependencies('ticket', $activeId);
            if ($activeId !== $id) {
                $dependencies['redirected_from'] = $id;
                $dependencies['ticket_id'] = $activeId;
            }
            return new JSONResponse($dependencies);
        } catch (\Throwable $e) {
            $this->logger->error('Deletion operation failed', ['exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Analyze customer dependencies
     */
    #[NoAdminRequired]
    public function analyzeCustomer(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            if (!$this->permissionService->canManageSettings()) {
                return new JSONResponse(['error' => $l->t('access_denied')], Http::STATUS_FORBIDDEN);
            }

            $dependencies = $this->deletionService->analyzeDependencies('customer', $id);
            return new JSONResponse($dependencies);
        } catch (\Throwable $e) {
            $this->logger->error('Deletion operation failed', ['exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Analyze project dependencies
     */
    #[NoAdminRequired]
    public function analyzeProject(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            if (!$this->permissionService->canManageSettings()) {
                return new JSONResponse(['error' => $l->t('access_denied')], Http::STATUS_FORBIDDEN);
            }

            $dependencies = $this->deletionService->analyzeDependencies('project', $id);
            return new JSONResponse($dependencies);
        } catch (\Throwable $e) {
            $this->logger->error('Deletion operation failed', ['exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Analyze KB article dependencies
     */
    #[NoAdminRequired]
    public function analyzeKBArticle(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            if (!$this->permissionService->canManageKnowledgeBase()) {
                return new JSONResponse(['error' => $l->t('access_denied')], Http::STATUS_FORBIDDEN);
            }

            $dependencies = $this->deletionService->analyzeDependencies('kb_article', $id);
            return new JSONResponse($dependencies);
        } catch (\Throwable $e) {
            $this->logger->error('Deletion operation failed', ['exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Analyze guest user dependencies
     */
    #[NoAdminRequired]
    public function analyzeGuestUser(string $userId): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            if (!$this->permissionService->canManageSettings()) {
                return new JSONResponse(['error' => $l->t('access_denied')], Http::STATUS_FORBIDDEN);
            }

            $dependencies = $this->deletionService->analyzeDependencies('guest_user', $userId);
            return new JSONResponse($dependencies);
        } catch (\Throwable $e) {
            $this->logger->error('Deletion operation failed', ['exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Analyze KB category dependencies
     */
    #[NoAdminRequired]
    public function analyzeKBCategory(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            if (!$this->permissionService->canManageSettings()) {
                return new JSONResponse(['error' => $l->t('access_denied')], Http::STATUS_FORBIDDEN);
            }

            $dependencies = $this->deletionService->analyzeDependencies('kb_category', $id);
            return new JSONResponse($dependencies);
        } catch (\Throwable $e) {
            $this->logger->error('Deletion operation failed', ['exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Analyze template dependencies
     */
    #[NoAdminRequired]
    public function analyzeTemplate(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            if (!$this->permissionService->canManageSettings()) {
                return new JSONResponse(['error' => $l->t('access_denied')], Http::STATUS_FORBIDDEN);
            }

            $dependencies = $this->deletionService->analyzeDependencies('template', $id);
            return new JSONResponse($dependencies);
        } catch (\Throwable $e) {
            $this->logger->error('Deletion operation failed', ['exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Analyze assignment dependencies
     */
    #[NoAdminRequired]
    public function analyzeAssignment(int $id): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        try {
            if (!$this->permissionService->canManageSettings()) {
                return new JSONResponse(['error' => $l->t('access_denied')], Http::STATUS_FORBIDDEN);
            }

            $dependencies = $this->deletionService->analyzeDependencies('assignment', $id);
            return new JSONResponse($dependencies);
        } catch (\Throwable $e) {
            $this->logger->error('Deletion operation failed', ['exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], Http::STATUS_BAD_REQUEST);
        }
    }
}

<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2025 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Service\TemplateService;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Template Management Controller
 */
class TemplateController extends Controller
{

    private TemplateService $templateService;
    private PermissionService $permissionService;
    private LoggerInterface $logger;
    private IFactory $l10nFactory;

    public function __construct(
        string $appName,
        IRequest $request,
        TemplateService $templateService,
        PermissionService $permissionService,
        IFactory $l10nFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->templateService = $templateService;
        $this->permissionService = $permissionService;
        $this->l10nFactory = $l10nFactory;
        $this->logger = $logger;
    }

    /**
     * Get all templates (agents/admins can list for canned responses; settings admins for management)
     */
    #[NoAdminRequired]
    public function index(): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))
            && !$this->permissionService->canManageSettings()) {
            return new DataResponse(['error' => $l->t('access_denied')], 403);
        }

        $category = $this->request->getParam('category');
        $templates = $this->templateService->getAllTemplates($category);

        return new DataResponse([
            'success' => true,
            'templates' => $templates
        ]);
    }

    /**
     * Create template
     */
    #[NoAdminRequired]
    public function store(): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new DataResponse(['error' => $l->t('access_denied')], 403);
        }

        try {
            $name = trim((string)$this->request->getParam('name'));
            $content = trim((string)$this->request->getParam('content'));
            $category = $this->request->getParam('category');

            if ($name === '' || $content === '') {
                return new DataResponse(['error' => $l->t('name_and_content_required')], 400);
            }

            $id = $this->templateService->createTemplate($name, $content, $category);

            return new DataResponse([
                'success' => true,
                'template_id' => $id,
                'message' => $l->t('template_created_successfully')
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Template operation failed', ['exception' => $e]);
            return new DataResponse(['error' => $l->t('an_error_occurred')], 400);
        }
    }

    /**
     * Update template
     */
    #[NoAdminRequired]
    public function update(int $id): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new DataResponse(['error' => $l->t('access_denied')], 403);
        }

        try {
            // Uniform 404: a zero-row UPDATE must not masquerade as success.
            if ($this->templateService->getTemplate($id) === null) {
                return new DataResponse(['error' => $l->t('template_not_found')], 404);
            }
            $name = $this->request->getParam('name');
            $content = $this->request->getParam('content');
            $category = $this->request->getParam('category');

            $this->templateService->updateTemplate($id, $name, $content, $category);

            return new DataResponse([
                'success' => true,
                'message' => $l->t('template_updated_successfully')
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Template operation failed', ['exception' => $e]);
            return new DataResponse(['error' => $l->t('an_error_occurred')], 400);
        }
    }

    /**
     * Delete template
     */
    #[NoAdminRequired]
    public function delete(int $id): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canManageSettings()) {
            return new DataResponse(['error' => $l->t('access_denied')], 403);
        }

        try {
            // Uniform 404: deleting a missing template is a not-found (repeat deletes must fail).
            if ($this->templateService->getTemplate($id) === null) {
                return new DataResponse(['error' => $l->t('template_not_found')], 404);
            }
            $this->templateService->deleteTemplate($id);

            return new DataResponse([
                'success' => true,
                'message' => $l->t('template_deleted_successfully')
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Template operation failed', ['exception' => $e]);
            return new DataResponse(['error' => $l->t('an_error_occurred')], 400);
        }
    }

    /**
     * Process template with variables (agents/admins for canned responses; settings admins for management)
     * POST body: { variables: { ticket_number, customer_name, customer_email, title, ... } }
     */
    #[NoAdminRequired]
    public function process(int $id): DataResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canViewHelpdeskOverview((string) ($this->permissionService->getCurrentUserId() ?? ''))
            && !$this->permissionService->canManageSettings()) {
            return new DataResponse(['error' => $l->t('access_denied')], 403);
        }
        try {
            if ($this->templateService->getTemplate($id) === null) {
                return new DataResponse(['error' => $l->t('template_not_found')], 404);
            }
            $variables = $this->request->getParam('variables', []);
            $content = $this->templateService->processTemplate($id, $variables);

            return new DataResponse([
                'success' => true,
                'content' => $content
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Template operation failed', ['exception' => $e]);
            return new DataResponse(['error' => $l->t('an_error_occurred')], 400);
        }
    }
}


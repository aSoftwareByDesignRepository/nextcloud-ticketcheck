<?php

declare(strict_types=1);

/**
 * Admin CSV export controller.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Service\AssignableUserSearchService;
use OCA\Ticketcheck\Service\Export\CsvExportService;
use OCA\Ticketcheck\Service\Export\ExportLimitExceededException;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

class ExportController extends Controller
{
    use CSPTrait;
    use PageRenderTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly PermissionService $permissionService,
        private readonly CsvExportService $csvExportService,
        private readonly AssignableUserSearchService $assignableUserSearchService,
        private readonly ProjectService $projectService,
        private readonly IURLGenerator $urlGenerator,
        private readonly IFactory $l10nFactory,
        private readonly LocaleFormatService $localeFormat,
        private readonly NavigationContextService $navigationContext,
        private readonly FrontEndAssetService $frontEndAssets,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canExportData()) {
            $denied = new TemplateResponse($this->appName, 'error', [
                'message' => $l->t('permission_denied_export_admin_only'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $denied->setStatus(Http::STATUS_FORBIDDEN);
            return $denied;
        }

        $user = $this->userSession->getUser();
        $currentUserId = $user !== null ? $user->getUID() : '';

        $response = $this->renderAppPage(
            'export/index',
            [
                'projects' => $this->projectService->getAllProjects(500, 0, true),
                'customers' => $this->projectService->getAllCustomers(500, 0),
                'currentUserId' => $currentUserId,
            ],
            'export',
            $l->t('export_data'),
            $l->t('export_page_lead'),
            'export',
            'staff',
            ['contextLine' => $l->t('export_scope_admin')],
        );

        return $this->configureCSPWithNonce($response, 'main');
    }

    #[NoAdminRequired]
    public function getOptions(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canExportData()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }

        return new JSONResponse($this->csvExportService->getOptionsMetadata($l));
    }

    #[NoAdminRequired]
    public function searchAssignees(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canExportData()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }

        $query = trim((string)$this->request->getParam('query', ''));
        $limit = $this->normalizeOptionalPositiveInt($this->request->getParam('limit'));
        if ($limit === false || $limit === null) {
            $limit = 25;
        }
        $limit = max(5, min($limit, 50));

        $projectId = $this->normalizeOptionalPositiveInt($this->request->getParam('project_id'));

        try {
            return new JSONResponse([
                'success' => true,
                'users' => $this->assignableUserSearchService->search(
                    $projectId === false ? null : $projectId,
                    $query,
                    $limit,
                ),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Export assignee search failed', ['exception' => $e]);

            return new JSONResponse(['error' => $l->t('an_error_occurred')], 500);
        }
    }

    #[NoAdminRequired]
    public function preview(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canExportData()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }

        try {
            $payload = $this->readPayload();
            $entity = (string)($payload['entity'] ?? 'tickets');
            // Unknown entities must be rejected, not silently counted as tickets.
            if (!in_array($entity, ['tickets', 'projects'], true)) {
                return new JSONResponse(['error' => $l->t('invalid_entity')], 400);
            }
            if ($entity === 'projects') {
                $count = $this->csvExportService->countProjects($payload);
            } else {
                $count = $this->csvExportService->countTickets($payload);
            }

            return new JSONResponse([
                'count' => $count,
                'maxRows' => \OCA\Ticketcheck\Service\Export\ExportFilterParser::MAX_EXPORT_ROWS,
                'exceedsLimit' => $count > \OCA\Ticketcheck\Service\Export\ExportFilterParser::MAX_EXPORT_ROWS,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Export preview failed', ['exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], 400);
        }
    }

    #[NoAdminRequired]
    public function exportTickets(): DataDownloadResponse|JSONResponse
    {
        return $this->handleExport('tickets');
    }

    #[NoAdminRequired]
    public function exportProjects(): DataDownloadResponse|JSONResponse
    {
        return $this->handleExport('projects');
    }

    private function handleExport(string $entity): DataDownloadResponse|JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if (!$this->permissionService->canExportData()) {
            return new JSONResponse(['error' => $l->t('access_denied')], 403);
        }

        try {
            $payload = $this->readPayload();
            $columns = is_array($payload['columns'] ?? null) ? $payload['columns'] : [];

            $result = $entity === 'projects'
                ? $this->csvExportService->exportProjects($payload, $columns, $l)
                : $this->csvExportService->exportTickets($payload, $columns, $l);

            return new DataDownloadResponse(
                $result['csv'],
                $result['filename'],
                'text/csv; charset=utf-8'
            );
        } catch (ExportLimitExceededException $e) {
            return new JSONResponse([
                'error' => strtr($l->t('export_limit_exceeded'), [
                    '{count}' => (string)$e->getActualCount(),
                    '{maxRows}' => (string)$e->getMaxCount(),
                ]),
                'code' => 'export_limit_exceeded',
                'count' => $e->getActualCount(),
                'maxRows' => $e->getMaxCount(),
            ], 413);
        } catch (\Throwable $e) {
            $this->logger->error('CSV export failed', ['entity' => $entity, 'exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], 400);
        }
    }

    /**
     * @return array<string,mixed>
     */
    /**
     * @return int|null|false int for valid positive IDs, null when empty, false when invalid
     */
    private function normalizeOptionalPositiveInt(mixed $raw): int|null|false
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_int($raw)) {
            return $raw > 0 ? $raw : false;
        }

        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return null;
            }
            if (!ctype_digit($trimmed)) {
                return false;
            }
            $value = (int)$trimmed;

            return $value > 0 ? $value : false;
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function readPayload(): array
    {
        $params = $this->request->getParams();
        $contentType = (string)$this->request->getHeader('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return array_merge($params, $decoded);
                }
            }
        }
        return $params;
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

    protected function getNavigationContextService(): NavigationContextService
    {
        return $this->navigationContext;
    }

    protected function getPageL10n(): IL10N
    {
        return $this->l10nFactory->get('ticketcheck');
    }

    protected function getFrontEndAssetService(): FrontEndAssetService
    {
        return $this->frontEndAssets;
    }
}

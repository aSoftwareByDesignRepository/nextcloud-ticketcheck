<?php

declare(strict_types=1);

/**
 * CSV export service for tickets and projects (admin-only).
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service\Export;

use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class CsvExportService
{
    /** @var list<string> */
    public const TICKET_COLUMNS = [
        'id',
        'ticket_number',
        'title',
        'description',
        'status',
        'priority',
        'category',
        'customer_id',
        'customer_name',
        'customer_email',
        'project_id',
        'project_name',
        'assigned_to',
        'assigned_to_name',
        'created_at',
        'updated_at',
        'closed_at',
        'created_by',
        'created_by_guest',
        'sla_response_due',
        'sla_resolution_due',
        'comment_count',
        'attachment_count',
    ];

    /** @var list<string> */
    public const PROJECT_COLUMNS = [
        'id',
        'name',
        'description',
        'customer_id',
        'customer_name',
        'customer_email',
        'active',
        'member_count',
        'ticket_count',
        'open_ticket_count',
        'resolved_ticket_count',
        'created_at',
        'updated_at',
        'created_by',
    ];

    /** @var list<string> */
    public const TICKET_DEFAULT_COLUMNS = [
        'ticket_number',
        'title',
        'status',
        'priority',
        'category',
        'customer_name',
        'customer_email',
        'project_name',
        'assigned_to_name',
        'created_at',
        'updated_at',
        'closed_at',
    ];

    /** @var list<string> */
    public const PROJECT_DEFAULT_COLUMNS = [
        'id',
        'name',
        'customer_name',
        'active',
        'member_count',
        'ticket_count',
        'created_at',
    ];

    public function __construct(
        private TicketMapper $ticketMapper,
        private ProjectService $projectService,
        private CommentMapper $commentMapper,
        private AttachmentMapper $attachmentMapper,
        private IUserManager $userManager,
        private LocaleFormatService $localeFormat,
        private SafeFilenameService $safeFilenameService,
        private ExportFilterParser $filterParser,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *   ticketColumns: list<array{key:string,label:string,default:bool}>,
     *   projectColumns: list<array{key:string,label:string,default:bool}>,
     *   ticketPresets: list<array{id:string,label:string,columns:list<string>}>,
     *   projectPresets: list<array{id:string,label:string,columns:list<string>}>,
     *   statuses: list<string>,
     *   priorities: list<string>,
     *   categories: list<string>,
     *   maxRows: int,
     *   maxSelectedIds: int
     * }
     */
    public function getOptionsMetadata(IL10N $l): array
    {
        return [
            'ticketColumns' => $this->columnMetadata(self::TICKET_COLUMNS, self::TICKET_DEFAULT_COLUMNS, $l, 'export_col_ticket_'),
            'projectColumns' => $this->columnMetadata(self::PROJECT_COLUMNS, self::PROJECT_DEFAULT_COLUMNS, $l, 'export_col_project_'),
            'ticketPresets' => array_values([
                [
                    'id' => 'summary',
                    'label' => $l->t('export_preset_ticket_summary'),
                    'columns' => self::TICKET_DEFAULT_COLUMNS,
                ],
                [
                    'id' => 'full',
                    'label' => $l->t('export_preset_ticket_full'),
                    'columns' => self::TICKET_COLUMNS,
                ],
                [
                    'id' => 'customer',
                    'label' => $l->t('export_preset_ticket_customer'),
                    'columns' => ['ticket_number', 'title', 'status', 'customer_name', 'customer_email', 'project_name', 'created_at', 'closed_at'],
                ],
            ]),
            'projectPresets' => array_values([
                [
                    'id' => 'summary',
                    'label' => $l->t('export_preset_project_summary'),
                    'columns' => self::PROJECT_DEFAULT_COLUMNS,
                ],
                [
                    'id' => 'full',
                    'label' => $l->t('export_preset_project_full'),
                    'columns' => self::PROJECT_COLUMNS,
                ],
            ]),
            'statuses' => Ticket::getStatuses(),
            'priorities' => Ticket::getPriorities(),
            'categories' => Ticket::getCategories(),
            'maxRows' => ExportFilterParser::MAX_EXPORT_ROWS,
            'maxSelectedIds' => ExportFilterParser::MAX_SELECTED_IDS,
        ];
    }

    /**
     * @param array<string,mixed> $rawFilters
     */
    public function countTickets(array $rawFilters): int
    {
        $filters = $this->normalizeTicketDateFilters($this->filterParser->parseTicketFilters($rawFilters));
        return $this->ticketMapper->countForExport($filters);
    }

    /**
     * @param array<string,mixed> $rawFilters
     */
    public function countProjects(array $rawFilters): int
    {
        $filters = $this->filterParser->parseProjectFilters($rawFilters);
        return count($this->fetchProjectsForExport($filters));
    }

    /**
     * @param array<string,mixed> $rawFilters
     * @param list<string> $requestedColumns
     * @return array{csv:string,filename:string,rowCount:int}
     */
    public function exportTickets(array $rawFilters, array $requestedColumns, IL10N $l): array
    {
        $filters = $this->normalizeTicketDateFilters($this->filterParser->parseTicketFilters($rawFilters));
        $columns = $this->filterParser->parseColumns($requestedColumns, self::TICKET_COLUMNS);
        $total = $this->ticketMapper->countForExport($filters);

        if ($total > ExportFilterParser::MAX_EXPORT_ROWS) {
            throw new ExportLimitExceededException($total, ExportFilterParser::MAX_EXPORT_ROWS);
        }

        $tickets = $this->ticketMapper->findForExport($filters, ExportFilterParser::MAX_EXPORT_ROWS);
        $projectNames = $this->buildProjectNameMap($tickets);
        $assignedNames = $this->buildAssignedNameMap($tickets);
        $commentCounts = $this->needsTicketColumn($columns, 'comment_count')
            ? $this->bulkCommentCounts(array_map(static fn (Ticket $t) => $t->getId(), $tickets))
            : [];
        $attachmentCounts = $this->needsTicketColumn($columns, 'attachment_count')
            ? $this->bulkAttachmentCounts(array_map(static fn (Ticket $t) => $t->getId(), $tickets))
            : [];

        $headers = array_map(fn (string $column) => $this->ticketColumnLabel($column, $l), $columns);
        $rows = [];
        foreach ($tickets as $ticket) {
            $rows[] = $this->buildTicketRow(
                $ticket,
                $columns,
                $projectNames,
                $assignedNames,
                $commentCounts,
                $attachmentCounts,
                $l
            );
        }

        $filename = $this->safeFilenameService->sanitizeForContentDisposition(
            'ticketcheck_tickets_' . date('Y-m-d_His') . '.csv'
        );

        $this->logger->info('Ticket CSV export completed', [
            'row_count' => count($rows),
            'columns' => $columns,
            'scope' => $filters['scope'],
        ]);

        return [
            'csv' => $this->renderCsv($headers, $rows),
            'filename' => $filename,
            'rowCount' => count($rows),
        ];
    }

    /**
     * @param array<string,mixed> $rawFilters
     * @param list<string> $requestedColumns
     * @return array{csv:string,filename:string,rowCount:int}
     */
    public function exportProjects(array $rawFilters, array $requestedColumns, IL10N $l): array
    {
        $filters = $this->filterParser->parseProjectFilters($rawFilters);
        $columns = $this->filterParser->parseColumns($requestedColumns, self::PROJECT_COLUMNS);
        $projects = $this->fetchProjectsForExport($filters);

        if (count($projects) > ExportFilterParser::MAX_EXPORT_ROWS) {
            throw new ExportLimitExceededException(count($projects), ExportFilterParser::MAX_EXPORT_ROWS);
        }

        $headers = array_map(fn (string $column) => $this->projectColumnLabel($column, $l), $columns);
        $rows = [];
        foreach ($projects as $project) {
            $rows[] = $this->buildProjectRow($project, $columns, $l);
        }

        $filename = $this->safeFilenameService->sanitizeForContentDisposition(
            'ticketcheck_projects_' . date('Y-m-d_His') . '.csv'
        );

        $this->logger->info('Project CSV export completed', [
            'row_count' => count($rows),
            'columns' => $columns,
            'scope' => $filters['scope'],
        ]);

        return [
            'csv' => $this->renderCsv($headers, $rows),
            'filename' => $filename,
            'rowCount' => count($rows),
        ];
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $defaults
     * @return list<array{key:string,label:string,default:bool}>
     */
    private function columnMetadata(array $allowed, array $defaults, IL10N $l, string $labelPrefix): array
    {
        $defaultLookup = array_fill_keys($defaults, true);
        $items = [];
        foreach ($allowed as $column) {
            $labelKey = $labelPrefix . $column;
            $label = $l->t($labelKey);
            if ($label === $labelKey) {
                $label = ucwords(str_replace('_', ' ', $column));
            }
            $items[] = [
                'key' => $column,
                'label' => $label,
                'default' => isset($defaultLookup[$column]),
            ];
        }
        return $items;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function fetchProjectsForExport(array $filters): array
    {
        $scope = (string)($filters['scope'] ?? 'all');
        if ($scope === 'selected') {
            $projectIds = is_array($filters['project_ids'] ?? null) ? $filters['project_ids'] : [];
            if ($projectIds === []) {
                return [];
            }
            $projects = [];
            foreach ($projectIds as $projectId) {
                $project = $this->projectService->getProject((int)$projectId);
                if ($project !== null) {
                    $projects[] = $project;
                }
            }
            usort($projects, static fn (array $a, array $b): int => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
            return array_values($projects);
        }

        $includeInactive = !empty($filters['include_inactive']);
        $search = (string)($filters['search'] ?? '');
        $customerId = isset($filters['customer_id']) ? (int)$filters['customer_id'] : null;
        $projects = $this->projectService->getAllProjects(
            ExportFilterParser::MAX_EXPORT_ROWS + 1,
            0,
            $includeInactive,
            $search !== '' ? $search : null,
            $customerId
        );
        /** @var list<array<string, mixed>> $list */
        $list = array_values($projects);
        return $list;
    }

    /**
     * @param list<Ticket> $tickets
     * @return array<int,string>
     */
    private function buildProjectNameMap(array $tickets): array
    {
        $map = [];
        foreach ($tickets as $ticket) {
            $projectId = $ticket->getProjectId();
            if ($projectId === null || isset($map[$projectId])) {
                continue;
            }
            $project = $this->projectService->getProject((int)$projectId);
            $map[$projectId] = $project !== null ? (string)($project['name'] ?? '') : '';
        }
        return $map;
    }

    /**
     * @param list<Ticket> $tickets
     * @return array<string,string>
     */
    private function buildAssignedNameMap(array $tickets): array
    {
        $map = [];
        foreach ($tickets as $ticket) {
            $uid = $ticket->getAssignedTo();
            if ($uid === null || $uid === '' || isset($map[$uid])) {
                continue;
            }
            $user = $this->userManager->get($uid);
            $map[$uid] = $user !== null ? $user->getDisplayName() : $uid;
        }
        return $map;
    }

    /**
     * @param list<int> $ticketIds
     * @return array<int,int>
     */
    private function bulkCommentCounts(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }
        $counts = [];
        foreach ($ticketIds as $ticketId) {
            $counts[$ticketId] = $this->commentMapper->countByTicketId($ticketId, true);
        }
        return $counts;
    }

    /**
     * @param list<int> $ticketIds
     * @return array<int,int>
     */
    private function bulkAttachmentCounts(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }
        $counts = [];
        foreach ($ticketIds as $ticketId) {
            $counts[$ticketId] = count($this->attachmentMapper->findByTicketId($ticketId));
        }
        return $counts;
    }

    /**
     * @param list<string> $columns
     * @param array<int,string> $projectNames
     * @param array<string,string> $assignedNames
     * @param array<int,int> $commentCounts
     * @param array<int,int> $attachmentCounts
     * @return list<string>
     */
    private function buildTicketRow(
        Ticket $ticket,
        array $columns,
        array $projectNames,
        array $assignedNames,
        array $commentCounts,
        array $attachmentCounts,
        IL10N $l,
    ): array {
        $projectId = $ticket->getProjectId();
        $assignedTo = $ticket->getAssignedTo() ?? '';
        $values = [
            'id' => (string)$ticket->getId(),
            'ticket_number' => $ticket->getTicketNumber(),
            'title' => $ticket->getTitle(),
            'description' => $this->plainText($ticket->getDescription()),
            'status' => $this->translateToken('status_' . $ticket->getStatus(), $ticket->getStatus(), $l),
            'priority' => $this->translateToken('priority_' . $ticket->getPriority(), $ticket->getPriority(), $l),
            'category' => $this->translateToken('category_' . $ticket->getCategory(), $ticket->getCategory(), $l),
            'customer_id' => $ticket->getCustomerId() !== null ? (string)$ticket->getCustomerId() : '',
            'customer_name' => $ticket->getCustomerName(),
            'customer_email' => $ticket->getCustomerEmail(),
            'project_id' => $projectId !== null ? (string)$projectId : '',
            'project_name' => $projectId !== null ? ($projectNames[$projectId] ?? '') : '',
            'assigned_to' => $assignedTo,
            'assigned_to_name' => $assignedTo !== '' ? ($assignedNames[$assignedTo] ?? $assignedTo) : $l->t('unassigned'),
            'created_at' => $this->formatDateTime($ticket->getCreatedAt()),
            'updated_at' => $this->formatDateTime($ticket->getUpdatedAt()),
            'closed_at' => $ticket->getClosedAt() !== null ? $this->formatDateTime($ticket->getClosedAt()) : '',
            'created_by' => $ticket->getCreatedBy(),
            'created_by_guest' => $ticket->getCreatedByGuest() ? $l->t('yes') : $l->t('no'),
            'sla_response_due' => $ticket->getSlaResponseDue() !== null ? $this->formatDateTime($ticket->getSlaResponseDue()) : '',
            'sla_resolution_due' => $ticket->getSlaResolutionDue() !== null ? $this->formatDateTime($ticket->getSlaResolutionDue()) : '',
            'comment_count' => (string)($commentCounts[$ticket->getId()] ?? 0),
            'attachment_count' => (string)($attachmentCounts[$ticket->getId()] ?? 0),
        ];

        $row = [];
        foreach ($columns as $column) {
            $row[] = $values[$column] ?? '';
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $project
     * @param list<string> $columns
     * @return list<string>
     */
    private function buildProjectRow(array $project, array $columns, IL10N $l): array
    {
        $ticketCount = (int)($project['ticket_count'] ?? 0);
        $resolvedCount = (int)($project['resolved_count'] ?? 0);
        $values = [
            'id' => (string)($project['id'] ?? ''),
            'name' => (string)($project['name'] ?? ''),
            'description' => $this->plainText((string)($project['description'] ?? '')),
            'customer_id' => isset($project['customer_id']) ? (string)$project['customer_id'] : '',
            'customer_name' => (string)($project['customer_name'] ?? ''),
            'customer_email' => (string)($project['customer_email'] ?? ''),
            'active' => !empty($project['active']) ? $l->t('yes') : $l->t('no'),
            'member_count' => (string)($project['member_count'] ?? 0),
            'ticket_count' => (string)$ticketCount,
            'open_ticket_count' => (string)max(0, $ticketCount - $resolvedCount),
            'resolved_ticket_count' => (string)$resolvedCount,
            'created_at' => $this->formatDateTimeValue($project['created_at'] ?? null),
            'updated_at' => $this->formatDateTimeValue($project['updated_at'] ?? null),
            'created_by' => (string)($project['created_by'] ?? ''),
        ];

        $row = [];
        foreach ($columns as $column) {
            $row[] = $values[$column] ?? '';
        }
        return $row;
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    private function renderCsv(array $headers, array $rows): string
    {
        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            return '';
        }

        // UTF-8 BOM for Excel compatibility
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, CsvFormulaGuard::neutralizeRow($headers));
        foreach ($rows as $row) {
            fputcsv($output, CsvFormulaGuard::neutralizeRow($row));
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv !== false ? $csv : '';
    }

    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function formatDateTime(\DateTimeInterface $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }

    private function formatDateTimeValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $this->formatDateTime($value);
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                return trim($value);
            }
        }
        return '';
    }

    private function translateToken(string $key, string $fallback, IL10N $l): string
    {
        $translated = $l->t($key);
        return $translated !== $key ? $translated : $fallback;
    }

    private function ticketColumnLabel(string $column, IL10N $l): string
    {
        $key = 'export_col_ticket_' . $column;
        $label = $l->t($key);
        return $label !== $key ? $label : ucwords(str_replace('_', ' ', $column));
    }

    private function projectColumnLabel(string $column, IL10N $l): string
    {
        $key = 'export_col_project_' . $column;
        $label = $l->t($key);
        return $label !== $key ? $label : ucwords(str_replace('_', ' ', $column));
    }

    /**
     * @param list<string> $columns
     */
    private function needsTicketColumn(array $columns, string $column): bool
    {
        return in_array($column, $columns, true);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function normalizeTicketDateFilters(array $filters): array
    {
        $dateFrom = isset($filters['date_from']) && is_string($filters['date_from']) ? $filters['date_from'] : null;
        $dateTo = isset($filters['date_to']) && is_string($filters['date_to']) ? $filters['date_to'] : null;
        if ($dateFrom === null && $dateTo === null) {
            return $filters;
        }

        $bounds = $this->localeFormat->storageBoundsForCalendarDays($dateFrom, $dateTo);
        if ($bounds['from'] !== null) {
            $filters['date_from'] = $bounds['from'];
        } else {
            unset($filters['date_from']);
        }
        if ($bounds['to'] !== null) {
            $filters['date_to'] = $bounds['to'];
        } else {
            unset($filters['date_to']);
        }

        return $filters;
    }
}

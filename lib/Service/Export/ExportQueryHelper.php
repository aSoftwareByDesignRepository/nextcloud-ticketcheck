<?php

declare(strict_types=1);

/**
 * Builds safe export prefill query parameters from ticket list filters.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service\Export;

use OCA\Ticketcheck\Db\Ticket;

class ExportQueryHelper
{
    /**
     * Map ticket list UI filters to export API/query parameters.
     *
     * @param array<string,mixed> $currentFilters Keys from TicketController::resolveTicketListFiltersFromRequest currentFilters
     * @return array<string,mixed>
     */
    public function ticketListFiltersToExportParams(array $currentFilters): array
    {
        $params = [
            'entity' => 'tickets',
            'scope' => 'filtered',
        ];

        $status = trim((string)($currentFilters['status'] ?? ''));
        if ($status !== '') {
            $normalized = $this->normalizeListStatus($status);
            if ($normalized !== null) {
                $params['status'] = $normalized;
            }
        }

        foreach (['priority', 'category', 'search', 'assigned_to'] as $key) {
            $value = trim((string)($currentFilters[$key] ?? ''));
            if ($value !== '') {
                $params[$key] = $value;
            }
        }

        $projectId = (int)($currentFilters['project_id'] ?? 0);
        if ($projectId > 0) {
            $params['project_ids'] = [$projectId];
        }

        $customerId = (int)($currentFilters['customer_id'] ?? 0);
        if ($customerId > 0) {
            $params['customer_ids'] = [$customerId];
        }

        $hideDone = (string)($currentFilters['hide_done'] ?? '1');
        if ($hideDone === '1' && !isset($params['status'])) {
            $params['exclude_done'] = '1';
        }

        return $params;
    }

    /**
     * @param array<string,mixed> $params
     */
    public function buildExportIndexQuery(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $entry) {
                    if ($entry === null || $entry === '') {
                        continue;
                    }
                    $parts[] = rawurlencode($key . '[]') . '=' . rawurlencode((string)$entry);
                }
                continue;
            }
            $parts[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
        }

        return $parts === [] ? '' : '?' . implode('&', $parts);
    }

    private function normalizeListStatus(string $status): ?string
    {
        if (str_contains($status, ',')) {
            return null;
        }

        $map = [
            'open' => Ticket::STATUS_NEW,
            'in_progress' => Ticket::STATUS_IN_PROGRESS,
            'inprogress' => Ticket::STATUS_IN_PROGRESS,
            'working' => Ticket::STATUS_IN_PROGRESS,
            'waiting_customer' => Ticket::STATUS_WAITING,
            'resolved' => Ticket::STATUS_DONE,
            'closed' => Ticket::STATUS_DONE,
        ];
        $status = strtolower(trim($status));
        $normalized = $map[$status] ?? $status;

        return in_array($normalized, Ticket::getStatuses(), true) ? $normalized : null;
    }
}

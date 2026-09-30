<?php

declare(strict_types=1);

/**
 * Validates and normalises CSV export filter parameters from HTTP requests.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service\Export;

use OCA\Ticketcheck\Db\Ticket;

class ExportFilterParser
{
    /** @var int Maximum rows returned in a single export */
    public const MAX_EXPORT_ROWS = 10000;

    /** @var int Maximum explicit ID selections per export */
    public const MAX_SELECTED_IDS = 500;

    /**
     * @param array<string, mixed> $params
     * @return array{
     *   scope: string,
     *   search: string,
     *   status: ?string,
     *   priority: ?string,
     *   category: ?string,
     *   assigned_to: ?string,
     *   project_ids: list<int>,
     *   customer_ids: list<int>,
     *   ticket_ids: list<int>,
     *   date_from: ?string,
     *   date_to: ?string,
     *   include_merged: bool,
     *   status_not: ?string,
     *   status_in: list<string>
     * }
     */
    public function parseTicketFilters(array $params): array
    {
        $scope = $this->parseScope((string)($params['scope'] ?? 'all'));
        $ticketIds = $this->parseIdList($params['ticket_ids'] ?? $params['ticket_ids[]'] ?? null);
        $status = $this->parseEnumValue($params['status'] ?? null, Ticket::getStatuses());
        $statusIn = $this->parseStatusList($params['status_in'] ?? $params['status_in[]'] ?? null);
        $statusNot = $this->parseEnumValue($params['status_not'] ?? null, Ticket::getStatuses());

        if ($this->parseBool($params['exclude_done'] ?? false) && $status === null && $statusIn === []) {
            $statusNot = Ticket::STATUS_DONE;
        }

        return [
            'scope' => $scope,
            'search' => $this->sanitizeSearch((string)($params['search'] ?? '')),
            'status' => $status,
            'status_in' => $statusIn,
            'status_not' => $statusNot,
            'priority' => $this->parseEnumValue($params['priority'] ?? null, Ticket::getPriorities()),
            'category' => $this->parseEnumValue($params['category'] ?? null, Ticket::getCategories()),
            'assigned_to' => $this->parseAssignedTo($params['assigned_to'] ?? null),
            'project_ids' => $scope === 'selected' ? [] : $this->parseIdList($params['project_ids'] ?? $params['project_ids[]'] ?? $params['project_id'] ?? null),
            'customer_ids' => $scope === 'selected' ? [] : $this->parseIdList($params['customer_ids'] ?? $params['customer_ids[]'] ?? $params['customer_id'] ?? null),
            'ticket_ids' => $scope === 'selected' ? $ticketIds : [],
            'date_from' => $this->parseDate($params['date_from'] ?? null),
            'date_to' => $this->parseDate($params['date_to'] ?? null),
            'include_merged' => $this->parseBool($params['include_merged'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{
     *   scope: string,
     *   search: string,
     *   customer_id: ?int,
     *   include_inactive: bool,
     *   project_ids: list<int>
     * }
     */
    public function parseProjectFilters(array $params): array
    {
        $scope = $this->parseScope((string)($params['scope'] ?? 'all'));
        $projectIds = $this->parseIdList($params['project_ids'] ?? $params['project_ids[]'] ?? null);
        $customerId = $this->parseOptionalPositiveInt($params['customer_id'] ?? null);

        return [
            'scope' => $scope,
            'search' => $this->sanitizeSearch((string)($params['search'] ?? '')),
            'customer_id' => $scope === 'selected' ? null : $customerId,
            'include_inactive' => $this->parseBool($params['include_inactive'] ?? false),
            'project_ids' => $scope === 'selected' ? $projectIds : [],
        ];
    }

    /**
     * @param list<string> $requested
     * @param list<string> $allowed
     * @return list<string>
     */
    public function parseColumns(array $requested, array $allowed): array
    {
        if ($requested === []) {
            return $allowed;
        }

        $allowedLookup = array_fill_keys($allowed, true);
        $columns = [];
        foreach ($requested as $column) {
            $key = trim((string)$column);
            if ($key === '' || !isset($allowedLookup[$key])) {
                continue;
            }
            if (!in_array($key, $columns, true)) {
                $columns[] = $key;
            }
        }

        return $columns !== [] ? $columns : $allowed;
    }

    private function parseScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        return in_array($scope, ['all', 'filtered', 'selected'], true) ? $scope : 'all';
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private function parseIdList(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        $values = is_array($raw) ? $raw : explode(',', (string)$raw);
        $ids = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value === '' || !ctype_digit($value)) {
                continue;
            }
            $id = (int)$value;
            if ($id <= 0 || in_array($id, $ids, true)) {
                continue;
            }
            $ids[] = $id;
            if (count($ids) >= self::MAX_SELECTED_IDS) {
                break;
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $allowed
     */
    /**
     * @param list<string> $allowed
     */
    private function parseEnumValue(mixed $raw, array $allowed): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = strtolower(trim((string)$raw));
        return in_array($value, $allowed, true) ? $value : null;
    }

    private function parseAssignedTo(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = trim((string)$raw);
        if ($value === '' || strlen($value) > 64) {
            return null;
        }
        if (!preg_match('/^[a-zA-Z0-9._@-]+$/', $value)) {
            return null;
        }
        return $value;
    }

    private function parseDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = trim((string)$raw);
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }
        return $value;
    }

    private function parseOptionalPositiveInt(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            return null;
        }
        $value = (int)$raw;
        return $value > 0 ? $value : null;
    }

    private function parseBool(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        $value = strtolower(trim((string)$raw));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function sanitizeSearch(string $search): string
    {
        $search = trim($search);
        if (strlen($search) > 200) {
            return substr($search, 0, 200);
        }
        return $search;
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function parseStatusList(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        $values = is_array($raw) ? $raw : explode(',', (string)$raw);
        $allowed = Ticket::getStatuses();
        $statuses = [];
        foreach ($values as $value) {
            $normalized = $this->parseEnumValue($value, $allowed);
            if ($normalized !== null && !in_array($normalized, $statuses, true)) {
                $statuses[] = $normalized;
            }
        }

        return $statuses;
    }
}

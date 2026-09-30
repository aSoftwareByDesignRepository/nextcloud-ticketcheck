<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\Ticket;
use OCP\IRequest;

/**
 * Guest portal ticket list filters (GET params → sanitized state → in-memory apply).
 */
final class PortalTicketListFilterService
{
	public const MAX_SEARCH_LENGTH = 200;

	/**
	 * @param list<int> $allowedProjectIds
	 * @return array{currentFilters: array<string, string>, status: string, priority: string, category: string, project_id: string, search: string, hide_done: string}
	 */
	public function resolveFromRequest(IRequest $request, ?int $scopedProjectId, array $allowedProjectIds): array
	{
		$status = $this->sanitizeStatus((string)$request->getParam('status', ''));
		$priority = $this->sanitizePriority((string)$request->getParam('priority', ''));
		$category = $this->sanitizeCategory((string)$request->getParam('category', ''));
		$projectId = $this->sanitizeProjectId((string)$request->getParam('project_id', ''), $allowedProjectIds);
		$search = $this->sanitizeSearch(trim((string)$request->getParam('search', '')));
		$hideDone = $this->sanitizeHideDone((string)$request->getParam('hide_done', '1'));

		if ($scopedProjectId !== null && $scopedProjectId > 0) {
			$projectId = (string)$scopedProjectId;
		}

		$currentFilters = [
			'status' => $status,
			'priority' => $priority,
			'category' => $category,
			'project_id' => $projectId,
			'search' => $search,
			'hide_done' => $hideDone,
		];

		return array_merge($currentFilters, ['currentFilters' => $currentFilters]);
	}

	/**
	 * @param array<int, Ticket> $tickets
	 * @param array<string, mixed> $resolved
	 * @return list<Ticket>
	 */
	public function apply(array $tickets, array $resolved): array
	{
		$status = (string)($resolved['status'] ?? '');
		$priority = (string)($resolved['priority'] ?? '');
		$category = (string)($resolved['category'] ?? '');
		$projectId = (string)($resolved['project_id'] ?? '');
		$search = strtolower(trim((string)($resolved['search'] ?? '')));
		$hideDone = (string)($resolved['hide_done'] ?? '1');

		$normalizedStatusFilter = $status !== '' ? PortalTicketDisplay::normalizedStatus($status) : '';
		$isFilteringByDone = $normalizedStatusFilter === Ticket::STATUS_DONE;

		return array_values(array_filter(
			$tickets,
			static function (Ticket $ticket) use ($normalizedStatusFilter, $priority, $category, $projectId, $search, $hideDone, $isFilteringByDone): bool {
				if ($normalizedStatusFilter !== ''
					&& PortalTicketDisplay::normalizedStatus((string)$ticket->getStatus()) !== $normalizedStatusFilter) {
					return false;
				}
				if ($priority !== '' && $ticket->getPriority() !== $priority) {
					return false;
				}
				if ($category !== '' && $ticket->getCategory() !== $category) {
					return false;
				}
				if ($projectId !== '' && (string)$ticket->getProjectId() !== $projectId) {
					return false;
				}
				if ($hideDone === '1' && !$isFilteringByDone
					&& PortalTicketDisplay::isDoneStatus((string)$ticket->getStatus())) {
					return false;
				}
				if ($search !== '') {
					$haystack = strtolower(implode(' ', [
						(string)$ticket->getTitle(),
						(string)$ticket->getDescription(),
						(string)$ticket->getTicketNumber(),
					]));
					if (!str_contains($haystack, $search)) {
						return false;
					}
				}

				return true;
			},
		));
	}

	/**
	 * Whether narrowing filters are active (excluding the “show done tickets” preference).
	 *
	 * @param array<string, string> $currentFilters
	 */
	public function hasActiveFilters(array $currentFilters): bool
	{
		foreach (['status', 'priority', 'category', 'project_id', 'search'] as $key) {
			$value = $currentFilters[$key] ?? '';
			if ($value !== null && $value !== '') {
				return true;
			}
		}

		return false;
	}

	private function sanitizeStatus(string $status): string
	{
		$status = strtolower(trim($status));
		if ($status === '') {
			return '';
		}

		$normalized = PortalTicketDisplay::normalizedStatus($status);
		if (!in_array($normalized, Ticket::getStatuses(), true)) {
			return '';
		}

		return $normalized;
	}

	private function sanitizePriority(string $priority): string
	{
		$priority = strtolower(trim($priority));

		return in_array($priority, Ticket::getPriorities(), true) ? $priority : '';
	}

	private function sanitizeCategory(string $category): string
	{
		$category = trim($category);

		return in_array($category, Ticket::getCategories(), true) ? $category : '';
	}

	/**
	 * @param list<int> $allowedProjectIds
	 */
	private function sanitizeProjectId(string $projectId, array $allowedProjectIds): string
	{
		$projectId = trim($projectId);
		if ($projectId === '' || !ctype_digit($projectId)) {
			return '';
		}

		$id = (int)$projectId;
		if ($id <= 0 || !in_array($id, $allowedProjectIds, true)) {
			return '';
		}

		return (string)$id;
	}

	private function sanitizeSearch(string $search): string
	{
		if ($search === '') {
			return '';
		}

		if (mb_strlen($search) > self::MAX_SEARCH_LENGTH) {
			$search = mb_substr($search, 0, self::MAX_SEARCH_LENGTH);
		}

		return $search;
	}

	private function sanitizeHideDone(string $hideDone): string
	{
		return $hideDone === '0' ? '0' : '1';
	}
}

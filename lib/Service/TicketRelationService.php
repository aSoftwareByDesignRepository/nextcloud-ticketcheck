<?php

declare(strict_types=1);

/**
 * Ticket side-relations: links, watchers, surveys.
 *
 * Kept in one place so delete and merge never leave orphan rows or drop CC/links silently.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\TicketLinkMapper;
use OCA\Ticketcheck\Db\TicketSurveyMapper;
use OCA\Ticketcheck\Db\TicketWatcherMapper;
use Psr\Log\LoggerInterface;

/**
 * Cascade cleanup and merge transfer for ticket relation tables
 * (no FK constraints on helpdesk_ticket_links / watchers / surveys).
 */
class TicketRelationService
{
	public function __construct(
		private readonly TicketLinkMapper $linkMapper,
		private readonly TicketWatcherMapper $watcherMapper,
		private readonly TicketSurveyMapper $surveyMapper,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Delete all link / watcher / survey rows that reference a ticket.
	 *
	 * @return array{links: int, watchers: int, surveys: int}
	 */
	public function purgeForTicket(int $ticketId): array
	{
		if ($ticketId <= 0) {
			return ['links' => 0, 'watchers' => 0, 'surveys' => 0];
		}

		$links = $this->linkMapper->deleteByTicketIdEitherSide($ticketId);
		$watchers = $this->watcherMapper->deleteByTicketId($ticketId);
		$surveys = $this->surveyMapper->deleteByTicketId($ticketId);

		if ($links > 0 || $watchers > 0 || $surveys > 0) {
			$this->logger->info('Purged ticket relations', [
				'ticket_id' => $ticketId,
				'links' => $links,
				'watchers' => $watchers,
				'surveys' => $surveys,
			]);
		}

		return [
			'links' => $links,
			'watchers' => $watchers,
			'surveys' => $surveys,
		];
	}

	/**
	 * Move relations from a merged-away source onto the surviving target.
	 *
	 * Watchers: transfer unique users; drop duplicates already on target.
	 * Links: re-point both sides; drop self-loops and unique-constraint collisions.
	 * Surveys: keep target survey if present; otherwise move source survey.
	 *
	 * @return array{links_moved: int, links_dropped: int, watchers_moved: int, watchers_dropped: int, surveys_moved: int, surveys_dropped: int}
	 */
	public function transferOnMerge(int $sourceId, int $targetId): array
	{
		if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
			return [
				'links_moved' => 0,
				'links_dropped' => 0,
				'watchers_moved' => 0,
				'watchers_dropped' => 0,
				'surveys_moved' => 0,
				'surveys_dropped' => 0,
			];
		}

		$linkStats = $this->linkMapper->repointTicketOnMerge($sourceId, $targetId);
		$watcherStats = $this->watcherMapper->moveToTicket($sourceId, $targetId);
		$surveyStats = $this->surveyMapper->moveOrDropOnMerge($sourceId, $targetId);

		$this->logger->info('Transferred ticket relations on merge', [
			'source_id' => $sourceId,
			'target_id' => $targetId,
			'links_moved' => $linkStats['moved'],
			'links_dropped' => $linkStats['dropped'],
			'watchers_moved' => $watcherStats['moved'],
			'watchers_dropped' => $watcherStats['dropped'],
			'surveys_moved' => $surveyStats['moved'],
			'surveys_dropped' => $surveyStats['dropped'],
		]);

		return [
			'links_moved' => $linkStats['moved'],
			'links_dropped' => $linkStats['dropped'],
			'watchers_moved' => $watcherStats['moved'],
			'watchers_dropped' => $watcherStats['dropped'],
			'surveys_moved' => $surveyStats['moved'],
			'surveys_dropped' => $surveyStats['dropped'],
		];
	}

	/**
	 * Counts used by deletion impact analysis.
	 *
	 * @return array{links: int, watchers: int, surveys: int}
	 */
	public function countForTicket(int $ticketId): array
	{
		if ($ticketId <= 0) {
			return ['links' => 0, 'watchers' => 0, 'surveys' => 0];
		}

		return [
			'links' => $this->linkMapper->countByTicketIdEitherSide($ticketId),
			'watchers' => $this->watcherMapper->countByTicketId($ticketId),
			'surveys' => $this->surveyMapper->countByTicketId($ticketId),
		];
	}
}

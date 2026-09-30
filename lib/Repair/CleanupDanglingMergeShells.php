<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Repair;

use OCA\Ticketcheck\Service\TicketService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Purge merge shells whose merged_into_id points at a deleted survivor.
 */
final class CleanupDanglingMergeShells implements IRepairStep
{
	public function __construct(
		private readonly TicketService $ticketService,
		private readonly LoggerInterface $logger,
	) {
	}

	public function getName(): string
	{
		return 'Cleanup Ticketcheck dangling merge shells';
	}

	public function run(IOutput $output): void
	{
		try {
			$removed = $this->ticketService->cleanupDanglingMergeShells(200);
		} catch (\Throwable $e) {
			$this->logger->error('Ticketcheck dangling merge shell cleanup failed', ['exception' => $e]);
			$output->warning('Ticketcheck: dangling merge shell cleanup failed — see log.');
			return;
		}

		if ($removed === 0) {
			$output->info('Ticketcheck: no dangling merge shells found.');
			return;
		}

		$output->info('Ticketcheck: removed ' . $removed . ' dangling merge shell(s).');
	}
}

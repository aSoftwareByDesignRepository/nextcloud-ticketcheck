<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\BackgroundJob;

use OCA\Ticketcheck\Service\IdempotencyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly purge of expired `tc_idempotency` rows so the companion create cache
 * cannot grow without bound after the 24h TTL.
 */
final class IdempotencyPurgeJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private IdempotencyService $idempotency,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60);
	}

	protected function run($argument): void
	{
		try {
			$deleted = $this->idempotency->purgeExpired();
			if ($deleted > 0) {
				$this->logger->debug(
					'TicketCheck: idempotency purge removed {deleted} rows',
					['app' => 'ticketcheck', 'deleted' => $deleted],
				);
			}
		} catch (\Throwable $e) {
			// best-effort: a failed hourly purge must not kill the cron run —
			// the next tick retries; growth is bounded by the 24h TTL.
			$this->logger->warning(
				'TicketCheck: idempotency purge failed: {error}',
				[
					'app' => 'ticketcheck',
					'error' => $e->getMessage(),
					'exception' => $e,
				],
			);
		}
	}
}

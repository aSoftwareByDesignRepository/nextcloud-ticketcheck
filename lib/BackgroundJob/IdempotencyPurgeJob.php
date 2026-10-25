<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\BackgroundJob;

use OCA\Ticketcheck\Service\IdempotencyService;
use OCA\Ticketcheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
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
		private ILockingProvider $locking,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60);
	}

	protected function run($argument): void
	{
		// Shared lock on the backup key: an admin restoring an upgrade
		// snapshot holds it exclusively — the purge skips rather than
		// deleting rows mid-restore.
		try {
			$this->locking->acquireLock(
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
				'TicketCheck idempotency purge',
			);
		} catch (LockedException) {
			$this->logger->info(
				'TicketCheck: skipping idempotency purge — upgrade backup/restore in progress',
				['app' => 'ticketcheck'],
			);
			return;
		}
		try {
			$this->runLocked();
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}

	private function runLocked(): void
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

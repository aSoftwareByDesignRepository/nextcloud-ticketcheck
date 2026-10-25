<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\BackgroundJob;

use OCA\Ticketcheck\Service\InboundMailSyncService;
use OCA\Ticketcheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Polls the configured inbound IMAP mailbox every 15 minutes.
 * Failure isolation: a sync error is logged, never thrown — the Nextcloud
 * cron runner must not die on a mail server's bad day (spec NN-03).
 */
final class InboundMailSyncJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private InboundMailSyncService $sync,
		private LoggerInterface $logger,
		private ILockingProvider $locking,
	) {
		parent::__construct($time);
		$this->setInterval(15 * 60);
	}

	protected function run($argument): void
	{
		// Shared lock on the backup key: an admin restoring an upgrade
		// snapshot holds it exclusively — the sync skips rather than
		// inserting tickets/messages mid-restore.
		try {
			$this->locking->acquireLock(
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
				'TicketCheck inbound mail sync',
			);
		} catch (LockedException) {
			$this->logger->info(
				'TicketCheck: skipping inbound mail sync — upgrade backup/restore in progress',
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
			$result = $this->sync->sync();
			if (($result['status'] ?? '') === 'error') {
				$this->logger->warning('TicketCheck inbound sync error: {reason}', [
					'app' => 'ticketcheck',
					'reason' => $result['reason'] ?? 'unknown',
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->error('TicketCheck inbound sync crashed: {error}', [
				'app' => 'ticketcheck',
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}
}

<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\BackgroundJob;

use OCA\Ticketcheck\Service\InboundMailSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
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
	) {
		parent::__construct($time);
		$this->setInterval(15 * 60);
	}

	protected function run($argument): void
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

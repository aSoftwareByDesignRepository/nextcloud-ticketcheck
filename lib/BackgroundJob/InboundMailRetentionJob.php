<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\BackgroundJob;

use OCA\Ticketcheck\Db\InboundMessageMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily retention purge for helpdesk_inbound_msgs (spec NN-17):
 * terminal rows older than 30 days are dropped. In-flight rows are
 * never purged here — the claim staleness window owns them.
 */
final class InboundMailRetentionJob extends TimedJob
{
	private const RETENTION_DAYS = 30;

	public function __construct(
		ITimeFactory $time,
		private InboundMessageMapper $mapper,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);
	}

	protected function run($argument): void
	{
		try {
			$cutoff = (new \DateTime())->modify('-' . self::RETENTION_DAYS . ' days');
			$deleted = $this->mapper->purgeFinishedBefore($cutoff);
			if ($deleted > 0) {
				$this->logger->info('TicketCheck: inbound mail retention purged {deleted} rows', [
					'app' => 'ticketcheck',
					'deleted' => $deleted,
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('TicketCheck: inbound retention purge failed: {error}', [
				'app' => 'ticketcheck',
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}
}

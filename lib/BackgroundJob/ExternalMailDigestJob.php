<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\BackgroundJob;

use OCA\Ticketcheck\Db\MailOutboxMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\EmailService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * NH-03: hourly recovery digest for cap-suppressed external-leg events.
 * When the (ticket,address) hourly cap drops customer mail, each dropped
 * event is ledgered as an 'extdrop:' row. This job bundles those drops into
 * at most one digest mail per (ticket,address) per hour — customers learn
 * that activity happened without reopening the flood gate. Opt-out, the
 * kill switch, and address validity are re-checked inside
 * EmailService::sendExternalLegDigest.
 */
final class ExternalMailDigestJob extends TimedJob
{
	/** Drops older than this are stale — the window has fully rolled. */
	private const SCAN_WINDOW_MINUTES = 75;

	/** Minimum gap between two digests to the same (ticket,address). */
	private const DIGEST_GAP_MINUTES = 60;

	public function __construct(
		ITimeFactory $time,
		private MailOutboxMapper $outbox,
		private TicketMapper $tickets,
		private EmailService $emailService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60);
	}

	protected function run($argument): void
	{
		$since = new \DateTime('-' . self::SCAN_WINDOW_MINUTES . ' minutes');
		foreach ($this->outbox->cappedExternalLegPairs($since) as $pair) {
			try {
				$this->processPair((int)$pair['ticket_id'], (string)$pair['email']);
			} catch (\Throwable $e) {
				// One bad pair must not starve the rest of the queue.
				$this->logger->warning('TicketCheck: external digest pair failed', [
					'app' => 'ticketcheck',
					'ticket_id' => $pair['ticket_id'],
					'exception' => $e,
				]);
			}
		}
	}

	private function processPair(int $ticketId, string $email): void
	{
		$lastDigest = $this->outbox->lastExternalDigestTime($ticketId, $email);
		$gapBoundary = new \DateTime('-' . self::DIGEST_GAP_MINUTES . ' minutes');
		if ($lastDigest !== null && $lastDigest > $gapBoundary) {
			return;
		}
		$countSince = $lastDigest ?? new \DateTime('-' . self::DIGEST_GAP_MINUTES . ' minutes');
		$dropped = $this->outbox->cappedExternalLegCount($ticketId, $email, $countSince);
		if ($dropped === 0) {
			return;
		}
		try {
			$ticket = $this->tickets->find($ticketId);
		} catch (\Throwable) {
			// Ticket deleted between drop and tick — nothing to digest.
			return;
		}
		$this->emailService->sendExternalLegDigest($ticket, $email, $dropped);
	}
}

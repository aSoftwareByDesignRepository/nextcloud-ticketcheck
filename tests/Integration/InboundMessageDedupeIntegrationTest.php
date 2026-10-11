<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Integration;

use OCA\Ticketcheck\Db\InboundMessage;
use OCA\Ticketcheck\Db\InboundMessageMapper;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Real-DB verification of the claim-before-process dedupe contract:
 * first insert wins, terminal rows are duplicates, still-processing rows
 * stay non-terminal until the stale window, and stale claims are
 * reclaimed with a bumped attempt counter.
 */
final class InboundMessageDedupeIntegrationTest extends TestCase
{
	private InboundMessageMapper $mapper;

	protected function setUp(): void
	{
		parent::setUp();

		$appManager = \OC::$server->get(\OCP\App\IAppManager::class);
		if (!$appManager->isEnabledForUser('ticketcheck')) {
			$installer = \OC::$server->get(\OC\Installer::class);
			$installer->installApp('ticketcheck');
			$appManager->enableApp('ticketcheck');
		}
		$this->mapper = new InboundMessageMapper(\OC::$server->get(IDBConnection::class));

		// Clean slate: purge rows from prior runs.
		$db = \OC::$server->get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->delete('helpdesk_inbound_msgs')->executeStatement();
	}

	private function row(string $hash, int $uid = 0): InboundMessage
	{
		$row = new InboundMessage();
		$row->setMailboxId('default');
		$row->setUid($uid);
		$row->setUidvalidity(1);
		$row->setMsgHash($hash);
		$row->setChannel('imap');
		$row->setAction(InboundMessage::ACTION_PROCESSING);
		$row->setAttempts(1);
		$row->setClaimedAt(new \DateTime());
		$row->setReceivedAt(new \DateTime());
		return $row;
	}

	public function testFirstClaimWinsAndTerminalIsDuplicate(): void
	{
		$a = $this->mapper->claim($this->row('hash-a', 10));
		self::assertSame('claimed', $a['status']);

		$this->mapper->finish($a['row'], InboundMessage::ACTION_TICKET_CREATED, 7);

		// Second claim on the same hash: terminal → duplicate with stored result.
		$b = $this->mapper->claim($this->row('hash-a', 10));
		self::assertSame('duplicate', $b['status']);
		self::assertSame(InboundMessage::ACTION_TICKET_CREATED, $b['row']->getAction());
		self::assertSame(7, $b['row']->getTicketId());
	}

	public function testActiveProcessingRowIsDuplicateNotReclaimed(): void
	{
		$a = $this->mapper->claim($this->row('hash-b', 11));
		self::assertSame('claimed', $a['status']);

		// Same hash again while still processing: duplicate, NOT a reclaim —
		// the second worker must not double-post the comment.
		$b = $this->mapper->claim($this->row('hash-b', 11));
		self::assertSame('duplicate', $b['status']);
		self::assertSame(InboundMessage::ACTION_PROCESSING, $b['row']->getAction());
	}

	public function testStaleClaimIsReclaimedWithBumpedAttempts(): void
	{
		$row = $this->row('hash-c', 12);
		// Pretend the first claim died 20 minutes ago.
		$row->setClaimedAt((new \DateTime())->modify('-20 minutes'));
		$a = $this->mapper->claim($row);
		self::assertSame('claimed', $a['status']);

		$b = $this->mapper->claim($this->row('hash-c', 12), 900);
		self::assertSame('reclaim', $b['status']);
		self::assertSame(2, $b['row']->getAttempts());
	}

	public function testUidRowsAreNotUniqueAcrossChannels(): void
	{
		// Webhook rows all share uid=0/uidvalidity=0 — multiple must coexist
		// (dedupe lives on msg_hash, not uid).
		$a = $this->mapper->claim($this->row('hash-d1', 0));
		$b = $this->mapper->claim($this->row('hash-d2', 0));
		self::assertSame('claimed', $a['status']);
		self::assertSame('claimed', $b['status']);
	}

	public function testPurgeOnlyFinishedRows(): void
	{
		$a = $this->mapper->claim($this->row('hash-e1', 20));
		$this->mapper->finish($a['row'], InboundMessage::ACTION_COMMENT, 1, 2);

		$b = $this->mapper->claim($this->row('hash-e2', 21));
		// Leave $b processing (unfinished).

		$cutoff = (new \DateTime())->modify('-1 day');
		// Age the finished row's updated_at past the cutoff.
		$db = \OC::$server->get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->update('helpdesk_inbound_msgs')
			->set('finished_at', $qb->createNamedParameter((new \DateTime())->modify('-2 days')->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('msg_hash', $qb->createNamedParameter('hash-e1')))
			->executeStatement();

		$purged = $this->mapper->purgeFinishedBefore($cutoff);
		self::assertSame(1, $purged);
		self::assertNotNull($this->mapper->findByMsgHash('hash-e2'));
	}
}

<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Integration;

use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\InboundMessage;
use OCA\Ticketcheck\Db\InboundMessageMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\InboundEmailService;
use OCA\Ticketcheck\Service\ReplyTokenService;
use OCA\Ticketcheck\Service\TicketService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Real-DB proof of the NH-04 thread fallback end to end: a reply whose
 * +address and subject carry no ticket signal routes via the token-bound
 * Message-ID in In-Reply-To — through the real claim→route→authz→comment
 * pipeline, with the dedupe row landing on the same real MariaDB.
 */
final class InboundThreadRoutingIntegrationTest extends TestCase
{
	private InboundEmailService $service;
	private TicketService $ticketService;
	private InboundMessageMapper $inboundMapper;
	private IAppConfig $appConfig;
	private ?string $previousAddress = null;

	protected function setUp(): void
	{
		parent::setUp();

		$appManager = \OC::$server->get(\OCP\App\IAppManager::class);
		if (!$appManager->isEnabledForUser('ticketcheck')) {
			$installer = \OC::$server->get(\OC\Installer::class);
			$installer->installApp('ticketcheck');
			$appManager->enableApp('ticketcheck');
		}

		$this->service = \OC::$server->get(InboundEmailService::class);
		$this->ticketService = \OC::$server->get(TicketService::class);
		$this->inboundMapper = \OC::$server->get(InboundMessageMapper::class);
		$this->appConfig = \OC::$server->get(IAppConfig::class);

		// Point the inbound address at a deterministic test mailbox and
		// remember the previous value so teardown restores it.
		$this->previousAddress = $this->appConfig->getValueString(
			'ticketcheck', 'inbound_imap_address', '', lazy: true
		);
		$this->appConfig->setValueString('ticketcheck', 'inbound_imap_address', 'support@example.com');

		$db = \OC::$server->get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->delete('helpdesk_inbound_msgs')->executeStatement();
	}

	protected function tearDown(): void
	{
		if ($this->previousAddress !== null) {
			$this->appConfig->setValueString('ticketcheck', 'inbound_imap_address', $this->previousAddress);
		}
		parent::tearDown();
	}

	public function testThreadReferenceReplyLandsCommentOnRealDatabase(): void
	{
		$customerEmail = 'threadcustomer@example.com';

		// Real ticket owned by the customer — created exactly the way the
		// inbound pipeline creates mail-originated tickets.
		$ticket = $this->ticketService->createTicketFromEmail([
			'title' => 'Thread routing fixture',
			'description' => 'Initial mail body.',
			'customer_email' => $customerEmail,
			'customer_name' => 'Thread Customer',
		]);
		$ticketId = (int)$ticket->getId();

		try {
			// The token a real outbound notification would embed — real
			// system secret, real HMAC binding.
			$token = \OC::$server->get(ReplyTokenService::class)
				->token($ticketId, $customerEmail);

			$msg = [
				'from' => $customerEmail,
				'fromName' => 'Thread Customer',
				// Plain mailbox recipient — no +tag, no ticket id anywhere.
				'to' => ['support@example.com'],
				'cc' => [],
				// Subject carries a DIFFERENT ticket number — the mangle
				// case threading exists to survive.
				'subject' => 'Re: ticket #99999 update',
				'messageId' => '<reply-' . bin2hex(random_bytes(6)) . '@client.example>',
				'rawHeader' => "From: {$customerEmail}\r\n"
					. "References: <unrelated@chain.example>\r\n"
					. "In-Reply-To: <tc.ticket.{$ticketId}-{$token}.a1b2c3d4@example.com>\r\n",
				'text' => "Reply via threading header.",
				'html' => '',
				'internalDate' => null,
			];

			$result = $this->service->processInboundMessage($msg, InboundMessage::CHANNEL_IMAP, 9101, 1);

			self::assertSame(InboundMessage::ACTION_COMMENT, $result['action']);
			self::assertSame($ticketId, $result['ticket_id']);

			// Ground truth: the comment row exists in MariaDB, authored by
			// the customer, on the thread-resolved ticket.
			$commentId = (int)$result['comment_id'];
			$comment = \OC::$server->get(CommentMapper::class)->find($commentId);
			self::assertSame($ticketId, $comment->getTicketId());
			self::assertSame($customerEmail, $comment->getAuthorEmail());
			self::assertStringContainsString('Reply via threading header', (string)$comment->getContent());

			// And the dedupe row finished on the comment action.
			$row = $this->inboundMapper->findByMsgHash(
				\OCA\Ticketcheck\Service\InboundMailClassifier::msgHash(
					$msg['messageId'], $customerEmail, $msg['subject'], null, 9101
				)
			);
			self::assertNotNull($row);
			self::assertSame(InboundMessage::ACTION_COMMENT, $row->getAction());
			self::assertSame($ticketId, $row->getTicketId());
		} finally {
			// Remove the fixture ticket + its comments + dedupe rows so the
			// suite leaves the shared DB as it found it.
			$db = \OC::$server->get(IDBConnection::class);
			foreach (['helpdesk_comments', 'helpdesk_inbound_msgs'] as $table) {
				$qb = $db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId)));
				if ($table === 'helpdesk_inbound_msgs') {
					$qb->orWhere($qb->expr()->eq('mailbox_id', $qb->createNamedParameter('default')));
				}
				$qb->executeStatement();
			}
			try {
				\OC::$server->get(TicketMapper::class)->delete($ticket);
			} catch (\Throwable) {
				// Ticket may already be gone — teardown must not fail the test.
			}
		}
	}

	public function testForgedThreadReferenceLandsBadTokenOnRealDatabase(): void
	{
		$customerEmail = 'forgevictim@example.com';
		$ticket = $this->ticketService->createTicketFromEmail([
			'title' => 'Forge fixture',
			'description' => 'Body.',
			'customer_email' => $customerEmail,
		]);
		$ticketId = (int)$ticket->getId();

		try {
			$msg = [
				'from' => $customerEmail,
				'to' => ['support@example.com'],
				'cc' => [],
				'subject' => 'Re: whatever',
				'messageId' => '<forged-' . bin2hex(random_bytes(6)) . '@evil.example>',
				// Attacker guesses the pattern but cannot forge the HMAC.
				'rawHeader' => "In-Reply-To: <tc.ticket.{$ticketId}-deadbeef00@example.com>\r\n",
				'text' => 'Injected comment attempt.',
				'html' => '',
			];

			$result = $this->service->processInboundMessage($msg, InboundMessage::CHANNEL_IMAP, 9102, 1);

			self::assertSame(InboundMessage::ACTION_IGNORED_BAD_TOKEN, $result['action']);

			// No comment may exist for this content on the victim ticket.
			$db = \OC::$server->get(IDBConnection::class);
			$qb = $db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'c'))
				->from('helpdesk_comments')
				->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId)));
			self::assertSame(0, (int)$qb->executeQuery()->fetchOne());
		} finally {
			$db = \OC::$server->get(IDBConnection::class);
			foreach (['helpdesk_comments', 'helpdesk_inbound_msgs'] as $table) {
				$qb = $db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId)));
				if ($table === 'helpdesk_inbound_msgs') {
					$qb->orWhere($qb->expr()->eq('mailbox_id', $qb->createNamedParameter('default')));
				}
				$qb->executeStatement();
			}
			try {
				\OC::$server->get(TicketMapper::class)->delete($ticket);
			} catch (\Throwable) {
			}
		}
	}
}

<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\InboundMessage;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\InboundAttachmentIngestService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\InboundEmailService;
use OCA\Ticketcheck\Service\InboundMailAuthService;
use OCA\Ticketcheck\Service\InboundMailConfigService;
use OCA\Ticketcheck\Service\InboundMailSyncService;
use OCA\Ticketcheck\Support\ImapSyncClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Lock\LockedException;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class InboundMailSyncServiceTest extends TestCase
{
	/** @var array<string, string> */
	private array $store = [];
	private InboundMailConfigService $mailboxConfig;
	private ILockingProvider $locking;
	private InboundEmailService $processor;
	private InboundMailSyncService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->store = [
			'inbound_imap_enabled' => 'yes',
			'inbound_imap_address' => 'support@example.com',
			'inbound_imap_host' => 'imap.example.com',
			'inbound_imap_port' => '993',
			'inbound_imap_enc' => 'ssl',
			'inbound_imap_username' => 'support@example.com',
			'inbound_imap_secret_enc' => 'ENC(pw)',
			'inbound_imap_folder' => 'INBOX',
			'inbound_imap_last_uid' => '50',
			'inbound_imap_uidvalidity' => '42',
		];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default);
		$appConfig->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			});
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(fn (string $k, $d = '') => $k === 'secret' ? 'x' : $d);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(fn (string $v) => str_starts_with($v, 'ENC(') ? substr($v, 4, -1) : '');
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $k) => $k);
		$this->mailboxConfig = new InboundMailConfigService($appConfig, $config, $crypto, $l);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1_700_000_000);
		$auth = new InboundMailAuthService($this->mailboxConfig, $timeFactory, $this->createMock(LoggerInterface::class));
		$this->processor = $this->createMock(InboundEmailService::class);
		$this->locking = $this->createMock(ILockingProvider::class);

		$this->service = new InboundMailSyncService(
			$this->mailboxConfig,
			$auth,
			$this->processor,
			$this->ingest(),
			$this->locking,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function ingest(): InboundAttachmentIngestService
	{
		return new InboundAttachmentIngestService(
			$this->createMock(AttachmentUploadService::class),
			$this->createMock(TicketService::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testDisabledReturnsEarly(): void
	{
		$this->store['inbound_imap_enabled'] = 'no';
		self::assertSame(['status' => 'disabled'], $this->service->sync());
	}

	public function testUnconfiguredReturnsEarly(): void
	{
		$this->store['inbound_imap_host'] = '';
		self::assertSame(['status' => 'unconfigured'], $this->service->sync());
	}

	public function testBackoffSkipsRun(): void
	{
		$this->store['inbound_imap_error_count'] = '3';
		$this->store['inbound_imap_last_sync_at'] = date('Y-m-d H:i:s'); // just now
		self::assertSame(['status' => 'backoff'], $this->service->sync());
	}

	public function testLockedSkipsRun(): void
	{
		$this->locking->method('acquireLock')->willThrowException(new LockedException('held'));
		self::assertSame(['status' => 'locked'], $this->service->sync());
	}

	public function testConnectFailureRecordsError(): void
	{
		$r = $this->service->sync(static fn (array $connect) => [
			'ok' => false, 'reason' => 'network', 'detail' => 'refused',
		]);
		self::assertSame('error', $r['status']);
		self::assertSame('network', $r['reason']);
		self::assertStringStartsWith('network', $this->store['inbound_imap_last_error'] ?? 'missing');
		self::assertSame('1', $this->store['inbound_imap_error_count'] ?? 'missing');
	}

	public function testAuthFailureCountsAuthFailures(): void
	{
		$r = $this->service->sync(static fn (array $connect) => [
			'ok' => false, 'reason' => 'auth', 'detail' => 'bad creds',
		]);
		self::assertSame('error', $r['status']);
		self::assertSame('1', $this->store['inbound_imap_auth_fail_count'] ?? 'missing');
	}

	/** Wire fixture: SELECT + SEARCH(empty). */
	public function testEmptyMailboxIsOk(): void
	{
		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		self::assertIsArray($pair);
		[$clientEnd, $serverEnd] = $pair;
		stream_set_blocking($clientEnd, true);
		stream_set_timeout($clientEnd, 5);
		fwrite($serverEnd,
			"* 5 EXISTS\r\n* OK [UIDVALIDITY 42] ok\r\n* OK [UIDNEXT 51] ok\r\nT0001 OK SELECT\r\n"
			. "* SEARCH\r\nT0002 OK SEARCH\r\n"
		);
		$client = ImapSyncClient::fromStream($clientEnd);

		$r = $this->service->sync(static fn (array $connect) => ['ok' => true, 'client' => $client]);
		self::assertSame('ok', $r['status']);
		self::assertSame(0, $r['processed']);
		fclose($serverEnd);
	}

	/** Full single-message wire fixture → created → cursor advances + \Seen. */
	public function testSingleMessageCreatedAdvancesCursorAndStoresSeen(): void
	{
		$header = "From: customer@example.com\r\nTo: support@example.com\r\nSubject: Need help\r\nMessage-ID: <m1@x>\r\n\r\n";
		$body = 'Please help.';
		$bodyStruct = '(("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" ' . strlen($body) . ' 1)'
			. '("APPLICATION" "PDF" ("NAME" "a.pdf") NIL NIL "BASE64" 8 NIL ("ATTACHMENT" ("FILENAME" "a.pdf")) NIL)'
			. ' "MIXED")';
		$pdfB64 = base64_encode('pdfdata');

		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		self::assertIsArray($pair);
		[$clientEnd, $serverEnd] = $pair;
		stream_set_blocking($clientEnd, true);
		stream_set_timeout($clientEnd, 5);
		fwrite($serverEnd,
			"* 6 EXISTS\r\n* OK [UIDVALIDITY 42] ok\r\n* OK [UIDNEXT 52] ok\r\nT0001 OK SELECT\r\n"
			. "* SEARCH 51\r\nT0002 OK SEARCH\r\n"
			// FETCH meta for uid 51 (header literal)
			. '* 1 FETCH (UID 51 INTERNALDATE "10-Oct-2026 12:00:00 +0000" FLAGS () BODY[HEADER] {' . strlen($header) . "}\r\n"
			. $header . ")\r\nT0003 OK FETCH\r\n"
			// BODYSTRUCTURE
			. '* 1 FETCH (BODYSTRUCTURE ' . $bodyStruct . ")\r\nT0004 OK FETCH\r\n"
			// text part 1
			. '* 1 FETCH (BODY[1] {' . strlen($body) . "}\r\n" . $body . ")\r\nT0005 OK FETCH\r\n"
			// attachment part 2
			. '* 1 FETCH (BODY[2] {' . strlen($pdfB64) . "}\r\n" . $pdfB64 . ")\r\nT0006 OK FETCH\r\n"
			// STORE \Seen
			. "T0007 OK STORE\r\n"
		);
		$client = ImapSyncClient::fromStream($clientEnd);

		$this->processor->expects(self::once())
			->method('processInboundMessage')
			->with(
				self::callback(fn (array $msg) =>
					$msg['from'] === 'customer@example.com'
					&& str_contains($msg['text'], 'Please help')
				),
				InboundMessage::CHANNEL_IMAP,
				51,
				42,
				self::anything()
			)
			->willReturn(['action' => InboundMessage::ACTION_TICKET_CREATED, 'ticket_id' => 7]);

		$attachments = $this->ingest();
		$service = new InboundMailSyncService(
			$this->mailboxConfig,
			new InboundMailAuthService($this->mailboxConfig, $this->createMock(ITimeFactory::class), $this->createMock(LoggerInterface::class)),
			$this->processor,
			$attachments,
			$this->locking,
			$this->createMock(LoggerInterface::class),
		);

		$r = $service->sync(static fn (array $connect) => ['ok' => true, 'client' => $client]);
		self::assertSame('ok', $r['status']);
		self::assertSame(1, $r['created']);
		self::assertSame('51', $this->store['inbound_imap_last_uid']);
		fclose($serverEnd);
	}

	/** Retryable outcome: cursor must NOT advance past the blocked uid. */
	public function testRetryableMessageHoldsCursor(): void
	{
		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		[$clientEnd, $serverEnd] = $pair;
		stream_set_blocking($clientEnd, true);
		stream_set_timeout($clientEnd, 5);
		$header = "From: a@b.com\r\nTo: support@example.com\r\nSubject: x\r\n\r\n";
		fwrite($serverEnd,
			"* 6 EXISTS\r\n* OK [UIDVALIDITY 42] ok\r\n* OK [UIDNEXT 53] ok\r\nT0001 OK SELECT\r\n"
			. "* SEARCH 51 52\r\nT0002 OK SEARCH\r\n"
			. '* 1 FETCH (UID 51 INTERNALDATE "10-Oct-2026 12:00:00 +0000" FLAGS () BODY[HEADER] {' . strlen($header) . "}\r\n" . $header . ")\r\n"
			. '* 2 FETCH (UID 52 INTERNALDATE "10-Oct-2026 12:00:00 +0000" FLAGS () BODY[HEADER] {' . strlen($header) . "}\r\n" . $header . ")\r\n"
			. "T0003 OK FETCH\r\n"
			. '* 1 FETCH (BODYSTRUCTURE ("TEXT" "PLAIN" NIL NIL NIL "7BIT" 1 1))' . "\r\nT0004 OK FETCH\r\n"
			. '* 1 FETCH (BODY[1] {5}' . "\r\n" . 'hello' . ")\r\nT0005 OK FETCH\r\n"
			. '* 2 FETCH (BODYSTRUCTURE ("TEXT" "PLAIN" NIL NIL NIL "7BIT" 1 1))' . "\r\nT0006 OK FETCH\r\n"
			. '* 2 FETCH (BODY[1] {5}' . "\r\n" . 'hello' . ")\r\nT0007 OK FETCH\r\n"
		);
		$client = ImapSyncClient::fromStream($clientEnd);

		$this->processor->method('processInboundMessage')
			->willReturnOnConsecutiveCalls(
				['action' => InboundMessage::ACTION_ERROR_RETRYABLE],
				['action' => InboundMessage::ACTION_COMMENT, 'ticket_id' => 9, 'comment_id' => 3]
			);

		$r = $this->service->sync(static fn (array $connect) => ['ok' => true, 'client' => $client]);
		self::assertSame('ok', $r['status']);
		self::assertSame(1, $r['errors']);
		self::assertSame(1, $r['commented']);
		// uid 51 blocked the cursor — it must not advance past it even though
		// uid 52 terminated cleanly (out-of-order terminal allowed).
		self::assertSame('50', $this->store['inbound_imap_last_uid']);
		// 1 of 2 messages unfinished → backlog 1.
		self::assertSame('1', $this->store['inbound_imap_unseen_backlog']);
		fclose($serverEnd);
	}

	/**
	 * UNSEEN triage policy (DEC-20): ignored_unauthorized mails are terminal
	 * (cursor advances) but must NOT be \Seen-marked — an admin reviews them.
	 * The wire fixture intentionally omits the STORE reply: if the client
	 * issued STORE \Seen anyway, the tagged-response wait would fail.
	 */
	public function testIgnoredUnauthorizedStaysUnseenButAdvancesCursor(): void
	{
		$header = "From: stranger@example.com\r\nTo: support+3-x@example.com\r\nSubject: Re: t\r\n\r\n";
		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		[$clientEnd, $serverEnd] = $pair;
		stream_set_blocking($clientEnd, true);
		stream_set_timeout($clientEnd, 5);
		fwrite($serverEnd,
			"* 6 EXISTS\r\n* OK [UIDVALIDITY 42] ok\r\n* OK [UIDNEXT 52] ok\r\nT0001 OK SELECT\r\n"
			. "* SEARCH 51\r\nT0002 OK SEARCH\r\n"
			. '* 1 FETCH (UID 51 INTERNALDATE "10-Oct-2026 12:00:00 +0000" FLAGS () BODY[HEADER] {' . strlen($header) . "}\r\n" . $header . ")\r\n"
			. "T0003 OK FETCH\r\n"
			. '* 1 FETCH (BODYSTRUCTURE ("TEXT" "PLAIN" NIL NIL NIL "7BIT" 1 1))' . "\r\nT0004 OK FETCH\r\n"
			. '* 1 FETCH (BODY[1] {5}' . "\r\n" . 'hello' . ")\r\nT0005 OK FETCH\r\n"
			// No STORE response — a \Seen attempt would block and time out.
		);
		$client = ImapSyncClient::fromStream($clientEnd);

		$this->processor->method('processInboundMessage')
			->willReturn(['action' => InboundMessage::ACTION_IGNORED_UNAUTHORIZED]);

		$r = $this->service->sync(static fn (array $connect) => ['ok' => true, 'client' => $client]);
		self::assertSame('ok', $r['status']);
		self::assertSame(1, $r['ignored']);
		// Terminal: cursor still advances.
		self::assertSame('51', $this->store['inbound_imap_last_uid']);
		// No STORE \Seen may reach the wire (its tagged reply is absent above;
		// a stray STORE would just read EOF — capture the wire instead).
		stream_set_blocking($serverEnd, false);
		$sent = (string)stream_get_contents($serverEnd);
		self::assertStringNotContainsString('STORE', $sent);
		fclose($serverEnd);
	}

	public function testUidvalidityChangeRebaselines(): void
	{
		$this->store['inbound_imap_uidvalidity'] = '42';
		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		[$clientEnd, $serverEnd] = $pair;
		stream_set_blocking($clientEnd, true);
		stream_set_timeout($clientEnd, 5);
		fwrite($serverEnd,
			"* 6 EXISTS\r\n* OK [UIDVALIDITY 99] ok\r\n* OK [UIDNEXT 10] ok\r\nT0001 OK SELECT\r\n"
			. "* SEARCH\r\nT0002 OK SEARCH\r\n"
		);
		$client = ImapSyncClient::fromStream($clientEnd);
		$r = $this->service->sync(static fn (array $connect) => ['ok' => true, 'client' => $client]);
		self::assertSame('ok', $r['status']);
		fclose($serverEnd);
	}
}

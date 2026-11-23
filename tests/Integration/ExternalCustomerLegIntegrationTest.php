<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Integration;

use OCA\Ticketcheck\Db\GuestIdentity;
use OCA\Ticketcheck\Db\GuestIdentityMapper;
use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Db\MailOutboxMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\InboundEmailService;
use OCA\Ticketcheck\Service\ReplyTokenService;
use OCA\Ticketcheck\Service\TicketService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use Test\TestCase;

/**
 * Real-DB, real-DI, real-SMTP proof of the account-less external leg:
 * every ticket shape resolves the same recipient set, delivery is asserted
 * against a live MailHog API, the cap ledger lands real rows, and the
 * outbound→inbound loop closes via a real tokenized reply address.
 *
 * Requires the compose stack (nextcloud + mailhog at mailhog:8025).
 */
final class ExternalCustomerLegIntegrationTest extends TestCase
{
	private TicketService $ticketService;
	private EmailService $emailService;
	private InboundEmailService $inbound;
	private TicketMapper $ticketMapper;
	private GuestProjectAccessMapper $access;
	private GuestIdentityMapper $identities;
	private MailOutboxMapper $outbox;
	private ReplyTokenService $replyTokens;
	private IAppConfig $appConfig;
	private IUserManager $users;
	private IDBConnection $db;

	/** @var array<string, string> */
	private array $savedConfig = [];

	/** @var list<int> */
	private array $ticketIds = [];

	/** @var list<string> */
	private array $uids = [];

	/** @var list<int> */
	private array $identityIds = [];

	private array $optoutKeys = [];

	private const CONFIG_KEYS = [
		'inbound_imap_address',
		'inbound_imap_enabled',
		'email_notifications_enabled',
		'external_customer_mail_enabled',
	];

	protected function setUp(): void
	{
		parent::setUp();

		$appManager = \OC::$server->get(\OCP\App\IAppManager::class);
		if (!$appManager->isEnabledForUser('ticketcheck')) {
			\OC::$server->get(\OC\Installer::class)->installApp('ticketcheck');
			$appManager->enableApp('ticketcheck');
		}

		$this->ticketService = \OC::$server->get(TicketService::class);
		$this->emailService = \OC::$server->get(EmailService::class);
		$this->inbound = \OC::$server->get(InboundEmailService::class);
		$this->ticketMapper = \OC::$server->get(TicketMapper::class);
		$this->access = \OC::$server->get(GuestProjectAccessMapper::class);
		$this->identities = \OC::$server->get(GuestIdentityMapper::class);
		$this->outbox = \OC::$server->get(MailOutboxMapper::class);
		$this->replyTokens = \OC::$server->get(ReplyTokenService::class);
		$this->appConfig = \OC::$server->get(IAppConfig::class);
		$this->users = \OC::$server->get(IUserManager::class);
		$this->db = \OC::$server->get(IDBConnection::class);

		foreach (self::CONFIG_KEYS as $key) {
			$this->savedConfig[$key] = $this->appConfig->getValueString('ticketcheck', $key, '', lazy: true);
		}
		$this->appConfig->setValueString('ticketcheck', 'inbound_imap_address', 'support@example.com');
		$this->appConfig->setValueString('ticketcheck', 'inbound_imap_enabled', 'yes');
		$this->appConfig->setValueString('ticketcheck', 'email_notifications_enabled', 'yes');
		$this->appConfig->setValueString('ticketcheck', 'external_customer_mail_enabled', 'yes');

		$this->purgeMailhog();
	}

	protected function tearDown(): void
	{
		foreach (self::CONFIG_KEYS as $key) {
			$this->appConfig->setValueString('ticketcheck', $key, $this->savedConfig[$key]);
		}
		$qb = $this->db->getQueryBuilder();
		foreach ($this->ticketIds as $id) {
			$qb->delete('helpdesk_comments')->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($id)))->executeStatement();
			foreach (['extmail:', 'extdrop:', 'extdigest:'] as $prefix) {
				$qb->delete('helpdesk_mail_outbox')->where($qb->expr()->like('idempotency_key', $qb->createNamedParameter($prefix . $id . ':%')))->executeStatement();
			}
			try {
				$this->ticketMapper->delete($this->ticketMapper->find($id));
			} catch (\Throwable) {
			}
		}
		foreach ($this->uids as $uid) {
			$this->access->deleteByUser($uid);
			$this->users->get($uid)?->delete();
		}
		foreach ($this->identityIds as $id) {
			try {
				$this->identities->delete($this->identities->find($id));
			} catch (\Throwable) {
			}
		}
		foreach ($this->optoutKeys as $key) {
			try {
				$this->appConfig->deleteKey('ticketcheck', $key);
			} catch (\Throwable) {
			}
		}
		parent::tearDown();
	}

	public function testOptOutSuppressesExternalLegOnRealStore(): void
	{
		$ext = 'it-ext-optout@mailtest.de';
		$ticket = $this->ticketService->createTicketFromEmail([
			'title' => 'IT optout',
			'description' => 'Body',
			'customer_email' => $ext,
			'customer_name' => 'IT Optout',
		]);
		$id = (int)$ticket->getId();
		$this->ticketIds[] = $id;
		self::assertSame(1, $this->mailhogCount($ext));

		$optout = \OC::$server->get(\OCA\Ticketcheck\Service\ExternalMailOptoutService::class);
		$url = $optout->optoutUrl($id, $ext);
		self::assertStringContainsString('t=' . $id, $url);
		self::assertStringNotContainsString($ext, $url, 'opt-out URL must not carry the address');
		// Same hashing the service applies (sha256 prefix, len 32).
		$hash = substr(hash('sha256', $ext), 0, 32);
		$optout->optOut($id, $hash);
		$this->optoutKeys[] = 'extmail_optout:' . $id . ':' . $hash;

		self::assertTrue($optout->isOptedOut($id, $ext));
		$before = $this->outbox->recentExternalLegCount($id, $ext, new \DateTime('-1 hour'));
		$this->emailService->sendTicketUpdatedNotification($ticket, 'post-optout update', 'staff@nc.local');
		self::assertSame(1, $this->mailhogCount($ext), 'opted-out address gets no further mail');
		self::assertSame($before, $this->outbox->recentExternalLegCount($id, $ext, new \DateTime('-1 hour')), 'suppressed send is not ledgered');
	}

	// ---------- ticket shapes ----------

	public function testInboundCreatedAccountlessCustomerReceivesTokenizedMail(): void
	{
		$ext = 'it-ext-inbound@mailtest.de';
		$ticket = $this->ticketService->createTicketFromEmail([
			'title' => 'IT inbound create',
			'description' => 'Body',
			'customer_email' => $ext,
			'customer_name' => 'IT Ext',
		]);
		$id = (int)$ticket->getId();
		$this->ticketIds[] = $id;

		self::assertSame(1, $this->mailhogCount($ext));
		self::assertGreaterThanOrEqual(1, $this->outbox->recentExternalLegCount($id, $ext, new \DateTime('-1 hour')));

		$replyTo = $this->mailhogHeader($ext, 'Reply-To');
		$token = $this->replyTokens->token($id, $ext);
		self::assertNotSame('', $replyTo);
		self::assertStringContainsString('+' . $id . '-' . $token . '@example.com', $replyTo);
	}

	public function testLoopCloseExternalReplyLandsAsComment(): void
	{
		$ext = 'it-ext-loop@mailtest.de';
		$ticket = $this->ticketService->createTicketFromEmail([
			'title' => 'IT loop close',
			'description' => 'Body',
			'customer_email' => $ext,
			'customer_name' => 'IT Loop',
		]);
		$id = (int)$ticket->getId();
		$this->ticketIds[] = $id;

		$token = $this->replyTokens->token($id, $ext);
		$r = $this->inbound->processWebhook([
			'from' => $ext,
			'to' => 'support+' . $id . '-' . $token . '@example.com',
			'subject' => 'Re: IT loop close',
			'text' => 'External loop-close reply body 9f3c.',
			'message-id' => '<it-loop-' . $id . '@mailtest.de>',
		]);

		self::assertTrue($r['success']);
		self::assertSame($id, $r['ticket_id']);
		$comments = $this->ticketService->getComments($id);
		self::assertNotEmpty($comments);
		self::assertStringContainsString('loop-close reply body', (string)end($comments)->getContent());
	}

	public function testStaffCreatedTicketMailsExternalCustomer(): void
	{
		$ext = 'it-ext-staff@mailtest.de';
		$ticket = $this->ticketService->createTicketFromEmail([
			'title' => 'IT staff create',
			'description' => 'Body',
			'customer_email' => $ext,
			'customer_name' => 'IT Staff',
		]);
		$this->ticketIds[] = (int)$ticket->getId();
		// Staff provenance: creator is an ordinary staff uid — creator leg skips.
		$ticket->setCreatedBy('admin');
		$this->purgeMailhog();

		self::assertTrue($this->emailService->sendTicketCreatedNotification($ticket, 'staff@nc.local'));
		self::assertSame(1, $this->mailhogCount($ext));
	}

	public function testPartyGuestAccountClaimsAddressExactlyOnce(): void
	{
		$ext = 'it-ext-claim@mailtest.de';
		$uid = 'it-guest-' . substr(bin2hex(random_bytes(4)), 0, 8);
		$user = $this->users->createUser($uid, 'It!pass' . bin2hex(random_bytes(4)));
		self::assertNotFalse($user);
		$user->setEMailAddress($ext);
		$this->uids[] = $uid;
		$this->access->grantAccess($uid, 777001, null, 'it-fixture');

		$ticket = $this->ticketService->createTicketFromEmail([
			'title' => 'IT claimed guest',
			'description' => 'Body',
			'customer_email' => $ext,
			'customer_name' => 'IT Claim',
		]);
		$id = (int)$ticket->getId();
		$this->ticketIds[] = $id;
		$ticket->setProjectId(777001);
		$this->purgeMailhog();

		self::assertTrue($this->emailService->sendTicketCreatedNotification($ticket, 'staff@nc.local'));
		// Account leg sent once; claimed → raw leg suppressed (NN-05). The
		// only ledger row is the pre-project create mail; no new external
		// send was ledgered after the account claimed the address.
		self::assertSame(1, $this->mailhogCount($ext));
		self::assertSame(1, $this->outbox->recentExternalLegCount($id, $ext, new \DateTime('-1 hour')));
	}

	public function testProjectlessPortalCreatorLegAndExternalLeg(): void
	{
		$creatorMail = 'it-portal-creator@mailtest.de';
		$ext = 'it-ext-portal@mailtest.de';
		$identity = new GuestIdentity();
		$identity->setEmail($creatorMail);
		$identity->setDisplayName('Portal Creator');
		$identity->setStatus('active');
		$identity->setCreatedAt(new \DateTime());
		$identity->setCreatedBy('it-fixture');
		$identity = $this->identities->insert($identity);
		$this->identityIds[] = (int)$identity->getId();

		$ticket = $this->ticketService->createTicketFromEmail([
			'title' => 'IT portal creator',
			'description' => 'Body',
			'customer_email' => $ext,
			'customer_name' => 'IT Portal',
		]);
		$this->ticketIds[] = (int)$ticket->getId();
		$ticket->setCreatedBy('portal:' . $identity->getId());
		$this->purgeMailhog();

		self::assertTrue($this->emailService->sendTicketCreatedNotification($ticket, 'staff@nc.local'));
		self::assertSame(1, $this->mailhogCount($creatorMail), 'creator leg must mail the portal identity');
		self::assertSame(1, $this->mailhogCount($ext), 'external leg must mail customer_email');
	}

	// ---------- gates ----------

	public function testKillSwitchSuppressesExternalLeg(): void
	{
		$this->appConfig->setValueString('ticketcheck', 'external_customer_mail_enabled', 'no');
		$ext = 'it-ext-off@mailtest.de';
		$ticket = $this->ticketService->createTicketFromEmail([
			'title' => 'IT disabled',
			'description' => 'Body',
			'customer_email' => $ext,
			'customer_name' => 'IT Off',
		]);
		$this->ticketIds[] = (int)$ticket->getId();

		self::assertSame(0, $this->mailhogCount($ext));
	}

	public function testHourlyCapBoundsLedgerRowsAndDelivery(): void
	{
		$ext = 'it-ext-cap@mailtest.de';
		$ticket = $this->ticketService->createTicketFromEmail([
			'title' => 'IT cap',
			'description' => 'Body',
			'customer_email' => $ext,
			'customer_name' => 'IT Cap',
		]);
		$id = (int)$ticket->getId();
		$this->ticketIds[] = $id;

		for ($i = 0; $i < 7; $i++) {
			$this->emailService->sendTicketUpdatedNotification($ticket, 'update ' . $i, 'staff@nc.local');
		}
		self::assertSame(5, $this->mailhogCount($ext), 'cap = 5 sends/hour total (create + 4 updates)');
		self::assertSame(5, $this->outbox->recentExternalLegCount($id, $ext, new \DateTime('-1 hour')), '5 sends ledgered, 3 dropped');
	}

	public function testCappedDropsRecoverViaHourlyDigestJob(): void
	{
		$ext = 'it-ext-digest@mailtest.de';
		$ticket = $this->ticketService->createTicketFromEmail([
			'title' => 'IT digest',
			'description' => 'Body',
			'customer_email' => $ext,
			'customer_name' => 'IT Digest',
		]);
		$id = (int)$ticket->getId();
		$this->ticketIds[] = $id;

		for ($i = 0; $i < 7; $i++) {
			$this->emailService->sendTicketUpdatedNotification($ticket, 'update ' . $i, 'staff@nc.local');
		}
		self::assertSame(5, $this->mailhogCount($ext));
		self::assertSame(3, $this->outbox->cappedExternalLegCount($id, $ext, new \DateTime('-1 hour')), '3 dropped updates ledgered as extdrop rows');
		self::assertSame(5, $this->outbox->recentExternalLegCount($id, $ext, new \DateTime('-1 hour')), 'drop rows must not inflate the send cap');

		$job = \OC::$server->get(\OCA\Ticketcheck\BackgroundJob\ExternalMailDigestJob::class);
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);

		self::assertSame(6, $this->mailhogCount($ext), 'one digest mail delivered');
		self::assertNotNull($this->outbox->lastExternalDigestTime($id, $ext), 'digest ledgered via extdigest key');

		$run->invoke($job, null);
		self::assertSame(6, $this->mailhogCount($ext), 'second tick within the hour sends no second digest');
	}

	// ---------- MailHog helpers ----------

	private function purgeMailhog(): void
	{
		$ch = curl_init('http://mailhog:8025/api/v1/messages');
		curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
		curl_exec($ch);
		curl_close($ch);
	}

	private function mailhogCount(string $to): int
	{
		$ch = curl_init('http://mailhog:8025/api/v2/search?kind=to&query=' . rawurlencode($to));
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
		$body = curl_exec($ch);
		curl_close($ch);
		if (!is_string($body)) {
			self::fail('MailHog API unreachable at mailhog:8025 — is the compose stack up?');
		}
		$data = json_decode($body, true);
		return (int)($data['count'] ?? 0);
	}

	private function mailhogHeader(string $to, string $header): string
	{
		$ch = curl_init('http://mailhog:8025/api/v2/search?kind=to&query=' . rawurlencode($to));
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
		$body = curl_exec($ch);
		curl_close($ch);
		$data = json_decode(is_string($body) ? $body : '', true);
		foreach (($data['items'] ?? []) as $item) {
			$v = $item['Content']['Headers'][$header][0] ?? null;
			if (is_string($v)) {
				return $v;
			}
		}
		return '';
	}
}

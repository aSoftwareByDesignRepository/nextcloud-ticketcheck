<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\InboundMessage;
use OCA\Ticketcheck\Support\ImapConnection;
use OCA\Ticketcheck\Support\ImapSyncClient;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * IMAP polling orchestration (spec §9.3).
 *
 * One sync run = one advisory lock → one connection → SELECT (UIDVALIDITY
 * checkpoint) → UID-cursor SEARCH → per-message dedupe-claim → classify →
 * effect → terminal row → \Seen policy → cursor advance.
 *
 * Deliberate invariants:
 *  - Cursor is UID-based, never \Unseen (an external client marking a mail
 *    read must not make it invisible to us).
 *  - Cursor only advances past TERMINAL outcomes — an error_retryable mail
 *    keeps its UID below the cursor and is retried next run.
 *  - \Seen is set only for actions allowed by the triage policy; ignored_*
 *    mail stays UNSEEN so humans see suspicious/rejected mail.
 *  - Connection failures feed an exponential backoff counter.
 */
final class InboundMailSyncService
{
	public const BATCH_SIZE = 100;
	private const META_CHUNK = 50;
	private const BODY_TEXT_MAX_BYTES = 204800;
	private const LOCK_KEY = 'ticketcheck/inbound-imap/sync';
	private const BACKOFF_BASE_MIN = 15;

	public function __construct(
		private readonly InboundMailConfigService $mailboxConfig,
		private readonly InboundMailAuthService $auth,
		private readonly InboundEmailService $processor,
		private readonly InboundAttachmentIngestService $attachments,
		private readonly ILockingProvider $locking,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Actions that may mark a mail \Seen (spec UNSEEN triage policy).
	 *
	 * @return list<string>
	 */
	public static function seenActions(): array
	{
		return [
			InboundMessage::ACTION_IGNORED_AUTO,
			InboundMessage::ACTION_COMMENT,
			InboundMessage::ACTION_TICKET_CREATED,
			InboundMessage::ACTION_ERROR_PERMANENT,
		];
	}

	/**
	 * @param callable(array<string,mixed>): array<string,mixed>|null $clientFactory
	 *        test seam — returns ImapSyncClient::connect()-shaped result
	 * @return array{status: string, processed?: int, created?: int, commented?: int, reason?: string}
	 */
	public function sync(?callable $clientFactory = null): array
	{
		$cfg = $this->mailboxConfig->mailboxConfig();
		if (!$cfg['enabled']) {
			return ['status' => 'disabled'];
		}
		if (!$this->mailboxConfig->isConfigured()) {
			return ['status' => 'unconfigured'];
		}
		if ($this->inBackoff($cfg)) {
			return ['status' => 'backoff'];
		}

		try {
			$this->locking->acquireLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE, 'TicketCheck inbound IMAP sync');
		} catch (LockedException) {
			return ['status' => 'locked'];
		}

		try {
			return $this->syncLocked($cfg, $clientFactory);
		} finally {
			$this->locking->releaseLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Exponential backoff after connection errors (spec §9.5):
	 * gap = base × 2^(error_count-1), capped at 4h.
	 */
	/** @param array<string, mixed> $cfg */
	private function inBackoff(array $cfg): bool
	{
		$count = (int)$cfg['error_count'];
		if ($count <= 0) {
			return false;
		}
		$lastSync = strtotime((string)$cfg['last_sync_at'] ?: '') ?: 0;
		if ($lastSync <= 0) {
			return false;
		}
		$gapSeconds = min(self::BACKOFF_BASE_MIN * (1 << min($count - 1, 16)), 240) * 60;
		return time() - $lastSync < $gapSeconds;
	}

	/**
	 * @param array<string, mixed> $cfg
	 * @return array{status: string, processed?: int, created?: int, commented?: int, reason?: string}
	 */
	private function syncLocked(array $cfg, ?callable $clientFactory): array
	{
		try {
			$cred = $this->auth->resolve($cfg);
		} catch (\RuntimeException $e) {
			$this->mailboxConfig->recordSyncError('auth', $e->getMessage());
			if ($e->getMessage() === 'oauth_expired') {
				$this->mailboxConfig->setNeedsReauth(true);
			}
			return ['status' => 'error', 'reason' => 'auth'];
		}

		$connect = [
			'host' => (string)$cfg['host'],
			'port' => (int)$cfg['port'],
			'enc' => (string)$cfg['enc'],
			'user' => $cred['user'],
			'secret' => $cred['secret'],
			'authMode' => $cred['mode'],
			'allowSelfSigned' => !empty($cfg['allow_self_signed']),
			'allowPrivateHost' => !empty($cfg['allow_private_host']),
			'timeoutSeconds' => 20.0,
		];
		// Private hosts require the explicit opt-in — rechecked at dial time.
		if (ImapConnection::isBlockedMailHost((string)$cfg['host']) && empty($cfg['allow_private_host'])) {
			$this->mailboxConfig->recordSyncError('host_blocked');
			return ['status' => 'error', 'reason' => 'host_blocked'];
		}

		$res = $clientFactory !== null
			? $clientFactory($connect)
			: ImapSyncClient::connect($connect);
		if (empty($res['ok']) || empty($res['client'])) {
			$reason = (string)($res['reason'] ?? 'network');
			$this->mailboxConfig->recordSyncError($reason, (string)($res['detail'] ?? ''));
			if ($reason === 'auth') {
				$this->mailboxConfig->recordAuthFailure();
			}
			return ['status' => 'error', 'reason' => $reason];
		}
		/** @var ImapSyncClient $client */
		$client = $res['client'];

		try {
			$select = $client->selectFolder((string)$cfg['folder']);
			if (empty($select['ok'])) {
				$this->mailboxConfig->recordSyncError('select', (string)($select['response'] ?? ''));
				return ['status' => 'error', 'reason' => 'select'];
			}
			$uidvalidity = (int)($select['uidvalidity'] ?? 0);
			$lastUid = (int)$cfg['last_uid'];
			// UIDVALIDITY changed → mailbox was recreated; old UIDs are
			// meaningless. Re-baseline rather than replaying the world.
			if ($uidvalidity > 0 && (int)$cfg['uidvalidity'] > 0 && $uidvalidity !== (int)$cfg['uidvalidity']) {
				$this->logger->warning('Inbound IMAP UIDVALIDITY changed — re-baselining cursor', ['app' => 'ticketcheck']);
				$lastUid = 0;
			}

			$search = $client->searchUidsAfter($lastUid, self::BATCH_SIZE);
			if (empty($search['ok'])) {
				$this->mailboxConfig->recordSyncError('search', (string)($search['response'] ?? ''));
				return ['status' => 'error', 'reason' => 'search'];
			}
			$uids = $search['uids'];
			if ($uids === []) {
				$this->mailboxConfig->recordSyncSuccess(0);
				return ['status' => 'ok', 'processed' => 0];
			}

			$counts = ['processed' => 0, 'created' => 0, 'commented' => 0, 'ignored' => 0, 'errors' => 0];
			$seenUids = [];
			$cursor = $lastUid;
			$blockedAt = null; // first non-terminal uid — cursor must not pass it
			$terminated = 0;

			foreach (array_chunk($uids, self::META_CHUNK) as $chunk) {
				$meta = $client->fetchMessageMeta($chunk);
				if (empty($meta['ok'])) {
					$this->mailboxConfig->recordSyncError('fetch', (string)($meta['response'] ?? ''));
					break;
				}
				foreach ($chunk as $uid) {
					$m = $meta['messages'][$uid] ?? null;
					if ($m === null) {
						// Deleted between listing and fetch — safe to skip
						// (spec: deleted mail is not an error).
						if ($blockedAt === null) {
							$cursor = max($cursor, $uid);
						}
						$terminated++;
						continue;
					}
					$outcome = $this->processOne($client, $m, $uidvalidity, $cfg);
					$counts['processed']++;
					$action = (string)($outcome['action'] ?? '');
					if (!empty($outcome['duplicate'])) {
						// Retry-window duplicate: the stored action already
						// counted on its original run — don't double-count.
						$counts['ignored']++;
					} elseif ($action === InboundMessage::ACTION_TICKET_CREATED) {
						$counts['created']++;
					} elseif ($action === InboundMessage::ACTION_COMMENT) {
						$counts['commented']++;
					} elseif (str_starts_with($action, 'ignored_')) {
						$counts['ignored']++;
					} else {
						$counts['errors']++;
					}
					if (in_array($action, self::seenActions(), true)) {
						$seenUids[] = $uid;
					}
					$terminal = $action !== InboundMessage::ACTION_ERROR_RETRYABLE;
					if ($terminal) {
						$terminated++;
						if ($blockedAt === null) {
							$cursor = max($cursor, $uid);
						}
					} elseif ($blockedAt === null) {
						$blockedAt = $uid;
					}
				}
			}

			if ($seenUids !== []) {
				$client->storeSeen($seenUids);
			}
			if ($cursor > (int)$cfg['last_uid']) {
				$this->mailboxConfig->updateCursor($cursor, $uidvalidity);
			}
			// Backlog = uids in this batch that did not reach a terminal
			// state (retryable, unprocessed after a mid-run fetch failure).
			$this->mailboxConfig->recordSyncSuccess(count($uids) - $terminated);

			return ['status' => 'ok'] + $counts;
		} finally {
			$client->close();
		}
	}

	/**
	 * One message end-to-end: fetch text + structure → prepare attachments →
	 * shared processor. Every failure is contained to this message.
	 *
	 * @param array{uid: int, internalDate: ?string, flags: list<string>, headers: array<string, mixed>, rawHeader: string} $m
	 * @param array<string, mixed> $cfg
	 * @return array{action: string, ticket_id?: int, comment_id?: int, duplicate?: bool}
	 */
	private function processOne(ImapSyncClient $client, array $m, int $uidvalidity, array $cfg): array
	{
		$uid = (int)$m['uid'];
		$headers = $m['headers'];

		try {
			$parts = ImapSyncClient::flattenParts(
				ImapSyncClient::parseBodyStructure((string)($client->fetchBodyStructure($uid) ?? ''))
			);
			$attachmentPlan = InboundAttachmentIngestService::planFetch(
				array_values(array_filter($parts, static fn (array $p): bool => !empty($p['attachment'])))
			);

			// Body text: fetch the best text leaf, falling back to TEXT.
			// Decode by the part's declared Content-Transfer-Encoding —
			// never by guessing at the payload shape.
			$text = '';
			$html = '';
			foreach ($parts as $part) {
				$enc = strtolower((string)($part['encoding'] ?? ''));
				if (($part['type'] ?? '') === 'text' && ($part['subtype'] ?? '') === 'plain' && $text === '') {
					$raw = (string)($client->fetchSection($uid, (string)$part['partId'], self::BODY_TEXT_MAX_BYTES) ?? '');
					$text = $this->decodeTransfer($raw, $enc);
				} elseif (($part['type'] ?? '') === 'text' && ($part['subtype'] ?? '') === 'html' && $html === '') {
					$raw = (string)($client->fetchSection($uid, (string)$part['partId'], self::BODY_TEXT_MAX_BYTES) ?? '');
					$html = $this->decodeTransfer($raw, $enc);
				}
			}
			if ($text === '' && $html === '') {
				$text = (string)($client->fetchSection($uid, 'TEXT', self::BODY_TEXT_MAX_BYTES) ?? '');
			}

			$attachmentCtx = null;
			if ($attachmentPlan['fetch'] !== [] || $attachmentPlan['rejected'] !== []) {
				$prepared = $this->attachments->prepare(
					$uid,
					$attachmentPlan['fetch'],
					fn (int $u, string $partId, int $max): ?string => $client->fetchSection($u, $partId, $max),
					$attachmentPlan['rejected'],
				);
				$names = array_map(static fn (array $f): string => (string)$f['name'], $prepared['valid']);
				$attachmentCtx = [
					'names' => $names,
					'rejected' => $prepared['rejected'],
					'persist' => function (int $ticketId, ?int $commentId) use ($prepared): array {
						return $this->attachments->persist($ticketId, $commentId, $prepared['valid']);
					},
					'_prepared' => $prepared,
				];
			}

			$msg = [
				'from' => (string)($headers['from'] ?? ''),
				'fromName' => '',
				'to' => $headers['to'] ?? [],
				'cc' => $headers['cc'] ?? [],
				'subject' => (string)($headers['subject'] ?? ''),
				'messageId' => $headers['messageId'] ?? null,
				'internalDate' => $m['internalDate'] ?? null,
				'rawHeader' => (string)$m['rawHeader'],
				'text' => $text,
				'html' => $html,
			];

			try {
				return $this->processor->processInboundMessage(
					$msg,
					InboundMessage::CHANNEL_IMAP,
					$uid,
					$uidvalidity,
					$attachmentCtx !== null ? ['attachments' => $attachmentCtx] : [],
				);
			} finally {
				if ($attachmentCtx !== null) {
					$this->attachments->cleanup($attachmentCtx['_prepared']['valid']);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Inbound message fetch/process error', [
				'app' => 'ticketcheck',
				'uid' => $uid,
				'exception' => $e,
			]);
			return ['action' => InboundMessage::ACTION_ERROR_RETRYABLE];
		}
	}

	/**
	 * Decode a text part by its declared Content-Transfer-Encoding.
	 * Non-UTF-8 charsets are converted best-effort to UTF-8.
	 */
	private function decodeTransfer(string $raw, string $encoding): string
	{
		if ($raw === '') {
			return '';
		}
		$enc = strtolower(trim($encoding));
		if ($enc === 'base64') {
			$decoded = base64_decode(preg_replace('/\s+/', '', $raw) ?? '', true);
			$raw = $decoded !== false ? $decoded : '';
		} elseif ($enc === 'quoted-printable') {
			$raw = quoted_printable_decode($raw);
		}
		if ($raw !== '' && !mb_check_encoding($raw, 'UTF-8')) {
			$converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252, ISO-8859-1');
			if (is_string($converted)) {
				$raw = $converted;
			}
		}
		return $raw;
	}
}

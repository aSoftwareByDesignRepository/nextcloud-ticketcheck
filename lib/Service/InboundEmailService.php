<?php

declare(strict_types=1);

/**
 * Inbound email service for helpdesk app
 *
 * Shared normalized processor for the webhook channel (SendGrid/Mailgun)
 * and the IMAP polling channel (InboundMailSyncService). Claim-before-
 * process dedupe lives here so both channels collapse duplicate delivery
 * to a single side effect (spec §9.3).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\HelpdeskCustomerMapper;
use OCA\Ticketcheck\Db\HelpdeskProjectMapper;
use OCA\Ticketcheck\Db\InboundMessage;
use OCA\Ticketcheck\Db\InboundMessageMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCP\L10N\IFactory as L10NFactory;
use Psr\Log\LoggerInterface;

/**
 * @phpstan-type InboundMsg array{from: string, fromName?: string, to: list<string>, cc: list<string>,
 *   subject: string, messageId: ?string, rawHeader?: string, text: string,
 *   html?: string, internalDate?: ?string}
 * @phpstan-type InboundOpts array{attachments?: array{names: list<string>, rejected: list<array{name: string, reason: string}>,
 *   persist: callable(int, ?int): array{saved: list<string>, rejected: list<array{name: string, reason: string}>}}}
 * @phpstan-type InboundResult array{action: string, ticket_id?: int, comment_id?: int, duplicate?: bool}
 */
class InboundEmailService
{
	/** Bounded retry: a mail failing this many claims becomes error_permanent. */
	public const MAX_ATTEMPTS = 3;
	/** Creation caps (spec §9.6) — runaway-create breakers. */
	public const MAX_CREATE_PER_SENDER_PER_DAY = 5;
	public const MAX_CREATE_GLOBAL_PER_DAY = 200;

	public function __construct(
		private TicketService $ticketService,
		private LoggerInterface $logger,
		private InboundMailConfigService $mailboxConfig,
		private InboundMessageMapper $inboundMapper,
		private ReplyTokenService $replyTokens,
		private EmailService $emailService,
		private HelpdeskCustomerMapper $customerMapper,
		private HelpdeskProjectMapper $projectMapper,
		private L10NFactory $l10nFactory,
	) {
	}

	/**
	 * Parse webhook payload and add comment to ticket if valid.
	 * Preserves the legacy response shape for InboundEmailController.
	 *
	 * @param array<string, mixed> $payload
	 * @return array{success: bool, ticket_id?: int, error?: string}
	 */
	public function processWebhook(array $payload): array
	{
		$msg = $this->normalizeWebhook($payload);
		$result = $this->processInboundMessage($msg, InboundMessage::CHANNEL_WEBHOOK);

		$action = (string)($result['action'] ?? '');
		if (!empty($result['duplicate'])) {
			// Idempotent re-delivery: report success with the stored ticket.
			$out = ['success' => true];
			if ($result['ticket_id'] ?? null) {
				$out['ticket_id'] = (int)$result['ticket_id'];
			}
			return $out;
		}
		if ($action === InboundMessage::ACTION_COMMENT || $action === InboundMessage::ACTION_TICKET_CREATED) {
			return ['success' => true, 'ticket_id' => (int)$result['ticket_id']];
		}
		if (str_starts_with($action, 'ignored_')) {
			// Terminal decision — the mail WAS processed. Returning failure
			// here would make the provider retry a mail we deliberately
			// ignored (the dedupe claim would collapse the retry anyway, but
			// the storm is pointless).
			return ['success' => true];
		}
		// Only retryable/permanent processing failures reach this point —
		// the payload stays generic on purpose (no existence/authz oracles).
		return ['success' => false, 'error' => 'Failed to process inbound email'];
	}

	/**
	 * Normalize a webhook payload into the shared message shape.
	 *
	 * @param array<string, mixed> $payload
	 * @return InboundMsg
	 */
	private function normalizeWebhook(array $payload): array
	{
		// `to`/`recipient` may carry several comma-separated addresses —
		// parse all so a plus-addressed reply is found even in a group To.
		$to = \OCA\Ticketcheck\Support\ImapLiveProbe::extractEmailAddresses(
			(string)($payload['to'] ?? $payload['recipient'] ?? '')
		);
		$cc = [];
		foreach (['cc', 'Cc'] as $key) {
			if (isset($payload[$key]) && is_string($payload[$key])) {
				$cc = array_merge($cc, \OCA\Ticketcheck\Support\ImapLiveProbe::extractEmailAddresses($payload[$key]));
			}
		}
		$text = trim((string)($payload['text'] ?? $payload['body-plain'] ?? $payload['body_plain']
			?? $payload['stripped-text'] ?? $payload['stripped_text'] ?? ''));
		// Security: strip HTML from external source to prevent XSS if ever rendered without escaping
		$text = strip_tags($text);
		$html = '';
		if ($text === '' && isset($payload['html'])) {
			$html = (string)($payload['html'] ?? $payload['body-html'] ?? $payload['body_html'] ?? '');
		}
		$messageId = null;
		foreach (['message-id', 'Message-ID', 'message_id'] as $key) {
			if (isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== '') {
				$messageId = trim($payload[$key]);
				break;
			}
		}
		// SendGrid-style headers blob may carry Message-ID + Return-Path.
		$rawHeader = '';
		if (isset($payload['headers']) && is_string($payload['headers'])) {
			$rawHeader = $payload['headers'];
			if ($messageId === null && preg_match('/^message-id:\s*(.+)$/mi', $rawHeader, $m) === 1) {
				$messageId = trim($m[1]);
			}
		}

		return [
			'from' => $this->extractEmail((string)($payload['from'] ?? $payload['sender'] ?? '')),
			'fromName' => $this->extractName((string)($payload['from'] ?? $payload['sender'] ?? '')),
			'to' => $to,
			'cc' => array_values(array_unique($cc)),
			'subject' => trim((string)($payload['subject'] ?? '')),
			'messageId' => $messageId,
			'rawHeader' => $rawHeader,
			'text' => $text,
			'html' => $html,
			'internalDate' => null,
		];
	}

	/**
	 * Shared inbound pipeline: claim → classify → route → effect → finish.
	 *
	 * @param InboundMsg $msg
	 * @param InboundOpts $opts
	 * @return InboundResult
	 */
	public function processInboundMessage(array $msg, string $channel, int $uid = 0, int $uidvalidity = 0, array $opts = []): array
	{
		$from = strtolower(trim((string)($msg['from'] ?? '')));
		$subject = trim((string)($msg['subject'] ?? ''));

		$row = new InboundMessage();
		$row->setMailboxId(InboundMailConfigService::MAILBOX_ID);
		$row->setUid($uid);
		$row->setUidvalidity($uidvalidity);
		$row->setMsgHash(InboundMailClassifier::msgHash(
			$msg['messageId'] ?? null,
			$from,
			$subject,
			$msg['internalDate'] ?? null,
			$uid,
		));
		$row->setMessageId($msg['messageId'] ?? null);
		$row->setFromAddr($from !== '' ? mb_substr($from, 0, 255) : null);
		$row->setSubject($subject !== '' ? mb_substr($subject, 0, 255) : null);
		$row->setChannel($channel);
		$row->setAction(InboundMessage::ACTION_PROCESSING);
		$row->setAttempts(1);
		$row->setClaimedAt(new \DateTime());
		$row->setReceivedAt(new \DateTime());

		try {
			$claim = $this->inboundMapper->claim($row);
		} catch (\Throwable $e) {
			// Dedupe storage down — fail open for the webhook (previous
			// behavior) but log loudly; IMAP replays are bounded anyway.
			$this->logger->error('Inbound dedupe claim failed: ' . $e->getMessage(), ['exception' => $e]);
			return ['action' => InboundMessage::ACTION_ERROR_RETRYABLE];
		}
		$row = $claim['row'];
		if ($claim['status'] === 'duplicate') {
			$dupAction = $row->getAction();
			if ($dupAction === InboundMessage::ACTION_PROCESSING
				|| $dupAction === InboundMessage::ACTION_ERROR_RETRYABLE) {
				// Still-processing or marked-for-retry: not terminal. The
				// cursor must hold until the stale-claim window reclaims it —
				// reporting success here would silently drop a retryable mail.
				return ['action' => InboundMessage::ACTION_ERROR_RETRYABLE];
			}
			$dup = ['action' => $row->getAction(), 'duplicate' => true];
			if ($row->getTicketId() !== null) {
				$dup['ticket_id'] = (int)$row->getTicketId();
			}
			return $dup;
		}

		// Bounded retry: reclaim past the attempt ceiling poisons out.
		if ((int)$row->getAttempts() > self::MAX_ATTEMPTS) {
			$this->finish($row, InboundMessage::ACTION_ERROR_PERMANENT, error: 'attempts_exceeded');
			return ['action' => InboundMessage::ACTION_ERROR_PERMANENT];
		}

		try {
			$result = $this->process($msg, $row, $opts);
		} catch (\Throwable $e) {
			$this->logger->error('Inbound message processing failed', ['exception' => $e]);
			$action = (int)$row->getAttempts() >= self::MAX_ATTEMPTS
				? InboundMessage::ACTION_ERROR_PERMANENT
				: InboundMessage::ACTION_ERROR_RETRYABLE;
			$this->finish($row, $action, error: $e->getMessage());
			return ['action' => $action];
		}

		return $result;
	}

	/**
	 * The state machine (spec §9.4). Returns the terminal action.
	 *
	 * @param InboundMsg $msg
	 * @param InboundOpts $opts
	 * @return array{action: string, ticket_id?: int, comment_id?: int}
	 */
	private function process(array $msg, InboundMessage $row, array $opts): array
	{
		$l = $this->l10nFactory->get('ticketcheck');
		$from = strtolower(trim((string)($msg['from'] ?? '')));
		$subject = trim((string)($msg['subject'] ?? ''));
		$inboundAddress = $this->mailboxConfig->inboundAddress();
		$cfg = $this->mailboxConfig->mailboxConfig();

		// 1. Automated mail — never auto-replied to, marked \Seen (terminal).
		if (InboundMailClassifier::isAutomated($msg, $inboundAddress)) {
			$this->finish($row, InboundMessage::ACTION_IGNORED_AUTO);
			return ['action' => InboundMessage::ACTION_IGNORED_AUTO];
		}

		// 2. Sender validation — a ticket/comment without a real sender is
		//    worthless; stays UNSEEN for humans.
		if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
			$this->finish($row, InboundMessage::ACTION_IGNORED_EMPTY, error: 'invalid_sender');
			return ['action' => InboundMessage::ACTION_IGNORED_EMPTY];
		}

		$body = InboundMailClassifier::normalizeBody(
			(string)($msg['text'] ?? ''),
			(string)($msg['html'] ?? ''),
		);
		if ($body === '') {
			$this->finish($row, InboundMessage::ACTION_IGNORED_EMPTY, error: 'empty_body');
			return ['action' => InboundMessage::ACTION_IGNORED_EMPTY];
		}

		$body .= $this->attachmentSummary($opts['attachments'] ?? null, $l);
		$recipients = array_merge($msg['to'] ?? [], $msg['cc'] ?? []);

		// 3. Address target: tokenized/legacy plus-address in To OR Cc.
		$target = InboundMailClassifier::extractReplyTarget($recipients, $inboundAddress);
		$ticketId = null;
		if ($target !== null) {
			$ticketId = $target['id'];
		} elseif (!$cfg['require_reply_token']) {
			// From-only subject fallback — disabled in token-required mode
			// because a bare #id + forged From is the spoofing primitive.
			$ticketId = InboundMailClassifier::extractSubjectTicketId($subject);
		}

		if ($ticketId !== null) {
			return $this->processReply($msg, $row, $ticketId, $target, $body, $opts);
		}

		// 4. No ticket reference → creation path.
		if ($cfg['unknown_sender_policy'] === 'ignore') {
			$this->finish($row, InboundMessage::ACTION_IGNORED_POLICY, error: 'unknown_sender_ignored');
			return ['action' => InboundMessage::ACTION_IGNORED_POLICY];
		}

		return $this->processCreate($msg, $row, $from, $subject, $body, $recipients, $opts, $l);
	}

	/**
	 * Comment path: resolve merge chain → token check → sender authz → write.
	 *
	 * @param InboundMsg $msg
	 * @param array{id: int, token: ?string, legacy: bool}|null $target
	 * @param InboundOpts $opts
	 * @return array{action: string, ticket_id?: int, comment_id?: int}
	 */
	private function processReply(array $msg, InboundMessage $row, int $ticketId, ?array $target, string $body, array $opts): array
	{
		$from = strtolower(trim((string)($msg['from'] ?? '')));

		try {
			$ticket = $this->ticketService->getActiveTicket($ticketId);
		} catch (\Throwable) {
			$this->finish($row, InboundMessage::ACTION_IGNORED_NO_TICKET, error: 'ticket_not_found');
			return ['action' => InboundMessage::ACTION_IGNORED_NO_TICKET];
		}
		$activeId = (int)$ticket->getId();
		$customerEmail = strtolower(trim((string)$ticket->getCustomerEmail()));

		// HMAC token verification — bound to the ACTIVE ticket's customer so
		// a leaked address can't be paired with a forged From (ARGUS-04).
		if ($target !== null && !empty($target['token'])) {
			if (!$this->replyTokens->verify($ticketId, (string)$target['token'], $customerEmail)) {
				$this->finish($row, InboundMessage::ACTION_IGNORED_BAD_TOKEN, error: 'token_mismatch');
				return ['action' => InboundMessage::ACTION_IGNORED_BAD_TOKEN];
			}
		} elseif ($target !== null && $target['legacy']) {
			$cfg = $this->mailboxConfig->mailboxConfig();
			if ($cfg['require_reply_token']) {
				$this->finish($row, InboundMessage::ACTION_IGNORED_BAD_TOKEN, error: 'token_required');
				return ['action' => InboundMessage::ACTION_IGNORED_BAD_TOKEN];
			}
			$this->logger->info('Inbound legacy +{id} reply accepted (no token)', [
				'app' => 'ticketcheck',
				'ticket_id' => $ticketId,
			]);
		}

		// Sender authz: only the ticket's customer may comment — agent mail
		// must never fabricate customer comments (From is spoofable).
		if ($customerEmail === '' || $customerEmail !== $from) {
			$this->finish($row, InboundMessage::ACTION_IGNORED_UNAUTHORIZED, error: 'sender_mismatch');
			return ['action' => InboundMessage::ACTION_IGNORED_UNAUTHORIZED];
		}

		$authorName = trim((string)($msg['fromName'] ?? ''));
		if ($authorName === '') {
			$authorName = $from;
		}

		try {
			$comment = $this->ticketService->addCommentFromEmail($activeId, $body, $from, $authorName);
		} catch (\Throwable $e) {
			if ($e->getMessage() === 'Sender not authorized for this ticket') {
				$this->finish($row, InboundMessage::ACTION_IGNORED_UNAUTHORIZED, error: 'sender_mismatch_locked');
				return ['action' => InboundMessage::ACTION_IGNORED_UNAUTHORIZED];
			}
			throw $e; // transient/DB errors → retryable claim handling upstream
		}

		$commentId = (int)$comment->getId();
		if (isset($opts['attachments']['persist'])) {
			try {
				($opts['attachments']['persist'])($activeId, $commentId);
			} catch (\Throwable $e) {
				$this->logger->warning('Inbound comment attachments failed', ['exception' => $e, 'ticket_id' => $activeId]);
			}
		}

		$this->notifyInboundComment($ticket, $authorName, $body);

		$this->finish($row, InboundMessage::ACTION_COMMENT, ticketId: $activeId, commentId: $commentId);
		$this->logger->info('Inbound email: comment added', ['app' => 'ticketcheck', 'ticket_id' => $activeId]);

		return ['action' => InboundMessage::ACTION_COMMENT, 'ticket_id' => $activeId, 'comment_id' => $commentId];
	}

	/**
	 * Creation path: caps → routing → create → attachments → confirm.
	 *
	 * @param InboundMsg $msg
	 * @param list<string> $recipients
	 * @param InboundOpts $opts
	 * @return array{action: string, ticket_id?: int, comment_id?: int}
	 */
	private function processCreate(array $msg, InboundMessage $row, string $from, string $subject, string $body, array $recipients, array $opts, \OCP\IL10N $l): array
	{
		// Runaway-create breakers (spec §9.6). Terminal on purpose: a cap that
		// only delays isn't a breaker; mail stays UNSEEN for humans.
		$dayAgo = (new \DateTime())->modify('-24 hours');
		if ($this->inboundMapper->countTicketCreationsBySender($from, $dayAgo) >= self::MAX_CREATE_PER_SENDER_PER_DAY) {
			$this->bumpIgnoredCap();
			$this->finish($row, InboundMessage::ACTION_IGNORED_CAP, error: 'sender_cap');
			return ['action' => InboundMessage::ACTION_IGNORED_CAP];
		}
		if ($this->inboundMapper->countTicketCreationsSince($dayAgo) >= self::MAX_CREATE_GLOBAL_PER_DAY) {
			$this->bumpIgnoredCap();
			$this->finish($row, InboundMessage::ACTION_IGNORED_CAP, error: 'global_cap');
			return ['action' => InboundMessage::ACTION_IGNORED_CAP];
		}

		// Routing: recipient key → {project_id, category} (DEC-27). A deleted
		// project falls back to general/no-project with a warning.
		$routing = InboundMailClassifier::routingTarget(
			$recipients,
			$this->mailboxConfig->inboundAddress(),
			$this->mailboxConfig->routingMap(),
		);
		$projectId = $routing['project_id'] ?? null;
		$category = $routing['category'] ?? Ticket::CATEGORY_GENERAL;
		if ($projectId !== null && !$this->projectExists((int)$projectId)) {
			$this->logger->warning('Inbound routing target project missing — falling back', [
				'app' => 'ticketcheck',
				'project_id' => $projectId,
			]);
			$projectId = null;
			$category = Ticket::CATEGORY_GENERAL;
		}

		$customer = $this->customerMapper->findByEmail($from);
		$fromName = trim((string)($msg['fromName'] ?? ''));
		$title = $subject !== '' ? mb_strcut($subject, 0, 200, 'UTF-8') : $l->t('inbound_no_subject');

		$ticket = $this->ticketService->createTicketFromEmail([
			'title' => $title,
			'description' => $body,
			'customer_email' => $from,
			'customer_name' => $fromName !== '' ? $fromName : $from,
			'customer_id' => $customer !== null ? (int)$customer->getId() : null,
			'project_id' => $projectId,
			'category' => $category,
		]);
		$ticketId = (int)$ticket->getId();

		if (isset($opts['attachments']['persist'])) {
			try {
				($opts['attachments']['persist'])($ticketId, null);
			} catch (\Throwable $e) {
				$this->logger->warning('Inbound ticket attachments failed', ['exception' => $e, 'ticket_id' => $ticketId]);
			}
		}

		// Exactly one automated reply: the created-ticket confirmation. Its
		// Reply-To carries the tokenized address so "reply to this email"
		// closes the email-first loop (MH-19).
		try {
			$this->emailService->sendTicketCreatedNotification($ticket);
		} catch (\Throwable $e) {
			$this->logger->warning('Inbound confirmation mail failed', ['exception' => $e, 'ticket_id' => $ticketId]);
		}

		$this->finish($row, InboundMessage::ACTION_TICKET_CREATED, ticketId: $ticketId);
		$this->logger->info('Inbound email: ticket created', ['app' => 'ticketcheck', 'ticket_id' => $ticketId]);

		return ['action' => InboundMessage::ACTION_TICKET_CREATED, 'ticket_id' => $ticketId];
	}

	/**
	 * Attachment listing appended to the ticket/comment text — accepted
	 * names AND rejected names with reasons (no silent loss).
	 *
	 * @param array{names?: list<string>, rejected?: list<array{name: string, reason: string}>}|null $attachments
	 */
	private function attachmentSummary(?array $attachments, \OCP\IL10N $l): string
	{
		if ($attachments === null) {
			return '';
		}
		$names = $attachments['names'] ?? [];
		$rejected = $attachments['rejected'] ?? [];
		$out = '';
		if ($names !== []) {
			$out .= "\n\n" . $l->t('inbound_attachments_list') . ' ' . implode(', ', array_map('strval', $names));
		}
		if ($rejected !== []) {
			$lines = [];
			foreach ($rejected as $r) {
				$lines[] = (string)($r['name'] ?? '?') . ' (' . (string)($r['reason'] ?? '?') . ')';
			}
			$out .= "\n" . $l->t('inbound_attachments_rejected') . ' ' . implode(', ', $lines);
		}
		return $out;
	}

	private function notifyInboundComment(Ticket $ticket, string $authorName, string $body): void
	{
		try {
			$this->emailService->sendInternalCustomerCommentNotification($ticket, $authorName, $body, null);
		} catch (\Throwable $e) {
			$this->logger->warning('Inbound comment staff notification failed', ['exception' => $e]);
		}
	}

	private function projectExists(int $projectId): bool
	{
		try {
			$this->projectMapper->find($projectId);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	private function bumpIgnoredCap(): void
	{
		$cfg = $this->mailboxConfig->mailboxConfig();
		$this->mailboxConfig->setIgnoredCapCount($cfg['ignored_cap_count'] + 1);
	}

	private function finish(InboundMessage $row, string $action, ?int $ticketId = null, ?int $commentId = null, ?string $error = null): void
	{
		try {
			$this->inboundMapper->finish($row, $action, $ticketId, $commentId, $error);
		} catch (\Throwable $e) {
			// A lost audit row must not kill processing — but it IS loud.
			$this->logger->error('Inbound finish row failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}

	private function extractEmail(string $value): string
	{
		if (preg_match('/<([^>]+)>/', $value, $m)) {
			return trim(strtolower($m[1]));
		}
		return trim(strtolower($value));
	}

	private function extractName(string $value): string
	{
		if (preg_match('/^([^<]+)</', $value, $m)) {
			return trim($m[1], " \t\n\r\0\x0B\"'");
		}
		return '';
	}
}

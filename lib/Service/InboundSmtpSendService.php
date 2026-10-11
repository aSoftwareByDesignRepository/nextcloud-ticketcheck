<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Support\HelpdeskSmtpClient;
use Psr\Log\LoggerInterface;

/**
 * Outbound send through the configured inbound mailbox's SMTP (DEC-21).
 *
 * Used when send_via_smtp is on AND an smtp_host is configured — otherwise
 * the caller falls back to the instance mailer. Fails closed on invalid
 * explicit config: never silently downgrades to the instance mailer when
 * the admin explicitly asked for mailbox SMTP.
 */
final class InboundSmtpSendService
{
	public function __construct(
		private readonly InboundMailConfigService $mailboxConfig,
		private readonly InboundMailAuthService $auth,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Whether mailbox-SMTP sending is configured and enabled.
	 */
	public function mailboxSmtpActive(): bool
	{
		$cfg = $this->mailboxConfig->mailboxConfig();
		if (empty($cfg['send_via_smtp'])) {
			return false;
		}
		$smtp = $this->mailboxConfig->smtpConfig();
		return $smtp['host'] !== '';
	}

	/**
	 * Send a plain+HTML mail through the mailbox SMTP.
	 *
	 * @param list<string> $cc
	 * @throws \RuntimeException on any failure — callers must NOT fall back
	 *         to the instance mailer after an explicit SMTP config failed.
	 */
	public function send(string $to, string $toName, string $subject, string $textBody, string $htmlBody = '', string $replyTo = '', array $cc = []): void
	{
		$cfg = $this->mailboxConfig->mailboxConfig();
		$smtp = $this->mailboxConfig->smtpConfig();
		if ($smtp['host'] === '') {
			throw new \RuntimeException('smtp_not_configured');
		}

		$cred = $this->auth->resolveSmtp($smtp, $cfg);
		$from = $smtp['from'] !== '' ? $smtp['from'] : (string)$cfg['address'];
		$fromName = (string)$this->mailboxConfigStatusFromName($cfg);

		HelpdeskSmtpClient::send([
			'host' => $smtp['host'],
			'port' => $smtp['port'],
			'enc' => $smtp['enc'],
			'username' => $cred['user'],
			'password' => $cred['secret'],
			'authMode' => $cred['mode'],
			'from' => $from,
			'fromName' => $fromName,
			'to' => $to,
			'toName' => $toName,
			'subject' => $subject,
			'body' => $textBody,
			'bodyHtml' => $htmlBody,
			'replyTo' => $replyTo,
			'cc' => $cc,
			'allowPrivateHost' => !empty($cfg['allow_private_host']),
			'allowSelfSignedCert' => !empty($cfg['allow_self_signed']),
		]);
	}

	/** @param array<string, mixed> $cfg */
	private function mailboxConfigStatusFromName(array $cfg): string
	{
		// From-Name comes from the address local part when no display name is
		// configured — keep it simple and honest for customers replying.
		$address = (string)($cfg['address'] ?? '');
		$at = strpos($address, '@');
		return $at !== false ? substr($address, 0, $at) : 'Helpdesk';
	}
}

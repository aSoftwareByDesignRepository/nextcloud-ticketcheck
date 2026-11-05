<?php

declare(strict_types=1);

/**
 * Guest mail outbox (spec D-T08, MH-T12, NN-T10).
 * All OTP/invite/migration mail is queued here with an idempotency key and
 * drained by PortalMailOutboxJob (or the post-response OTP drain hook).
 * No IMailer::send happens inside a request lifecycle.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\MailOutbox;
use OCA\Ticketcheck\Db\MailOutboxMapper;
use OCA\Ticketcheck\Db\PortalOtpMapper;
use OCP\IDBConnection;
use OCP\Mail\IMailer;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

class GuestMailOutboxService
{
    public const TPL_OTP = 'otp_login';
    public const TPL_INVITE = 'guest_invite';
    public const TPL_ANNOUNCE = 'migration_announce';
    public const TPL_NEW_DEVICE = 'new_device_notice';
    public const TPL_EMAIL_CHANGED = 'email_changed_notice';

    private const PER_EMAIL_HOURLY_CAP = 5;
    private const PENDING_CAP = 10000;

    public function __construct(
        private readonly MailOutboxMapper $outbox,
        private readonly PortalOtpMapper $otps,
        private readonly IMailer $mailer,
        private readonly IFactory $l10nFactory,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Queue a guest mail. Idempotent on $key — a second call with the same
     * key is a no-op (S3 announce, OTP re-send dedup). Returns the row id
     * or null when dropped (cap/dupe/invalid address — never throws).
     */
    public function enqueue(string $key, string $to, string $template, array $payload, bool $capped = true): ?int
    {
        $to = strtolower(trim($to));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        if ($capped && $this->outbox->recentSendCount($to, new \DateTime('-1 hour')) >= self::PER_EMAIL_HOURLY_CAP) {
            return null;
        }
        // Non-OTP templates drop silently beyond the queue cap (§7 scalability).
        if ($template !== self::TPL_OTP && $this->outbox->countPending() >= self::PENDING_CAP) {
            $this->logger->warning('guest outbox cap reached, dropping ' . $template, ['app' => 'ticketcheck']);
            return null;
        }
        if ($this->outbox->findByIdempotencyKey($key) !== null) {
            return null;
        }
        $e = new MailOutbox();
        $e->setIdempotencyKey($key);
        $e->setToEmail($to);
        $e->setTemplate($template);
        $e->setPayload(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        $e->setStatus('pending');
        $e->setAttempts(0);
        $e->setCreatedAt(new \DateTime());
        try {
            $e = $this->outbox->insert($e);
            return (int)$e->getId();
        } catch (\OCP\DB\Exception $ex) {
            if ($ex->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                return null; // raced enqueue — unique key kept one row
            }
            throw $ex;
        }
    }

    /**
     * Drain due rows. Safe to call from the background job AND from the
     * post-response OTP hook — claim() fences concurrent workers.
     * @return array{sent:int,failed:int,deferred:int}
     */
    public function drainDue(int $limit = 50): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'deferred' => 0];
        $lease = new \DateTime('+15 minutes');
        foreach ($this->outbox->dueBatch($limit) as $mail) {
            $id = (int)$mail->getId();
            $epoch = (int)$mail->getAttempts() + 1;
            if (!$this->outbox->claim($id, $lease)) {
                continue;
            }
            if ($epoch > 5) {
                $this->outbox->markFailed($id, $epoch, true, 'attempt_cap');
                $result['failed']++;
                continue;
            }
            try {
                $payload = json_decode((string)($mail->getPayload() ?? '{}'), true);
                $payload = is_array($payload) ? $payload : [];
                [$subject, $text] = $this->render((string)$mail->getTemplate(), $payload, (string)($payload['locale'] ?? ''));
                $message = $this->mailer->createMessage();
                $message->setTo([(string)$mail->getToEmail() => '']);
                $message->setSubject($subject);
                $message->setPlainBody($text);
                $this->mailer->send($message);
                $this->outbox->markSent($id, $epoch);
                $result['sent']++;
            } catch (\Throwable $e) {
                $this->logger->warning('guest outbox send failed: ' . $e->getMessage(), ['app' => 'ticketcheck']);
                $this->outbox->markFailed($id, $epoch, $epoch >= 5, 'send_error');
                $result['deferred']++;
            }
        }
        return $result;
    }

    /**
     * [subject, plain body] per template — plaintext only (OTP mails must
     * render even when the instance has no HTML template configured).
     * No codes/tokens are ever logged — they only exist inside the payload.
     */
    private function render(string $template, array $payload, string $locale): array
    {
        $l = $this->l10nFactory->get('ticketcheck', $locale !== '' ? $locale : null);
        return match ($template) {
            self::TPL_OTP => [
                $l->t('portal_otp_subject'),
                sprintf(
                    "%s\n\n%s\n\n%s\n\n%s\n\n%s",
                    $l->t('portal_otp_body_intro'),
                    (string)($payload['code'] ?? ''),
                    $l->t('portal_otp_body_code_hint'),
                    (string)($payload['magic_url'] ?? ''),
                    $l->t('portal_otp_body_outro')
                ),
            ],
            self::TPL_INVITE => [
                $l->t('portal_invite_subject'),
                sprintf(
                    "%s\n\n%s\n\n%s",
                    $l->t('portal_invite_body_intro', [(string)($payload['display_name'] ?? '')]),
                    (string)($payload['magic_url'] ?? ''),
                    $l->t('portal_invite_body_outro')
                ),
            ],
            self::TPL_ANNOUNCE => [
                $l->t('portal_announce_subject'),
                sprintf(
                    "%s\n\n%s\n\n%s",
                    $l->t('portal_announce_body_intro', [(string)($payload['display_name'] ?? '')]),
                    (string)($payload['login_url'] ?? ''),
                    $l->t('portal_announce_body_outro')
                ),
            ],
            self::TPL_NEW_DEVICE => [
                $l->t('portal_new_device_subject'),
                sprintf("%s\n\n%s",
                    $l->t('portal_new_device_body_intro', [(string)($payload['display_name'] ?? '')]),
                    $l->t('portal_new_device_body_outro')),
            ],
            self::TPL_EMAIL_CHANGED => [
                $l->t('portal_email_changed_subject'),
                sprintf("%s\n\n%s",
                    $l->t('portal_email_changed_body_intro', [(string)($payload['display_name'] ?? '')]),
                    $l->t('portal_email_changed_body_outro')),
            ],
            default => throw new \InvalidArgumentException('unknown mail template: ' . $template),
        };
    }
}

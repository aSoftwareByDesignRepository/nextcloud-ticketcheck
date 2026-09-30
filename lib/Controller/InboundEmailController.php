<?php

declare(strict_types=1);

/**
 * Inbound email webhook controller
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Service\InboundEmailService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Controller for inbound email webhooks (SendGrid, Mailgun, etc.)
 */
class InboundEmailController extends Controller
{
    private const INBOUND_RATE_LIMIT_PER_MINUTE = 60;
    private const INBOUND_RATE_LIMIT_WINDOW_SECONDS = 60;
    private const INBOUND_REPLAY_TTL_SECONDS = 900;
    private const INBOUND_SIGNATURE_MAX_SKEW_SECONDS = 300;

    private ICache $inboundCache;
    private ?string $rawBody = null;

    public function __construct(
        string $appName,
        IRequest $request,
        private InboundEmailService $inboundEmailService,
        private IConfig $config,
        private IFactory $l10nFactory,
        ICacheFactory $cacheFactory,
        private ILockingProvider $locking,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
        $this->inboundCache = $cacheFactory->createDistributed('ticketcheck-inbound-webhook');
    }

    /**
     * Receive inbound email webhook
     *
     * POST only. Validates X-Webhook-Token header against inbound_email_webhook_token config.
     * Generic provider: JSON body required (HMAC binds to raw body). Mailgun/SendGrid: form or JSON.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function webhook(): Response
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if ($this->request->getMethod() !== 'POST') {
            return new JSONResponse(['error' => $l->t('method_not_allowed')], 405);
        }

        $token = trim((string) $this->request->getHeader('X-Webhook-Token'));
        $expectedToken = trim((string) $this->config->getAppValue('ticketcheck', 'inbound_email_webhook_token', ''));

        if ($expectedToken === '') {
            return new JSONResponse(['error' => $l->t('webhook_not_configured')], 503);
        }

		if ($token === '' || !hash_equals($expectedToken, $token)) {
			return new JSONResponse(['error' => $l->t('invalid_token')], 401);
		}

		$inboundEnabled = $this->config->getAppValue('ticketcheck', 'inbound_email_enabled', 'no') === 'yes';
		$inboundAddress = trim((string)$this->config->getAppValue('ticketcheck', 'inbound_email_address', ''));
		if (!$inboundEnabled || $inboundAddress === '') {
			return new JSONResponse(['error' => $l->t('webhook_not_configured')], 503);
		}

		// Generic + Mailgun require a signing secret (token alone is not enough).
		// SendGrid uses a separate public key (checked in verifySendgridSignature).
		$provider = strtolower(trim((string)$this->config->getAppValue('ticketcheck', 'inbound_email_webhook_provider', 'generic')));
		if ($provider === '' || $provider === 'generic' || $provider === 'mailgun') {
			$signingSecret = trim((string)$this->config->getAppValue('ticketcheck', 'inbound_email_webhook_signing_secret', ''));
			if ($signingSecret === '') {
				return new JSONResponse(['error' => $l->t('webhook_not_configured')], 503);
			}
		}

		$payload = $this->getPayload();
		if ($payload === null) {
			return new JSONResponse(['error' => $l->t('invalid_payload')], 400);
		}
		if (!$this->verifyWebhookSignature($payload)) {
			return new JSONResponse(['error' => $l->t('invalid_token')], 401);
		}

		// Rate-limit only after signature succeeds so a leaked token cannot
		// burn the per-IP quota with unsigned junk (DoS of inbound replies).
		$clientKey = $this->buildClientRateLimitKey($token);
		if ($this->isRateLimited($clientKey)) {
			return new JSONResponse(['error' => $l->t('too_many_requests')], 429);
		}

		if (!$this->matchesConfiguredInboundAddress($payload, $inboundAddress)) {
			return new JSONResponse(['error' => $l->t('invalid_payload')], 400);
		}

        // Hold an exclusive lock for the replay fingerprint for the whole
        // process window so two parallel deliveries cannot both pass a
        // check-then-set race and double-post the same comment.
        $replayKey = $this->buildReplayCacheKey($payload);
        $replayLockKey = 'ticketcheck/inbound/replay/' . $replayKey;
        try {
            $this->locking->acquireLock($replayLockKey, ILockingProvider::LOCK_EXCLUSIVE, 'TicketCheck inbound replay');
        } catch (LockedException $e) {
            return new JSONResponse(['error' => $l->t('invalid_payload')], 400);
        }

        try {
            if ($this->inboundCache->get($replayKey) !== null) {
                return new JSONResponse(['error' => $l->t('invalid_payload')], 400);
            }

            // Claim the fingerprint BEFORE processWebhook so a crash/timeout
            // after the comment is written cannot be double-posted on provider retry.
            // Failed processing releases the claim so a corrected retry may proceed.
            $this->inboundCache->set($replayKey, time(), self::INBOUND_REPLAY_TTL_SECONDS);

            try {
                $result = $this->inboundEmailService->processWebhook($payload);
            } catch (\Throwable $e) {
                $this->inboundCache->remove($replayKey);
                throw $e;
            }

            if ($result['success']) {
                return new JSONResponse(['success' => true, 'ticket_id' => $result['ticket_id'] ?? null]);
            }

            $this->inboundCache->remove($replayKey);
            // Never echo service-internal reject reasons (Ticket not found vs
            // Sender not authorized) — same invalid_payload as replay/rate paths.
            $this->logger->warning('Inbound webhook processing rejected', [
                'detail' => $result['error'] ?? 'unknown',
            ]);
            return new JSONResponse([
                'success' => false,
                'error' => $l->t('invalid_payload'),
            ], 400);
        } finally {
            try {
                $this->locking->releaseLock($replayLockKey, ILockingProvider::LOCK_EXCLUSIVE);
            } catch (\Throwable $e) {
                // Lock release failures must not mask the webhook response.
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPayload(): ?array
    {
        $provider = strtolower(trim((string) $this->config->getAppValue('ticketcheck', 'inbound_email_webhook_provider', 'generic')));
        // Generic HMAC signs the raw body — only accept JSON so signed bytes match processed fields.
        // Form/query params with an empty body cannot authorize unsigned fields.
        if ($provider === '' || $provider === 'generic') {
            $body = $this->getRawBody();
            if ($body === '') {
                return null;
            }
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : null;
        }

        $contentType = (string) $this->request->getHeader('Content-Type');

        if (str_contains($contentType, 'application/json')) {
            $body = $this->getRawBody();
            if ($body === '') {
                return null;
            }
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : null;
        }

        return $this->request->getParams();
    }

    private function getRawBody(): string
    {
        if ($this->rawBody !== null) {
            return $this->rawBody;
        }
        $body = file_get_contents('php://input');
        if ($body === false) {
            $body = '';
        }
        $this->rawBody = $body;
        return $this->rawBody;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function verifyWebhookSignature(array $payload): bool
    {
        $provider = strtolower(trim((string)$this->config->getAppValue('ticketcheck', 'inbound_email_webhook_provider', 'generic')));
        if ($provider === 'mailgun') {
            return $this->verifyMailgunSignature($payload);
        }
        if ($provider === 'sendgrid') {
            return $this->verifySendgridSignature();
        }
        return $this->verifyGenericWebhookSignature();
    }

    private function verifyGenericWebhookSignature(): bool
    {
        $secret = trim((string) $this->config->getAppValue('ticketcheck', 'inbound_email_webhook_signing_secret', ''));
        // Fail closed: webhook() already requires a secret for generic providers;
        // never accept unsigned payloads if that gate is bypassed.
        if ($secret === '') {
            return false;
        }

        $timestampHeader = trim((string)$this->request->getHeader('X-Webhook-Timestamp'));
        if ($timestampHeader === '') {
            $timestampHeader = trim((string)$this->request->getHeader('X-Timestamp'));
        }
        $signatureHeader = trim((string)$this->request->getHeader('X-Webhook-Signature'));
        if ($signatureHeader === '') {
            $signatureHeader = trim((string)$this->request->getHeader('X-Signature'));
        }

        if ($timestampHeader === '' || $signatureHeader === '' || !ctype_digit($timestampHeader)) {
            return false;
        }

        $timestamp = (int) $timestampHeader;
        if (abs(time() - $timestamp) > self::INBOUND_SIGNATURE_MAX_SKEW_SECONDS) {
            return false;
        }

        $rawBody = $this->getRawBody();
        $expectedSignature = hash_hmac('sha256', $timestampHeader . '.' . $rawBody, $secret);
        return hash_equals($expectedSignature, $signatureHeader);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function verifyMailgunSignature(array $payload): bool
    {
        $signingKey = trim((string) $this->config->getAppValue('ticketcheck', 'inbound_email_webhook_signing_secret', ''));
        if ($signingKey === '') {
            return false;
        }

        $signatureBag = is_array($payload['signature'] ?? null) ? $payload['signature'] : $payload;
        $timestamp = trim((string)($signatureBag['timestamp'] ?? ''));
        $token = trim((string)($signatureBag['token'] ?? ''));
        $signature = trim((string)($signatureBag['signature'] ?? ''));

        if ($timestamp === '' || $token === '' || $signature === '' || !ctype_digit($timestamp)) {
            return false;
        }
        if (abs(time() - (int)$timestamp) > self::INBOUND_SIGNATURE_MAX_SKEW_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . $token, $signingKey);
        return hash_equals($expected, $signature);
    }

    private function verifySendgridSignature(): bool
    {
        $publicKey = trim((string) $this->config->getAppValue('ticketcheck', 'inbound_email_webhook_public_key', ''));
        if ($publicKey === '') {
            return false;
        }
        $signatureHeader = trim((string)$this->request->getHeader('X-Twilio-Email-Event-Webhook-Signature'));
        $timestampHeader = trim((string)$this->request->getHeader('X-Twilio-Email-Event-Webhook-Timestamp'));
        if ($signatureHeader === '' || $timestampHeader === '' || !ctype_digit($timestampHeader)) {
            return false;
        }
        if (abs(time() - (int)$timestampHeader) > self::INBOUND_SIGNATURE_MAX_SKEW_SECONDS) {
            return false;
        }
        $signatureRaw = base64_decode($signatureHeader, true);
        if ($signatureRaw === false) {
            return false;
        }
        $publicKeyPem = $this->normalizePublicKeyToPem($publicKey);
        if ($publicKeyPem === null) {
            return false;
        }

        $data = $timestampHeader . $this->getRawBody();
        $verifyResult = openssl_verify($data, $signatureRaw, $publicKeyPem, OPENSSL_ALGO_SHA256);
        return $verifyResult === 1;
    }

    private function normalizePublicKeyToPem(string $publicKey): ?string
    {
        if (str_contains($publicKey, 'BEGIN PUBLIC KEY')) {
            return $publicKey;
        }
        $sanitized = preg_replace('/\s+/', '', $publicKey);
        if (!is_string($sanitized) || $sanitized === '' || base64_decode($sanitized, true) === false) {
            return null;
        }
        $chunked = trim(chunk_split($sanitized, 64, "\n"));
        return "-----BEGIN PUBLIC KEY-----\n" . $chunked . "\n-----END PUBLIC KEY-----";
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function matchesConfiguredInboundAddress(array $payload, string $inboundAddress): bool
    {
        $toRaw = (string)($payload['to'] ?? $payload['recipient'] ?? '');
        if ($toRaw === '') {
            return false;
        }

        if (!preg_match('/([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/i', $toRaw, $matches)) {
            return false;
        }

        $recipient = strtolower(trim($matches[1]));
        $configured = strtolower(trim($inboundAddress));
        if ($recipient === $configured) {
            return true;
        }

        $atPos = strpos($configured, '@');
        if ($atPos === false) {
            return false;
        }
        $configuredLocal = substr($configured, 0, $atPos);
        $configuredDomain = substr($configured, $atPos + 1);

        $recipientAt = strpos($recipient, '@');
        if ($recipientAt === false) {
            return false;
        }
        $recipientLocal = substr($recipient, 0, $recipientAt);
        $recipientDomain = substr($recipient, $recipientAt + 1);

        if ($recipientDomain !== $configuredDomain) {
            return false;
        }

        return $recipientLocal === $configuredLocal || str_starts_with($recipientLocal, $configuredLocal . '+');
    }

    private function buildClientRateLimitKey(string $token): string
    {
        $remote = trim((string)$this->request->getRemoteAddress());
        if ($remote === '') {
            $remote = 'unknown';
        }
        return 'rl:' . hash('sha256', $remote . '|' . $token);
    }

    private function isRateLimited(string $clientKey): bool
    {
        $lockKey = 'ticketcheck/inbound/rl/' . $clientKey;
        try {
            $this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE, 'TicketCheck inbound rate limit');
        } catch (LockedException $e) {
            // Fail closed: under lock contention treat as limited so parallel
            // floods cannot bypass the counter by racing the lock.
            return true;
        }

        try {
            $current = (int)($this->inboundCache->get($clientKey) ?? 0);
            if ($current >= self::INBOUND_RATE_LIMIT_PER_MINUTE) {
                return true;
            }

            $this->inboundCache->set($clientKey, $current + 1, self::INBOUND_RATE_LIMIT_WINDOW_SECONDS);
            return false;
        } finally {
            try {
                $this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * Replay fingerprint.
     *
     * Generic / SendGrid: body-bound (HMAC already covers body for those providers).
     * Mailgun: signature is only HMAC(timestamp + token) — body is unbound. Key
     * the replay cache on the signature package so a captured timestamp/token/
     * signature cannot authorize a swapped from/subject/text body.
     *
     * @param array<string, mixed> $payload
     */
    private function buildReplayCacheKey(array $payload): string
    {
        $provider = strtolower(trim((string)$this->config->getAppValue('ticketcheck', 'inbound_email_webhook_provider', 'generic')));
        if ($provider === 'mailgun') {
            $signatureBag = is_array($payload['signature'] ?? null) ? $payload['signature'] : $payload;
            $timestamp = trim((string)($signatureBag['timestamp'] ?? ''));
            $token = trim((string)($signatureBag['token'] ?? ''));
            $digest = hash('sha256', 'mailgun|' . $timestamp . '|' . $token);
            return 'replay:' . $digest;
        }

        $idHeader = trim((string)$this->request->getHeader('X-Webhook-Id'));
        if ($idHeader === '') {
            $idHeader = trim((string)$this->request->getHeader('Message-Id'));
        }
        $timestampHeader = trim((string)$this->request->getHeader('X-Webhook-Timestamp'));

        $payloadFingerprint = [
            'id' => $idHeader !== '' ? $idHeader : null,
            'to' => (string)($payload['to'] ?? $payload['recipient'] ?? ''),
            'from' => (string)($payload['from'] ?? $payload['sender'] ?? ''),
            'subject' => (string)($payload['subject'] ?? ''),
            'text' => (string)($payload['text'] ?? $payload['body-plain'] ?? $payload['body_plain'] ?? $payload['stripped-text'] ?? $payload['stripped_text'] ?? ''),
            'html' => (string)($payload['html'] ?? $payload['body-html'] ?? $payload['body_html'] ?? ''),
            'timestamp' => $timestampHeader !== '' ? $timestampHeader : null,
        ];

        $digest = hash('sha256', json_encode($payloadFingerprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        return 'replay:' . $digest;
    }
}

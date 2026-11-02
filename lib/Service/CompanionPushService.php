<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\AppInfo\Application;
use OCA\Ticketcheck\Exception\CompanionValidationException;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Companion push registration + Expo delivery (WP-070).
 *
 * Security model (mirrors MobilityCheck MobilePushService):
 *  - Always store a SHA-256 fingerprint for revoke matching.
 *  - Store the token encrypted (ICrypto) so Expo delivery works without re-registration.
 *  - {@see enqueue()} records an outbound intent whenever a token exists.
 *  - Remote delivery runs only when {@see isFeatureEnabled()} is true
 *    (app config companion_push_enabled=1 AND expo_push_access_token set).
 *  - Default stays fail-closed (feature off) until ops proves delivery.
 *
 * Payloads carry the ticket id + number only — never comment bodies or
 * customer data (lock-screen minimization, spec §12).
 */
class CompanionPushService
{
	public const INTENT_ASSIGNED = 'ticket.assigned';
	public const INTENT_PUBLIC_COMMENT = 'ticket.public_comment';
	public const INTENT_WAITING = 'ticket.waiting';

	public const CONFIG_FEATURE = 'companion_push_enabled';
	public const CONFIG_EXPO_TOKEN = 'expo_push_access_token';
	public const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';

	public function __construct(
		private IConfig $config,
		private ?ICrypto $crypto = null,
		private ?IClientService $http = null,
		private ?LoggerInterface $logger = null,
	) {
	}

	/**
	 * Companion bootstrap `features.push` / `pushAvailable` — true only when ops
	 * enabled delivery AND an Expo access token is configured. Never invents
	 * success (honest capability contract, spec §6.5).
	 */
	public function isFeatureEnabled(): bool
	{
		if ($this->config->getAppValue(Application::APP_ID, self::CONFIG_FEATURE, '0') !== '1') {
			return false;
		}
		return trim($this->config->getAppValue(Application::APP_ID, self::CONFIG_EXPO_TOKEN, '')) !== '';
	}

	/**
	 * Store (upsert) the companion device's Expo push token.
	 * One active registration per user — a re-register replaces the previous
	 * device. Registration is accepted even while delivery is disabled so an
	 * ops flag flip activates already-registered devices.
	 *
	 * @throws CompanionValidationException on invalid token/platform/device
	 */
	public function register(string $userId, string $pushToken, string $platform, string $deviceId = ''): void
	{
		$pushToken = trim($pushToken);
		if ($pushToken === '' || strlen($pushToken) > 512) {
			throw new CompanionValidationException('invalid_push_token', 'pushToken');
		}
		$platform = strtolower(trim($platform));
		if (!in_array($platform, ['ios', 'android'], true)) {
			throw new CompanionValidationException('invalid_platform', 'platform');
		}
		$deviceId = trim($deviceId);
		if (strlen($deviceId) > 255) {
			throw new CompanionValidationException('invalid_device', 'deviceId');
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'companion_push_token_hash', hash('sha256', $pushToken));
		$this->config->setUserValue($userId, Application::APP_ID, 'companion_push_platform', $platform);
		$this->config->setUserValue($userId, Application::APP_ID, 'companion_push_at', gmdate('c'));
		if ($deviceId !== '') {
			$this->config->setUserValue($userId, Application::APP_ID, 'companion_push_device', $deviceId);
		} else {
			$this->config->deleteUserValue($userId, Application::APP_ID, 'companion_push_device');
		}
		if ($this->crypto !== null) {
			$this->config->setUserValue(
				$userId,
				Application::APP_ID,
				'companion_push_token_cipher',
				$this->crypto->encrypt($pushToken),
			);
		}
	}

	/**
	 * Clear the registration. When a token is supplied it must hash-match the
	 * stored one (a stale second device must not wipe the active registration);
	 * an empty/missing token clears unconditionally (sign-out path).
	 */
	public function unregister(string $userId, ?string $pushToken = null): void
	{
		$pushToken = trim((string)$pushToken);
		if ($pushToken !== '') {
			$stored = $this->config->getUserValue($userId, Application::APP_ID, 'companion_push_token_hash', '');
			if ($stored !== '' && !hash_equals($stored, hash('sha256', $pushToken))) {
				return;
			}
		}
		$this->clear($userId);
	}

	public function clear(string $userId): void
	{
		$this->config->deleteUserValue($userId, Application::APP_ID, 'companion_push_token_hash');
		$this->config->deleteUserValue($userId, Application::APP_ID, 'companion_push_token_cipher');
		$this->config->deleteUserValue($userId, Application::APP_ID, 'companion_push_platform');
		$this->config->deleteUserValue($userId, Application::APP_ID, 'companion_push_device');
		$this->config->deleteUserValue($userId, Application::APP_ID, 'companion_push_at');
		$this->config->deleteUserValue($userId, Application::APP_ID, 'companion_push_last_intent');
	}

	public function hasRegistration(string $userId): bool
	{
		return $this->config->getUserValue($userId, Application::APP_ID, 'companion_push_token_hash', '') !== '';
	}

	/**
	 * Queue a companion push intent when the agent has a registered token.
	 * Delivers via Expo when the feature flag is enabled; the intent is always
	 * recorded for UAT/debug (`companion_push_last_intent`).
	 *
	 * @param array<string,mixed> $payload
	 * @return bool true when an intent was recorded (token present)
	 */
	public function enqueue(string $userId, string $type, array $payload = []): bool
	{
		$userId = trim($userId);
		$type = trim($type);
		if ($userId === '' || $type === '') {
			return false;
		}
		if (!$this->hasRegistration($userId)) {
			return false;
		}
		$delivered = false;
		$reason = 'features.push_disabled';
		if ($this->isFeatureEnabled()) {
			$send = $this->deliverExpo($userId, $type, $payload);
			$delivered = $send['ok'];
			$reason = $send['reason'];
		}
		$intent = [
			'type' => $type,
			'payload' => $payload,
			'at' => gmdate('c'),
			'delivered' => $delivered,
			'deliveryDeferredReason' => $delivered ? null : $reason,
		];
		try {
			$this->config->setUserValue(
				$userId,
				Application::APP_ID,
				'companion_push_last_intent',
				json_encode($intent, JSON_THROW_ON_ERROR),
			);
		} catch (\Throwable) {
			// Intent bookkeeping must never break the ticket mutation path.
		}
		return true;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{ok:bool,reason:string}
	 */
	public function deliverExpo(string $userId, string $type, array $payload = []): array
	{
		if (!$this->isFeatureEnabled()) {
			return ['ok' => false, 'reason' => 'features.push_disabled'];
		}
		if ($this->http === null || $this->crypto === null) {
			return ['ok' => false, 'reason' => 'push_http_unavailable'];
		}
		$cipher = $this->config->getUserValue($userId, Application::APP_ID, 'companion_push_token_cipher', '');
		if ($cipher === '') {
			return ['ok' => false, 'reason' => 'push_token_cipher_missing'];
		}
		try {
			$token = $this->crypto->decrypt($cipher);
		} catch (\Throwable) {
			return ['ok' => false, 'reason' => 'push_token_decrypt_failed'];
		}
		$token = trim($token);
		if ($token === '') {
			return ['ok' => false, 'reason' => 'push_token_empty'];
		}
		$access = trim($this->config->getAppValue(Application::APP_ID, self::CONFIG_EXPO_TOKEN, ''));
		$message = [
			'to' => $token,
			'title' => $this->titleFor($type),
			'body' => $this->bodyFor($type, $payload),
			// Whitelist: push data may carry routing identifiers only. A caller
			// passing title/comment fields must never reach the lock screen.
			'data' => ['type' => $type]
				+ array_intersect_key($payload, array_flip(['ticketId', 'number'])),
			'sound' => 'default',
		];
		try {
			$client = $this->http->newClient();
			$response = $client->post(self::EXPO_PUSH_URL, [
				'headers' => [
					'Authorization' => 'Bearer ' . $access,
					'Accept' => 'application/json',
					'Content-Type' => 'application/json',
				],
				'body' => json_encode([$message], JSON_THROW_ON_ERROR),
				'timeout' => 8,
			]);
			$code = $response->getStatusCode();
			if ($code < 200 || $code >= 300) {
				$this->logger?->warning('TicketCheck Expo push HTTP {code}', ['code' => $code]);
				return ['ok' => false, 'reason' => 'expo_http_' . $code];
			}
			$decoded = json_decode($response->getBody(), true);
			$data = is_array($decoded) ? ($decoded['data'] ?? $decoded) : null;
			$row = is_array($data) ? ($data[0] ?? $data) : null;
			if (!is_array($row)) {
				return ['ok' => false, 'reason' => 'expo_ticket_malformed'];
			}
			$status = strtolower(trim((string)($row['status'] ?? '')));
			if ($status === 'error') {
				$err = is_array($row['details'] ?? null) ? (string)($row['details']['error'] ?? 'error') : 'error';
				return ['ok' => false, 'reason' => 'expo_ticket_error:' . $err];
			}
			// Expo push tickets: status "ok" with ticket id, or legacy bare id.
			if ($status === 'ok' || isset($row['id'])) {
				return ['ok' => true, 'reason' => 'delivered'];
			}
			return ['ok' => false, 'reason' => 'expo_ticket_unknown_status'];
		} catch (\Throwable $e) {
			$this->logger?->warning('TicketCheck Expo push failed: {msg}', ['msg' => $e->getMessage()]);
			return ['ok' => false, 'reason' => 'expo_transport_error'];
		}
	}

	/** @return array<string,mixed>|null */
	public function lastIntent(string $userId): ?array
	{
		$raw = $this->config->getUserValue($userId, Application::APP_ID, 'companion_push_last_intent', '');
		if ($raw === '') {
			return null;
		}
		try {
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		return is_array($decoded) ? $decoded : null;
	}

	private function titleFor(string $type): string
	{
		return match ($type) {
			self::INTENT_ASSIGNED => 'Ticket assigned to you',
			self::INTENT_PUBLIC_COMMENT => 'New customer reply',
			self::INTENT_WAITING => 'Ticket waiting for customer',
			default => 'TicketCheck',
		};
	}

	/**
	 * Body carries the ticket number only — never the ticket title or comment
	 * text (push messages land on lock screens outside the app's trust zone).
	 *
	 * @param array<string,mixed> $payload
	 */
	private function bodyFor(string $type, array $payload): string
	{
		$number = trim((string)($payload['number'] ?? ''));
		$label = $number !== '' ? $number : 'a ticket';
		return match ($type) {
			self::INTENT_ASSIGNED => "You were assigned {$label}.",
			self::INTENT_PUBLIC_COMMENT => "New public reply on {$label}.",
			self::INTENT_WAITING => "{$label} is waiting for the customer.",
			default => 'Open TicketCheck for details.',
		};
	}
}

<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Exception\ValidationException;
use OCA\Ticketcheck\Support\ImapConnection;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Security\ICrypto;

/**
 * Single source of truth for the inbound IMAP mailbox config.
 *
 * All keys live under app id 'ticketcheck' with the 'inbound_imap_' prefix.
 * Secrets (IMAP password, SMTP password, OAuth tokens) are stored encrypted
 * via ICrypto and are NEVER returned by status() or written to logs.
 *
 * Legacy keys that predate this feature and stay authoritative:
 *  - inbound_email_enabled / inbound_email_address (webhook + Reply-To)
 *  - inbound_email_webhook_* (webhook provider + signature config)
 */
final class InboundMailConfigService
{
	public const APP_ID = 'ticketcheck';
	public const MAILBOX_ID = 'default';

	public const AUTH_PASSWORD = 'password';
	public const AUTH_XOAUTH2 = 'xoauth2';

	public const PROVIDER_GOOGLE = 'google';
	public const PROVIDER_MICROSOFT = 'microsoft';

	private const PREFIX = 'inbound_imap_';

	/** Every appconfig key this feature owns — iterated for uninstall cleanup. */
	public const ALL_KEYS = [
		'inbound_imap_enabled',
		'inbound_imap_address',
		'inbound_imap_auth_mode',
		'inbound_imap_oauth_provider',
		'inbound_imap_host',
		'inbound_imap_port',
		'inbound_imap_enc',
		'inbound_imap_username',
		'inbound_imap_secret_enc',
		'inbound_imap_oauth_access_enc',
		'inbound_imap_oauth_refresh_enc',
		'inbound_imap_oauth_expires_at',
		'inbound_imap_folder',
		'inbound_imap_last_uid',
		'inbound_imap_baseline_uid',
		'inbound_imap_uidvalidity',
		'inbound_imap_last_sync_at',
		'inbound_imap_last_error',
		'inbound_imap_error_count',
		'inbound_imap_auth_fail_count',
		'inbound_imap_test_passed_hash',
		'inbound_imap_test_passed_at',
		'inbound_imap_unseen_backlog',
		'inbound_imap_ignored_cap_count',
		'inbound_imap_needs_reauth',
		'inbound_imap_allow_private_host',
		'inbound_imap_allow_self_signed',
		'inbound_imap_process_backlog',
		'inbound_imap_require_reply_token',
		'inbound_imap_unknown_sender_policy',
		'inbound_imap_routing_json',
		'inbound_imap_send_via_smtp',
		'inbound_imap_smtp_host',
		'inbound_imap_smtp_port',
		'inbound_imap_smtp_enc',
		'inbound_imap_smtp_username',
		'inbound_imap_smtp_secret_enc',
		'inbound_imap_smtp_from',
		'inbound_imap_dsgvo_ack',
		'inbound_imap_probe_window_start',
		'inbound_imap_probe_attempts',
	];

	/** Connection-test hash covers every field that can invalidate a "passed" test. */
	private const HASH_FIELDS = [
		'host', 'port', 'enc', 'username', 'auth_mode', 'oauth_provider', 'folder',
	];

	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IConfig $config,
		private readonly ICrypto $crypto,
		private readonly IL10N $l,
	) {
	}

	private function getKey(string $short, string $default = ''): string
	{
		return trim((string)$this->appConfig->getValueString(self::APP_ID, self::PREFIX . $short, $default));
	}

	private function setKey(string $short, string $value): void
	{
		$this->appConfig->setValueString(self::APP_ID, self::PREFIX . $short, $value);
	}

	private function boolKey(string $short, bool $default = false): bool
	{
		$v = strtolower($this->getKey($short, $default ? 'yes' : 'no'));
		return $v === 'yes' || $v === '1' || $v === 'true';
	}

	private function setBoolKey(string $short, bool $value): void
	{
		$this->setKey($short, $value ? 'yes' : 'no');
	}

	/**
	 * Normalized mailbox config for runtime use. Secrets stay encrypted here —
	 * resolution happens in InboundMailAuthService.
	 *
	 * @return array<string, mixed>
	 */
	public function mailboxConfig(): array
	{
		$port = (int)$this->getKey('port', '993');
		$enc = ImapConnection::resolveImapEnc($this->getKey('enc', ''), $port > 0 ? $port : 993);

		return [
			'mailbox_id' => self::MAILBOX_ID,
			'enabled' => $this->isEnabled(),
			'address' => strtolower($this->getKey('address', '')),
			'auth_mode' => $this->getKey('auth_mode', self::AUTH_PASSWORD) === self::AUTH_XOAUTH2
				? self::AUTH_XOAUTH2
				: self::AUTH_PASSWORD,
			'oauth_provider' => $this->getKey('oauth_provider', ''),
			'host' => $this->getKey('host', ''),
			'port' => $port > 0 ? $port : 993,
			'enc' => $enc,
			'username' => $this->getKey('username', ''),
			'secret_enc' => $this->getKey('secret_enc', ''),
			'oauth_access_enc' => $this->getKey('oauth_access_enc', ''),
			'oauth_refresh_enc' => $this->getKey('oauth_refresh_enc', ''),
			'oauth_expires_at' => $this->getKey('oauth_expires_at', ''),
			'folder' => $this->getKey('folder', 'INBOX') !== '' ? $this->getKey('folder', 'INBOX') : 'INBOX',
			'last_uid' => (int)$this->getKey('last_uid', '0'),
			'baseline_uid' => (int)$this->getKey('baseline_uid', '0'),
			'uidvalidity' => (int)$this->getKey('uidvalidity', '0'),
			'last_sync_at' => $this->getKey('last_sync_at', ''),
			'last_error' => $this->getKey('last_error', ''),
			'error_count' => (int)$this->getKey('error_count', '0'),
			'auth_fail_count' => (int)$this->getKey('auth_fail_count', '0'),
			'test_passed_hash' => $this->getKey('test_passed_hash', ''),
			'test_passed_at' => $this->getKey('test_passed_at', ''),
			'unseen_backlog' => (int)$this->getKey('unseen_backlog', '0'),
			'ignored_cap_count' => (int)$this->getKey('ignored_cap_count', '0'),
			'needs_reauth' => $this->boolKey('needs_reauth'),
			'allow_private_host' => $this->boolKey('allow_private_host'),
			'allow_self_signed' => $this->boolKey('allow_self_signed'),
			'process_backlog' => $this->boolKey('process_backlog'),
			'require_reply_token' => $this->boolKey('require_reply_token'),
			'unknown_sender_policy' => $this->getKey('unknown_sender_policy', 'create') === 'ignore' ? 'ignore' : 'create',
			'send_via_smtp' => $this->boolKey('send_via_smtp', true),
		];
	}

	/**
	 * SMTP override config — only meaningful when send_via_smtp is on AND
	 * an explicit smtp_host is configured (otherwise EmailService falls back
	 * to the instance mailer, spec DEC-21).
	 *
	 * @return array{host: string, port: int, enc: string, username: string, secret_enc: string, from: string}
	 */
	public function smtpConfig(): array
	{
		$port = (int)$this->getKey('smtp_port', '587');
		return [
			'host' => $this->getKey('smtp_host', ''),
			'port' => $port > 0 ? $port : 587,
			'enc' => ImapConnection::resolveSmtpEnc($this->getKey('smtp_enc', ''), $port > 0 ? $port : 587),
			'username' => $this->getKey('smtp_username', ''),
			'secret_enc' => $this->getKey('smtp_secret_enc', ''),
			'from' => strtolower($this->getKey('smtp_from', '')),
		];
	}

	public function isEnabled(): bool
	{
		return $this->boolKey('enabled');
	}

	public function isConfigured(): bool
	{
		$cfg = $this->mailboxConfig();
		return $cfg['address'] !== '' && $cfg['host'] !== '' && $cfg['username'] !== '';
	}

	/**
	 * The address inbound replies route to. Prefers the IMAP mailbox address;
	 * falls back to the legacy webhook inbound_email_address so Reply-To
	 * generation stays correct when only the webhook is configured.
	 */
	public function inboundAddress(): string
	{
		$imap = strtolower($this->getKey('address', ''));
		if ($imap !== '' && filter_var($imap, FILTER_VALIDATE_EMAIL)) {
			return $imap;
		}
		return strtolower(trim((string)$this->config->getAppValue(self::APP_ID, 'inbound_email_address', '')));
	}

	/**
	 * Any inbound channel enabled (IMAP and/or webhook).
	 */
	public function anyInboundEnabled(): bool
	{
		$webhookEnabled = $this->config->getAppValue(self::APP_ID, 'inbound_email_enabled', 'no') === 'yes';
		return $this->isEnabled() || $webhookEnabled;
	}

	/**
	 * The connection-test hash (spec: enable requires a passing test against
	 * the CURRENT config — editing any covered field invalidates it).
	 */
	public function testPassedHash(array $cfg): string
	{
		$parts = [];
		foreach (self::HASH_FIELDS as $f) {
			$parts[] = (string)($cfg[$f] ?? '');
		}
		return hash('sha256', implode('|', $parts));
	}

	public function configMatchesLastPass(array $cfg): bool
	{
		$stored = $this->getKey('test_passed_hash', '');
		return $stored !== '' && hash_equals($stored, $this->testPassedHash($cfg));
	}

	/**
	 * Status payload for the settings UI — no secrets, masked username.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array
	{
		$cfg = $this->mailboxConfig();
		return [
			'enabled' => $cfg['enabled'],
			'configured' => $this->isConfigured(),
			'address' => $cfg['address'],
			'auth_mode' => $cfg['auth_mode'],
			'oauth_provider' => $cfg['oauth_provider'],
			'host' => $cfg['host'],
			'port' => $cfg['port'],
			'enc' => $cfg['enc'],
			'username' => $cfg['username'],
			'has_password' => $cfg['secret_enc'] !== '',
			'has_oauth_tokens' => $cfg['oauth_access_enc'] !== '' || $cfg['oauth_refresh_enc'] !== '',
			'folder' => $cfg['folder'],
			'last_sync_at' => $cfg['last_sync_at'],
			'last_error' => $cfg['last_error'],
			'error_count' => $cfg['error_count'],
			'auth_fail_count' => $cfg['auth_fail_count'],
			'needs_reauth' => $cfg['needs_reauth'],
			'test_passed' => $cfg['test_passed_hash'] !== '' && hash_equals($cfg['test_passed_hash'], $this->testPassedHash($cfg)),
			'unseen_backlog' => $cfg['unseen_backlog'],
			'ignored_cap_count' => $cfg['ignored_cap_count'],
			'allow_private_host' => $cfg['allow_private_host'],
			'allow_self_signed' => $cfg['allow_self_signed'],
			'process_backlog' => $cfg['process_backlog'],
			'require_reply_token' => $cfg['require_reply_token'],
			'unknown_sender_policy' => $cfg['unknown_sender_policy'],
			'send_via_smtp' => $cfg['send_via_smtp'],
			'smtp' => [
				'host' => $this->getKey('smtp_host', ''),
				'port' => (int)$this->getKey('smtp_port', '587'),
				'enc' => $this->getKey('smtp_enc', ''),
				'username' => $this->getKey('smtp_username', ''),
				'from' => $this->getKey('smtp_from', ''),
				'has_password' => $this->getKey('smtp_secret_enc', '') !== '',
			],
			'routing' => $this->routingMap(),
			'dsgvo_ack' => $this->boolKey('dsgvo_ack'),
		];
	}

	/**
	 * Validate + normalize the settings form into a storable array.
	 * Throws ValidationException with field-keyed errors (spec: field-level
	 * rendering, never a generic banner).
	 *
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 */
	public function validateInput(array $raw): array
	{
		$l = $this->l;
		$errors = [];
		$out = [];

		$address = strtolower(trim((string)($raw['address'] ?? '')));
		if ($address === '' || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
			$errors['address'] = $l->t('inbound_err_address_invalid');
		}
		$out['address'] = $address;

		$authMode = strtolower(trim((string)($raw['auth_mode'] ?? self::AUTH_PASSWORD)));
		$out['auth_mode'] = $authMode === self::AUTH_XOAUTH2 ? self::AUTH_XOAUTH2 : self::AUTH_PASSWORD;

		$provider = strtolower(trim((string)($raw['oauth_provider'] ?? '')));
		if ($out['auth_mode'] === self::AUTH_XOAUTH2 && !in_array($provider, [self::PROVIDER_GOOGLE, self::PROVIDER_MICROSOFT], true)) {
			$errors['oauth_provider'] = $l->t('inbound_err_provider_required');
		}
		$out['oauth_provider'] = $provider;

		// Happy path: host blank → server auto-detects from the address domain.
		$host = ImapConnection::normalizeHost((string)($raw['host'] ?? ''));
		$port = (int)($raw['port'] ?? 0);
		$enc = strtolower(trim((string)($raw['enc'] ?? '')));
		if ($host !== '') {
			if ($port <= 0) {
				$port = $enc === 'ssl' ? 993 : 143;
			}
			if ($port < 1 || $port > 65535) {
				$errors['port'] = $l->t('inbound_err_port_invalid');
			}
			if (!in_array($enc, ['ssl', 'starttls', 'none'], true)) {
				$enc = ImapConnection::resolveImapEnc('', $port);
			}
			if (ImapConnection::isBlockedMailHost($host) && !$this->truthy($raw['allow_private_host'] ?? null)) {
				// Uniform message — no reachability oracle for delegated admins.
				$errors['host'] = $l->t('inbound_err_host_blocked');
			}
		}
		$out['host'] = $host;
		$out['port'] = $port;
		$out['enc'] = $enc !== '' ? $enc : ImapConnection::resolveImapEnc('', $port > 0 ? $port : 993);

		$username = ImapConnection::normalizeUsername((string)($raw['username'] ?? ''));
		if ($username === '') {
			$username = $address;
		}
		if ($this->hasCtrlChars($username)) {
			$errors['username'] = $l->t('inbound_err_username_invalid');
		}
		$out['username'] = $username;

		$folder = trim((string)($raw['folder'] ?? 'INBOX'));
		if ($folder === '') {
			$folder = 'INBOX';
		}
		if (!ImapConnection::isSafeImapFolder($folder)) {
			$errors['folder'] = $l->t('inbound_err_folder_invalid');
		}
		$out['folder'] = $folder;

		// Password: empty field = keep the stored one (never echoed back).
		$out['password'] = trim((string)($raw['password'] ?? ''));

		$out['allow_private_host'] = $this->truthy($raw['allow_private_host'] ?? null);
		$out['allow_self_signed'] = $this->truthy($raw['allow_self_signed'] ?? null);
		$out['process_backlog'] = $this->truthy($raw['process_backlog'] ?? null);
		$out['require_reply_token'] = $this->truthy($raw['require_reply_token'] ?? null);
		$out['send_via_smtp'] = $this->truthy($raw['send_via_smtp'] ?? true);
		$out['dsgvo_ack'] = $this->truthy($raw['dsgvo_ack'] ?? null);
		if (!$out['dsgvo_ack']) {
			$errors['dsgvo_ack'] = $l->t('inbound_err_dsgvo_required');
		}

		$policy = strtolower(trim((string)($raw['unknown_sender_policy'] ?? 'create')));
		$out['unknown_sender_policy'] = $policy === 'ignore' ? 'ignore' : 'create';

		$out['routing'] = $this->validateRoutingMap($raw['routing'] ?? [], $errors);

		// SMTP override block (optional).
		$smtpHost = ImapConnection::normalizeHost((string)($raw['smtp_host'] ?? ''));
		$smtpPort = (int)($raw['smtp_port'] ?? 0);
		$smtpEnc = strtolower(trim((string)($raw['smtp_enc'] ?? '')));
		if ($smtpHost !== '') {
			if ($smtpPort <= 0) {
				$smtpPort = $smtpEnc === 'ssl' ? 465 : 587;
			}
			if ($smtpPort < 1 || $smtpPort > 65535) {
				$errors['smtp_port'] = $l->t('inbound_err_port_invalid');
			}
			if (!in_array($smtpEnc, ['ssl', 'tls', 'starttls', 'none'], true)) {
				$smtpEnc = ImapConnection::resolveSmtpEnc('', $smtpPort);
			}
			if (ImapConnection::isBlockedMailHost($smtpHost) && !$out['allow_private_host']) {
				$errors['smtp_host'] = $l->t('inbound_err_host_blocked');
			}
		}
		$out['smtp_host'] = $smtpHost;
		$out['smtp_port'] = $smtpPort;
		$out['smtp_enc'] = $smtpEnc !== '' ? $smtpEnc : ImapConnection::resolveSmtpEnc('', $smtpPort > 0 ? $smtpPort : 587);
		$out['smtp_username'] = ImapConnection::normalizeUsername((string)($raw['smtp_username'] ?? ''));
		$out['smtp_password'] = trim((string)($raw['smtp_password'] ?? ''));
		$smtpFrom = strtolower(trim((string)($raw['smtp_from'] ?? '')));
		if ($smtpFrom !== '' && !filter_var($smtpFrom, FILTER_VALIDATE_EMAIL)) {
			$errors['smtp_from'] = $l->t('inbound_err_address_invalid');
		}
		$out['smtp_from'] = $smtpFrom;

		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		return $out;
	}

	/**
	 * Checkbox parsing — form-encoded "false"/"0" are truthy strings
	 * (api-security-patterns §4); only explicit true values count.
	 */
	private function truthy(mixed $v): bool
	{
		if (is_bool($v)) {
			return $v;
		}
		$v = strtolower(trim((string)$v));
		return $v === '1' || $v === 'yes' || $v === 'true' || $v === 'on';
	}

	private function hasCtrlChars(string $s): bool
	{
		return preg_match('/[\x00-\x1F\x7F]/', $s) === 1;
	}

	/**
	 * Routing map: recipient key → {project_id, category}.
	 * Keys: full address, local part, or named plus-tag. Numeric plus-tags are
	 * RESERVED for ticket replies and are rejected (spec DEC-27).
	 *
	 * @param mixed $raw
	 * @param array<string, string> $errors
	 * @return array<string, array{project_id: int|null, category: string}>
	 */
	private function validateRoutingMap(mixed $raw, array &$errors): array
	{
		if (is_string($raw)) {
			$raw = trim($raw) === '' ? [] : (json_decode($raw, true) ?? null);
		}
		if ($raw === null) {
			$errors['routing'] = $this->l->t('inbound_err_routing_json');
			return [];
		}
		if (!is_array($raw)) {
			if ($raw === [] || $raw === '') {
				return [];
			}
			$errors['routing'] = $this->l->t('inbound_err_routing_json');
			return [];
		}
		$map = [];
		foreach ($raw as $key => $row) {
			$key = strtolower(trim((string)$key));
			if ($key === '') {
				continue;
			}
			if (strlen($key) > 254 || $this->hasCtrlChars($key)) {
				$errors['routing'] = $this->l->t('inbound_err_routing_key');
				return [];
			}
			if (preg_match('/^\d+$/', $key) === 1) {
				$errors['routing'] = $this->l->t('inbound_err_routing_numeric');
				return [];
			}
			$projectId = null;
			$category = 'general';
			if (is_array($row)) {
				$projectId = isset($row['project_id']) && $row['project_id'] !== '' && $row['project_id'] !== null
					? (int)$row['project_id'] : null;
				if ($projectId !== null && $projectId <= 0) {
					$errors['routing'] = $this->l->t('inbound_err_routing_json');
					return [];
				}
				$category = strtolower(trim((string)($row['category'] ?? 'general')));
			}
			if ($category === '' || strlen($category) > 64 || $this->hasCtrlChars($category)) {
				$category = 'general';
			}
			$map[$key] = ['project_id' => $projectId, 'category' => $category];
		}

		return $map;
	}

	public function routingMap(): array
	{
		$raw = $this->getKey('routing_json', '');
		if ($raw === '') {
			return [];
		}
		$errors = [];
		// Re-validate on read — a hand-edited appconfig must not crash sync.
		return $this->validateRoutingMap($raw, $errors);
	}

	/**
	 * Persist a validateInput() result. Secrets are encrypted here and only
	 * overwritten when a new value was actually submitted.
	 */
	public function save(array $v): void
	{
		$this->setKey('address', $v['address']);
		$this->setKey('auth_mode', $v['auth_mode']);
		$this->setKey('oauth_provider', $v['oauth_provider']);
		$this->setKey('host', $v['host']);
		$this->setKey('port', (string)$v['port']);
		$this->setKey('enc', $v['enc']);
		$this->setKey('username', $v['username']);
		$this->setKey('folder', $v['folder']);
		if ($v['password'] !== '') {
			$this->appConfig->setValueString(self::APP_ID, self::PREFIX . 'secret_enc', $this->crypto->encrypt($v['password']), false, true);
		}
		$this->setBoolKey('allow_private_host', $v['allow_private_host']);
		$this->setBoolKey('allow_self_signed', $v['allow_self_signed']);
		$this->setBoolKey('process_backlog', $v['process_backlog']);
		$this->setBoolKey('require_reply_token', $v['require_reply_token']);
		$this->setBoolKey('send_via_smtp', $v['send_via_smtp']);
		$this->setBoolKey('dsgvo_ack', $v['dsgvo_ack']);
		$this->setKey('unknown_sender_policy', $v['unknown_sender_policy']);
		$this->setKey('routing_json', $v['routing'] === [] ? '' : (string)json_encode($v['routing']));
		$this->setKey('smtp_host', $v['smtp_host']);
		$this->setKey('smtp_port', (string)$v['smtp_port']);
		$this->setKey('smtp_enc', $v['smtp_enc']);
		$this->setKey('smtp_username', $v['smtp_username']);
		if ($v['smtp_password'] !== '') {
			$this->appConfig->setValueString(self::APP_ID, self::PREFIX . 'smtp_secret_enc', $this->crypto->encrypt($v['smtp_password']), false, true);
		}
		$this->setKey('smtp_from', $v['smtp_from']);
	}

	/**
	 * Sliding-window probe throttle (spec: the test/enable endpoints share a
	 * budget so delegated admins cannot use connectivity probes as an
	 * oracle). Returns false when the budget is exhausted.
	 */
	public function probeThrottleCheck(int $limit = 10, int $windowSeconds = 300): bool
	{
		$start = (int)$this->getKey('probe_window_start', '0');
		$count = (int)$this->getKey('probe_attempts', '0');
		$now = time();
		if ($now - $start > $windowSeconds) {
			$this->setKey('probe_window_start', (string)$now);
			$this->setKey('probe_attempts', '1');
			return true;
		}
		if ($count >= $limit) {
			return false;
		}
		$this->setKey('probe_attempts', (string)($count + 1));
		return true;
	}

	public function markTestPassed(array $cfg): void
	{
		$this->setKey('test_passed_hash', $this->testPassedHash($cfg));
		$this->setKey('test_passed_at', date('Y-m-d H:i:s'));
		$this->setKey('last_error', '');
		$this->setBoolKey('needs_reauth', false);
	}

	/**
	 * Enable requires a passing test against the current config (spec gate).
	 */
	public function enable(array $cfg, int $baselineUid, int $uidvalidity): void
	{
		$this->setBoolKey('enabled', true);
		$this->setKey('last_uid', (string)$baselineUid);
		$this->setKey('baseline_uid', (string)$baselineUid);
		$this->setKey('uidvalidity', (string)$uidvalidity);
		$this->setKey('error_count', '0');
		$this->setKey('last_error', '');
	}

	public function disable(): void
	{
		$this->setBoolKey('enabled', false);
	}

	public function updateCursor(int $lastUid, int $uidvalidity): void
	{
		$this->setKey('last_uid', (string)$lastUid);
		if ($uidvalidity > 0) {
			$this->setKey('uidvalidity', (string)$uidvalidity);
		}
	}

	public function recordSyncSuccess(int $unseenBacklog = 0): void
	{
		$this->setKey('last_sync_at', date('Y-m-d H:i:s'));
		$this->setKey('last_error', '');
		$this->setKey('error_count', '0');
		$this->setKey('unseen_backlog', (string)max(0, $unseenBacklog));
	}

	public function recordSyncError(string $reason, string $detail = ''): void
	{
		$this->setKey('last_sync_at', date('Y-m-d H:i:s'));
		$this->setKey('last_error', mb_substr(trim($reason . ($detail !== '' ? ': ' . $detail : '')), 0, 255));
		$this->setKey('error_count', (string)((int)$this->getKey('error_count', '0') + 1));
	}

	public function recordAuthFailure(): void
	{
		$count = (int)$this->getKey('auth_fail_count', '0') + 1;
		$this->setKey('auth_fail_count', (string)$count);
		if ($this->mailboxConfig()['auth_mode'] === self::AUTH_XOAUTH2 && $count >= 3) {
			$this->setBoolKey('needs_reauth', true);
		}
	}

	public function clearAuthFailures(): void
	{
		$this->setKey('auth_fail_count', '0');
		$this->setBoolKey('needs_reauth', false);
	}

	public function setNeedsReauth(bool $v): void
	{
		$this->setBoolKey('needs_reauth', $v);
	}

	public function setIgnoredCapCount(int $n): void
	{
		$this->setKey('ignored_cap_count', (string)max(0, $n));
	}

	public function setUnseenBacklog(int $n): void
	{
		$this->setKey('unseen_backlog', (string)max(0, $n));
	}

	public function storeOAuthTokens(string $access, string $refresh, int $expiresIn): void
	{
		$this->appConfig->setValueString(self::APP_ID, self::PREFIX . 'oauth_access_enc', $this->crypto->encrypt($access), false, true);
		if ($refresh !== '') {
			$this->appConfig->setValueString(self::APP_ID, self::PREFIX . 'oauth_refresh_enc', $this->crypto->encrypt($refresh), false, true);
		}
		$this->setKey('oauth_expires_at', date('Y-m-d H:i:s', time() + max(60, $expiresIn)));
	}

	public function decryptField(string $enc): string
	{
		$enc = trim($enc);
		if ($enc === '') {
			return '';
		}
		try {
			return $this->crypto->decrypt($enc);
		} catch (\Throwable) {
			return '';
		}
	}

	public function encryptField(string $plain): string
	{
		return $this->crypto->encrypt($plain);
	}

	/**
	 * OAuth client credentials — admin/system config, never exposed in the UI
	 * response or mailbox fields.
	 */
	public function oauthClientId(string $provider): string
	{
		$key = $provider === self::PROVIDER_GOOGLE ? 'oauth_google_client_id' : 'oauth_microsoft_client_id';
		return trim((string)$this->config->getAppValue(self::APP_ID, $key, ''));
	}

	public function oauthClientSecret(string $provider): string
	{
		$key = $provider === self::PROVIDER_GOOGLE ? 'oauth_google_client_secret' : 'oauth_microsoft_client_secret';
		$raw = trim((string)$this->config->getAppValue(self::APP_ID, $key, ''));
		if ($raw === '') {
			return '';
		}
		$plain = $this->decryptField($raw);
		// Legacy plaintext values are tolerated for read, like the donor app.
		return $plain !== '' ? $plain : $raw;
	}

	/**
	 * Remove every owned config key (uninstall + "disconnect mailbox").
	 */
	public function purge(): void
	{
		foreach (self::ALL_KEYS as $key) {
			$this->appConfig->deleteKey(self::APP_ID, $key);
		}
	}
}

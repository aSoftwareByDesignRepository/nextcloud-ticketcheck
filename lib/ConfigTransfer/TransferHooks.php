<?php

declare(strict_types=1);

/**
 * TicketCheck wiring for the shared transfer engine. Access door lives in
 * app-local appconfig — there is no platform restriction mirror, so the
 * app has no mirrored keys (the door fails closed on empty scopes, hence
 * no cross-key invariant needs an atomic writer either).
 *
 *  - requireTransferAdmin gates on the app's real door-admin role
 *    (PermissionService::isAppAdmin — NC system admins, helpdesk_admins
 *    group members and configured app-admin uids).
 *  - validateValue/normalizeValue run the SAME rules the settings write
 *    paths apply (SettingsController::normalizeYesNo, GuestAuthConfig
 *    enums + clamps, SlaPolicyService formats, the cat_key slug, CSV
 *    group lists — import grants no bypass, NN-08).
 *  - securityRelevantKeys/settingsSectionForKey delegate to the manifest
 *    so the wire contract stays the single source of truth.
 *  - createSafetySnapshot delegates to UpgradeBackupService (FS-2).
 *  - auditEvent writes to the app log — TicketCheck has no admin audit
 *    table (helpdesk_guest_audit covers the guest subsystem only).
 */

namespace OCA\Ticketcheck\ConfigTransfer;

use OCA\Ticketcheck\Exception\PermissionDeniedException;
use OCA\Ticketcheck\Service\EmailHeaderSanitizer;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\SlaPolicyService;
use OCA\Ticketcheck\Service\UpgradeBackupService;
use Psr\Log\LoggerInterface;

final class TransferHooks implements ConfigTransferHooks
{
	/** 'yes'/'no' booleans — SettingsController::normalizeYesNo storage form. */
	private const YESNO_KEYS = [
		'app_access_helpdesk_admins',
		'app_access_helpdesk_agents',
		'app_access_helpdesk_customers',
		'app_access_helpdesk_requesters',
		'app_access_requester_all_users',
		'app_access_requester_project_access',
		'kb_enabled',
		'kb_portal_enabled',
		'kb_feedback_enabled',
		'kb_comments_enabled',
		'email_notifications_enabled',
		'daily_digest_enabled',
		'weekly_digest_enabled',
		'sla_business_hours_enabled',
		'inbound_imap_process_backlog',
		'inbound_imap_require_reply_token',
		'inbound_imap_dsgvo_ack',
	];

	/** '1'/'0' booleans — the write path stores digits, not yes/no. */
	private const FLAG_KEYS = [
		'access_restriction_enabled',
		'companion_push_enabled',
	];

	/** JSON list-of-strings storage (uid/gid lists). */
	private const ID_LIST_KEYS = [
		'access_allowed_user_ids',
		'app_admin_user_ids',
		'app_access_requester_groups',
		'guest_nc_fallback',
	];

	private const CATEGORY_KEY_PATTERN = '/^[a-z0-9]([a-z0-9-]{0,30}[a-z0-9])?$/';
	private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';
	private const DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})$/';
	private const LOCALE_PATTERN = '/^[a-zA-Z]{2,3}([_-][a-zA-Z0-9]{2,8})*$/';
	private const MAX_VALUE_LENGTH = 65536;
	private const MAX_HOLIDAYS = 366;

	public function __construct(
		private readonly PermissionService $permissions,
		private readonly TransferManifest $manifest,
		private readonly UpgradeBackupService $backups,
		private readonly EmailHeaderSanitizer $headerSanitizer,
		private readonly LoggerInterface $logger,
	) {
	}

	public function requireTransferAdmin(string $uid): void
	{
		if ($uid === '' || !$this->permissions->isAppAdmin($uid)) {
			throw new PermissionDeniedException();
		}
	}

	public function validateValue(string $key, string $value): ?string
	{
		try {
			$this->normalize($key, $value);
			return null;
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage() !== '' ? $e->getMessage() : 'INVALID_VALUE';
		} catch (\JsonException) {
			return 'INVALID_JSON';
		} catch (\Throwable) {
			return 'INVALID_VALUE';
		}
	}

	public function normalizeValue(string $key, string $value): string
	{
		try {
			return $this->normalize($key, $value);
		} catch (\Throwable) {
			return $value; // validateValue already rejected bad input
		}
	}

	private function normalize(string $key, string $value): string
	{
		return match (true) {
			in_array($key, self::YESNO_KEYS, true) => $this->normalizeYesNo($value),
			in_array($key, self::FLAG_KEYS, true) => $this->normalizeFlag($value),
			in_array($key, self::ID_LIST_KEYS, true) => $this->normalizeIdList($value),
			$key === 'app_access_extra_groups' => $this->normalizeGroupCsv($value),
			$key === 'guest_provisioning' => in_array($value, ['nc_account', 'identity'], true)
				? $value
				: throw new \InvalidArgumentException('INVALID_PROVISIONING_MODE'),
			$key === 'nc_guest_portal_login' => in_array($value, ['allowed', 'refused'], true)
				? $value
				: throw new \InvalidArgumentException('INVALID_PORTAL_LOGIN_FLAG'),
			$key === 'inbound_imap_unknown_sender_policy' => strtolower(trim($value)) === 'ignore' ? 'ignore' : 'create',
			$key === 'guest_otp_ttl_min' => $this->normalizeRangedInt($value, 5, 30),
			$key === 'guest_portal_sess_days' => $this->normalizeRangedInt($value, 1, 365),
			$key === 'max_attachment_size' => $this->normalizeRangedInt($value, 1, PHP_INT_MAX),
			$key === 'sla_alert_cooldown_hours' => $this->normalizeRangedInt($value, 1, PHP_INT_MAX),
			$key === 'email_from_name' => $this->headerSanitizer->sanitizeWithDefault($value, 'Helpdesk'),
			$key === 'default_category' => preg_match(self::CATEGORY_KEY_PATTERN, $value) === 1
				? $value
				: throw new \InvalidArgumentException('INVALID_CATEGORY_KEY'),
			$key === 'default_locale' => $value === '' || preg_match(self::LOCALE_PATTERN, $value) === 1
				? $value
				: throw new \InvalidArgumentException('INVALID_LOCALE'),
			$key === 'sla_targets' => $this->normalizeSlaTargets($value),
			$key === 'sla_timezone' => $value === '' || in_array($value, \DateTimeZone::listIdentifiers(), true)
				? $value
				: throw new \InvalidArgumentException('INVALID_TIMEZONE'),
			$key === 'sla_business_days' => $this->normalizeSlaDays($value),
			$key === 'sla_business_start', $key === 'sla_business_end' => preg_match(self::TIME_PATTERN, $value) === 1
				? $value
				: throw new \InvalidArgumentException('INVALID_TIME'),
			$key === 'sla_holidays' => $this->normalizeSlaHolidays($value),
			default => strlen($value) <= self::MAX_VALUE_LENGTH
				? $value
				: throw new \InvalidArgumentException('TOO_LONG'),
		};
	}

	/**
	 * SettingsController::normalizeYesNo — 'yes','1','true','on' (ci) → 'yes',
	 * everything else → 'no'. The app's own write path coerces, never rejects.
	 */
	private function normalizeYesNo(string $value): string
	{
		return in_array(strtolower(trim($value)), ['yes', '1', 'true', 'on'], true) ? 'yes' : 'no';
	}

	/** Same truthiness rule, digit storage form. */
	private function normalizeFlag(string $value): string
	{
		return in_array(strtolower(trim($value)), ['yes', '1', 'true', 'on'], true) ? '1' : '0';
	}

	/** JSON list of non-empty strings, deduped — the uid/gid allowlist form. */
	private function normalizeIdList(string $value): string
	{
		$list = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
		if (!is_array($list) || !array_is_list($list)) {
			throw new \InvalidArgumentException('INVALID_ID_LIST');
		}
		$clean = [];
		foreach ($list as $id) {
			if ((is_string($id) || is_int($id)) && trim((string)$id) !== '') {
				$clean[] = trim((string)$id);
			}
		}
		return json_encode(array_values(array_unique($clean)), JSON_THROW_ON_ERROR);
	}

	/** CSV gid list — SettingsController::parseGroupList storage form. */
	private function normalizeGroupCsv(string $value): string
	{
		$groups = [];
		foreach (explode(',', $value) as $gid) {
			$gid = trim($gid);
			if ($gid !== '' && !in_array($gid, $groups, true)) {
				$groups[] = $gid;
			}
		}
		return implode(',', $groups);
	}

	private function normalizeRangedInt(string $value, int $min, int $max): string
	{
		if (preg_match('/^-?\d+$/', trim($value)) !== 1) {
			throw new \InvalidArgumentException('INVALID_INT');
		}
		return (string)max($min, min($max, (int)trim($value)));
	}

	/**
	 * SlaPolicyService::save storage form — canonical JSON keyed by priority,
	 * {response,resolution} hours in 1..MAX_HOURS. Unknown priorities are
	 * dropped (they cannot be addressed by the UI either); every declared
	 * priority must be present and complete, like the settings form.
	 */
	private function normalizeSlaTargets(string $value): string
	{
		$decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
		if (!is_array($decoded)) {
			throw new \InvalidArgumentException('INVALID_SLA_TARGETS');
		}
		$clean = [];
		foreach (SlaPolicyService::PRIORITIES as $priority) {
			$row = $decoded[$priority] ?? null;
			$response = is_array($row) ? $this->slaHours($row['response'] ?? null) : null;
			$resolution = is_array($row) ? $this->slaHours($row['resolution'] ?? null) : null;
			if ($response === null || $resolution === null) {
				throw new \InvalidArgumentException('INVALID_SLA_TARGETS');
			}
			if ($response > $resolution) {
				throw new \InvalidArgumentException('SLA_RESPONSE_AFTER_RESOLUTION');
			}
			$clean[$priority] = ['response' => $response, 'resolution' => $resolution];
		}
		return json_encode($clean, JSON_THROW_ON_ERROR);
	}

	private function slaHours(mixed $value): ?int
	{
		$hours = is_int($value)
			? $value
			: (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1 ? (int)trim($value) : null);
		return $hours !== null && $hours >= 1 && $hours <= SlaPolicyService::MAX_HOURS ? $hours : null;
	}

	/** CSV ISO weekdays 1–7, deduped + sorted — SlaPolicyService storage form. */
	private function normalizeSlaDays(string $value): string
	{
		$days = [];
		foreach (explode(',', $value) as $part) {
			$part = trim($part);
			if ($part !== '' && ctype_digit($part) && (int)$part >= 1 && (int)$part <= 7) {
				$days[] = (int)$part;
			}
		}
		$days = array_values(array_unique($days));
		sort($days);
		return implode(',', $days);
	}

	/**
	 * Newline-separated Y-m-d list — SlaPolicyService::save accepts
	 * whitespace/comma-separated input and stores one date per line,
	 * deduped and sorted.
	 */
	private function normalizeSlaHolidays(string $value): string
	{
		$dates = [];
		foreach (preg_split('/[\s,]+/', $value) ?: [] as $date) {
			if ($date === '') {
				continue;
			}
			if (preg_match(self::DATE_PATTERN, $date, $m) !== 1
				|| !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
				throw new \InvalidArgumentException('INVALID_HOLIDAY_DATE');
			}
			$dates[] = $date;
		}
		$dates = array_values(array_unique($dates));
		sort($dates);
		if (count($dates) > self::MAX_HOLIDAYS) {
			throw new \InvalidArgumentException('TOO_MANY_HOLIDAYS');
		}
		return implode("\n", $dates);
	}

	public function validateMirrored(array $keyValues): ?string
	{
		return $keyValues === [] ? null : 'NO_MIRRORED_KEYS';
	}

	public function writeMirrored(array $keyValues): void
	{
		if ($keyValues !== []) {
			throw new \InvalidArgumentException('NO_MIRRORED_KEYS');
		}
	}

	public function securityRelevantKeys(): array
	{
		$out = [];
		foreach ($this->manifest->keys() as $key => $spec) {
			if (($spec['security'] ?? false) === true) {
				$out[] = (string)$key;
			}
		}
		return $out;
	}

	public function createSafetySnapshot(string $reason): ?string
	{
		try {
			$result = $this->backups->createSnapshot($reason);
			return (string)($result['id'] ?? '');
		} catch (\Throwable) {
			// No data to back up yet (fresh instance) — not an error, but no
			// snapshot id either; the apply must not be silently unprotected
			// when tables DO exist, so rethrow real failures on non-empty DBs.
			if ($this->backups->hasDataToBackup()) {
				throw new \RuntimeException('TRANSFER_SNAPSHOT_FAILED');
			}
			return null;
		}
	}

	public function settingsSectionForKey(string $key): ?string
	{
		$section = ($this->manifest->keySpec($key) ?? [])['section'] ?? null;
		return is_string($section) && $section !== '' ? $section : null;
	}

	public function auditEvent(string $action, string $actor, array $details): void
	{
		try {
			$this->logger->info('ticketcheck: config transfer ' . $action, [
				'app' => 'ticketcheck',
				'actor' => $actor === '' ? 'cli' : $actor,
				'details' => $details,
			]);
		} catch (\Throwable) {
			// audit must never fail the transfer itself
		}
	}
}

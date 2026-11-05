<?php

declare(strict_types=1);

/**
 * Single read/write authority for the guest-auth configuration keys
 * (spec: passwordless-guests-2026-11 D-T05, §10.1 nc_fallback, S5 gate).
 * No other code may read these app values directly.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCP\IConfig;

class GuestAuthConfig
{
    private const APP_ID = 'ticketcheck';

    public const PROVISIONING_NC_ACCOUNT = 'nc_account';
    public const PROVISIONING_IDENTITY = 'identity';

    private const KEY_PROVISIONING = 'guest_provisioning';
    private const KEY_NC_PORTAL_LOGIN = 'nc_guest_portal_login';
    private const KEY_NC_FALLBACK = 'guest_nc_fallback';
    private const KEY_OTP_TTL = 'guest_otp_ttl_min';
    private const KEY_SESSION_DAYS = 'guest_portal_sess_days';
    private const KEY_S5_GATE = 'guest_s5_gate';
    private const KEY_DRYRUN_AT = 'guest_mig_dryrun_at';

    public function __construct(private readonly IConfig $config)
    {
    }

    /** What "add guest" creates: an NC account or a portal identity. */
    public function provisioning(): string
    {
        $v = $this->config->getAppValue(self::APP_ID, self::KEY_PROVISIONING, self::PROVISIONING_NC_ACCOUNT);
        return $v === self::PROVISIONING_IDENTITY ? self::PROVISIONING_IDENTITY : self::PROVISIONING_NC_ACCOUNT;
    }

    public function isIdentityProvisioning(): bool
    {
        return $this->provisioning() === self::PROVISIONING_IDENTITY;
    }

    /**
     * The S3 door: may an NC-session guest still use /portal/**?
     * When the S5 gate is armed the legacy path is gone entirely —
     * refused wins over any other value (fail closed).
     */
    public function ncPortalLoginAllowed(): bool
    {
        if ($this->s5GateArmed()) {
            return false;
        }
        return $this->config->getAppValue(self::APP_ID, self::KEY_NC_PORTAL_LOGIN, 'allowed') !== 'refused';
    }

    public function setProvisioning(string $mode): void
    {
        if (!in_array($mode, [self::PROVISIONING_NC_ACCOUNT, self::PROVISIONING_IDENTITY], true)) {
            throw new \InvalidArgumentException('invalid provisioning mode');
        }
        $this->config->setAppValue(self::APP_ID, self::KEY_PROVISIONING, $mode);
    }

    public function setNcPortalLoginAllowed(bool $allowed): void
    {
        $this->config->setAppValue(self::APP_ID, self::KEY_NC_PORTAL_LOGIN, $allowed ? 'allowed' : 'refused');
    }

    /** S5 gate — armed means "legacy NC-guest portal path removed". */
    public function s5GateArmed(): bool
    {
        return $this->config->getAppValue(self::APP_ID, self::KEY_S5_GATE, 'no') === 'yes';
    }

    public function setS5GateArmed(bool $armed): void
    {
        $this->config->setAppValue(self::APP_ID, self::KEY_S5_GATE, $armed ? 'yes' : 'no');
    }

    /** @return list<string> uids exempted from the S3 door (audited, per-uid) */
    public function ncFallbackUids(): array
    {
        $raw = $this->config->getAppValue(self::APP_ID, self::KEY_NC_FALLBACK, '[]');
        if (!is_string($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $uid) {
            if ((is_string($uid) || is_int($uid)) && trim((string)$uid) !== '') {
                $out[] = trim((string)$uid);
            }
        }
        return $out;
    }

    public function isNcFallback(string $uid): bool
    {
        return in_array($uid, $this->ncFallbackUids(), true);
    }

    /** @return bool true when the uid set actually changed */
    public function setNcFallback(string $uid, bool $exempt): bool
    {
        $uids = $this->ncFallbackUids();
        $has = in_array($uid, $uids, true);
        if ($exempt === $has) {
            return false;
        }
        if ($exempt) {
            $uids[] = $uid;
        } else {
            $uids = array_values(array_filter($uids, static fn (string $u): bool => $u !== $uid));
        }
        $this->config->setAppValue(self::APP_ID, self::KEY_NC_FALLBACK, json_encode(array_values($uids)) ?: '[]');
        return true;
    }

    /** OTP TTL in minutes, admin-tunable 5–30 (spec MH-T01). */
    public function otpTtlMinutes(): int
    {
        $v = (int)$this->config->getAppValue(self::APP_ID, self::KEY_OTP_TTL, '10');
        return max(5, min(30, $v));
    }

    /** Sliding session window in days (absolute cap lives in PortalSessionService). */
    public function sessionDays(): int
    {
        $v = (int)$this->config->getAppValue(self::APP_ID, self::KEY_SESSION_DAYS, '30');
        return max(1, min(365, $v));
    }

    /** Wizard gate: timestamp of the last dry-run report (A-T05 mitigation). */
    public function lastDryRunAt(): ?\DateTime
    {
        $raw = $this->config->getAppValue(self::APP_ID, self::KEY_DRYRUN_AT, '');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return new \DateTime($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    public function markDryRunNow(): void
    {
        $this->config->setAppValue(self::APP_ID, self::KEY_DRYRUN_AT, (new \DateTime())->format('Y-m-d H:i:s'));
    }
}

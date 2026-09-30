<?php

declare(strict_types=1);

/**
 * Centralized email preferences for helpdesk users.
 * Security-critical: Users can ONLY control their own preferences.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCP\IConfig;

/**
 * Manages per-user email notification preferences.
 * All preference checks respect: 1) global app switch, 2) user's individual opt-in/out.
 */
class EmailPreferencesService
{
    private const APP_ID = 'ticketcheck';

    /** Preference key constants - used for IConfig getUserValue/setUserValue */
    public const PREF_DAILY_DIGEST = 'receive_daily_digest';
    public const PREF_WEEKLY_DIGEST = 'receive_weekly_digest';
    public const PREF_TICKET_ASSIGNMENT = 'receive_ticket_assignment';
    public const PREF_NEW_TICKET_IN_PROJECT = 'receive_new_ticket_in_project';
    public const PREF_CUSTOMER_REPLY = 'receive_customer_reply';
    public const PREF_PROJECT_MEMBER_ADDED = 'receive_project_member_added';

    /** Guest-only preference keys (for customers receiving ticket notifications) */
    public const PREF_GUEST_TICKET_CREATED = 'receive_guest_ticket_created';
    public const PREF_GUEST_TICKET_UPDATED = 'receive_guest_ticket_updated';
    public const PREF_GUEST_TICKET_STATUS_CHANGED = 'receive_guest_ticket_status_changed';
    public const PREF_GUEST_TICKET_COMMENT = 'receive_guest_ticket_comment';

    /** All valid preference keys for validation (internal users) */
    private const VALID_KEYS = [
        self::PREF_DAILY_DIGEST,
        self::PREF_WEEKLY_DIGEST,
        self::PREF_TICKET_ASSIGNMENT,
        self::PREF_NEW_TICKET_IN_PROJECT,
        self::PREF_CUSTOMER_REPLY,
        self::PREF_PROJECT_MEMBER_ADDED,
    ];

    /** Guest preference keys */
    private const GUEST_VALID_KEYS = [
        self::PREF_GUEST_TICKET_CREATED,
        self::PREF_GUEST_TICKET_UPDATED,
        self::PREF_GUEST_TICKET_STATUS_CHANGED,
        self::PREF_GUEST_TICKET_COMMENT,
    ];

    /** All keys (for combined forms) - built at runtime to avoid PHP const limitations */
    private static function getAllValidKeys(): array
    {
        return array_merge(self::VALID_KEYS, self::GUEST_VALID_KEYS);
    }

    private const DEFAULT_VALUE = 'yes';

    public function __construct(
        private IConfig $config
    ) {
    }

    /**
     * Check if a user wants to receive a specific type of email.
     * Always checks: 1) global email_notifications_enabled, 2) user preference.
     *
     * @param string|null $userId User ID (null = skip user pref)
     * @param string $preferenceKey One of self::PREF_* constants
     */
    public function userWantsEmail(?string $userId, string $preferenceKey): bool
    {
        $allKeys = self::getAllValidKeys();
        if (!in_array($preferenceKey, $allKeys, true)) {
            return false;
        }

        // Global switch: if email is disabled app-wide, nobody gets emails
        $globalEnabled = $this->config->getAppValue(self::APP_ID, 'email_notifications_enabled', 'yes') === 'yes';
        if (!$globalEnabled) {
            return false;
        }

        // Digest-specific global switches (admin can disable digests for everyone)
        if ($preferenceKey === self::PREF_DAILY_DIGEST) {
            $digestEnabled = $this->config->getAppValue(self::APP_ID, 'daily_digest_enabled', 'yes') === 'yes';
            if (!$digestEnabled) {
                return false;
            }
        }
        if ($preferenceKey === self::PREF_WEEKLY_DIGEST) {
            $weeklyEnabled = $this->config->getAppValue(self::APP_ID, 'weekly_digest_enabled', 'yes') === 'yes';
            if (!$weeklyEnabled) {
                return false;
            }
        }

        // No user ID
        if ($userId === null || $userId === '') {
            return false;
        }

        $value = $this->config->getUserValue($userId, self::APP_ID, $preferenceKey, self::DEFAULT_VALUE);
        return $value === 'yes';
    }

    /**
     * Get all email preferences for a user.
     * Uses legacy 'receive_digest' if 'receive_daily_digest' not yet set.
     *
     * @param string $userId User ID
     * @param bool $includeGuestPrefs Include guest-only preferences (for guest users)
     * @return array<string, string> Map of preference key => 'yes'|'no'
     */
    public function getUserPreferences(string $userId, bool $includeGuestPrefs = false): array
    {
        $keys = $includeGuestPrefs ? self::getAllValidKeys() : self::VALID_KEYS;
        $prefs = [];
        foreach ($keys as $key) {
            $prefs[$key] = $this->config->getUserValue($userId, self::APP_ID, $key, self::DEFAULT_VALUE);
        }
        // Backward compat: legacy receive_digest overrides receive_daily_digest when new key not set
        $newValue = $this->config->getUserValue($userId, self::APP_ID, self::PREF_DAILY_DIGEST, '');
        if ($newValue === '') {
            $legacy = $this->config->getUserValue($userId, self::APP_ID, 'receive_digest', '');
            if ($legacy !== '') {
                $prefs[self::PREF_DAILY_DIGEST] = $legacy;
            }
        }
        return $prefs;
    }

    /**
     * Set a single preference for a user.
     * SECURITY: Caller MUST ensure $userId is the current user (never from request).
     *
     * @param string $userId Must be the authenticated user's ID
     * @param string $key One of self::PREF_* constants
     * @param string $value 'yes' or 'no'
     */
    public function setUserPreference(string $userId, string $key, string $value): void
    {
        if (!in_array($key, self::getAllValidKeys(), true)) {
            throw new \InvalidArgumentException('Invalid preference key: ' . $key);
        }
        $normalized = ($value === 'yes' || $value === '1' || $value === true) ? 'yes' : 'no';
        $this->config->setUserValue($userId, self::APP_ID, $key, $normalized);
    }

    /**
     * Set multiple preferences at once.
     * SECURITY: Caller MUST ensure $userId is the current user.
     *
     * @param string $userId Must be the authenticated user's ID
     * @param array<string, string> $preferences Map of key => 'yes'|'no'
     */
    public function setUserPreferences(string $userId, array $preferences): void
    {
        foreach ($preferences as $key => $value) {
            if (in_array($key, self::getAllValidKeys(), true)) {
                $this->setUserPreference($userId, $key, (string) $value);
            }
        }
    }

    /**
     * Returns all valid preference keys for UI/API.
     *
     * @param bool $includeGuest Include guest preference keys
     * @return list<string>
     */
    public static function getValidKeys(bool $includeGuest = false): array
    {
        return array_values($includeGuest ? self::getAllValidKeys() : self::VALID_KEYS);
    }

    /**
     * Returns guest-only preference keys.
     *
     * @return list<string>
     */
    public static function getGuestValidKeys(): array
    {
        return self::GUEST_VALID_KEYS;
    }

    /**
     * Legacy key mapping: NotificationJob uses 'receive_digest', we use 'receive_daily_digest'.
     * This ensures backward compatibility with existing user values.
     */
    public function userWantsDailyDigest(string $userId): bool
    {
        // Support legacy key for existing installations
        $legacy = $this->config->getUserValue($userId, self::APP_ID, 'receive_digest', '');
        if ($legacy !== '') {
            return $legacy === 'yes';
        }
        return $this->userWantsEmail($userId, self::PREF_DAILY_DIGEST);
    }
}

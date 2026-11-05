<?php

declare(strict_types=1);

/**
 * Staged guest migration S1–S6 (spec §9.1, US-T04).
 * Per-guest transaction: identity → alias → access rewrite → language/prefs
 * port → miglog. One failure rolls back THAT guest only. Re-runs are
 * idempotent. IUser::delete is never called here (NN-T02/NN-T09).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\GuestAlias;
use OCA\Ticketcheck\Db\GuestAliasMapper;
use OCA\Ticketcheck\Db\GuestMiglogMapper;
use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class GuestMigrationService
{
    public const BUCKETS = [
        'migratable', 'blocked_no_email', 'blocked_duplicate_email',
        'skipped_staff', 'no_access', 'already_migrated', 'nc_fallback', 'error',
    ];

    public function __construct(
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly IConfig $config,
        private readonly IDBConnection $db,
        private readonly GuestIdentityService $identityService,
        private readonly GuestAliasMapper $aliases,
        private readonly GuestMiglogMapper $miglog,
        private readonly GuestProjectAccessMapper $access,
        private readonly GuestAuthConfig $authConfig,
        private readonly ILockingProvider $locking,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<string> uids of helpdesk_customers members */
    public function guestUids(): array
    {
        $group = $this->groupManager->get(PortalActorResolver::GROUP_GUESTS);
        if ($group === null) {
            return [];
        }
        $uids = [];
        foreach ($group->getUsers() as $user) {
            $uids[] = $user->getUID();
        }
        sort($uids, SORT_STRING);
        return $uids;
    }

    /** Staff = agents/admins groups, NC admin, or a guest whose e-mail belongs to a staff NC user (E-T01). */
    private function isStaffGuest(string $uid, ?string $email): bool
    {
        if ($this->groupManager->isInGroup($uid, 'helpdesk_agents')
            || $this->groupManager->isInGroup($uid, 'helpdesk_admins')
            || $this->groupManager->isAdmin($uid)) {
            return true;
        }
        if ($email === null || $email === '') {
            return false;
        }
        foreach (['helpdesk_agents', 'helpdesk_admins'] as $gid) {
            $g = $this->groupManager->get($gid);
            if ($g === null) {
                continue;
            }
            foreach ($g->getUsers() as $staffUser) {
                if (strcasecmp((string)$staffUser->getEMailAddress(), $email) === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Classify one uid into a dry-run bucket. */
    public function classify(string $uid, array $emailCounts, array $aliasedUids, array $fallbackUids): string
    {
        $user = $this->userManager->get($uid);
        if ($user === null) {
            return 'no_access'; // stale group row — nothing live to preserve
        }
        if (in_array($uid, $aliasedUids, true)) {
            return 'already_migrated';
        }
        if (in_array($uid, $fallbackUids, true)) {
            return 'nc_fallback';
        }
        $email = strtolower(trim((string)$user->getEMailAddress()));
        if ($email === '') {
            return 'blocked_no_email';
        }
        if ($this->isStaffGuest($uid, $email)) {
            return 'skipped_staff';
        }
        if (($emailCounts[$email] ?? 0) > 1) {
            return 'blocked_duplicate_email';
        }
        if ($this->access->countProjectsForUser($uid) === 0
            && $this->access->countProjectsForUser(PortalActorResolver::PREFIX . '0') === 0
            && !$this->hasAnyAccessRow($uid)) {
            return 'no_access';
        }
        return 'migratable';
    }

    private function hasAnyAccessRow(string $uid): bool
    {
        return $this->access->countProjectsForUser($uid) > 0;
    }

    /**
     * Dry-run report (US-T04 AC-1): counts + per-bucket uid lists.
     * Read-only — never writes.
     *
     * @return array{buckets: array<string,int>, lists: array<string,list<string>>, generated_at: string}
     */
    public function dryRun(): array
    {
        $uids = $this->guestUids();
        $aliased = $this->aliases->aliasedUids();
        $fallback = $this->authConfig->ncFallbackUids();
        $emailCounts = [];
        foreach ($uids as $uid) {
            $user = $this->userManager->get($uid);
            $email = strtolower(trim((string)($user?->getEMailAddress() ?? '')));
            if ($email !== '') {
                $emailCounts[$email] = ($emailCounts[$email] ?? 0) + 1;
            }
        }
        $buckets = array_fill_keys(self::BUCKETS, 0);
        $lists = array_fill_keys(self::BUCKETS, []);
        foreach ($uids as $uid) {
            $bucket = $this->classify($uid, $emailCounts, $aliased, $fallback);
            $buckets[$bucket]++;
            $lists[$bucket][] = $uid;
        }
        return [
            'buckets' => $buckets,
            'lists' => $lists,
            'generated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Migrate one guest inside a per-uid advisory lock + one DB transaction.
     * @return array{bucket:string, identity_id?:int, error?:string}
     */
    public function migrateOne(string $uid, string $actor, ?int $forcedIdentityId = null): array
    {
        try {
            $this->locking->acquireLock('ticketcheck_guest_access_' . $uid, ILockingProvider::LOCK_EXCLUSIVE, 'guest migration ' . $uid);
        } catch (LockedException) {
            return ['bucket' => 'error', 'error' => 'locked'];
        }
        try {
            return $this->doMigrateOne($uid, $actor, $forcedIdentityId);
        } finally {
            $this->locking->releaseLock('ticketcheck_guest_access_' . $uid, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    private function doMigrateOne(string $uid, string $actor, ?int $forcedIdentityId): array
    {
        $user = $this->userManager->get($uid);
        if ($user === null) {
            return ['bucket' => 'no_access'];
        }
        if ($this->aliases->findByUid($uid) !== null) {
            return ['bucket' => 'already_migrated'];
        }
        $email = strtolower(trim((string)$user->getEMailAddress()));
        if ($forcedIdentityId === null && $email === '') {
            return ['bucket' => 'blocked_no_email'];
        }
        if ($forcedIdentityId === null && $this->isStaffGuest($uid, $email)) {
            return ['bucket' => 'skipped_staff'];
        }
        if ($forcedIdentityId === null && !$this->hasAnyAccessRow($uid)) {
            return ['bucket' => 'no_access'];
        }

        $this->db->beginTransaction();
        try {
            if ($forcedIdentityId !== null) {
                $identity = $this->identityService->requireIdentity($forcedIdentityId);
            } else {
                $lang = $this->resolveGuestLanguage($uid);
                $identity = $this->identityService->findOrCreate(
                    $email,
                    $user->getDisplayName() !== '' ? $user->getDisplayName() : $email,
                    'migration',
                    $lang !== '' ? $lang : null,
                );
            }
            $identityId = (int)$identity->getId();

            $alias = new GuestAlias();
            $alias->setLegacyUid($uid);
            $alias->setIdentityId($identityId);
            $alias->setMigratedAt(new \DateTime());
            $alias->setMigratedBy($actor);
            $this->aliases->insert($alias);

            // D-T04: access rows are live state — rewritten in place.
            $qb = $this->db->getQueryBuilder();
            $qb->update('helpdesk_guest_access')
                ->set('user_id', $qb->createNamedParameter(PortalActorResolver::actorForIdentity($identityId)))
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
            $rewritten = $qb->executeStatement();

            $this->portGuestPrefs($uid, $identityId);
            $this->portGuestLanguage($uid, $identity);
            $this->miglog->log($uid, $identityId, 's2_migrate', [
                'rewritten_access' => $rewritten,
                'forced' => $forcedIdentityId !== null,
            ], $actor);

            $this->db->commit();
            return ['bucket' => 'migratable', 'identity_id' => $identityId];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('guest migration failed for ' . $uid, ['exception' => $e, 'app' => 'ticketcheck']);
            try {
                $this->miglog->log($uid, null, 's2_migrate', ['error' => $e->getMessage()], $actor);
            } catch (\Throwable) {
            }
            return ['bucket' => 'error', 'error' => $e->getMessage()];
        }
    }

    /** Copy the 4 guest pref uservalues → helpdesk_guest_prefs (same vocabulary). */
    private function portGuestPrefs(string $uid, int $identityId): void
    {
        foreach (EmailPreferencesService::getGuestValidKeys() as $key) {
            $value = $this->config->getUserValue($uid, 'ticketcheck', $key, '');
            if ($value === 'yes' || $value === 'no') {
                $qb = $this->db->getQueryBuilder();
                $qb->update('helpdesk_guest_prefs')
                    ->set('pref_value', $qb->createNamedParameter($value))
                    ->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
                    ->andWhere($qb->expr()->eq('pref_key', $qb->createNamedParameter($key)));
                if ($qb->executeStatement() === 0) {
                    $ins = $this->db->getQueryBuilder();
                    $ins->insert('helpdesk_guest_prefs')
                        ->setValue('identity_id', $ins->createNamedParameter($identityId))
                        ->setValue('pref_key', $ins->createNamedParameter($key))
                        ->setValue('pref_value', $ins->createNamedParameter($value));
                    $ins->executeStatement();
                }
            }
        }
    }

    private function portGuestLanguage(string $uid, $identity): void
    {
        if (trim((string)($identity->getLocale() ?? '')) !== '') {
            return;
        }
        $lang = $this->resolveGuestLanguage($uid);
        if ($lang === '') {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->update('helpdesk_guest_identity')
            ->set('locale', $qb->createNamedParameter($lang))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$identity->getId())));
        $qb->executeStatement();
    }

    private function resolveGuestLanguage(string $uid): string
    {
        $lang = trim((string)$this->config->getUserValue($uid, 'core', 'lang', ''));
        if ($lang === '') {
            $lang = trim((string)$this->config->getUserValue($uid, 'ticketcheck', 'guest_language', ''));
        }
        return $lang;
    }

    /** S2 rollback: access rows → uid, alias dropped; miglog keeps both events. */
    public function rollbackOne(string $uid, string $actor): array
    {
        $alias = $this->aliases->findByUid($uid);
        if ($alias === null) {
            return ['rolled_back' => false, 'reason' => 'no_alias'];
        }
        $identityId = (int)$alias->getIdentityId();
        $this->db->beginTransaction();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update('helpdesk_guest_access')
                ->set('user_id', $qb->createNamedParameter($uid))
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter(PortalActorResolver::actorForIdentity($identityId))));
            $restored = $qb->executeStatement();
            $this->aliases->deleteByUid($uid);
            $this->miglog->log($uid, $identityId, 's2_rollback', ['restored_access' => $restored], $actor);
            $this->db->commit();
            return ['rolled_back' => true, 'identity_id' => $identityId];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Run over a uid list (or all guests) → per-bucket counts. */
    public function run(?array $uids, string $actor): array
    {
        $uids = $uids ?? $this->guestUids();
        $aliased = $this->aliases->aliasedUids();
        $fallback = $this->authConfig->ncFallbackUids();
        $emailCounts = [];
        foreach ($uids as $uid) {
            $user = $this->userManager->get($uid);
            $email = strtolower(trim((string)($user?->getEMailAddress() ?? '')));
            if ($email !== '') {
                $emailCounts[$email] = ($emailCounts[$email] ?? 0) + 1;
            }
        }
        $results = array_fill_keys(self::BUCKETS, 0);
        foreach ($uids as $uid) {
            $bucket = $this->classify($uid, $emailCounts, $aliased, $fallback);
            if ($bucket !== 'migratable') {
                $results[$bucket]++;
                continue;
            }
            $r = $this->migrateOne($uid, $actor);
            $results[$r['bucket'] === 'migratable' ? 'migratable' : $r['bucket']]++;
            $aliased[] = $uid;
        }
        return $results;
    }

    /** S4: disable the migrated NC accounts (never delete — NN-T02). */
    public function disableMigratedAccounts(string $actor): int
    {
        $disabled = 0;
        foreach ($this->aliases->findAll() as $alias) {
            $user = $this->userManager->get((string)$alias->getLegacyUid());
            if ($user === null || !$user->isEnabled()) {
                continue;
            }
            $user->setEnabled(false);
            $this->miglog->log((string)$alias->getLegacyUid(), (int)$alias->getIdentityId(), 's4_disable', [], $actor);
            $disabled++;
        }
        return $disabled;
    }

    /** Mark one uid as migration-abandoned (S5 resolution, auditable). */
    public function markAbandoned(string $uid, string $actor, string $reason = ''): void
    {
        $this->miglog->log($uid, null, 'abandoned', ['reason' => $reason], $actor);
    }

    /** Uids still needing a decision before the S5 gate can pass. */
    public function unmigratedBlockingUids(): array
    {
        $aliased = $this->aliases->aliasedUids();
        $abandoned = $this->miglog->uidsForStage('abandoned');
        $skip = array_merge($aliased, $abandoned);
        $out = [];
        foreach ($this->guestUids() as $uid) {
            if (!in_array($uid, $skip, true)) {
                $out[] = $uid;
            }
        }
        return $out;
    }
}

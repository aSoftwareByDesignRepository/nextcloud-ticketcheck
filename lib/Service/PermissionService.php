<?php

declare(strict_types=1);

/**
 * Permission service for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\DbQueryGuard;
use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Service for managing user permissions and access control
 */
class PermissionService
{
    public const GROUP_HELPDESK_CUSTOMERS = 'helpdesk_customers';

    /** Table for project membership (must match migrations) */
    private const TABLE_PROJECT_MEMBERS = 'hd_proj_members';
    public const GROUP_HELPDESK_AGENTS = 'helpdesk_agents';
    public const GROUP_HELPDESK_ADMINS = 'helpdesk_admins';
    public const GROUP_HELPDESK_REQUESTERS = 'helpdesk_requesters';
    private const APP_ACCESS_ADMINS_KEY = 'app_access_helpdesk_admins';
    private const APP_ACCESS_AGENTS_KEY = 'app_access_helpdesk_agents';
    private const APP_ACCESS_CUSTOMERS_KEY = 'app_access_helpdesk_customers';
    private const APP_ACCESS_REQUESTERS_KEY = 'app_access_helpdesk_requesters';
    private const APP_ACCESS_REQUESTER_GROUPS_KEY = 'app_access_requester_groups';
    /** NH-03: every logged-in NC user is requester-scope (opt-in, default off). */
    private const APP_ACCESS_REQUESTER_ALL_USERS_KEY = 'app_access_requester_all_users';
    /** NH-05/Q-R2: requester-scope uids may hold helpdesk_guest_access rows (opt-in, default off). */
    private const APP_ACCESS_REQUESTER_PROJECT_ACCESS_KEY = 'app_access_requester_project_access';
    private const APP_ACCESS_EXTRA_GROUPS_KEY = 'app_access_extra_groups';
    /** Max groups an admin may grant requester scope (picker-enforced). */
    public const REQUESTER_GROUPS_LIMIT = 50;
    private const ACCESS_RESTRICTION_KEY = 'access_restriction_enabled';
    private const ACCESS_ALLOWED_USERS_KEY = 'access_allowed_user_ids';
    private const APP_ADMIN_USERS_KEY = 'app_admin_user_ids';

    /**
     * Non-uid ticket provenance markers (ticket.created_by) that count as
     * "system" for the restricted requester e-mail leg (D-R15). A live
     * non-staff uid is never in this list — that is the whole point of the
     * restriction (peer-authored tickets must not match via a shared e-mail).
     */
    private const SYSTEM_TICKET_CREATORS = ['inbound-email'];

    private IUserSession $userSession;
    private IGroupManager $groupManager;
    private IConfig $config;
    private GuestProjectAccessMapper $guestProjectAccessMapper;
    private IDBConnection $db;
    private ?PortalActorResolver $actorResolver;
    private ?\Psr\Log\LoggerInterface $logger;
    private ?\OCP\IUserManager $userManager;

    /** @var array<string, bool> Per-request group-membership cache (key: "userId:groupId") */
    private array $groupCache = [];
    /** @var array<string, bool> Per-request NC-admin cache (key: userId) */
    private array $adminCache = [];
    /** @var array<string, bool> Per-request project-membership cache (key: userId) */
    private array $projectMemberCache = [];

    public function __construct(
        IUserSession $userSession,
        IGroupManager $groupManager,
        IConfig $config,
        GuestProjectAccessMapper $guestProjectAccessMapper,
        IDBConnection $db,
        ?PortalActorResolver $actorResolver = null,
        ?\Psr\Log\LoggerInterface $logger = null,
        ?\OCP\IUserManager $userManager = null
    ) {
        $this->actorResolver = $actorResolver;
        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
        $this->config = $config;
        $this->guestProjectAccessMapper = $guestProjectAccessMapper;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Requester-side project access toggle evaluation (NH-05). Separate from
     * requesterProjectAccessForUid() so settings parsing stays in one place.
     * @param array<string, mixed> $settings
     */
    private function requesterProjectAccessEnabled(array $settings): bool
    {
        return !empty($settings['requester_project_access']);
    }

    // ── Per-request cache helpers ────────────────────────────────────────────

    private function isInGroupCached(string $userId, string $groupId): bool
    {
        $key = $userId . ':' . $groupId;
        if (!array_key_exists($key, $this->groupCache)) {
            $this->groupCache[$key] = (bool)$this->groupManager->isInGroup($userId, $groupId);
        }
        return $this->groupCache[$key];
    }

    /**
     * Fail-closed variant for requester-scope grants (D-R18): a group-backend
     * exception (LDAP/AD hiccup) yields no-scope + WARN, never a 500 and never
     * a grant. Containment checks keep using isInGroupCached() unchanged —
     * a backend hiccup there must not lock admins out (fail-open posture),
     * while grant checks must always fail closed.
     */
    private function isInGroupFailClosed(string $userId, string $groupId): bool
    {
        $key = $userId . ':' . $groupId;
        if (!array_key_exists($key, $this->groupCache)) {
            try {
                $this->groupCache[$key] = (bool)$this->groupManager->isInGroup($userId, $groupId);
            } catch (\Throwable $e) {
                $this->groupCache[$key] = false;
                $this->logger?->warning('[requester-scope] group backend check failed; denying scope grant', [
                    'user_id' => $userId,
                    'group_id' => $groupId,
                    'exception' => $e,
                ]);
            }
        }
        return $this->groupCache[$key];
    }

    private function isNcAdminCached(string $userId): bool
    {
        if (!array_key_exists($userId, $this->adminCache)) {
            $this->adminCache[$userId] = (bool)$this->groupManager->isAdmin($userId);
        }
        return $this->adminCache[$userId];
    }

    private function hasProjectMembershipCached(string $userId): bool
    {
        if (!array_key_exists($userId, $this->projectMemberCache)) {
            $this->projectMemberCache[$userId] = $this->hasProjectMembership($userId);
        }
        return $this->projectMemberCache[$userId];
    }

    /**
     * Get the current user ID
     *
     * @return string|null
     */
    public function getCurrentUserId(): ?string
    {
        // Canonical actor string: 'portal:<id>' for identity sessions and for
        // migrated NC guests (alias canonicalization, spec §9.2).
        if ($this->actorResolver !== null) {
            $actor = $this->actorResolver->currentActor();
            if ($actor !== null) {
                return $actor;
            }
        }
        $user = $this->userSession->getUser();
        return $user ? $user->getUID() : null;
    }

    /**
     * Check if current user is a guest (customer)
     *
     * @return bool
     */
    public function isGuest(): bool
    {
        // Identity sessions are guests even though NC sees no logged-in user.
        // 'nc_guest' counts too: a migrated uid's actor is canonicalized to
        // 'portal:<id>' (§9.2), which can never pass the NC group check below —
        // without this the portal silently treats migrated NC guests as staff.
        if ($this->actorResolver !== null && $this->actorResolver->isGuest()) {
            return true;
        }
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            return false;
        }
        return $this->isInGroupCached($userId, self::GROUP_HELPDESK_CUSTOMERS);
    }

    /**
     * Whether the uid holds requester-scope group membership under the
     * requester master toggle. Low-level predicate — used by isRequester(),
     * hasEnabledHelpdeskScope(), the directory door, and the dual-role
     * "My requests" nav (a staff member can be in a requester group too).
     *
     * Fail-closed (spec D-R18): a group-backend/LDAP exception yields
     * false, never a 500 and never a grant.
     */
    public function isRequesterGroupMember(string $userId): bool
    {
        if ($userId === '' || PortalActorResolver::isIdentityActor($userId)) {
            return false;
        }
        return $this->isRequesterGroupMemberWithSettings($userId, $this->getAppAccessSettings());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function isRequesterGroupMemberWithSettings(string $userId, array $settings): bool
    {
        if (empty($settings['allow_helpdesk_requesters'])) {
            return false;
        }
        // NH-03 opt-in: the deployment wants every NC user to be a requester.
        if (!empty($settings['requester_all_users'])) {
            return true;
        }
        if ($this->isInGroupFailClosed($userId, self::GROUP_HELPDESK_REQUESTERS)) {
            return true;
        }
        foreach ($settings['requester_groups'] ?? [] as $groupId) {
            if ($this->isInGroupFailClosed($userId, (string) $groupId)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the current user resolves to requester scope:
     * NC user, not guest-contained, no staff scope, requester-group member.
     * Precedence (spec D-R04): guest containment > staff scope > requester.
     */
    public function isRequester(): bool
    {
        try {
            $userId = $this->getCurrentUserId();
            if ($userId === null || $userId === '') {
                return false;
            }
            // Guest containment wins — a helpdesk_customers member stays a
            // contained guest even when also in a requester group.
            if ($this->isGuest()) {
                return false;
            }
            // Staff scope wins — dual-role users keep the staff product.
            if ($this->hasStaffScope($userId)) {
                return false;
            }
            return $this->isRequesterGroupMember($userId);
        } catch (\Throwable $e) {
            // E-17/D-R18: any evaluation failure is no-scope, never a 500
            // and never a grant.
            $this->logWarn('requester scope evaluation failed (fail-closed)', $e);
            return false;
        }
    }

    /**
     * Whether the current actor runs on an NC-managed session inside the
     * portal: any logged-in NC user that is not guest-contained. Covers
     * internal requesters AND dual-role staff (who enter the portal create
     * path per D-R22) — both must get the requester nav variant, because
     * guest-only items (portal logout, change-password, account deletion)
     * are meaningless or destructive on an NC session (D-R07/NN-07).
     * Contained NC guests keep the full guest variant: their app-managed
     * accounts legitimately use password/logout.
     */
    public function isNcManagedPortalUser(): bool
    {
        try {
            $userId = $this->getCurrentUserId();
            if ($userId === null || $userId === ''
                || PortalActorResolver::isIdentityActor($userId)) {
                return false;
            }
            return !$this->isGuest();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Support handle (spec §7): one structured WARN line when a requester
     * hits a denied surface. No ticket content, uid + path only — same
     * parity as the existing [app-access]/[guest-access] lines.
     */
    public function logRequesterScopeDenial(string $surface): void
    {
        if (!$this->isRequester()) {
            return;
        }
        try {
            $logger = $this->logger ?? \OCP\Server::get(\Psr\Log\LoggerInterface::class);
            $logger->warning('[requester-scope] denied surface reached', [
                'app' => 'ticketcheck',
                'uid' => $this->getCurrentUserId(),
                'surface' => $surface,
            ]);
        } catch (\Throwable) {
            // Logging must never break the denial path itself.
        }
    }

    /**
     * Staff scope for precedence checks: helpdesk admin/agent groups, the
     * app-admin uid list, NC admin, or any project membership.
     * Deliberately NOT hasEnabledHelpdeskScope() — that includes the
     * requester branch itself (and extra_groups is not a staff power).
     */
    private function hasStaffScope(string $userId, bool $failToStaff = true): bool
    {
        try {
            if ($this->isNcAdminCached($userId)
                || $this->isInGroupCached($userId, self::GROUP_HELPDESK_ADMINS)
                || $this->isInGroupCached($userId, self::GROUP_HELPDESK_AGENTS)
                || in_array($userId, $this->getAppAdminUserIds(), true)) {
                return true;
            }
            return $this->hasProjectMembershipCached($userId);
        } catch (\Throwable $e) {
            // Posture is call-site dependent: precedence checks fail TOWARD
            // staff (a requester mis-seen as staff still hits per-controller
            // denial; a staff user mis-seen as requester loses their product).
            // The D-R15 leak guard fails toward NON-staff (a hiccup must not
            // reopen the shared-e-mail leak).
            $this->logWarn('staff scope evaluation failed', $e);
            return $failToStaff;
        }
    }

    private function logWarn(string $message, \Throwable $e): void
    {
        try {
            $logger = $this->logger ?? \OCP\Server::get(\Psr\Log\LoggerInterface::class);
            $logger->warning('[app-access] ' . $message . ': ' . $e->getMessage(), ['app' => 'ticketcheck']);
        } catch (\Throwable) {
            // Logging must never break permission evaluation.
        }
    }

    /**
     * Check if current user is an agent
     *
     * @return bool
     */
    public function isAgent(): bool
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            return false;
        }
        return $this->isInGroupCached($userId, self::GROUP_HELPDESK_AGENTS);
    }

    /**
     * Whether this user may see the in-app helpdesk dashboard and the Nextcloud dashboard desklet.
     * Matches the gate used for the agent ticket list (not project-only staff without an agent role).
     * Dual-role guest+agent: guest membership wins — portal only, no agent overview.
     */
    public function canViewHelpdeskOverview(string $userId): bool
    {
        if ($userId === '') {
            return false;
        }
        if ($this->isInGroupCached($userId, self::GROUP_HELPDESK_CUSTOMERS)) {
            return false;
        }
        if (!$this->canUserAccessAppByUid($userId)) {
            return false;
        }
        return $this->isInGroupCached($userId, self::GROUP_HELPDESK_AGENTS)
            || $this->isInGroupCached($userId, self::GROUP_HELPDESK_ADMINS)
            || $this->isNcAdminCached($userId);
    }

    /**
     * Check if current user is an admin
     *
     * @return bool
     */
    public function isHelpdeskAdmin(): bool
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            return false;
        }
        return $this->isAppAdmin($userId);
    }

    /**
     * Dedicated App Admin (BudgetCheck OR-semantics): NC system admin OR listed UID
     * OR classic helpdesk_admins group membership.
     */
    public function isAppAdmin(?string $userId = null): bool
    {
        $userId = $userId ?? $this->getCurrentUserId();
        if ($userId === null || $userId === '') {
            return false;
        }
        if ($this->isNcAdminCached($userId)) {
            return true;
        }
        if ($this->isInGroupCached($userId, self::GROUP_HELPDESK_ADMINS)) {
            return true;
        }
        return in_array($userId, $this->getAppAdminUserIds(), true);
    }

    /**
     * Alias for isHelpdeskAdmin() - for backward compatibility
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        return $this->isHelpdeskAdmin();
    }

    /**
     * Check if user can view a ticket
     *
     * @param mixed $ticket
     * @return bool
     */
    public function canViewTicket($ticket): bool
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            return false;
        }

        // Guests always use guest scoping — even if also in agent/admin groups
        // (misconfigured dual membership). Path isolation treats them as guests;
        // ticket APIs must not suddenly grant "view all".
        if ($this->isGuest()) {
            if ($ticket->getCreatedBy() === $userId) {
                return true;
            }
            $projectId = $ticket->getProjectId();
            if ($projectId !== null) {
                return $this->canAccessProject((int)$projectId)
                    && $this->emailsMatch(
                        $this->getCurrentUserEmail(),
                        (string) $ticket->getCustomerEmail()
                    );
            }
            // No project: creator owns it, and the addressed customer sees
            // tickets emailed to them — same scope as the portal's
            // findByCustomerEmail ticket list.
            return $this->emailsMatch(
                $this->getCurrentUserEmail(),
                (string) $ticket->getCustomerEmail()
            );
        }

        // Deliberately NO requester branch here (spec US-R05 AC-1/NN-02):
        // canViewTicket feeds the STAFF routes. A requester hitting a staff
        // ticket URL gets the same denial as today — their read path exists
        // only on portal routes via canPortalViewTicket().

        // Admins and agents can view all tickets
        if ($this->isHelpdeskAdmin() || $this->isAgent()) {
            return true;
        }

        // Project members (non-agent staff) can view tickets in their projects.
        $projectId = $ticket->getProjectId();
        if ($projectId !== null && $this->isProjectMember((int)$projectId, $userId)) {
            return true;
        }

        return false;
    }

    /**
     * Single authority for requester ticket visibility (spec D-R05 + D-R15):
     * created_by match, or — with the restricted e-mail leg — a
     * customer_email match (project tickets additionally require project
     * access). Reused by canViewTicket and the "My requests" staff surface
     * so both evaluate identical scoping.
     *
     * @param mixed $ticket
     * @param string|null $userEmail The candidate's own e-mail — pass it when
     *        resolving visibility for an actor other than the session user
     *        (e.g. notification recipients); null falls back to the session
     *        user's e-mail for in-request checks.
     */
    public function isRequesterScopedTicket($ticket, string $userId, ?string $userEmail = null): bool
    {
        if ($userId === '') {
            return false;
        }
        if ($ticket->getCreatedBy() === $userId) {
            return true;
        }
        if (!$this->requesterEmailMatchAllowed($ticket, $userId)) {
            return false;
        }
        $emailMatch = $this->emailsMatch(
            $userEmail ?? $this->getCurrentUserEmail(),
            (string) $ticket->getCustomerEmail()
        );
        if (!$emailMatch) {
            return false;
        }
        $projectId = $ticket->getProjectId();
        if ($projectId !== null) {
            return $this->canAccessProjectForUser((int) $projectId, $userId);
        }
        return true;
    }

    /**
     * Project access for a specific uid (offline recipient resolution):
     * agents/admins all projects; project members their projects; requesters
     * their helpdesk_guest_access grants when NH-05 is enabled.
     */
    private function canAccessProjectForUser(int $projectId, string $userId): bool
    {
        try {
            if ($this->isInGroupCached($userId, self::GROUP_HELPDESK_ADMINS)
                || $this->isInGroupCached($userId, self::GROUP_HELPDESK_AGENTS)
                || $this->isNcAdminCached($userId)) {
                return true;
            }
            if ($this->isProjectMember($projectId, $userId)) {
                return true;
            }
            if ($this->isInGroupCached($userId, self::GROUP_HELPDESK_CUSTOMERS)) {
                return $this->guestProjectAccessMapper->hasAccess($userId, $projectId);
            }
            return $this->requesterProjectAccessForUid($userId)
                && $this->guestProjectAccessMapper->hasAccess($userId, $projectId);
        } catch (\Throwable $e) {
            $this->logWarn('project access evaluation failed (fail-closed)', $e);
            return false;
        }
    }

    /**
     * Recipient uids for customer-side notifications on this ticket
     * (spec MH-10): requester-scope users for whom the ticket is visible —
     * the uid author plus NC users matching customer_email under the
     * restricted e-mail leg (D-R15). Contained guests are excluded: they are
     * covered by the guest_access recipient path (D-R04 precedence). Staff
     * stay included — a dual-role member's self-filed ticket is
     * customer-authored and they want the customer-facing reply mail.
     *
     * @param \OCA\Ticketcheck\Db\Ticket $ticket
     * @return list<string>
     */
    public function requesterRecipientUids($ticket): array
    {
        $candidates = [];
        $createdBy = trim((string)($ticket->getCreatedBy() ?? ''));
        if ($createdBy !== ''
            && !PortalActorResolver::isIdentityActor($createdBy)
            && !in_array($createdBy, self::SYSTEM_TICKET_CREATORS, true)) {
            $candidates[] = $createdBy;
        }
        $customerEmail = strtolower(trim((string)($ticket->getCustomerEmail() ?? '')));
        if ($customerEmail !== '' && $this->userManager !== null) {
            try {
                foreach ((array)$this->userManager->getByEmail($customerEmail) as $user) {
                    $uid = $user !== null ? (string)$user->getUID() : '';
                    if ($uid !== '') {
                        $candidates[] = $uid;
                    }
                }
            } catch (\Throwable $e) {
                $this->logWarn('requester recipient lookup failed (fail-closed)', $e);
            }
        }

        $recipients = [];
        foreach (array_unique($candidates) as $uid) {
            try {
                if ($this->isInGroupCached($uid, self::GROUP_HELPDESK_CUSTOMERS)) {
                    continue; // guest containment wins — guest path notifies
                }
                if (!$this->isRequesterGroupMember($uid)) {
                    continue;
                }
                $userEmail = '';
                if ($this->userManager !== null) {
                    $user = $this->userManager->get($uid);
                    $userEmail = (string)($user?->getEMailAddress() ?? '');
                }
                if ($this->isRequesterScopedTicket($ticket, $uid, $userEmail)) {
                    $recipients[] = $uid;
                }
            } catch (\Throwable $e) {
                $this->logWarn('requester recipient evaluation failed (fail-closed)', $e);
            }
        }
        return $recipients;
    }

    /**
     * Whether uid-based helpdesk_guest_access grants count for
     * requester-scope users (spec NH-05/Q-R2, default off). Contained
     * guests and staff are unaffected — this only widens the requester
     * branch of project access.
     */
    private function requesterProjectAccessForUid(string $userId): bool
    {
        $settings = $this->getAppAccessSettings();
        if (!$this->requesterProjectAccessEnabled($settings)) {
            return false;
        }
        return $this->isRequesterGroupMemberWithSettings($userId, $settings);
    }

    /**
     * Whether staff may grant this uid a helpdesk_guest_access row
     * (spec NH-05): pure requester-scope uids only — guests use the guest
     * path, staff already have staff project access. The NH-05 toggle must
     * be on; otherwise the grant would be a silent no-op at read time.
     */
    public function isRequesterProjectAccessGrantable(string $userId): bool
    {
        if ($userId === '' || PortalActorResolver::isIdentityActor($userId)) {
            return false;
        }
        if (!$this->requesterProjectAccessForUid($userId)) {
            return false;
        }
        if ($this->isInGroupCached($userId, self::GROUP_HELPDESK_CUSTOMERS)
            || $this->isInGroupCached($userId, self::GROUP_HELPDESK_ADMINS)
            || $this->isInGroupCached($userId, self::GROUP_HELPDESK_AGENTS)) {
            return false;
        }
        return true;
    }

    /**
     * Restricted e-mail-match leg for requesters (spec D-R15): the
     * customer_email leg grants visibility only when the ticket's created_by
     * is the requester themselves, a staff-scope uid, empty/system, or a
     * 'portal:' identity — never a different non-staff uid. Two requesters
     * sharing an e-mail address (legal in NC) must not read each other's
     * tickets; staff-filed and inbound tickets still reach them.
     *
     * @param mixed $ticket
     */
    private function requesterEmailMatchAllowed($ticket, string $userId): bool
    {
        $createdBy = (string)($ticket->getCreatedBy() ?? '');
        if ($createdBy === '' || $createdBy === $userId) {
            return true;
        }
        if (PortalActorResolver::isIdentityActor($createdBy)
            || in_array($createdBy, self::SYSTEM_TICKET_CREATORS, true)) {
            return true;
        }
        // A uid authored this: allowed only when that uid holds staff scope.
        // Intentionally not hasEnabledHelpdeskScope() — requester scope must
        // not count, or two requesters sharing an address would leak (D-R15).
        // Fail-closed posture: a backend hiccup must not reopen the leak.
        return $this->hasStaffScope($createdBy, false);
    }

    /**
     * Check if user can edit a ticket
     *
     * @param mixed $ticket
     * @return bool
     */
    public function canEditTicket($ticket): bool
    {
        // Dual-role guest+admin: guest membership wins (defense in depth vs allowlist).
        if ($this->isGuest()) {
            return false;
        }
        // Helpdesk admins and agents can edit any ticket they can reach.
        if ($this->isHelpdeskAdmin() || $this->isAgent()) {
            return true;
        }
        // Project admins may edit tickets in their project (aligned with canDeleteTicket).
        return $this->isProjectAdminForTicket($ticket);
    }

    /**
     * Check if user can delete a ticket
     *
     * @param mixed $ticket
     * @return bool
     */
    public function canDeleteTicket($ticket): bool
    {
        // Dual-role guest+admin: guest membership wins (defense in depth vs allowlist).
        if ($this->isGuest()) {
            return false;
        }

        // Helpdesk admins can always delete tickets
        if ($this->isHelpdeskAdmin()) {
            return true;
        }

        // Project admins may delete tickets in their project (aligned with canEditTicket).
        return $this->isProjectAdminForTicket($ticket);
    }

    /**
     * @param mixed $ticket
     */
    private function isProjectAdminForTicket($ticket): bool
    {
        $projectId = $ticket->getProjectId();
        if ($projectId === null) {
            return false;
        }
        $userId = $this->getCurrentUserId();
        return $userId !== null && $userId !== '' && $this->isProjectAdmin((int) $projectId, $userId);
    }

    /**
     * Whether the current user may change a ticket's project_id to $targetProjectId.
     * Helpdesk agents/admins may move freely (target existence is validated separately).
     * Project admins may only move between projects they administer (never clear project).
     *
     * @param mixed $ticket
     */
    public function canMoveTicketToProject($ticket, ?int $targetProjectId): bool
    {
        if ($this->isGuest()) {
            return false;
        }

        $currentRaw = $ticket->getProjectId();
        $currentId = $currentRaw !== null && $currentRaw !== '' ? (int) $currentRaw : null;
        if ($currentId === $targetProjectId) {
            return true;
        }

        if ($this->isHelpdeskAdmin() || $this->isAgent()) {
            return true;
        }

        if ($targetProjectId === null || $targetProjectId <= 0) {
            return false;
        }

        $userId = $this->getCurrentUserId();
        if ($userId === null || $userId === '') {
            return false;
        }

        if ($currentId !== null && !$this->isProjectAdmin($currentId, $userId)) {
            return false;
        }

        return $this->isProjectAdmin($targetProjectId, $userId);
    }

    /**
     * Global project/customer directories are staff-only (not project-scoped admins).
     */
    public function canBrowseGlobalProjectDirectory(): bool
    {
        if ($this->isGuest()) {
            return false;
        }
        return $this->isHelpdeskAdmin() || $this->isAgent();
    }

    /**
     * Check if a user is a project admin
     *
     * @param int $projectId
     * @param string $userId
     * @return bool
     */
    public function isProjectAdmin(int $projectId, string $userId): bool
    {
        return $this->getProjectRole($projectId, $userId) === 'Admin';
    }

    public function isNextcloudAdministrator(string $userId): bool
    {
        return $this->isNcAdminCached($userId);
    }

    public function getUserProjectRole(int $projectId, string $userId): ?string
    {
        return $this->getProjectRole($projectId, $userId);
    }

    public function isProjectMember(int $projectId, string $userId): bool
    {
        return $this->getProjectRole($projectId, $userId) !== null;
    }

    public function canManageProjectMembers(int $projectId): bool
    {
        $userId = $this->getCurrentUserId();
        if ($userId === null || $userId === '') {
            return false;
        }
        if ($this->isGuest()) {
            return false;
        }

        // App admins (NC admin, helpdesk_admins group, dedicated app-admin
        // UIDs) administer every project's roster — same scope as the rest
        // of the app's admin surfaces.
        if ($this->isAppAdmin($userId)) {
            return true;
        }

        return $this->isProjectAdmin($projectId, $userId);
    }

    /**
     * Whether the bulk-delete control should be offered on the ticket list.
     * Helpdesk admins and users who administer at least one project.
     */
    public function canBulkDeleteTickets(): bool
    {
        if ($this->isGuest()) {
            return false;
        }
        if ($this->isHelpdeskAdmin()) {
            return true;
        }
        $userId = $this->getCurrentUserId();
        if ($userId === null || $userId === '') {
            return false;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
                ->from(self::TABLE_PROJECT_MEMBERS)
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('role', $qb->createNamedParameter('Admin')))
                ->setMaxResults(1);
            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            return $row !== false && $row !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getProjectRole(int $projectId, string $userId): ?string
    {
        if ($projectId <= 0 || $userId === '') {
            return null;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('role')
                ->from(self::TABLE_PROJECT_MEMBERS)
                ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            if (!$row || !isset($row['role'])) {
                return null;
            }
            return (string)$row['role'];
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Check if user can add comments to a ticket
     *
     * @param mixed $ticket
     * @return bool
     */
    public function canCommentOnTicket($ticket): bool
    {
        return $this->canViewTicket($ticket);
    }

    /**
     * Check if user can view internal notes
     *
     * @return bool
     */
    public function canViewInternalNotes(): bool
    {
        if ($this->isGuest()) {
            return false;
        }
        return $this->isHelpdeskAdmin() || $this->isAgent();
    }

    /**
     * Check if user can create internal notes
     *
     * @return bool
     */
    public function canCreateInternalNotes(): bool
    {
        if ($this->isGuest()) {
            return false;
        }
        return $this->isHelpdeskAdmin() || $this->isAgent();
    }

    /**
     * Check if user can manage settings
     *
     * @return bool
     */
    public function canManageSettings(): bool
    {
        if ($this->isGuest()) {
            return false;
        }
        return $this->isHelpdeskAdmin();
    }

    /**
     * CSV export of tickets/projects (admin UI and APIs).
     * Same gate as settings: helpdesk admins and Nextcloud administrators only.
     */
    public function canExportData(): bool
    {
        return $this->canManageSettings();
    }

    /**
     * Customer directory pages and APIs (index, create, edit, show, delete).
     * Agents may work with customer data on tickets and projects but not this area.
     */
    public function canAccessCustomerAdministration(): bool
    {
        if ($this->isGuest()) {
            return false;
        }
        return $this->isHelpdeskAdmin();
    }

    /**
     * Guest user directory pages and management APIs.
     */
    public function canAccessGuestUserAdministration(): bool
    {
        if ($this->isGuest()) {
            return false;
        }
        return $this->isHelpdeskAdmin();
    }

    /**
     * Check if user can manage knowledge base
     *
     * Dual-role guest+agent: guest membership wins (same as ticket authz).
     * Guests must never create/edit KB articles or upload images via allowlisted paths.
     *
     * @return bool
     */
    public function canManageKnowledgeBase(): bool
    {
        if ($this->isGuest()) {
            return false;
        }
        return $this->isHelpdeskAdmin() || $this->isAgent();
    }

    /**
     * Whether the Knowledge Base index is available for the current user.
     * Mirrors the KnowledgeBaseController route guard: global KB setting plus guest portal
     * when the user is in the helpdesk customers group.
     */
    public function isKnowledgeBaseIndexAvailable(): bool
    {
        if ($this->config->getAppValue('ticketcheck', 'kb_enabled', 'yes') !== 'yes') {
            return false;
        }
        if ($this->isGuest() && $this->config->getAppValue('ticketcheck', 'kb_portal_enabled', 'yes') !== 'yes') {
            return false;
        }
        return true;
    }

    /**
     * Whether the customer portal may expose Knowledge Base content (global KB on and portal KB on).
     * Used for portal layout, portal KB routes, and any UI that must stay hidden when the portal
     * product toggle is off—regardless of user role.
     */
    public function isKnowledgeBasePortalContentEnabled(): bool
    {
        return $this->config->getAppValue('ticketcheck', 'kb_enabled', 'yes') === 'yes'
            && $this->config->getAppValue('ticketcheck', 'kb_portal_enabled', 'yes') === 'yes';
    }

    /**
     * Global toggles for KB article feedback and comments (admin settings).
     * API and templates should use these instead of reading IConfig directly.
     */
    public function isKnowledgeBaseFeedbackEnabled(): bool
    {
        return $this->config->getAppValue('ticketcheck', 'kb_feedback_enabled', 'yes') === 'yes';
    }

    public function isKnowledgeBaseCommentsEnabled(): bool
    {
        return $this->config->getAppValue('ticketcheck', 'kb_comments_enabled', 'yes') === 'yes';
    }

    /**
     * Get current user's email
     *
     * @return string|null
     */
    public function getCurrentUserEmail(): ?string
    {
        if ($this->actorResolver !== null) {
            $identity = $this->actorResolver->currentIdentity();
            if ($identity !== null) {
                $email = (string)$identity->getEmail();
                return $email !== '' ? $email : null;
            }
        }
        $user = $this->userSession->getUser();
        if (!$user) {
            return null;
        }
        return $user->getEMailAddress();
    }

    /**
     * Get current user's display name
     *
     * @return string|null
     */
    public function getCurrentUserDisplayName(): ?string
    {
        if ($this->actorResolver !== null) {
            $identity = $this->actorResolver->currentIdentity();
            if ($identity !== null) {
                return (string)$identity->getDisplayName();
            }
        }
        $user = $this->userSession->getUser();
        if (!$user) {
            return null;
        }
        return $user->getDisplayName();
    }

    /**
     * Check if a user exists in a group
     *
     * @param string $userId
     * @param string $groupId
     * @return bool
     */
    public function isUserInGroup(string $userId, string $groupId): bool
    {
        return $this->isInGroupCached($userId, $groupId);
    }

    /**
     * Check rate limit for ticket creation (guests only)
     *
     * @param int $count Number of recent tickets
     * @param int $limit Maximum allowed tickets per day
     * @return bool
     */
    public function checkRateLimit(int $count, int $limit = 50): bool
    {
        if ($this->isGuest()) {
            return $count < $limit;
        }
        // Internal requesters write through the portal path — same rate
        // limits as guests (spec US-R02 AC-4).
        $userId = $this->getCurrentUserId();
        if ($userId !== null && $userId !== '' && $this->isRequesterGroupMember($userId)) {
            return $count < $limit;
        }
        return true; // No rate limit for agents/admins
    }

    /**
     * Get all project IDs the current user can access
     * - Agents/admins can access all projects
     * - Guests can only access assigned projects
     *
     * @return array|null Array of project IDs, or null if all projects are accessible
     */
    public function getAccessibleProjectIds(): ?array
    {
        // Guests first: dual membership must not expand to "all projects".
        if ($this->isGuest()) {
            $userId = $this->getCurrentUserId();
            if (!$userId) {
                return [];
            }
            return $this->guestProjectAccessMapper->getProjectsForUser($userId);
        }

        // Agents and admins have access to all projects (uses cached checks internally)
        if ($this->isAgent() || $this->isHelpdeskAdmin()) {
            return null; // null means "all projects"
        }

        // Requester-scope users only see projects they were explicitly
        // granted into via helpdesk_guest_access (NH-05, opt-in toggle).
        $userId = $this->getCurrentUserId();
        if ($userId !== null && $userId !== '' && $this->requesterProjectAccessForUid($userId)) {
            return $this->guestProjectAccessMapper->getProjectsForUser($userId);
        }

        // Default: no projects accessible
        return [];
    }

    /**
     * Check if current user can access a specific project
     *
     * @param int|null $projectId
     * @return bool
     */
    public function canAccessProject(?int $projectId): bool
    {
        // No project specified = accessible
        if ($projectId === null) {
            return true;
        }

        $userId = $this->getCurrentUserId();

        // Guests must be explicitly granted access (even if also agent/admin).
        if ($this->isGuest()) {
            if (!$userId) {
                return false;
            }
            return $this->guestProjectAccessMapper->hasAccess($userId, $projectId);
        }

        // Agents and admins can access all projects
        if ($this->isAgent() || $this->isHelpdeskAdmin()) {
            return true;
        }

        if ($userId !== null && $this->isProjectMember($projectId, $userId)) {
            return true;
        }

        // NH-05: requester-scope uids may hold explicit guest_access grants.
        if ($userId !== null && $userId !== ''
            && $this->requesterProjectAccessForUid($userId)) {
            return $this->guestProjectAccessMapper->hasAccess($userId, $projectId);
        }

        return false;
    }

    /**
     * Can the current user view this ticket (with project access check)?
     *
     * @param \OCA\Ticketcheck\Db\Ticket $ticket
     * @return bool
     */
    public function canViewTicketWithProjectAccess($ticket): bool
    {
        // Check basic view permission first
        if (!$this->canViewTicket($ticket)) {
            return false;
        }

        // Guests first (dual membership must not skip project checks).
        if ($this->isGuest()) {
            $projectId = $ticket->getProjectId();

            // Tickets without a project are visible to the guest who created
            // them and to the addressed customer (inbound-email tickets carry
            // created_by='inbound-email', so creator check alone would 404).
            if ($projectId === null) {
                return $ticket->getCreatedBy() === $this->getCurrentUserId()
                    || $this->emailsMatch(
                        $this->getCurrentUserEmail(),
                        (string) $ticket->getCustomerEmail()
                    );
            }

            // Check project access
            return $this->canAccessProject($projectId);
        }

        // If user is agent/admin, they can see all tickets
        if ($this->isAgent() || $this->isHelpdeskAdmin()) {
            return true;
        }

        // Project members and other viewers that passed canViewTicket().
        return true;
    }

    /**
     * Portal-only ticket view for the current actor (spec D-R05/D-R15).
     * Requesters get guest-derived scoping on their uid via
     * isRequesterScopedTicket(); every other actor falls through to the
     * normal guest/staff check.
     *
     * Portal paths must use this — never canViewTicketWithProjectAccess —
     * so requester scoping stays off the staff routes (US-R05 AC-1/NN-02).
     *
     * @param \OCA\Ticketcheck\Db\Ticket $ticket
     */
    public function canPortalViewTicket($ticket): bool
    {
        if ($this->isRequester()) {
            $userId = (string)($this->getCurrentUserId() ?? '');
            return $this->isRequesterScopedTicket($ticket, $userId);
        }
        return $this->canViewTicketWithProjectAccess($ticket);
    }

    /**
     * Determine whether the current user can access the Ticketcheck app at all.
     * Nextcloud admins are always allowed as a fail-safe to avoid permanent lockout.
     */
    public function canAccessApp(): bool
    {
        $userId = $this->getCurrentUserId();
        if ($userId === null || $userId === '') {
            return false;
        }
        return $this->canUserAccessAppByUid($userId);
    }

    public function canUserAccessAppByUid(string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        $settings = $this->getAppAccessSettings();
        return $this->canUserAccessAppWithSettings($userId, $settings);
    }

    /**
     * Directory door only (portfolio ACCESS-AND-DIRECTORY-PICKERS §3).
     * Open ⇒ every logged-in user may open TicketCheck (menu + shell).
     * Restricted ⇒ allow-listed users/groups (+ guests when customers toggle is on).
     * Helpdesk role toggles are enforced separately via {@see hasEnabledHelpdeskScope()}.
     *
     * @param array{
     *   allow_helpdesk_admins: bool,
     *   allow_helpdesk_agents: bool,
     *   allow_helpdesk_customers: bool,
     *   extra_groups: array<int, string>,
     *   access_restriction_enabled: bool,
     *   access_allowed_user_ids: list<string>,
     *   app_admin_user_ids: list<string>
     * } $settings
     */
    public function canUserAccessAppWithSettings(string $userId, array $settings): bool
    {
        if ($userId === '') {
            return false;
        }

        // Portal identities have no NC user behind them — the customers door
        // is the only rule that applies (E-T17): fail closed when it is off.
        if (PortalActorResolver::isIdentityActor($userId)) {
            return !empty($settings['allow_helpdesk_customers']);
        }

        // Nextcloud admins always have access — fail-safe against lockout.
        if ($this->isNcAdminCached($userId)) {
            return true;
        }

        // Dedicated app administrators always pass the directory door.
        if (in_array($userId, $settings['app_admin_user_ids'] ?? [], true)) {
            return true;
        }

        $restricted = !empty($settings['access_restriction_enabled']);
        if ($restricted) {
            return $this->passesDirectoryDoor($userId, $settings);
        }

        // Open mode: door ≠ role. Anyone logged in may enter; pages/APIs still
        // require an enabled helpdesk scope (or show the calm enrollment page).
        return true;
    }

    /**
     * True when the user may use staff/guest TicketCheck features under the
     * saved role toggles (beyond the directory door).
     *
     * @param array<string, mixed>|null $settings
     */
    public function hasEnabledHelpdeskScope(string $userId, ?array $settings = null): bool
    {
        if ($userId === '') {
            return false;
        }
        if ($this->isNcAdminCached($userId)) {
            return true;
        }
        $settings = $settings ?? $this->getAppAccessSettings();
        if (in_array($userId, $settings['app_admin_user_ids'] ?? [], true)) {
            return true;
        }
        // Requester scope (spec D-R20): checked before the functional-role
        // gate — requester membership IS the functional role here.
        if ($this->isRequesterGroupMemberWithSettings($userId, $settings)) {
            return true;
        }
        if (!$this->hasFunctionalHelpdeskRole($userId, $settings['extra_groups'] ?? [])) {
            return false;
        }
        if (!empty($settings['allow_helpdesk_admins']) && $this->isInGroupCached($userId, self::GROUP_HELPDESK_ADMINS)) {
            return true;
        }
        if (!empty($settings['allow_helpdesk_agents']) && $this->isInGroupCached($userId, self::GROUP_HELPDESK_AGENTS)) {
            return true;
        }
        if (!empty($settings['allow_helpdesk_customers']) && $this->isInGroupCached($userId, self::GROUP_HELPDESK_CUSTOMERS)) {
            return true;
        }
        if (!empty($settings['allow_helpdesk_agents']) && $this->hasProjectMembershipCached($userId)) {
            return true;
        }
        foreach ($settings['extra_groups'] ?? [] as $groupId) {
            if ($this->isInGroupCached($userId, (string) $groupId)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Door is open but no helpdesk scope yet — calm enrollment UX.
     */
    public function needsRoleEnrollment(?string $userId = null): bool
    {
        $userId = $userId ?? $this->getCurrentUserId();
        if ($userId === null || $userId === '') {
            return false;
        }
        if (!$this->canUserAccessAppByUid($userId)) {
            return false;
        }
        if ($this->isAppAdmin($userId)) {
            return false;
        }
        return !$this->hasEnabledHelpdeskScope($userId);
    }

    /**
     * Directory allowlist when Restricted is on.
     * Guests (helpdesk_customers) keep portal access when the customers toggle is on,
     * even if the allowlists are empty — otherwise Restricted would soft-lock the portal.
     *
     * @param array<string, mixed> $settings
     */
    private function passesDirectoryDoor(string $userId, array $settings): bool
    {
        $allowedUsers = $settings['access_allowed_user_ids'] ?? [];
        $allowedGroups = $settings['extra_groups'] ?? [];
        if (in_array($userId, $allowedUsers, true)) {
            return true;
        }
        foreach ($allowedGroups as $groupId) {
            if ($this->isInGroupCached($userId, (string) $groupId)) {
                return true;
            }
        }
        if (!empty($settings['allow_helpdesk_customers'])
            && $this->isInGroupCached($userId, self::GROUP_HELPDESK_CUSTOMERS)) {
            return true;
        }
        // Requester-group membership implies door admission (spec D-R08/D-R19):
        // a configured requester group must never hit a second allowlist.
        if ($this->isRequesterGroupMemberWithSettings($userId, $settings)) {
            return true;
        }
        // Empty Restricted allowlists fail closed for staff.
        return false;
    }

    /**
     * A user has a functional helpdesk role when they belong to any helpdesk
     * group, are a project member, or are in one of the admin-configured extra
     * groups. This prevents pure "UI-only" access dead-ends while ensuring the
     * extra_groups feature actually works for users who are not in a helpdesk
     * group by default.
     *
     * @param array<int, string> $extraGroups
     */
    private function hasFunctionalHelpdeskRole(string $userId, array $extraGroups = []): bool
    {
        if ($this->isNcAdminCached($userId)) {
            return true;
        }

        if ($this->isInGroupCached($userId, self::GROUP_HELPDESK_ADMINS) ||
            $this->isInGroupCached($userId, self::GROUP_HELPDESK_AGENTS) ||
            $this->isInGroupCached($userId, self::GROUP_HELPDESK_CUSTOMERS) ||
            $this->hasProjectMembershipCached($userId)) {
            return true;
        }

        foreach ($extraGroups as $groupId) {
            if ($this->isInGroupCached($userId, $groupId)) {
                return true;
            }
        }

        return false;
    }

    private function hasProjectMembership(string $userId): bool
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*'))
                ->from(self::TABLE_PROJECT_MEMBERS)
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
            $result = $qb->executeQuery();
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            return $count > 0;
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * @return array{
     *   allow_helpdesk_admins: bool,
     *   allow_helpdesk_agents: bool,
     *   allow_helpdesk_customers: bool,
     *   allow_helpdesk_requesters: bool,
     *   requester_groups: array<int, string>,
     *   requester_all_users: bool,
     *   requester_project_access: bool,
     *   extra_groups: array<int, string>,
     *   access_restriction_enabled: bool,
     *   access_allowed_user_ids: list<string>,
     *   app_admin_user_ids: list<string>
     * }
     */
    public function getAppAccessSettings(): array
    {
        $admins = $this->config->getAppValue('ticketcheck', self::APP_ACCESS_ADMINS_KEY, 'yes');
        $agents = $this->config->getAppValue('ticketcheck', self::APP_ACCESS_AGENTS_KEY, 'yes');
        $customers = $this->config->getAppValue('ticketcheck', self::APP_ACCESS_CUSTOMERS_KEY, 'yes');
        $requesters = $this->config->getAppValue('ticketcheck', self::APP_ACCESS_REQUESTERS_KEY, 'yes');
        $requesterAllUsers = $this->config->getAppValue('ticketcheck', self::APP_ACCESS_REQUESTER_ALL_USERS_KEY, 'no');
        $requesterProjectAccess = $this->config->getAppValue('ticketcheck', self::APP_ACCESS_REQUESTER_PROJECT_ACCESS_KEY, 'no');
        $requesterGroupsRaw = $this->config->getAppValue('ticketcheck', self::APP_ACCESS_REQUESTER_GROUPS_KEY, '[]');
        if (!is_string($requesterGroupsRaw)) {
            $requesterGroupsRaw = '';
        }
        $extraGroupsRaw = $this->config->getAppValue('ticketcheck', self::APP_ACCESS_EXTRA_GROUPS_KEY, '');
        if (!is_string($extraGroupsRaw)) {
            // Untyped OCP\IConfig::getAppValue — non-string behaves like unset.
            $extraGroupsRaw = '';
        }
        $restriction = $this->config->getAppValue('ticketcheck', self::ACCESS_RESTRICTION_KEY, '0');

        return [
            'allow_helpdesk_admins' => $admins === 'yes',
            'allow_helpdesk_agents' => $agents === 'yes',
            'allow_helpdesk_customers' => $customers === 'yes',
            'allow_helpdesk_requesters' => $requesters === 'yes',
            'requester_groups' => $this->parseJsonGidList($requesterGroupsRaw),
            'requester_all_users' => $requesterAllUsers === 'yes',
            'requester_project_access' => $requesterProjectAccess === 'yes',
            'extra_groups' => $this->parseGroupList($extraGroupsRaw),
            'access_restriction_enabled' => $restriction === '1' || $restriction === 'yes',
            'access_allowed_user_ids' => $this->getJsonUidList(self::ACCESS_ALLOWED_USERS_KEY),
            'app_admin_user_ids' => $this->getAppAdminUserIds(),
        ];
    }

    /**
     * @return list<string>
     */
    public function getAppAdminUserIds(): array
    {
        return $this->getJsonUidList(self::APP_ADMIN_USERS_KEY);
    }

    /**
     * Portfolio §2.1 / user lifecycle: strip deleted UIDs from app-admin and allow lists.
     */
    public function purgeUser(string $userId): void
    {
        if ($userId === '') {
            return;
        }
        foreach ([self::APP_ADMIN_USERS_KEY, self::ACCESS_ALLOWED_USERS_KEY] as $key) {
            $ids = $this->getJsonUidList($key);
            $filtered = array_values(array_filter(
                $ids,
                static fn (string $id): bool => $id !== $userId,
            ));
            if ($filtered !== $ids) {
                $this->config->setAppValue(
                    'ticketcheck',
                    $key,
                    json_encode($filtered, JSON_THROW_ON_ERROR),
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function getJsonUidList(string $key): array
    {
        $raw = $this->config->getAppValue('ticketcheck', $key, '[]');
        if (!is_string($raw)) {
            // OCP\IConfig::getAppValue is untyped — a non-string return must
            // behave like an unset key, not crash json_decode (TypeError).
            $raw = '';
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            // Legacy comma-separated fallback
            return array_values($this->parseGroupList($raw));
        }
        $out = [];
        foreach ($decoded as $uid) {
            if (!is_string($uid) && !is_int($uid)) {
                continue;
            }
            $uid = trim((string) $uid);
            if ($uid !== '' && !in_array($uid, $out, true)) {
                $out[] = $uid;
            }
        }
        return $out;
    }

    /**
     * Requester-groups config is JSON-only (no legacy CSV data exists for the
     * key): malformed input evaluates to [] — fail closed (spec §10/§11).
     * Capped at REQUESTER_GROUPS_LIMIT so evaluation stays O(50).
     *
     * @return list<string>
     */
    private function parseJsonGidList(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $gid) {
            if (!is_string($gid) && !is_int($gid)) {
                continue;
            }
            $gid = trim((string) $gid);
            if ($gid !== '' && !in_array($gid, $out, true)) {
                $out[] = $gid;
            }
            if (count($out) >= self::REQUESTER_GROUPS_LIMIT) {
                break;
            }
        }
        return $out;
    }

    /**
     * Case-insensitive email compare (RFC local-parts are case-sensitive in theory;
     * in practice mailbox providers treat them as case-insensitive, and guests
     * must not lose ticket visibility due to casing differences).
     */
    private function emailsMatch(?string $left, ?string $right): bool
    {
        $a = strtolower(trim((string) $left));
        $b = strtolower(trim((string) $right));

        return $a !== '' && $a === $b;
    }

    /**
     * @return array<int, string>
     */
    private function parseGroupList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $groups = array_map(static fn (string $group): string => trim($group), explode(',', $raw));
        $groups = array_filter($groups, static fn (string $group): bool => $group !== '');
        return array_values(array_unique($groups));
    }
}

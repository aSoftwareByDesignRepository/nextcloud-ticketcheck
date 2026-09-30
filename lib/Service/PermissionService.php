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
    private const APP_ACCESS_ADMINS_KEY = 'app_access_helpdesk_admins';
    private const APP_ACCESS_AGENTS_KEY = 'app_access_helpdesk_agents';
    private const APP_ACCESS_CUSTOMERS_KEY = 'app_access_helpdesk_customers';
    private const APP_ACCESS_EXTRA_GROUPS_KEY = 'app_access_extra_groups';
    private const ACCESS_RESTRICTION_KEY = 'access_restriction_enabled';
    private const ACCESS_ALLOWED_USERS_KEY = 'access_allowed_user_ids';
    private const APP_ADMIN_USERS_KEY = 'app_admin_user_ids';

    private IUserSession $userSession;
    private IGroupManager $groupManager;
    private IConfig $config;
    private GuestProjectAccessMapper $guestProjectAccessMapper;
    private IDBConnection $db;

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
        IDBConnection $db
    ) {
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
        $this->config = $config;
        $this->guestProjectAccessMapper = $guestProjectAccessMapper;
        $this->db = $db;
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
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            return false;
        }
        return $this->isInGroupCached($userId, self::GROUP_HELPDESK_CUSTOMERS);
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
            if ($projectId !== null && $this->canAccessProject((int)$projectId)) {
                return $this->emailsMatch(
                    $this->getCurrentUserEmail(),
                    (string) $ticket->getCustomerEmail()
                );
            }

            return false;
        }

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

        // Nextcloud admins are the break-glass recovery path.
        if ($this->isNcAdminCached($userId)) {
            return true;
        }

        if ($this->isProjectAdmin($projectId, $userId)) {
            return true;
        }

        // Global helpdesk admins may seed orphaned projects, but not override
        // project admins in projects they do not administer.
        return $this->isInGroupCached($userId, self::GROUP_HELPDESK_ADMINS)
            && $this->countProjectAdmins($projectId) === 0;
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

    private function countProjectAdmins(int $projectId): int
    {
        if ($projectId <= 0) {
            return 0;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*'))
                ->from(self::TABLE_PROJECT_MEMBERS)
                ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('role', $qb->createNamedParameter('Admin')));

            $result = $qb->executeQuery();
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            return $count;
        } catch (\Throwable $e) {
            if (DbQueryGuard::isMissingSchemaObject($e)) {
                return -1;
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
        if (!$this->isGuest()) {
            return true; // No rate limit for agents/admins
        }
        return $count < $limit;
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

            // Tickets without a project are only visible to the guest who created them.
            if ($projectId === null) {
                return $ticket->getCreatedBy() === $this->getCurrentUserId();
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

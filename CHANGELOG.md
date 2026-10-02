# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 2.3.10 - 2026-10-02

### Fixed
- App Store screenshots now use whitespace-free URL values, preventing the App Store image proxy from treating XML indentation as part of each URL and returning `File not found`.

## 2.3.9 - 2026-10-02

### Added
- **Companion idempotency:** companion mutations accept an optional `X-TC-Idempotency-Key` header (or `idempotencyKey` body field) — a replayed key returns the original result instead of creating a duplicate row (lost-200 / offline-queue double-submit protection, 24 h TTL, scoped per user + route). New `tc_idempotency` table via migration `Version4215Date202610020000`, cleanup via `IdempotencyPurgeJob`.
- Shared field-level error rendering (`js/common/field-errors.js`, `css/common/field-errors.css`): server `fields` error maps now render inline per named control with `aria-invalid`.

### Fixed
- **Companion auth precedence:** a stale session cookie could shadow a valid `Authorization: Basic` credential on `/companion/api/v1/*` (core resolves the cookie session before Basic). `ClientLicenseMiddleware` now treats the explicit credential as authoritative — on identity mismatch the session is re-authenticated (throttled) with it; invalid Basic + foreign cookie returns 401. Web/session auth unchanged.

## 2.3.8 - 2026-09-28

### Added
- Migration `Version4212` covering permission-service schema needs; UI-API contract registry.

### Fixed
- Companion auth throttle returns JSON; compare-and-swap TOCTOU closed by locking the watch under TicketWorkflowLock.
- Tickets list skip-link selector against Nextcloud core collision; mobile-nav, dialog and screenshot-baseline hardening from Atlas passes.
- **Session CSRF guard:** new `SessionCsrfMiddleware` re-validates the request token server-side for every session-authenticated mutation — including routes marked `#[NoCSRFRequired]` and requests carrying `OCS-APIRequest` (a client-controlled header that tells Nextcloud to skip its CSRF check). Basic-auth companion calls and anonymous requests stay exempt; a forged request can no longer opt out of CSRF.

## 2.3.6 - 2026-08-13

### Changed

- Packaging release: version bump for ready4upload / production archive (local install already at 2.3.5).

## 2.3.5 - 2026-08-02

### Changed

- Packaging release: version bump for ready4upload / production archive (local install already at 2.3.4).

## 2.3.4 - 2026-08-02

### Changed

- **Companion invoicing deep links:** Ticket detail and customer detail can open a companion invoicing app's receivables filtered by ProjectCheck `customerId` (TicketCheck customer ids are PC customer ids — no invented name-search mapping), shown only when that app is installed and enabled.

## [Unreleased]

## [2.3.3] - 2026-08-01

### Changed

- Release packaging for multipage settings and companion access hardening (routes, access picker, settings redirect/chip bar, locale sync).

## [2.3.2] - 2026-07-31

### Changed

- **Multipage settings:** Split the admin settings mega-page into `/settings/{section}` sub-pages (Access, Email, Knowledge base, KB categories, Escalation, License, Support) with sidebar sub-nav, in-page chip bar, legacy `#anchor` forwarding, and section-scoped template data.

## [2.3.1] - 2026-07-30

### Security

- **Companion API hardening:** seat gates on power routes; app-password session proof; honest push capabilities; attachments, filters, watchers, create, and bulk require seats so the official companion cannot bypass licensing or leak guest `RoleDenied` UX; CAS / conflict handling on concurrent updates.

## [2.3.0] - 2026-07-27

### Added

- **Companion S0 (TKC2):** `TKC2` license tables (`tc_license_state`, `tc_mobile_seats`), seat assignment UI, companion JSON API under `/companion/api/v1/*`, `ClientLicenseMiddleware` (402/403 on Basic-auth companion only; web staff + guest portal ungated), bootstrap with signed envelope, NC Notifications for assignment and guest/public comments, capability `ticketcheck.companion.min=1`.

## [2.2.33] - 2026-07-27

### Fixed

- **Upgrade backup restore:** appdata folder restore clears in place when delete is permission-blocked (e.g. root-owned `kb-images` leftovers) and fails closed with actionable errors instead of opaque `NotPermittedException` mid-restore.

### Changed

- **Visual/architecture epic closure:** Settings uses `renderAppPage`; deletion modal wraps `TicketCheckComponents.openModal`; accessibility helpers folded into `common/nav` + `form-validation`; user-facing copy prefers TicketCheck (group IDs unchanged); ARCH-22 EntryController rename waived in `docs/ARCH-STATUS.md`.
- **Sign-off docs:** `docs/ARCH-STATUS.md`, `docs/VISUAL-SIGNOFF.md`, `docs/AUDIT-UAT.md`, `docs/WORKFLOW-SCOPE.md`.

## [2.2.32] - 2026-07-24

### Security

- **removeLinkByPair linked-id oracle closed:** controller resolves+edits both route and linked tickets before pair remove; service pre-lock authz maps missing ≡ foreign to `ticket_not_found` (no lock burn, no 400 `invalid_parameters` vs 404 split).

## [2.2.31] - 2026-07-24

### Security

- **removeLink oracle closed:** ticket authz runs before any link lookup (controller + `removeLinkForTicket`); missing and foreign tickets share `ticket_not_found`; missing links after authz map to `invalid_parameters`.
- **Link/watcher pre-lock authz:** `addLink` / watcher add/remove refuse foreign tickets before taking exclusive workflow locks (merge-style), closing lock-burn and residual probe paths.

## [2.2.30] - 2026-07-24

### Security

- **Portal download existence oracle closed:** missing ticket now returns the same `ticket_not_found` body as foreign-project deny (no more `ticket_or_attachment_not_found` leak). Survivor hop failures are also uniform 404.
- **Inbound process rejects are opaque:** after valid signature, failed `processWebhook` responses always use `invalid_payload` (details stay in logs only) so missing ticket vs unauthorized sender cannot be distinguished over the webhook.

## [2.2.29] - 2026-07-24

### Security

- **Bulk action existence oracle closed:** missing and foreign-project IDs both return `ticket_not_found` (no more `operation_failed` via premature `ticketMapper->find`). Guest / non-overview callers get 403 before any resolve.
- **Portal + assign email soft-fail is Throwable-complete:** create/reply/upload portal notifications and staff assign email no longer let `Error` escape into a false 400/500 after a successful persist.

## [2.2.28] - 2026-07-24

### Security

- **Staff mutate existence oracle closed:** update/updatePost/delete/changeStatus/assign/addComment/uploadAttachment/deleteAttachment/searchAssignableUsers/bulk and the edit form now treat missing and no-project-view as uniform `ticket_not_found` (404); capability deny on a viewable ticket stays 403.
- **Comment/status/assign soft-fail notifications:** email/activity failures after a successful mutation no longer return false 400 (retry → duplicate comments).
- **Split missing/foreign oracle:** missing source and no project view throw `ticket_not_found` (same client mapping as Permission denied), not `split_failed`.
- **Dead createWithAttachments() delegates to store()** so unrouted callers cannot drift from live create compensation.
- **Single-file upload outer catch is Throwable** (staff + portal) so Error after persist returns JSON 400 instead of an uncaught 500.

## [2.2.27] - 2026-07-24

### Security

- **Live-path orphan attach coverage:** staff create compensation tests target routed `store()` (not dead `createWithAttachments()`). Portal create/reply Error compensation covered with mutations that kill narrowing compensate to InvalidArgument only.
- **Portal reply Error align with create:** reply attach wraps `Exception` as upload failure but lets `Error` bubble so comment-level Throwable compensate runs; store/reply outer catches are `Throwable` so guests get JSON 400 instead of an uncaught 500.

## [2.2.26] - 2026-07-24

### Security

- **Create/reply orphan ticket/comment on Error closed:** staff create/store and portal create/reply delete the new ticket/comment on any attach-batch `Throwable` (not only InvalidArgument / ticket_not_found). UpdatePost field-fail compensate also uses `Throwable`.

## [2.2.25] - 2026-07-24

### Security

- **Staff attach batch compensate is Throwable-complete:** `handleMultipleFileUploads` matches portal — partial uploads are cleaned up on `Error` as well as exceptions.
- **Split commit-under-lock proven:** positive unit test + mutation that moves commit after lock release is killed.

## [2.2.24] - 2026-07-24

### Security

- **Inbound replay claim-before-process:** fingerprint is set under the exclusive lock before `processWebhook`, so crash/timeout after a successful comment cannot double-post on provider retry; failed processing releases the claim.
- **Portal attach oracle completes legacy missing-row:** `Ticket not found` (TicketService) maps like `ticket_not_found` (uniform 404, not upload failure).
- **Split commits under workflow locks:** DB commit happens inside `withTicketLocks` (merge-style), closing the lock-release-before-commit window.

### Changed

- **Access-denied:** drop unused empty live-alert region (single assertive alert on the message only).

## [2.2.23] - 2026-07-24

### Security

- **Portal attach authz oracle:** mid-batch `ticket_not_found` is no longer masked as upload failure; create/reply compensate then return uniform 404.
- **Inbound rate-limit lock contention:** unit coverage for fail-closed `LockedException` → HTTP 429 after signature.

### Changed

- **Access-denied a11y:** assertive `role="alert"` on the message only (CTAs outside the alert), forced-colors focus on deny actions.
- **Support & Us:** forced-colors focus rings; absolute license CTAs get `noopener`; section/CSS mutation gauntlet + settings Playwright smoke.

## [2.2.22] - 2026-07-24

### Changed

- **Support & Us polish:** settings chrome matches sibling sections (no double card), Partner block is a real landmark `<section>`, active Block A–H copy in en/de l10n (stale intros removed), WCAG-friendly option grid styles kept.

### Security (prior)

- **Merge-safe attach compensate:** `deleteAttachmentWherever` follows rows moved to a merge survivor during create/upload races (staff + portal batches).
- **App-access deny UX unified:** `AppAccessMiddleware` rethrows so `GuestSecurityMiddleware` owns the terminal 403 (loop-safe logout / guest chrome). Access-denied CTA label matches action (`logout` vs `back_to_nextcloud`).
- **Attach authz denials stay 403:** `processSingleFileUpload` rethrows `AppAccessDeniedException` (no longer masked as upload failure).
- **Guest portal redirect-loop closed:** when `app_access_helpdesk_customers=no` (or deny on an allowlisted path), `GuestSecurityMiddleware` renders a terminal 403 access-denied page instead of redirecting back to `/portal` (`ERR_TOO_MANY_REDIRECTS`). Staff app-access denies also get a terminal 403 (never 302 `/`, which can soft-loop when defaultpage is TicketCheck). Denied guests are offered logout, not defaultpage bounce.
- **Access-denied landmark:** template uses `<main id="tc-denied-main">` for WCAG skip-target / landmark.
- **Portal create/reply attach batches** compensate this-request attachment IDs on failure (aligned with staff), then still delete ticket/comment as second line.
- **Staff `updatePost` attach-first atomicity:** multipart update uploads files before mutating ticket fields; failed batches leave fields untouched. Partial/this-request attaches are compensated on failure (including when field update fails after a successful batch).
- **Guest e2e auth:** global-setup writes `.auth/guest-storage-state.json` from `E2E_GUEST_USER`/`E2E_GUEST_PASSWORD`; portal smoke isolates guest storage from agent state.
- **Mutation gauntlet** for update attach atomicity (`tests/Mutation/run-update-attach-mutations.php`).
- **Portal/staff create+attach atomicity:** failed attach batch compensates by deleting the new ticket (no orphan ticket).
- **Portal reply+attach atomicity:** failed attach batch compensates by deleting the new comment; rate-gate no longer maps upload failures to HTTP 429.
- **`deleteComment` merge-safe:** resolves comment home (survivor after merge), fails loudly on lock/delete failure, retries once on mid-lock move.
- **Staff create/update notifications soft-fail** — email/activity errors no longer return 400 with a live ticket (duplicate-retry trap).
- **Staff create/update attaches fail closed** (no silent partial `success: true`).
- **Mutation gauntlet** for `deleteComment` compensation (`tests/Mutation/run-delete-comment-mutations.php`).
- **Portal `created_by_guest` reflects actual guest membership** (not always true).
- **Merge/link existence oracle closed:** non-editable targets map to uniform `ticket_not_found` (404); merge pre-checks authz before taking exclusive locks.
- **Inbound rate limit only after signature** — leaked webhook token cannot burn the per-IP quota with unsigned requests.
- **Split locks `[source,…children]` in one ascending set** after create (no mid-flight downward lock expansion).
- **Link remove requires canEdit on both endpoints** (aligned with add).
- **Portal create/reply attachment uploads fail closed** when any pending file does not persist.
- **Legacy GET `/tickets/{id}/export/pdf` retired (410)** — CSRF session-exfil (same class as CSV); use Export UI.
- **Ticket delete authz re-checked under workflow lock** (single + bulk) — closes demotion TOCTOU.
- **Attachment delete authz re-checked under workflow lock.**
- **Project member add re-asserts manage + role-rank under lock** (aligned with remove/role-change).
- **Portal survey + create-ticket re-check authz/project under lock/gate.**
- **CSP:** `CspNonceProvider` wraps private NC nonce manager; `CSPTrait` no longer uses `\OC::$server`; guest templates drop app-owned inline styles (KB images use width/height attrs).
- **KB sanitizer** promotes editor `--image-width/height` to HTML attributes (CSP-safe).
- **Authz re-checked under workflow lock** for status/assign/comment/attach (staff + portal) and bulk assign/priority/status — closes demotion/move TOCTOU beyond ticket update.
- **Attachment persist+insert under one ticket lock** (`addAttachmentFromUploadedFile`) — closes merge↔orphan file races; `comment_id` rebound under lock (portal rejects internal).
- **Legacy GET `/tickets/export/csv` retired (410)** — CSRF session-exfil closed; use Export UI/POST.
- **Forced-colors focus rings** extended to combobox options and filter-bar inputs.

### Changed

- Bulk delete button shown for helpdesk admins **and** project admins (`canBulkDeleteTickets`), matching API authz.
- Ticket form project combobox: `aria-required` on the visible search input (not the aria-hidden select).
- **Per-user KB helpful votes** (`hd_kb_helpful` UNIQUE article+user) — ranking inflation closed; idempotent re-votes.
- **Ticket update authz re-checked under workflow lock** (edit + project move) — closes check-then-act after concurrent move/demotion.
- **Ticket delete hops to merge survivor** before authz/cascade (aligned with analyze).
- **Portal KB helpful/comments share guest activity gate + ceiling** (includes KB comment/vote counts).
- **KB HTML sanitizer rejects external `img[src]`** (app-relative TicketCheck KB images only).
- **Attachment upload cleans staged bytes** when DB insert fails after persist.
- **Forced-colors focus rings** on merge/link pickers (WCAG 2.4.7).
- **Guest isolation is deny-by-default:** Boot intercept, template listeners, and `GuestSecurityMiddleware` now block any path not on `GuestAccessAllowlist` (previously a denylist / partial throw left `/settings/admin`, bare `/apps/settings`, and other non-`/apps/*` routes fail-open after logging). Personal settings remain allowlisted; CSRF token refresh added; `/core/` narrowed to js/css/img/l10n/preview only. Unit coverage for admin-settings deny and portal allow.
- **Internal-note attachments no longer downloadable by guests:** Portal and staff download paths deny attachments whose comment is internal unless the viewer may see internal notes (404, no enumeration).
- **Inbound email sender authz matches portal:** Only the ticket `customer_email` may post via webhook (not any guest with project access).
- **Generic inbound webhook requires HMAC signing secret:** Token-only generic webhooks return 503 until a signing secret is configured (Mailgun/SendGrid already fail-closed).
- **Dual-role guest+agent uses guest ticket scoping:** `helpdesk_customers` membership wins for ticket/project authz and internal notes (misconfiguration cannot grant portal “view all”).
- **Project delete cleans `hd_proj_members` and locks all project tickets before cascade.**
- **Guest rate ceiling is unified:** ticket create, replies, and attachment uploads share `countRecentPortalActionsByUser` (tickets + comments + attachments) over a 24h window — standalone uploads can no longer bypass the limit.
- **CSV formula injection neutralized** (`CsvFormulaGuard`) on admin export and ticket CSV download.
- **Rate-limit status API** returns only `{ allowed: bool }` (no counters for quota probing).
- **`closed_at` preserved** when re-saving an already-done ticket (resolution metrics stay honest).
- Removed dead unused guest upload allowlist constants from `CustomerPortalController` (single source of truth remains `AttachmentUploadService`).
- **Bulk actions no longer bypass TicketService:** assign/priority/status/delete go through the same merge-safe, locked service paths as single-ticket mutations (full cascade on delete; SLA recalculation on priority; status validation). Selection capped at 100 tickets.
- **Survey double-submit closed:** UNIQUE(`ticket_id`) on `helpdesk_ticket_surveys` (migration `Version4207`) plus per-ticket workflow lock; unique violations map to “already submitted”.
- **Guest rate-limit race closed:** create/reply/attach re-check the daily ceiling under an exclusive per-user gate so parallel POSTs cannot exceed the limit.
- **Inbound webhook rate-limit fail-closed:** lock contention now counts as limited (no bypass under flood).
- **Status/assign/update serialized** with `TicketWorkflowLock` (same ordered locks as merge/comment/attach).
- **Merge↔content race closed:** Comments, attachments, links, watchers, and deletes take the same ordered `TicketWorkflowLock` as merge/split (re-entrant for nested split→comment). Orphan cleanup after a lost merge race only deletes rows still pointing at the source ticket (never the survivor).
- **Merge-chain IDOR closed:** Comments authorize and notify against the surviving ticket after a merge, never the merged-away source. Staff and portal detail views redirect to the survivor when the viewer is allowed to see it.
- **Guest email match is case-insensitive** for ticket visibility and email-based lookups (PostgreSQL-safe `LOWER()` queries).
- **Inbound webhook replay race closed:** exclusive lock covers check + process + remember so parallel deliveries cannot double-post. Rate-limit increments are serialized the same way.
- **SLA monitor and escalation jobs** take exclusive locks so multi-worker cron cannot double-send alerts or double-apply rules.
- Digest jobs already claim day/week idempotency under locks (daily/weekly).
- **Merge-safe mutations:** Ticket field updates use `UPDATE … WHERE merged_into_id IS NULL`. Comments/attachments that lose a merge race are rolled back (orphan rows deleted). Escalation skips merged rows the same way.
- **SLA alert stamps** write only the alert timestamp columns (no stale full-entity overwrite of concurrent edits).
- **Attachment merge-race cleanup:** Losing an attach-vs-merge race deletes both the DB row and the bytes on disk (no orphan files under the data directory).
- **Attachment delete takes the workflow lock:** deleting an attachment now runs in `TicketService` under the shared ticket lock, deletes the row conditionally (`WHERE ticket_id = …`) before the bytes, and fails closed when a concurrent merge re-pointed the row to the survivor (previously the controller unlinked the file first, unguarded).
- **Escalation uses locked TicketService updates:** cron escalation no longer writes tickets unlocked (closes lost-update races with concurrent agent edits); SLA recalc stays inside `updateTicket`.
- **Merge attachment durability:** files are **copied** to the target before the DB transaction; source originals are deleted only after commit. Crash mid-merge never leaves survivor rows pointing at missing bytes (worst case: orphan copies under the target).
- **Watcher/link remove under workflow locks:** `removeWatcher` / `removeLink` / `removeLinkByPair` take the same ordered ticket locks as merge (no unguarded graph mutation).
- **Split is fully transactional:** children are created inside one DB TX under the source lock; unique-number retries use nested savepoints so PostgreSQL unique violations do not abort the outer TX. Rollback removes all children — no create-then-lock window and no orphan-compensation path.
- **No downward lock expansion:** nested `withTicketLocks` refuses to acquire a lower ticket id while a higher one is held (prevents classic A↔B deadlock with nested callers).
- **Inbound merge-hop sender re-authz:** `addCommentFromEmail` re-checks `customer_email` under the ticket lock (and after merge retry) so a concurrent merge cannot inject comments onto a different survivor.
- **Survey DONE re-checked under lock:** reopen between controller check and insert cannot slip a survey onto an open ticket.
- **Portal reply/attach require open under lock:** guests cannot win a close↔reply race; closed-ticket errors map to 403.
- **Project cascade delete converges:** lock → re-query → retry until membership is stable, plus a leftover sweep.
- **SLA alert stamps take TicketWorkflowLock:** same ordered locks as priority/update (no stamp vs clear race).
- **Guest allowlist uses path-segment boundaries:** `/settings/user` no longer matches `/settings/users`; `portal` / `api/guest` prefixes cannot collide with lookalike paths.
- **Guest allowlist decodes percent-encoding before `..` resolution:** `/portal/%2e%2e/tickets` and double-encoded `%252e` no longer keep a portal prefix match (NUL after decode collapses to `/`).
- **Portal home KPIs are real links** to My Tickets with `?status=` (works without JS; no fake `role="button"` widgets).
- **Guest project-access rewrite serialized** under exclusive per-user lock (wipe+grant cannot lose grants under concurrent admin updates); grant/revoke/delete share the same gate.
- **Guest email uniqueness enforced** (case-insensitive among enabled `helpdesk_customers`) — prevents portal ticket cross-visibility via shared email.
- **Project member picker no longer dumps all Nextcloud users** — only helpdesk agents/admins (closes directory IDOR for project Admins).
- **Project member add/bulkAdd under the same lock** as remove/role-change.
- **Workflow API errors mapped to l10n** (split/link/watcher) — no raw lock/exception text to clients.
- **Staff reply UI gated on `canCommentOnTicket`** (aligned with API for project members).
- **Portal password set serialized** with admin reset (shared password gate).
- **Retired unsafe `ProjectService::deleteProject` / `deleteCustomer`:** fail closed — controllers already use locked `DeletionService` (legacy raw SQL skipped workflow locks, attachment files, links/watchers/surveys, merge shells).
- **KB article delete purges comments** (`deleteByArticleId`); deletion analyze reports `kb_comments`.
- **Watcher/link remove reject merged shells** under lock (defense in depth vs controller survivor hop).
- **Portal KB APIs require published articles:** comments and helpful votes on drafts return not-found (aligned with HTML view).
- **Bundled create/reply attachments consume rate-limit slots:** pending file count is reserved under the guest activity gate (no +10 bypass of the daily ceiling).
- **KB image filenames use `random_bytes`** (harder to guess than `uniqid`).
- **KB images bound to published articles for non-managers:** guests/agents only receive images referenced by a published article body; managers keep editor preview. `Cache-Control: private, no-store` (was public).
- **Guest theming allowlist narrowed** to theme/image/favicon/icon/img/manifest + exact background GET (no `/ajax/`).
- **`/core/preview` removed from guest allowlist** (ticket previews use app download).
- **Merge attachment orphan GC:** hourly SLA monitor deletes unreferenced files under `helpdesk_attachments/{ticketId}/` (hard-crash copy leftovers).
- **KB view/helpful counters are atomic** (`views = views + 1` / `helpful_count = helpful_count + 1`) — concurrent portal reads and votes no longer lose increments.
- **KB image orphan GC:** article delete/update removes unreferenced `kb_*` files; hourly SLA monitor age-guards leftovers (2h, mirrors attachment GC).
- **Project Admin edit aligned with delete:** project admins can edit tickets in their project (same scope as `canDeleteTicket`).
- **Removed dead unrouted `debugMembership` API** and `error_log` in customer guest listing (injected logger only).
- **KB HTML sanitizer blocks protocol-relative URLs** (`//evil…`) for `href`/`src` (aligned with `SafeInternalRedirect`).
- **Project Admin cannot move tickets into foreign projects** / clear project; edit/create/filter directories are membership-scoped (no global customer/project dump). Project moves sync `customer_id`/name/email from the target project.
- **Dual-role cannot manage KB:** `canManageKnowledgeBase()` guest-denies (closes upload/draft-image bypass for guest∩agent).
- **Guest allowlist KB images are GET-only:** `/kb/images/upload` denied; only `kb_*.{jpg,png,gif,webp}` filenames allowed.
- **CSV formula guard strips leading whitespace** before detecting `=+-@` (spreadsheet injection edge case).
- **Staff can bind attachments to internal notes:** `commentBelongsToTicket` includes internal comments when `canViewInternalNotes()` (portal stays public-only).
- **Dual-role guest-deny on admin caps:** delete ticket, project members, settings, export, customer/guest admin.
- **Guest password-fail attempts serialized** under an exclusive per-user gate (parallel wrong-password POSTs cannot exceed 5/15m).
- **Dual-role cannot see agent overview/desklet:** `canViewHelpdeskOverview` guest-denies (portal-only for guest∩agent).
- **Staff dashboard/API/templates/deletion use guest-wins gates** (`canViewHelpdeskOverview` / `canManageSettings`) instead of raw `isAgent()`/`isHelpdeskAdmin()`.
- **Guest allowlist OCS entries corrected:** pathInfo-shaped `/core/avatar/` only; removed dead/misleading `/ocs/v2.php/...` and `user_status` prefixes.
- **Portal attachment dropzones properly labelled** (`label for=` + `aria-labelledby` on the control).
- **Project create/store and admin chrome use guest-wins gates** (`canManageSettings` / `canViewHelpdeskOverview`) so dual-role guest∩admin cannot create projects or see staff overview UI.
- **Portal role badge: guest membership wins** over admin/agent for dual-role users.
- **Open-redirect closed on project `return_to`:** only same-app relative paths accepted (`SafeInternalRedirect`; rejects `//evil`, schemes, non-ticketcheck paths). Client mirrors the check.
- **Guest email fan-out scoped to portal parties:** project guests only receive ticket/comment mail if they created the ticket or match `customer_email` (no leak of other customers’ tickets in a shared project).
- **`SafeInternalRedirect` normalizes `.`/`..`** before the app-prefix check (blocks `/apps/ticketcheck/../../settings/admin`).
- **Portal shell enrichment guest-wins** for `isAdmin`/`isAgent` chrome flags (dual-role).
- **Internal-note activity recipients guest-deny** dual-role guest∩agent.
- **Last project-admin remove/demote serialized** under an exclusive per-project lock.
- **Portal create-ticket GET uses unified rate ceiling** (`countRecentPortalActionsByUser`).
- **Email deep-links are audience-aware:** guests always get portal URLs; staff/watchers/SLA digests always get staff ticket URLs (no allowlist bounce / no portal-without-internal-notes for agents).
- **CSAT survey is guest-only** (staff with view access cannot pollute ratings).
- **Portal attachment download follows merge survivors** (same hop as view/reply).
- **KB `isAdmin` chrome uses `canManageSettings()`** (dual-role guest-wins).
- **Generic inbound webhook is JSON-body-only:** HMAC covers the exact payload fields processed (form/query with empty body rejected).
- **Merge survivor hops completed** for portal upload/links and staff upload/API reads (`resolveTicketForApiRead` + `uploadAttachment`).
- **Activity recipients never include guests** (public and internal events).
- **Watcher emails skip guests** and honor `PREF_CUSTOMER_REPLY` for comment notifications.
- **Staff merge hops completed** for getLinks/getWatchers (use survivor id), deleteAttachment, link/watcher mutations, assign/update/status, bulk assign/priority/status; bulk-delete of merged shells is rejected (no accidental survivor delete).
- **Edit form redirects to survivor** after merge (same as show).
- **apiShow returns the survivor** via `resolveTicketForApiRead`.
- **KB drafts visible only to managers** (`canManageKnowledgeBase`), not every non-guest user.
- **Orphan attachment GC skips files younger than 2 hours** so in-flight merge copies / pre-insert uploads are not unlinked.
- **searchAssignableUsers hops to the survivor** (same project team as assign/watchers).
- **Deletion analyze hops to the survivor** (accurate dependency counts after merge).
- **Staff ticket form dropzone** labelled like portal (`label for=` + `aria-labelledby`).
- **Assignable/watcher allowlist enforced in the service layer** — only helpdesk agents/admins or the ticket’s project members (closes API bypass that emailed arbitrary NC users).
- **Assignable-user search never falls back to all Nextcloud users**.
- **Mailgun webhook replay keys the signature package** (`timestamp`+`token`) so a captured Mailgun signature cannot authorize a swapped body; Mailgun now also requires a signing secret at the webhook gate.
- **Portal CSAT `submitSurvey` hops to the survivor** after merge.
- **Deletion modal never retargets shell delete to the survivor** (shows merge-shell note; cascade cannot wipe the live ticket).
- **Staff status/priority badges use the solid AA palette** (same as portal).
- **List/Kanban view toggle is plain navigation** (`aria-current`, no fake `tablist`).
- **Staff reply dropzone** uses `label for=` + live `role="status"` file count.
- **Kanban card accessible name includes priority/category**.
- **Deleting a survivor also deletes merge shells** pointing at it (locks survivor+shells ascending; closes dangling `merged_into_id` / broken `getActiveTicket` hops). Analyze reports `merged_shells`.
- **Staff show/edit assignee lists no longer fall back to all Nextcloud users** (aligned with API allowlist).
- **Ticket list keyboard/AT:** real title `<a>` (staff + portal); rows no longer get `tabindex` / Enter-Space as widgets (click-to-open remains progressive enhancement).
- **Project cascade locks merge shells** (including cross-project shells) before nested ticket delete — no downward `TicketWorkflowLock` expansion mid-TX; `findByProjectId(..., 0)` for full sets.
- **`removeLink?link_id=` is bound to the route ticket** (`removeLinkForTicket` — link must involve that ticket).
- **Dangling merge-shell GC:** repair step `CleanupDanglingMergeShells` + hourly SLA monitor purge for historical `merged_into_id` orphans.
- **Customers/projects lists** use real title links (same a11y pattern as tickets).

### Fixed

- **Ticket edit status was silently ignored:** `updateTicket` now applies status (with `closed_at` sync) instead of dropping the field from edit forms.
- Ticket number unique-constraint collisions retry with a fresh number (nested savepoints when an outer TX is open).
- Mutating a merged ticket (status/assign/update/attach) is rejected; inbound email still follows the merge chain after sender validation on the survivor.
- Escalation priority changes recalculate SLA deadlines like manual updates.
- Kanban: keyboard-accessible status select (WCAG equivalent to drag-and-drop), focus restore on dialogs, busy-state on guest submit buttons.
- **Disclosure toggles exposed to assistive tech (WCAG 4.1.2):** Chromium demotes `<summary>` styled with flex display to a generic role; all `details > summary` toggles (ticket/portal filters, dashboard quick start) now get an explicit button role with `aria-expanded` synced on toggle (`common/nav.js`, loaded on every page).
- E2E: tickets-list a11y spec targets the app's own skip link (NC ≥ 34 header adds a second one); portal mobile spec asserts real toggle/title box intersection instead of assuming a side-by-side layout.
- Ticket detail actions: clear groups (edit / split-merge / delete), icon-only remove controls, touch-sized mobile buttons, theme-safe header shadow.
- **Ticket delete orphan rows:** deleting a ticket now removes attachment rows, links, watchers, and surveys (no FK cascade previously left orphans).
- **Ticket merge relations:** watchers, links, and surveys transfer to the survivor (duplicates / self-loops dropped safely).
- Search picker focus ring uses the shared WCAG-visible focus token.
- **ProjectMemberController** logs via injected `LoggerInterface` (no `\OC::$server` lookup).
- Portal home: visible Quick Actions heading + lead; help tip is an labelled `aside` with decorative icon hidden from AT; primary CTA description keeps solid primary-text (AA contrast).
- Section rhythm: clearer spacing and subtle dividers between major page sections.
- Portal survey stars use `role="radio"` + `aria-checked` inside a labelled radiogroup (WCAG 4.1.2).

### Changed

- Version aligned to **2.2.14** (`info.xml` + `appinfo/version`) so the survey unique-index migration runs on upgrade.
- Portal satisfaction survey: radiogroup semantics, sticky touch targets, clearer card emphasis; bulk-action bar sticky and visually distinct.

### Added

- Shared `TicketWorkflowLock` for merge/split/comment/attach/link/watcher/delete (ordered exclusive locks, deadlock-safe, request-local re-entrancy); exclusive-key helper for guest rate gates.
- Shared `TicketRelationService` for delete purge + merge transfer of links/watchers/surveys.
- `TicketService` FQCN DI alias (same pattern as PermissionService) so constructor injection resolves reliably.
- Settings note on hardening inbound webhooks with token + signing secret.
- Ticket numbers use `random_int()` instead of `rand()` for stronger uniqueness under concurrency.

## [2.2.12] - 2026-06-12

### Fixed

- **Ticket detail quick actions:** Assignee change no longer throws a DOM `insertBefore` error; success feedback inserts into the correct card body. Quick-action fields (status, priority, assignee) align in one row.
- **Forms:** Safer DOM insertion for guest ticket validation/draft banners; project combobox no longer duplicates static help text.

## [2.2.11] - 2026-06-12

### Fixed

- **Data loss after Nextcloud upgrade:** `UninstallDropTables` preserves tables and settings on disable; full cleanup runs only on app removal.

## [2.2.10] - 2026-06-04

### Fixed

- **SLA breach alert spam**: The hourly SLA monitor now de-duplicates breach notifications. Each response/resolution breach is alerted at most once per cooldown window (default 24h) instead of on every hourly run. The cooldown is configurable via `occ config:app:set ticketcheck sla_alert_cooldown_hours --value=<hours>`.
- Alert timestamps are persisted only after the notification is actually delivered, so tickets whose alert could not be sent (no recipient / email failure) are retried on the next run.
- SLA alert timestamps are cleared when SLA due dates are recalculated (e.g. priority change), so a new breach cycle can alert promptly instead of being blocked by a stale cooldown.

### Added

- New nullable `sla_response_alerted_at` / `sla_resolution_alerted_at` columns on `helpdesk_tickets` (migration `Version4206`) to track when breach alerts were last sent.
- PHPUnit coverage for cooldown suppression, post-delivery timestamp stamping, and the "do not stamp on delivery failure" contract.

## [2.2.8] - 2026-06-04

### Fixed

- **SLA monitor job**: Load all non-done tickets (including legacy statuses such as `open`, `waiting`, `working`) via `TicketMapper::findOpenForSlaMonitoring()` instead of a fixed status whitelist; skip merged tickets. Ensures cron SLA monitoring works after deploy even when the database still stores pre-migration status values.
- **Daily digest job**: Uses `TicketMapper::findOpenTickets()` and shared `TicketMapper::isDoneStatus()` so legacy open statuses are included and done aliases are excluded consistently.
- **Cron regression tests**: PHPUnit guards for removed `firstResponseAt`, SLA `run()` smoke tests, and open/done status contracts.

### Added
- **Linked tickets**: Explicit "blocks" / "related to" links between tickets for dependencies; add/remove links in agent portal; read-only display in guest portal
- **CC / watchers**: Add observers who receive updates without being assignees; watchers are notified on ticket update, status change, and new comment
- **Ticket splitting**: Split one ticket into several when it covers multiple issues; agent-only action in ticket detail view

## [2.2.7] - 2026-06-01

### Fixed

- **SLA monitor job**: Sends real breach/warning emails via `EmailService` (no log-only stub). Staff first-response detection uses agent/admin groups only; resolution SLA near-breach alerts included; duplicate tickets deduplicated per run.
- **Guest passwords**: `GuestPasswordPolicyService` validates against Nextcloud `password_policy` via `ValidatePasswordPolicyEvent`; guest create/reset retry when the server rejects a candidate password.

## [2.2.6] - 2026-06-01

### Fixed

- **SLA monitor job**: Detect first staff response via public comments instead of removed `firstResponseAt` entity field, fixing cron failures (`firstResponseAt is not a valid attribute`).
- **Guest user creation**: Generate passwords with `GuestPasswordPolicyService::generateCompliantPassword()` so auto-created guest accounts satisfy Nextcloud `password_policy` (e.g. numeric character requirement).

Deploy with a **new** `appinfo` version so Nextcloud runs the app update; re-tarring the same `2.2.5` bundle will not apply these fixes.

## [2.2.5] - 2026-05-26

### Fixed

- **Activity (NC 33+)**: Activity provider throws `UnknownActivityException` for foreign apps and unknown subjects instead of `InvalidArgumentException`, removing deprecation noise in `nextcloud.log` when the activity or notifications API polls other apps' events (e.g. Deck).

## [2.2.4] - 2026-04-25

### Fixed

- **Database (migrations)**: `helpdesk_customers.company` column and index (where missing). `hd_proj_members` `created_at` / `created_by` audit fields with backfill from legacy `added_at` / `added_by` where present.

  Deployments must use a **new** `appinfo` version (not a re-tar of the same `X.Y.Z` as already installed) so Nextcloud runs the app update and applies these migrations.

## [2.2.3] - 2026-04-25

### Release

- Version bump for the upload-only production bundle; aligns `ready4upload/ticketcheck-*-production.tar.gz` with `appinfo` / App Store release naming.

## [2.2.2] - 2026-03-27

### Added

- Public standalone repository metadata: root `LICENSE`, `SECURITY.md`, `.github/FUNDING.yml`; `composer.json` package name aligned with GitHub **`nextcloud-ticketcheck`** for distribution and SaaS-style publishing.

### Fixed

- Database API compatibility (`executeQuery` / `executeStatement` instead of deprecated `QueryBuilder::execute()`).

## [2.2.0] - 2025-03-07

### Added
- **Email threading (Reply-To)**: Configurable inbound email address and Reply-To header with plus-addressing (support+{ticketId}@domain) for ticket-related emails
- **Satisfaction survey**: Post-resolution feedback form for customers (1–5 stars + optional comment) when ticket status is done; stored in `helpdesk_ticket_surveys`
- **Canned responses**: Template selector in ticket detail comment form; insert pre-defined templates with variable substitution (ticket_number, customer_name, etc.)
- **Ticket merge**: Merge duplicate tickets; moves comments and attachments to target ticket, marks source as merged/done; new `merged_into_id` column
- **Escalation rules**: Configurable rules (age, priority, status) that auto-escalate tickets (change priority or assign); background job runs hourly
- **Inbound email webhook**: POST endpoint `/inbound-email/webhook` for SendGrid/Mailgun inbound parse; validates sender, extracts ticket ID from Reply-To or Subject, adds reply as comment; token-protected via `inbound_email_webhook_token` config

### Changed
- Migration `Version1106Date20250307130000`: added `merged_into_id` to tickets; created `helpdesk_ticket_surveys` and `helpdesk_escalation_rules` tables
- Settings UI: inbound email address, inbound email enabled, escalation rules section
- EmailService: optional Reply-To with ticket ID for ticket-related emails when inbound routing is enabled

### Fixed
- German translations for new settings messages

## [2.1.14] - 2025-10-20

### Fixed
- **CRITICAL**: Restored CSS loading functionality that was missing
- Added back `loadAppStyles()` method to properly load CSS files
- Guest users get `helpdesk-guest-unified.css` for portal styling
- Main app users get `helpdesk-main.css` for agent/admin styling
- CSS loading is scoped to helpdesk routes only to prevent interference

### Restored
- `loadAppStyles()` method in Application.php boot process
- Proper CSS file loading based on user type and route context
- Exclusive loading: portal gets portal CSS, main app gets main CSS

## [2.1.13] - 2025-10-20

### Fixed
- **CRITICAL**: Restored CSP functionality with proper scoping to helpdesk routes only
- CSPMiddleware now only applies to `/apps/ticketcheck` and `/index.php/apps/ticketcheck` paths
- Prevents CSP from affecting dashboard and other Nextcloud apps
- Guest portal and main app styling now works correctly with inline styles allowed
- All CSP-related functionality restored: CSPService, CSPMiddleware, CSPTrait

### Restored
- CSPService with proper inline style allowances for guest portal and main app
- CSPMiddleware with path-based scoping to prevent global interference
- CSPTrait for controller CSP configuration
- CSP middleware registration in Application.php

## [2.1.12] - 2025-10-20

### Fixed
- **CRITICAL**: Removed ALL custom CSP code that was causing styling issues
- Deleted CSPMiddleware, CSPService, and CSPTrait entirely
- Nextcloud's built-in CSP system is sufficient; custom CSP was breaking dashboard and other apps
- No more eval errors or font-loading issues on dashboard

### Removed
- CSPMiddleware (was interfering with other apps)
- CSPService (unnecessary, Nextcloud has built-in CSP)
- CSPTrait (unused and problematic)

## [2.1.11] - 2025-10-20

### Fixed
- Main helpdesk app (agents/admins) styling now renders correctly with inline styles allowed in CSP
- CSP scoped to helpdesk routes only; no longer affects dashboard/other apps

## [2.1.10] - 2025-10-20

### Changed
- Customer-facing ticket emails are now sent only to guest users with explicit project access
- Internal project members (non-guests) are notified on new tickets; urgent tickets are highlighted
- Internal members are notified when a guest/customer replies to a ticket

### Fixed
- Service wiring and dependencies for email/validation flows; lints clean

## [2.1.9] - 2025-01-20

### CRITICAL FIX - Version Bump
- **SECURITY**: Version bump to trigger migration execution
- Ensures critical permission fixes are applied to existing installations
- No functional changes, just triggers the migration system

## [2.1.8] - 2025-01-20

### CRITICAL FIX
- **SECURITY**: Fixed critical permission issue where enabling helpdesk locked out regular users
- Removed broken app restriction logic that incorrectly limited files/photos/calendar to only helpdesk groups
- Added migration to automatically restore app access for all affected users
- Guest isolation now properly maintained through request interceptor and middleware only

### Added
- Migration Version1102Date202501200002: Restores app access for all users
- Migration Version1001Date202501200003: Ensures helpdesk groups exist early
- EMERGENCY_FIX.sh script for immediate production fixes
- Comprehensive documentation of the fix and root cause

### Changed
- setupGuestUserSecurity() no longer modifies system-wide app enabled settings
- Guest isolation handled exclusively through boot interceptor, middleware, and quota enforcement

### Fixed
- Regular users can now access files, photos, calendar, and other apps after enabling helpdesk
- Group initialization happens before app boot to prevent race conditions

## [2.1.7] - 2025-01-20

### Added
- Comprehensive responsive design for all screen sizes
- Assigned user display in ticket detail view
- Enhanced accessibility features
- Improved mobile navigation
- Touch-friendly interface elements

### Changed
- Updated guest portal styling to match main helpdesk design
- Improved responsive breakpoints for better mobile experience
- Enhanced translation coverage
- Optimized CSS for better performance

### Fixed
- Hardcoded strings replaced with translation keys
- Mobile layout issues on small screens
- Navigation accessibility improvements
- Form validation and error handling

## [1.0.0] - 2025-01-16

### Added
- **Initial Release**: Complete helpdesk system
- **Multi-tenant Architecture**: Project-based access control
- **Guest Portal**: Secure customer access with isolated styling
- **Email Integration**: Automated notifications and background jobs
- **Knowledge Base**: Rich text editor with categorization
- **File Attachments**: Support for various file types with image previews
- **Visual Charts and Reporting**: Dashboard analytics and performance metrics
- **CSV/PDF Export**: Data export capabilities for reporting
- **SLA Monitoring**: Automated breach alerts and escalation
- **Daily Digest Emails**: Agent notifications and summaries
- **Responsive Design**: Full mobile and tablet support
- **Accessibility**: WCAG 2.1 AA compliance with high contrast and reduced motion support
- **Translations**: English and German support
- **Security**: Rate limiting and access control
- **Performance**: Optimized database queries and caching

### Features
- **Ticket Management**: Complete ticket lifecycle management
- **Project Management**: Multi-tenant project organization
- **Customer Management**: Integrated CRM functionality
- **Guest Portal**: Secure customer access portal
- **Knowledge Base**: Rich content management system
- **Email Notifications**: Automated email system
- **Dashboard**: Comprehensive analytics and reporting
- **File Handling**: Advanced attachment system
- **User Management**: Role-based access control
- **API**: RESTful API for integration

### Technical
- **Database**: MySQL/PostgreSQL support
- **PHP**: 8.1+ compatibility
- **Nextcloud**: 25+ integration
- **Security**: CSRF, XSS, SQL injection protection
- **Performance**: Optimized queries and caching
- **Responsive**: Mobile-first design approach
- **Accessibility**: WCAG 2.1 AA compliance

### Documentation
- **README**: Comprehensive documentation
- **API**: REST API documentation
- **Installation**: Step-by-step setup guide
- **Configuration**: Admin and user settings
- **Usage**: User guides for all roles
- **Development**: Developer documentation
- **Contributing**: Contribution guidelines

### License
- **AGPL-3.0-or-later**: Open source license
- **Copyright**: 2025-2026 Lara Raffel, Alexander Mäule, Hauke Klünder, and Nextcloud contributors

---

## Version History

### Version 1.0.0 (2025-01-16)
- **Initial Release**: Complete helpdesk system with all core features
- **Multi-tenant Architecture**: Project-based access control
- **Guest Portal**: Secure customer access with isolated styling
- **Email Integration**: Automated notifications and background jobs
- **Knowledge Base**: Rich text editor with categorization
- **File Attachments**: Support for various file types
- **Responsive Design**: Full mobile and tablet support
- **Accessibility**: WCAG 2.1 AA compliance
- **Translations**: English and German support
- **SLA Monitoring**: Automated breach alerts and escalation
- **Dashboard Analytics**: Visual charts and reporting
- **Export Features**: CSV/PDF export capabilities
- **Security**: Rate limiting and access control
- **Performance**: Optimized database queries and caching

---

## Development Notes

### Breaking Changes
- None in version 1.0.0

### Deprecations
- None in version 1.0.0

### Security
- All user inputs are validated and sanitized
- CSRF protection implemented
- XSS prevention through output encoding
- SQL injection prevention via prepared statements
- Rate limiting for API and form submissions
- Guest access restricted to helpdesk only

### Performance
- Database queries optimized with proper indexing
- Template caching for improved performance
- CSS and JavaScript minification
- Lazy loading for non-critical resources
- Efficient file handling and storage

### Accessibility
- WCAG 2.1 AA compliance
- High contrast mode support
- Reduced motion support
- Keyboard navigation
- Screen reader compatibility
- Touch-friendly interface elements

### Browser Support
- **Desktop**: Chrome 90+, Firefox 88+, Safari 14+, Edge 90+
- **Mobile**: iOS Safari 14+, Chrome Mobile 90+, Firefox Mobile 88+
- **Tablet**: iPad Safari 14+, Chrome Tablet 90+, Firefox Tablet 88+

### Database Support
- **MySQL**: 5.7.0 or higher
- **MariaDB**: 10.3.0 or higher
- **PostgreSQL**: 12.0 or higher

### PHP Support
- **PHP**: 8.1.0 or higher
- **Extensions**: PDO, JSON, XML, cURL, GD (optional)

### Nextcloud Support
- **Nextcloud**: 25.0.0 or higher
- **Platforms**: Linux, macOS, Windows
- **Architecture**: x86_64, ARM64

---

## Contributing

### Development Setup
1. Fork the repository
2. Clone your fork
3. Create a feature branch
4. Make your changes
5. Test thoroughly
6. Submit a pull request

### Code Standards
- **PHP**: PSR-12 coding standard
- **JavaScript**: ESLint configuration
- **CSS**: BEM methodology
- **HTML**: Semantic markup
- **Accessibility**: WCAG 2.1 AA compliance

### Testing
- **Unit Tests**: PHPUnit for backend
- **Integration Tests**: API endpoints
- **UI Tests**: Manual testing across devices
- **Accessibility Tests**: Screen reader and keyboard navigation
- **Performance Tests**: Load and stress testing

### Documentation
- **Code Comments**: Comprehensive inline documentation
- **API Documentation**: OpenAPI/Swagger specifications
- **User Guides**: Step-by-step instructions
- **Developer Docs**: Technical implementation details
- **Changelog**: Detailed change tracking

---

## Support

### Getting Help
- **Documentation**: Check README.md and inline docs
- **Issues**: Report on GitHub Issues
- **Community**: Nextcloud community forums
- **Contact**: GitHub Issues

### Reporting Issues
- **Bug Reports**: Use GitHub Issues template
- **Feature Requests**: Submit via GitHub Issues
- **Security Issues**: Contact directly via email
- **Documentation**: Submit pull requests

### Contributing
- **Code**: Submit pull requests
- **Documentation**: Improve existing docs
- **Translations**: Add new languages
- **Testing**: Report bugs and issues
- **Feedback**: Share your experience

---

**Copyright © 2025-2026 Lara Raffel, Alexander Mäule, Hauke Klünder**  
**Copyright © 2025 Nextcloud GmbH and Nextcloud contributors**  
**Licensed under AGPL-3.0-or-later**

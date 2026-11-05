# Copied sources

Security-relevant auth logic in this app was ported from **BookingCheck**
(`nextcloud/apps/bookingcheck`) at commit `31c2669e70` — the same passwordless
portal stack that survived the BookingCheck security audit. Behavioural parity
is intentional; deviations are noted below and in the feature spec
(`passwordless-guests-2026-11`).

| TicketCheck file | BookingCheck source | Notes |
|---|---|---|
| `lib/Service/PortalOtpService.php` | `lib/Service/OtpService.php` | Same shape: 6-digit `random_int` code, SHA-256 at rest, supersede-on-issue, SQL-fenced attempt increment before compare, CAS consume. Adds `magic_hash` + `purpose` columns. |
| `lib/Service/PortalSessionService.php` | `lib/Service/PortalSessionService.php` | 256-bit bearer, hash at rest, sliding expiry bounded by absolute cap, revoke support. Adds `csrf_hash` (double-submit) + `ua_short`. |
| `lib/Service/TokenService.php` | `lib/Service/TokenService.php` | URL-safe base64 tokens, `hash_equals` verify. |
| `lib/Middleware/PortalAuthMiddleware.php` | `lib/Middleware/PortalSessionMiddleware.php` | Exact/prefix route gates only — never substring. Adds NC-guest door (S3) + refused/explanation handling. |
| `lib/Service/GuestMailOutboxService.php` | `lib/Service/EmailService.php` + `EmailQueueJob` | Same enqueue/claim/lease/retry loop. Adds idempotency key, template renderer, pending cap with OTP exemption. |
| `lib/BackgroundJob/PortalMailOutboxJob.php` | `lib/BackgroundJob/EmailQueueJob.php` | TimedJob drain; OTP also drains via post-response hook (login latency). |

Deliberate deviations:

- TicketCheck has no `UpgradeBackupService::LOCK_KEY` on this release line —
  the email-queue restore lock was dropped; rows are claimed atomically.
- Actor model differs: BookingCheck keys by e-mail, TicketCheck by
  `helpdesk_guest_identity.id` (`portal:<id>` actor strings), with a
  legacy-uid alias table for the staged migration.
- Magic links are bundled with the OTP row (single-use, hashed) and require
  a POST confirmation — GET renders a confirm page so mail scanners can't
  consume tokens.

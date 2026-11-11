<?php

declare(strict_types=1);

/**
 * TicketCheck transfer manifest — every appconfig key and every
 * helpdesk_-, hd_- and tc_-prefixed table classified exactly once
 * (gate: scripts/check-config-transfer-manifest.py).
 */

namespace OCA\Ticketcheck\ConfigTransfer;

final class TransferManifest extends AbstractTransferManifest
{
	private const SCHEMA_VERSION = 1;

	public function schemaVersion(): int
	{
		return self::SCHEMA_VERSION;
	}

	/** @return array<string,array> */
	public function keys(): array
	{
		return self::normaliseKeys([
			// ── access door / admin policy — app-local appconfig (no
			// platform restriction mirror → no mirrored keys; the door
			// fails closed on empty scopes, so there is no cross-key
			// invariant that would need an atomic writer) ──
			'access_restriction_enabled' => ['class' => 'plain', 'security' => true, 'section' => 'access'],
			'access_allowed_user_ids' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			'app_admin_user_ids' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			'app_access_helpdesk_admins' => ['class' => 'plain', 'security' => true, 'section' => 'access'],
			'app_access_helpdesk_agents' => ['class' => 'plain', 'security' => true, 'section' => 'access'],
			'app_access_helpdesk_customers' => ['class' => 'plain', 'security' => true, 'section' => 'access'],
			'app_access_helpdesk_requesters' => ['class' => 'plain', 'security' => true, 'section' => 'access'],
			'app_access_requester_all_users' => ['class' => 'plain', 'security' => true, 'section' => 'access'],
			'app_access_requester_project_access' => ['class' => 'plain', 'security' => true, 'section' => 'access'],
			'app_access_requester_groups' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'gid_list_json'], 'section' => 'access'],
			// CSV gid list (not JSON) — the app's own storage format;
			// transfers verbatim like every other plain key.
			'app_access_extra_groups' => ['class' => 'plain', 'security' => true, 'section' => 'access'],
			// ── guest portal policy (Settings → access; identities/
			// sessions stay instance-local) ──
			'guest_provisioning' => ['class' => 'plain', 'security' => true, 'section' => 'access'],
			'nc_guest_portal_login' => ['class' => 'plain', 'security' => true, 'section' => 'access'],
			'guest_nc_fallback' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			'guest_otp_ttl_min' => ['class' => 'plain', 'section' => 'access'],
			'guest_portal_sess_days' => ['class' => 'plain', 'section' => 'access'],
			// ── email + digest knobs (Settings → Email) ──
			'email_from_name' => ['class' => 'plain', 'section' => 'email'],
			'email_notifications_enabled' => ['class' => 'plain', 'section' => 'email'],
			'daily_digest_enabled' => ['class' => 'plain', 'section' => 'email'],
			'weekly_digest_enabled' => ['class' => 'plain', 'section' => 'email'],
			'max_attachment_size' => ['class' => 'plain', 'section' => 'email'],
			// ── knowledge base toggles (Settings → Knowledge base) ──
			'kb_enabled' => ['class' => 'plain', 'section' => 'knowledge-base'],
			'kb_portal_enabled' => ['class' => 'plain', 'section' => 'knowledge-base'],
			'kb_feedback_enabled' => ['class' => 'plain', 'section' => 'knowledge-base'],
			'kb_comments_enabled' => ['class' => 'plain', 'section' => 'knowledge-base'],
			// ── ticket catalogue + SLA (Settings → Categories / SLA) ──
			'default_category' => ['class' => 'plain', 'section' => 'categories'],
			'sla_targets' => ['class' => 'plain', 'section' => 'sla'],
			'sla_timezone' => ['class' => 'plain', 'section' => 'sla'],
			'sla_business_hours_enabled' => ['class' => 'plain', 'section' => 'sla'],
			'sla_business_start' => ['class' => 'plain', 'section' => 'sla'],
			'sla_business_end' => ['class' => 'plain', 'section' => 'sla'],
			'sla_business_days' => ['class' => 'plain', 'section' => 'sla'],
			'sla_holidays' => ['class' => 'plain', 'section' => 'sla'],
			'sla_alert_cooldown_hours' => ['class' => 'plain', 'section' => 'sla'],
			// ── misc ──
			'default_locale' => ['class' => 'plain', 'section' => 'about'],
			'companion_push_enabled' => ['class' => 'plain', 'section' => 'about'],
			// ── inbound-mail behavioural policy (transfers); connection
			// parameters stay instance-bound (env below) ──
			'inbound_imap_process_backlog' => ['class' => 'plain', 'section' => 'inbound-mail'],
			'inbound_imap_require_reply_token' => ['class' => 'plain', 'section' => 'inbound-mail'],
			'inbound_imap_dsgvo_ack' => ['class' => 'plain', 'section' => 'inbound-mail'],
			'inbound_imap_unknown_sender_policy' => ['class' => 'plain', 'section' => 'inbound-mail'],
			// ── secrets — redacted inside the encrypted container ──
			'inbound_email_webhook_signing_secret' => ['class' => 'secret', 'section' => 'email'],
			'inbound_email_webhook_token' => ['class' => 'secret', 'section' => 'email'],
			'inbound_imap_secret_enc' => ['class' => 'secret', 'section' => 'inbound-mail'],
			'inbound_imap_smtp_secret_enc' => ['class' => 'secret', 'section' => 'inbound-mail'],
			'inbound_imap_oauth_access_enc' => ['class' => 'secret', 'section' => 'inbound-mail'],
			'inbound_imap_oauth_refresh_enc' => ['class' => 'secret', 'section' => 'inbound-mail'],
			'expo_push_access_token' => ['class' => 'secret', 'section' => 'about'],
			// Legacy guest PIN store — credential material, never leaves.
			'portal_pins' => ['class' => 'secret', 'section' => 'access'],
			// ── env — instance-bound integrations: prod sets its own
			// webhook/IMAP connection, Expo push and reply addresses ──
			'inbound_email_enabled' => ['class' => 'env', 'section' => 'email'],
			'inbound_email_address' => ['class' => 'env', 'section' => 'email'],
			'inbound_email_webhook_provider' => ['class' => 'env', 'section' => 'email'],
			'inbound_email_webhook_public_key' => ['class' => 'env', 'section' => 'email'],
			'inbound_imap_enabled' => ['class' => 'env', 'section' => 'inbound-mail'],
			'inbound_imap_address' => ['class' => 'env', 'section' => 'inbound-mail'],
			'inbound_imap_auth_mode' => ['class' => 'env', 'section' => 'inbound-mail'],
			'inbound_imap_oauth_provider' => ['class' => 'env', 'section' => 'inbound-mail'],
			'inbound_imap_host' => ['class' => 'env', 'section' => 'inbound-mail'],
			'inbound_imap_port' => ['class' => 'env', 'section' => 'inbound-mail'],
			'inbound_imap_username' => ['class' => 'env', 'section' => 'inbound-mail'],
			'inbound_imap_folder' => ['class' => 'env', 'section' => 'inbound-mail'],
			'inbound_imap_enc' => ['class' => 'env', 'section' => 'inbound-mail'],
			'inbound_imap_allow_private_host' => ['class' => 'env', 'section' => 'inbound-mail'],
			'inbound_imap_allow_self_signed' => ['class' => 'env', 'section' => 'inbound-mail'],
			'inbound_imap_send_via_smtp' => ['class' => 'env', 'section' => 'inbound-mail'],
			'inbound_imap_smtp_host' => ['class' => 'env', 'section' => 'inbound-mail'],
			'inbound_imap_smtp_port' => ['class' => 'env', 'section' => 'inbound-mail'],
			'inbound_imap_smtp_username' => ['class' => 'env', 'section' => 'inbound-mail'],
			'inbound_imap_smtp_from' => ['class' => 'env', 'section' => 'inbound-mail'],
			'inbound_imap_smtp_enc' => ['class' => 'env', 'section' => 'inbound-mail'],
			// Routing map embeds helpdesk_projects ids — unresolvable
			// across instances even though projects transfer.
			'inbound_imap_routing_json' => ['class' => 'env', 'section' => 'inbound-mail'],
			// ── internal bookkeeping — never transferred ──
			'enabled' => 'internal',
			'installed_version' => 'internal',
			'guest_s5_gate' => 'internal',
			'guest_created_at' => 'internal',
			'guest_mig_dryrun_at' => 'internal',
			'helpdesk_groups_ensured' => 'internal',
			'helpdesk_requester_group_ensured' => 'internal',
			'uninstall_repair_pass' => 'internal',
			'daily_digest_last_sent_day' => 'internal',
			'weekly_digest_last_sent_week' => 'internal',
			'inbound_imap_baseline_uid' => 'internal',
			'inbound_imap_last_uid' => 'internal',
			'inbound_imap_uidvalidity' => 'internal',
			'inbound_imap_last_sync_at' => 'internal',
			'inbound_imap_last_error' => 'internal',
			'inbound_imap_error_count' => 'internal',
			'inbound_imap_auth_fail_count' => 'internal',
			'inbound_imap_probe_attempts' => 'internal',
			'inbound_imap_probe_window_start' => 'internal',
			'inbound_imap_ignored_cap_count' => 'internal',
			'inbound_imap_needs_reauth' => 'internal',
			'inbound_imap_test_passed_at' => 'internal',
			'inbound_imap_test_passed_hash' => 'internal',
			'inbound_imap_oauth_expires_at' => 'internal',
			'upgrade_backup_last_snapshot_id' => 'internal',
			'upgrade_backup_max_snapshots' => 'internal',
			'transfer_receipts' => 'internal',
			'transfer_job_state' => 'internal',
			'transfer_last_import_at' => 'internal',
			// Dynamic/prefix key families — prefixes, not keys; members are
			// dynamic and never transferred by definition:
			//   'ticketcheck/ticket/<id>'   TicketWorkflowLock per-ticket flags
			//   'ticketcheck/<job>'         background-job lock keys
			//   'upgrade_backup_*'          snapshot-restore rewrites
		]);
	}

	/** @return array<string,array> */
	public function tables(): array
	{
		return self::normaliseTables([
			// ══ catalogue — admin-managed structure ══
			'helpdesk_ticket_categories' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['cat_key']],
			'helpdesk_ticket_resources' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['res_key']],
			'helpdesk_kb_categories' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name']],
			'helpdesk_escalation_rules' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name']],
			'helpdesk_ticket_templates' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name', 'category']],
			// Assignment routing rules: project_id/category are nullable —
			// no honest natural key → seed-if-empty.
			'helpdesk_assignments' => ['class' => 'catalog', 'strategy' => 'seed-if-empty',
				'fk' => ['project_id' => 'helpdesk_projects'], 'depends_on' => ['helpdesk_projects'],
				'uid_ref' => ['assigned_user_id']],
			// Intake whitelist rows carry nullable customer/project fks —
			// seed-if-empty for the same reason.
			'helpdesk_whitelist' => ['class' => 'catalog', 'strategy' => 'seed-if-empty',
				'fk' => [
					'customer_id' => 'helpdesk_customers',
					'project_id' => 'helpdesk_projects',
				],
				'depends_on' => ['helpdesk_customers', 'helpdesk_projects']],

			// ══ customer + project book ══
			// created_by carries verbatim (provenance degrades a view,
			// never sinks a row — customercheck precedent).
			'helpdesk_customers' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name']],
			'helpdesk_projects' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name', 'created_at'],
				'fk' => ['customer_id' => 'helpdesk_customers'], 'depends_on' => ['helpdesk_customers']],

			// ══ tickets ══
			// created_by mixes NC uids with markers ('inbound-email',
			// 'system', 'portal:<id>') → provenance, carried verbatim.
			// assigned_to is a real nullable uid → uid_ref_nullable.
			// merged_into_id self-FK resolves post-load (fk_late).
			// sla_*_alerted_at is per-instance job bookkeeping → env-dropped.
			'helpdesk_tickets' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['ticket_number'],
				'fk' => [
					'customer_id' => 'helpdesk_customers',
					'project_id' => 'helpdesk_projects',
				],
				'fk_late' => ['merged_into_id' => 'helpdesk_tickets'],
				'depends_on' => ['helpdesk_customers', 'helpdesk_projects'],
				'uid_ref_nullable' => ['assigned_to'],
				'env_columns' => ['sla_response_alerted_at', 'sla_resolution_alerted_at']],
			// Comments have no honest natural key (legitimate duplicates
			// exist) — spec default seed-if-empty on merge.
			'helpdesk_comments' => ['class' => 'business', 'strategy' => 'seed-if-empty',
				'fk' => ['ticket_id' => 'helpdesk_tickets'], 'depends_on' => ['helpdesk_tickets'],
				'uid_ref_nullable' => ['user_id']],
			'helpdesk_ticket_links' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['ticket_id', 'linked_ticket_id', 'link_type'],
				'fk' => [
					'ticket_id' => 'helpdesk_tickets',
					'linked_ticket_id' => 'helpdesk_tickets',
				],
				'depends_on' => ['helpdesk_tickets']],
			'helpdesk_ticket_res_map' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['ticket_id', 'res_key'],
				'fk' => ['ticket_id' => 'helpdesk_tickets'], 'depends_on' => ['helpdesk_tickets']],
			'helpdesk_ticket_surveys' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['ticket_id'],
				'fk' => ['ticket_id' => 'helpdesk_tickets'], 'depends_on' => ['helpdesk_tickets']],
			'helpdesk_ticket_watchers' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['ticket_id', 'user_id'],
				'fk' => ['ticket_id' => 'helpdesk_tickets'], 'depends_on' => ['helpdesk_tickets'],
				'uid_ref' => ['user_id']],

			// ══ knowledge base content ══
			// kb_articles.category is a varchar label/key, not an id — no fk.
			'helpdesk_kb_articles' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['title', 'category']],
			'helpdesk_kb_comments' => ['class' => 'business', 'strategy' => 'seed-if-empty',
				'fk' => ['article_id' => 'helpdesk_kb_articles'], 'depends_on' => ['helpdesk_kb_articles'],
				'uid_ref_nullable' => ['user_id']],
			'hd_kb_helpful' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['article_id', 'user_id'],
				'fk' => ['article_id' => 'helpdesk_kb_articles'], 'depends_on' => ['helpdesk_kb_articles'],
				'uid_ref' => ['user_id']],
			'hd_proj_members' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['project_id', 'user_id'],
				'fk' => ['project_id' => 'helpdesk_projects'], 'depends_on' => ['helpdesk_projects'],
				'uid_ref' => ['user_id']],

			// ══ internal / env / log — NEVER transferred ══
			// Attachment metadata points at files under
			// <datadirectory>/helpdesk_attachments/ — NOT an appdata folder,
			// so the blob mechanism cannot carry them; a metadata-only row
			// would leave a NOT NULL file_path pointing at a file that does
			// not exist on the target (crm_deal_cl_file precedent).
			'helpdesk_attachments' => ['class' => 'internal'],
			// Guest portal subsystem — identities, sessions, OTPs, devices,
			// PIN aliases and the project-access ledger are all bound to
			// this instance's portal auth; helpdesk_guest_access addresses
			// identities through 'portal:<id>' actor strings the id remapper
			// cannot rewrite, so no guest table is transferable.
			'helpdesk_guest_identity' => ['class' => 'internal'],
			'helpdesk_guest_access' => ['class' => 'internal'],
			// Pre-rename leftover — Version1082 drops it on sight; only
			// tableExists/dropTable ever touch it. Never transferable.
			'helpdesk_guest_project_access' => ['class' => 'internal'],
			'helpdesk_guest_alias' => ['class' => 'internal'],
			'helpdesk_guest_audit' => ['class' => 'internal'],
			'helpdesk_guest_device' => ['class' => 'internal'],
			'helpdesk_guest_miglog' => ['class' => 'internal'],
			'helpdesk_guest_prefs' => ['class' => 'internal'],
			'helpdesk_portal_otp' => ['class' => 'internal'],
			'helpdesk_portal_session' => ['class' => 'internal'],
			// Inbound IMAP processing ledger + outbound mail queue —
			// per-instance sync/queue state.
			'helpdesk_inbound_msgs' => ['class' => 'internal'],
			'helpdesk_mail_outbox' => ['class' => 'internal'],
			// Idempotency + license + mobile-seat rows are bound to this
			// instance's requests, vendor signature and device pairing.
			'tc_idempotency' => ['class' => 'internal'],
			'tc_license_state' => ['class' => 'internal'],
			'tc_mobile_seats' => ['class' => 'internal'],
		]);
	}
}

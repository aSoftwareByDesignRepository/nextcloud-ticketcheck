// @ts-check
/**
 * ATLAS inbound-mail ingest proofs — the "email collector shows code /
 * mojibake / MIME soup" bug class (issue #11) driven end-to-end:
 *
 *  IMAP leg:    a fake IMAP server (e2e/fixtures/atlas-imap-fake.php)
 *               inside the nextcloud container serves two canned mails
 *               → save+enable via the real settings API
 *               → `occ background-job:execute InboundMailSyncJob`
 *               → ticket bodies asserted via the real tickets API.
 *
 *  Webhook leg: signed POST to /inbound-email/webhook with an
 *               HTML-only payload → ticket body asserted clean.
 *
 * Mailbox JSON carries unique Message-IDs per run (dedupe-proof) and
 * process_backlog=1 makes the enable baseline deterministic (uid 0).
 */
const { test, expect } = require('@playwright/test');
const { ensureAuthenticated } = require('./helpers/auth-guard');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const COMPOSE_DIR = path.resolve(__dirname, '..', '..', '..');
const FAKE_PORT = 10143;
const FAKE_REMOTE = '/tmp/atlas-imap-fake.php';
const MBOX_REMOTE = '/tmp/atlas-mbox.json';
const INBOUND_ADDR = 'inbound@atlas.invalid';
const RUN_TAG = `atlas${Date.now().toString(36)}`;

const SYNC_JOB_CLASS = 'OCA\\Ticketcheck\\BackgroundJob\\InboundMailSyncJob';

const state = { ticketIds: [], webhookKeys: {}, imapConfigured: false };

function dcExec(args, input) {
	return execFileSync('docker', ['compose', 'exec', '-T', ...args], {
		cwd: COMPOSE_DIR,
		input,
		encoding: 'utf8',
		timeout: 60_000,
	});
}

function occ(...args) {
	return dcExec(['-u', 'www-data', 'nextcloud', 'php', 'occ', ...args]);
}

function occJson(...args) {
	const out = occ(...args);
	try {
		return JSON.parse(out);
	} catch {
		return null;
	}
}

function pushFile(localPath, remotePath) {
	dcExec(['nextcloud', 'sh', '-c', `cat > ${remotePath}`], fs.readFileSync(localPath));
}

function killFake() {
	try {
		dcExec(['nextcloud', 'sh', '-c',
			`kill "$(cat ${MBOX_REMOTE}.pid 2>/dev/null)" 2>/dev/null; rm -f ${MBOX_REMOTE} ${MBOX_REMOTE}.pid ${FAKE_REMOTE}`]);
	} catch { /* best-effort cleanup */ }
}

async function api(page, method, url, requesttoken, form) {
	const fn = method === 'del' ? 'delete' : method;
	const opts = { headers: { requesttoken } };
	if (form !== undefined) {
		opts.form = form;
	}
	const res = await page.request[fn](url, opts);
	return { status: res.status(), body: await res.json().catch(() => null) };
}

test.describe.serial('TicketCheck inbound mail ingest', () => {
	test.skip(!fs.existsSync(path.join(COMPOSE_DIR, 'docker-compose.yml')), 'nextcloud compose stack not found');

	test.beforeAll(() => {
		try {
			execFileSync('docker', ['compose', 'ps', 'nextcloud'], { cwd: COMPOSE_DIR, encoding: 'utf8', timeout: 30_000 });
		} catch (e) {
			test.skip(true, 'docker compose stack not reachable: ' + String(e).slice(0, 200));
		}
	});

	test.afterAll(async () => {
		killFake();
		// Restore the disabled baseline so fixture state cannot leak into
		// later specs (same hygiene rule as settings-inbound-mail). occ so it
		// also fires when the test threw before its own disable step — a
		// leftover enabled+dead-fake config would feed the cron backoff.
		try {
			occ('config:app:set', 'ticketcheck', 'inbound_imap_enabled', '--value=no');
			occ('config:app:delete', 'ticketcheck', 'inbound_email_enabled');
			occ('config:app:delete', 'ticketcheck', 'inbound_email_address');
			occ('config:app:delete', 'ticketcheck', 'inbound_email_webhook_token');
			occ('config:app:delete', 'ticketcheck', 'inbound_email_webhook_signing_secret');
			for (const [k, v] of Object.entries(state.webhookKeys)) {
				occ('config:app:set', 'ticketcheck', k, `--value=${v}`);
			}
		} catch { /* best-effort */ }
	});

	test('imap collector: multipart mail lands as readable text, never MIME soup', async ({ page }, testInfo) => {
		// Shared state: one fake-IMAP port + a single global mailbox config +
		// the enabled flag — run the proof on one project only.
		test.skip(testInfo.project.name !== 'chromium-1280', 'fake-imap port + mailbox config are shared — single project only');
		test.setTimeout(180_000);
		await page.goto('/apps/ticketcheck/settings/inbound-mail', { waitUntil: 'domcontentloaded' });
		await ensureAuthenticated(page);
		const requesttoken = await page.evaluate(() => document.head?.dataset?.requesttoken ?? '');
		expect(requesttoken).not.toBe('');

		// Disable first: a leftover enabled mailbox (interrupted run) would
		// make the enable baseline non-deterministic.
		await api(page, 'post', '/index.php/apps/ticketcheck/api/settings/inbound-imap/disable', requesttoken);

		// --- Fixture messages ------------------------------------------------
		const b64 = (s) => Buffer.from(s, 'latin1').toString('base64');
		const uidval = 1700000 + (Date.now() % 100000);

		// Msg 1: multipart/mixed — windows-1250 HTML body + txt attachment.
		// CP1250: =FC→ü  =A3=F3d=9F→Łódź  (mbstring lacks cp1250 → iconv path)
		const html1 =
			'<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=windows-1250">' +
			'<style>p{color:red;font-family:Arial}</style><script>alert("xss")</script></head><body>' +
			'<p>Hallo Team,</p><p>bitte pr=FCfen Sie das Angebot f=FCr =A3=F3d=9F.</p>' +
			'<ul><li>Position eins</li><li>Position zwei</li></ul></body></html>';
		const attachRaw = 'Attachment atlas body\n';
		const attachB64 = Buffer.from(attachRaw, 'latin1').toString('base64');
		const bs1 = '(("TEXT" "HTML" ("CHARSET" "windows-1250") NIL NIL "QUOTED-PRINTABLE" '
			+ html1.length + ' 12)("APPLICATION" "OCTET-STREAM" ("NAME" "angebot.txt") NIL NIL "BASE64" '
			+ attachB64.length + ' 1 NIL ("ATTACHMENT" ("FILENAME" "angebot.txt")) NIL NIL)"MIXED")';

		// Msg 2: broken server — BODYSTRUCTURE NIL forces the BODY[TEXT]
		// fallback; extractBestMimeText must still rescue readable text out
		// of the raw MIME soup (the exact #11 scenario).
		const soup2 =
			`From: Kunde Zwei <kunde2+${RUN_TAG}@example.org>\r\n` +
			`To: ${INBOUND_ADDR}\r\n` +
			`Subject: ATLAS-SOUP-${RUN_TAG}\r\n` +
			`Message-ID: <atlas-soup-${RUN_TAG}@atlas.invalid>\r\n` +
			'MIME-Version: 1.0\r\n' +
			'Content-Type: multipart/alternative; boundary="bnd-atlas-xyz"\r\n' +
			'\r\n' +
			'--bnd-atlas-xyz\r\n' +
			'Content-Type: text/plain; charset=ISO-8859-15\r\n' +
			'Content-Transfer-Encoding: 8bit\r\n' +
			'\r\n' +
			'Hallo Support,\r\n' +
			'bitte pr\xFCfen Sie das Angebot: 1.200 \xA4 netto.\r\n' + // \xA4 = € in ISO-8859-15
			'\r\n' +
			'--bnd-atlas-xyz\r\n' +
			'Content-Type: text/html; charset=UTF-8\r\n' +
			'Content-Transfer-Encoding: base64\r\n' +
			'\r\n' +
			'PGh0bWw+PGJvZHk+PHA+aHRtbCBmYWxsYmFjazwvcD48L2JvZHk+PC9odG1sPg==\r\n' +
			'--bnd-atlas-xyz--\r\n';

		const mbox = {
			uidvalidity: uidval,
			messages: [
				{
					uid: 1,
					internalDate: '26-Nov-2026 10:00:00 +0000',
					header:
						`From: =?UTF-8?Q?J=C3=BCrgen_M=C3=BCller?= <juergen.mueller+${RUN_TAG}@example.de>\r\n` +
						`To: ${INBOUND_ADDR}\r\n` +
						`Subject: =?UTF-8?Q?ATLAS-IMAP-${RUN_TAG}?=\r\n` +
						`Message-ID: <atlas-imap-${RUN_TAG}@atlas.invalid>\r\n` +
						'Date: Thu, 26 Nov 2026 10:00:00 +0000\r\n',
					bodystructure: bs1,
					sections: { '1': b64(html1), '2': b64(attachB64) },
				},
				{
					uid: 2,
					internalDate: '26-Nov-2026 10:01:00 +0000',
					header:
						`From: Kunde Zwei <kunde2+${RUN_TAG}@example.org>\r\n` +
						`To: ${INBOUND_ADDR}\r\n` +
						`Subject: ATLAS-SOUP-${RUN_TAG}\r\n` +
						`Message-ID: <atlas-soup-${RUN_TAG}@atlas.invalid>\r\n` +
						'Date: Thu, 26 Nov 2026 10:01:00 +0000\r\n',
					bodystructure: 'NIL',
					sections: { TEXT: b64(soup2) },
				},
			],
		};

		// --- Install + start the fake server --------------------------------
		pushFile(path.join(__dirname, 'fixtures', 'atlas-imap-fake.php'), FAKE_REMOTE);
		dcExec(['nextcloud', 'sh', '-c', `cat > ${MBOX_REMOTE}`], JSON.stringify(mbox));
		dcExec(['-d', 'nextcloud', 'php', FAKE_REMOTE, MBOX_REMOTE, String(FAKE_PORT)]);
		// Wait for the pid file (server bound + accepting).
		for (let i = 0; i < 30; i++) {
			try {
				dcExec(['nextcloud', 'sh', '-c', `test -s ${MBOX_REMOTE}.pid`]);
				break;
			} catch {
				if (i === 29) {
					throw new Error('fake IMAP server did not start');
				}
				await new Promise((r) => setTimeout(r, 200));
			}
		}

		// --- Save → test → enable (one orchestrated request) -----------------
		const save = await api(page, 'post', '/index.php/apps/ticketcheck/api/settings/inbound-imap', requesttoken, {
			address: INBOUND_ADDR,
			auth_mode: 'password',
			host: '127.0.0.1',
			port: String(FAKE_PORT),
			enc: 'none',
			username: 'atlas',
			password: 'atlas',
			folder: 'INBOX',
			allow_private_host: '1',
			process_backlog: '1',
			dsgvo_ack: '1',
			unknown_sender_policy: 'create',
			send_via_smtp: '0',
			enable_after_test: '1',
		});
		expect(save.status, JSON.stringify(save.body)).toBe(200);
		expect(save.body?.enabled, JSON.stringify(save.body)).toBe(true);
		state.imapConfigured = true;

		// A leftover enabled+unreachable config (dead fake from an interrupted
		// run) lets the 15-min cron accumulate sync errors — the exponential
		// backoff would silently skip the job. Reset it deterministically.
		occ('config:app:set', 'ticketcheck', 'inbound_imap_error_count', '--value=0');
		occ('config:app:delete', 'ticketcheck', 'inbound_imap_last_sync_at');

		// --- Run the real background job -------------------------------------
		const jobs = occJson('background-job:list', '--output=json_pretty') ?? occJson('background-job:list', '--output=json');
		const job = (Array.isArray(jobs) ? jobs : []).find((j) => j.class === SYNC_JOB_CLASS);
		expect(job, 'InboundMailSyncJob not registered in jobs table').toBeTruthy();
		occ('background-job:execute', String(job.id), '--force-execute');

		// --- Assert tickets --------------------------------------------------
		// apiIndex serializes ids only — the created ticket's id is resolved
		// from oc_helpdesk_inbound_msgs (action=ticket_created ↔ Message-ID),
		// which also proves the sync recorded the right terminal action.
		const findTicket = async (msgIdFragment) => {
			for (let i = 0; i < 20; i++) {
				let id = '';
				try {
					id = dcExec(['mariadb', 'sh', '-c',
						`mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -N -e ` +
						`"SELECT ticket_id FROM oc_helpdesk_inbound_msgs WHERE message_id LIKE '%${msgIdFragment}%' AND action='ticket_created' LIMIT 1;"`,
					]).trim();
				} catch { /* db lookup is best-effort per poll */ }
				if (id !== '') {
					return api(page, 'get', `/index.php/apps/ticketcheck/api/tickets/${id}`, requesttoken);
				}
				await new Promise((r) => setTimeout(r, 500));
			}
			return null;
		};

		// Msg 1 — HTML part decoded by declared charset, htmlToText applied,
		// attachment leaf fetched and named (not silently skipped).
		const t1 = await findTicket(`atlas-imap-${RUN_TAG}`);
		expect(t1, 'no ticket for IMAP multipart message').toBeTruthy();
		state.ticketIds.push(t1.body.id);
		const d1 = String(t1.body.description);
		expect(d1).toContain('Hallo Team,');
		expect(d1).toContain('Łódź');                  // windows-1250 honored
		expect(d1).toContain('- Position eins');      // <li> → "- "
		expect(d1).toContain('angebot.txt');          // attachment summary
		expect(d1).not.toMatch(/font-family|<style|<script|alert\(|<\/p>|<html/i);
		expect(d1).not.toMatch(/Content-Type:|multipart\/|boundary/i);
		expect(t1.body.customer_name).toBe('Jürgen Müller');   // RFC 2047 From name
		expect(t1.body.customer_email).toBe(`juergen.mueller+${RUN_TAG}@example.de`);

		// Msg 2 — BODYSTRUCTURE failed: TEXT fallback must still yield clean
		// text, never the raw MIME payload.
		const t2 = await findTicket(`atlas-soup-${RUN_TAG}`);
		expect(t2, 'no ticket for BODYSTRUCTURE-NIL message').toBeTruthy();
		state.ticketIds.push(t2.body.id);
		const d2 = String(t2.body.description);
		expect(d2).toContain('Hallo Support,');
		expect(d2).toContain('1.200 €');              // ISO-8859-15 honored
		expect(d2).not.toMatch(/Content-Type:|boundary=|bnd-atlas-xyz|PGh0bWw|base64/i);

		// Cleanup: disable + delete the created tickets.
		await api(page, 'post', '/index.php/apps/ticketcheck/api/settings/inbound-imap/disable', requesttoken);
		for (const id of state.ticketIds) {
			await api(page, 'del', `/index.php/apps/ticketcheck/api/tickets/${id}`, requesttoken).catch(() => {});
		}
	});

	test('webhook: HTML-only payload lands as readable text', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'chromium-1280', 'webhook app-config keys are shared — single project only');
		test.setTimeout(60_000);
		await page.goto('/apps/ticketcheck/settings/inbound-mail', { waitUntil: 'domcontentloaded' });
		await ensureAuthenticated(page);
		const requesttoken = await page.evaluate(() => document.head?.dataset?.requesttoken ?? '');
		expect(requesttoken).not.toBe('');

		// Capture + restore previous webhook config.
		const keys = ['inbound_email_enabled', 'inbound_email_address', 'inbound_email_webhook_token', 'inbound_email_webhook_signing_secret'];
		for (const k of keys) {
			try {
				const v = occ('config:app:get', 'ticketcheck', k);
				if (v.trim() !== '') {
					state.webhookKeys[k] = v.trim();
				}
			} catch { /* key unset — nothing to restore */ }
		}

		const token = `tok-${RUN_TAG}`;
		const secret = `sec-${RUN_TAG}`;
		occ('config:app:set', 'ticketcheck', 'inbound_email_enabled', '--value=yes');
		occ('config:app:set', 'ticketcheck', 'inbound_email_address', `--value=${INBOUND_ADDR}`);
		occ('config:app:set', 'ticketcheck', 'inbound_email_webhook_token', `--value=${token}`);
		occ('config:app:set', 'ticketcheck', 'inbound_email_webhook_signing_secret', `--value=${secret}`);

		const bodyObj = {
			from: `Webhook Absender <hook+${RUN_TAG}@example.org>`,
			to: INBOUND_ADDR,
			subject: `ATLAS-WEBHOOK-${RUN_TAG}`,
			text: '',
			html: '<html><head><style>a{color:red}</style><script>alert("x")</script></head>' +
				'<body><p>Webhook HTML Body</p><p>zweiter&nbsp;Absatz</p></body></html>',
			'message-id': `<atlas-wh-${RUN_TAG}@atlas.invalid>`,
			headers: `Message-ID: <atlas-wh-${RUN_TAG}@atlas.invalid>\r\n`,
		};
		const rawBody = JSON.stringify(bodyObj);
		const ts = String(Math.floor(Date.now() / 1000));
		const sig = crypto.createHmac('sha256', secret).update(`${ts}.${rawBody}`).digest('hex');

		const res = await page.request.post('/index.php/apps/ticketcheck/inbound-email/webhook', {
			headers: {
				'X-Webhook-Token': token,
				'X-Webhook-Timestamp': ts,
				'X-Webhook-Signature': sig,
				'Content-Type': 'application/json',
				// The session cookie riding in this context puts the POST
				// under SessionCsrfMiddleware even on the PublicPage route —
				// a valid requesttoken satisfies it (ambient-auth defense).
				requesttoken,
			},
			data: rawBody,
		});
		const wh = await res.json().catch(() => null);
		expect(res.status(), JSON.stringify(wh)).toBe(200);
		expect(wh?.success).toBe(true);
		expect(wh?.ticket_id).toBeTruthy();

		const detail = await api(page, 'get', `/index.php/apps/ticketcheck/api/tickets/${wh.ticket_id}`, requesttoken);
		const desc = String(detail.body?.description ?? '');
		expect(desc).toContain('Webhook HTML Body');
		expect(desc).toContain('zweiter Absatz');        // &nbsp; decoded
		expect(desc).not.toMatch(/<style|<script|alert\(|color:red|<\/p>/i);

		await api(page, 'del', `/index.php/apps/ticketcheck/api/tickets/${wh.ticket_id}`, requesttoken).catch(() => {});
	});
});

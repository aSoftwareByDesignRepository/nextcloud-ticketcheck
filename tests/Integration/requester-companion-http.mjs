import assert from 'node:assert/strict';
import { randomBytes, randomUUID } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { mkdtempSync, existsSync, unlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { acquireSharedInstanceLock, releaseSharedInstanceLock } from '../../e2e/shared-instance-lock.mjs';

if (!process.argv.includes('--provision')) throw new Error('Pass --provision to create isolated local test users.');
process.env.NC_E2E_LOCK_STRICT = '1';
const compose = fileURLToPath(new URL('../../../../', import.meta.url));
const base = process.env.E2E_BASE ?? 'http://localhost:8081';
const path = '/index.php/apps/ticketcheck/companion/api/v1';
const prefix = `tcr_${randomBytes(5).toString('hex')}`;
const fixtures = [];

function occ(args, password) {
  const env = { ...process.env };
  if (password) {
    env.OC_PASS = password;
    env.NC_PASS = password;
  }
  const command = ['compose', 'exec', '-T', '--user', 'www-data'];
  if (password) command.push('-e', 'OC_PASS', '-e', 'NC_PASS');
  command.push('nextcloud', 'php', 'occ', ...args);
  const result = spawnSync('docker', command, { cwd: compose, env, encoding: 'utf8' });
  if (result.status !== 0) throw new Error(`occ ${args[0]} failed (status ${result.status}); output withheld to protect credentials.`);
  return result.stdout.trim();
}

async function fixture(label, groups) {
  const uid = `${prefix}_${label}`;
  const password = randomBytes(24).toString('base64url');
  occ(['user:add', '--password-from-env', '--display-name', `Requester QA ${label}`, uid], password);
  occ(['user:resetpassword', '--password-from-env', uid], password);
  fixtures.push(uid);
  for (const group of groups) occ(['group:adduser', group, uid]);
  occ(['user:setting', uid, 'firstrunwizard', 'show', '0']);
  occ(['user:setting', uid, 'core', 'lang', 'en']);
  const output = occ(['user:auth-tokens:add', '--password-from-env', '--name', 'Requester QA', uid], password);
  const token = output.split(/\r?\n/).at(-1).replace(/^.*?:\s*/, '').trim();
  assert.ok(token.length >= 20, 'App password was not returned');
  return { uid, token, password };
}

async function request(user, endpoint, method = 'GET', body, key, attempt = 0) {
  const headers = { Authorization: `Basic ${Buffer.from(`${user.uid}:${user.token}`).toString('base64')}`, Accept: 'application/json' };
  if (key) headers['X-TC-Idempotency-Key'] = key;
  if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
  let response;
  try {
    response = await fetch(base + path + endpoint, {
      method, headers, body: body instanceof FormData ? body : body ? JSON.stringify(body) : undefined,
      redirect: 'manual', signal: AbortSignal.timeout(30000),
    });
  } catch (error) {
    // undici reuses keep-alive sockets; Apache closes them after ~5s idle (e.g. while the
    // --web Playwright block runs). A GET written onto that half-closed socket dies with
    // UND_ERR_SOCKET before reaching PHP — never dispatched, so one retry is safe.
    if (attempt === 0 && ['GET', 'HEAD'].includes(method) && error?.cause?.code === 'UND_ERR_SOCKET') {
      console.log(`NOTE socket-retry :: ${method} ${endpoint} hit a stale keep-alive socket; retrying once on a fresh connection`);
      return request(user, endpoint, method, body, key, 1);
    }
    throw error;
  }
  const raw = await response.text();
  let data;
  try { data = JSON.parse(raw); } catch { data = null; }
  return { status: response.status, data, raw };
}

function leg(id, desc, status) {
  console.log(`PASS ${id} :: ${desc} -> ${status}`);
}

async function runBrowser(admin, requester, peer, empty, dual) {
  if (process.argv.includes('--screens')) {
    const dualCreated = await request(dual, '/requester/tickets', 'POST', {
      title: `${prefix} dual request`, description: 'Dual-role own request for matrix evidence', priority: 'normal',
    }, randomUUID());
    assert.equal(dualCreated.status, 201, `Dual-role own request create returned ${dualCreated.status}: ${JSON.stringify(dualCreated.data)}`);
  }
  const authDir = mkdtempSync(join(tmpdir(), 'tc-requester-auth-'));
  const labels = ['agent', 'requester', 'requester2', 'requesterempty', 'dualrole'];
  const authPaths = labels.map(label => join(authDir, `${label}.json`));
  const env = {
    ...process.env, E2E_BASE: base,
    E2E_USER: admin.uid, E2E_PASSWORD: admin.password, E2E_STORAGE_STATE: authPaths[0],
    E2E_REQUESTER_USER: requester.uid, E2E_REQUESTER_PASSWORD: requester.password, E2E_REQUESTER_STORAGE_STATE: authPaths[1],
    E2E_REQUESTER2_USER: peer.uid, E2E_REQUESTER2_PASSWORD: peer.password, E2E_REQUESTER2_STORAGE_STATE: authPaths[2],
    E2E_REQUESTEREMPTY_USER: empty.uid, E2E_REQUESTEREMPTY_PASSWORD: empty.password, E2E_REQUESTEREMPTY_STORAGE_STATE: authPaths[3],
    E2E_DUALROLE_USER: dual.uid, E2E_DUALROLE_PASSWORD: dual.password, E2E_DUALROLE_STORAGE_STATE: authPaths[4],
  };
  await releaseSharedInstanceLock();
  try {
    const root = fileURLToPath(new URL('../../', import.meta.url));
    const playwright = join(root, 'node_modules/@playwright/test/cli.js');
    const journeys = spawnSync(process.execPath, [playwright,
      'test', 'e2e/requester-portal.spec.js', '--project=chromium-1280', '--workers=1'], { cwd: root, env, stdio: 'inherit' });
    assert.equal(journeys.status, 0, 'Requester browser journeys must pass without skipped fixtures');
    if (process.argv.includes('--screens')) {
      const matrix = spawnSync(process.execPath, [playwright,
        'test', '-c', 'e2e/playwright.screenshot-matrix.config.js', '--workers=1'], {
        cwd: root,
        env: {
          ...env,
          MATRIX_SURFACE_IDS: 'requester-portal-empty,requester-portal-populated,requester-create,my-requests-empty,my-requests-populated,settings-access-requesters',
        },
        stdio: 'inherit',
      });
      assert.equal(matrix.status, 0, 'Requester screenshot matrix must pass');
    }
  } finally {
    for (const file of authPaths) if (existsSync(file)) unlinkSync(file);
  }
  await acquireSharedInstanceLock('ticketcheck-requester-http');
}

await acquireSharedInstanceLock('ticketcheck-requester-http');
try {
  const needsBrowser = process.argv.includes('--web') || process.argv.includes('--screens');
  const admin = needsBrowser ? await fixture('admin', ['helpdesk_admins']) : null;
  const requester = await fixture('one', ['helpdesk_requesters']);
  const peer = await fixture('two', ['helpdesk_requesters']);
  const empty = needsBrowser ? await fixture('empty', ['helpdesk_requesters']) : null;
  const dual = await fixture('dual', ['helpdesk_requesters', 'helpdesk_agents']);
  const bootstrap = await request(requester, '/bootstrap');
  assert.equal(bootstrap.status, 200, 'Requester bootstrap authentication');
  assert.equal(bootstrap.data.user.role, 'requester');
  assert.deepEqual(bootstrap.data.capabilities.features, ['requesterPortal']);
  assert.equal(bootstrap.data.licensing.seat.assigned, false);
  assert.equal(bootstrap.data.capabilities.pushAvailable, false);
  assert.equal((await request(requester, '/inbox')).status, 403, 'No staff inbox');
  leg('req.bootstrap', 'app-password auth: requester role, no seat, no push capability', 200);
  leg('req.staff-inbox.deny', 'requester denied the staff inbox surface', 403);
  const key = randomUUID();
  const payload = { title: `${prefix} printer`, description: 'Paper jam', priority: 'normal' };
  const created = await request(requester, '/requester/tickets', 'POST', payload, key);
  assert.equal(created.status, 201, `Create returned ${created.status}: ${JSON.stringify(created.data)}`);
  const id = created.data.ticket.id;
  assert.ok(Number.isSafeInteger(id));
  leg('req.create', 'create returns integer ticket id', 201);
  const repeated = await request(requester, '/requester/tickets', 'POST', payload, key);
  assert.equal(repeated.status, 201);
  assert.equal(repeated.data.ticket.id, id, 'Create replay must not duplicate');
  leg('req.create.replay', 'same idempotency-key replays to the same ticket id', 201);
  const ownShow = await request(requester, `/requester/tickets/${id}`);
  assert.equal(ownShow.status, 200, 'Owner show returns own ticket');
  assert.equal(ownShow.data.ticket.id, id);
  leg('req.show.own', 'owner reads own ticket', 200);
  const foreign = await request(peer, `/requester/tickets/${id}`);
  const missing = await request(peer, '/requester/tickets/2147483647');
  assert.equal(foreign.status, 404);
  assert.deepEqual(foreign, missing, 'No missing-versus-forbidden oracle');
  leg('req.show.peer-404', 'foreign ticket show answers the uniform 404', 404);
  leg('req.show.missing-404', 'missing ticket show answers the identical 404 (no oracle)', 404);
  const list = await request(requester, '/requester/tickets');
  assert.equal(list.status, 200);
  assert.ok(list.data.items.some(ticket => ticket.id === id));
  leg('req.list.own', 'own list contains the created ticket', 200);
  const peerList = await request(peer, '/requester/tickets');
  assert.equal(peerList.status, 200);
  assert.ok(!peerList.data.items.some(ticket => ticket.id === id));
  leg('req.list.peer', 'peer list never exposes the foreign ticket', 200);
  const replyKey = randomUUID();
  const replyBody = () => { const form = new FormData(); form.set('comment', 'Please help'); return form; };
  const reply = await request(requester, `/requester/tickets/${id}/reply`, 'POST', replyBody(), replyKey);
  assert.equal(reply.status, 200, 'Public requester reply');
  leg('req.reply', 'public requester reply persisted', 200);
  const replay = await request(requester, `/requester/tickets/${id}/reply`, 'POST', replyBody(), replyKey);
  assert.equal(replay.status, 200);
  assert.equal(replay.data.comments.filter(comment => comment.body === 'Please help').length, 1, 'Reply replay must not duplicate');
  leg('req.reply.replay', 'same key replay does not duplicate the comment', 200);
  assert.equal((await request(peer, `/requester/tickets/${id}/reply`, 'POST', replyBody(), randomUUID())).status, 404);
  leg('req.reply.peer-404', 'reply on a foreign ticket answers the uniform 404', 404);
  const attachBody = `atlas ${prefix} attachment body`;
  const attachForm = new FormData();
  attachForm.set('title', `${prefix} attachment ticket`);
  attachForm.set('description', 'Attachment download evidence');
  attachForm.set('priority', 'normal');
  attachForm.append('attachments[]', new Blob([attachBody], { type: 'text/plain' }), 'atlas-requester.txt');
  const attachCreated = await request(requester, '/requester/tickets', 'POST', attachForm, randomUUID());
  assert.equal(attachCreated.status, 201, `Attachment create returned ${attachCreated.status}: ${JSON.stringify(attachCreated.data)}`);
  const attTicketId = attachCreated.data.ticket.id;
  const attId = attachCreated.data.attachments?.[0]?.id;
  assert.ok(Number.isSafeInteger(attId), 'Create response carries the attachment id');
  leg('req.create.attachment', 'multipart create stores the attachment', 201);
  const download = await request(requester, `/requester/tickets/${attTicketId}/attachments/${attId}`);
  assert.equal(download.status, 200, 'Owner attachment download');
  assert.ok(download.raw.includes(attachBody), 'Downloaded bytes match the uploaded payload');
  leg('req.download.own', 'owner downloads own attachment (payload bytes match)', 200);
  const peerDownload = await request(peer, `/requester/tickets/${attTicketId}/attachments/${attId}`);
  const missingDownload = await request(peer, `/requester/tickets/${attTicketId}/attachments/2147483647`);
  assert.equal(peerDownload.status, 404);
  assert.equal(missingDownload.status, 404);
  leg('req.download.peer-404', 'peer attachment download answers the uniform 404', 404);
  leg('req.download.missing-404', 'missing attachment id answers the identical 404 (no oracle)', 404);
  assert.equal((await request(dual, `/requester/tickets/${id}`)).status, 404, 'Staff membership must not widen requester scope');
  leg('req.show.dual-role', 'staff+requester dual-role does not widen requester scope', 404);
  if (needsBrowser) await runBrowser(admin, requester, peer, empty, dual);
  occ(['group:removeuser', 'helpdesk_requesters', requester.uid]);
  assert.equal((await request(requester, `/requester/tickets/${id}`)).status, 403, 'Revocation takes effect on next request');
  leg('req.revocation', 'group removal denies the next request', 403);
  console.log('PASS requester HTTP integration: app-password auth, no staff/seat/push access, own reads, peer isolation, uniform 404, create/reply replay, dual-role scope, revocation.');
} finally {
  console.log(`Isolated QA users retained: ${fixtures.join(', ')}. No existing users or authentication settings were modified.`);
  await releaseSharedInstanceLock();
}

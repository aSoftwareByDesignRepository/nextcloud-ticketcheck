'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Guest portal must never inherit agent storageState from playwright.config.
 * Prefer E2E_GUEST_STORAGE_STATE / .auth/guest-storage-state.json from global-setup.
 */
function resolveGuestStorageState() {
	return resolveStorageState('E2E_GUEST_STORAGE_STATE', 'guest-storage-state.json');
}

/**
 * Internal requester (NC user in helpdesk_requesters) — same isolation rule:
 * never inherit agent storageState. See e2e/requester-portal.spec.js.
 */
function resolveRequesterStorageState() {
	return resolveStorageState('E2E_REQUESTER_STORAGE_STATE', 'requester-storage-state.json');
}

/**
 * Generic per-label resolver: requester2 (second isolation user) and
 * dualrole (staff+requester). Env: E2E_<LABEL>_STORAGE_STATE.
 */
function resolveStorageStateFor(label) {
	const env = `E2E_${label.toUpperCase()}_STORAGE_STATE`;
	return resolveStorageState(env, `${label}-storage-state.json`);
}

function resolveStorageState(envVar, filename) {
	if (process.env[envVar]) {
		const resolved = path.resolve(process.env[envVar]);
		if (fs.existsSync(resolved)) {
			return resolved;
		}
	}
	const autoPath = path.join(__dirname, '..', '..', '.auth', filename);
	if (fs.existsSync(autoPath)) {
		return autoPath;
	}
	return { cookies: [], origins: [] };
}

module.exports = { resolveGuestStorageState, resolveRequesterStorageState, resolveStorageStateFor };

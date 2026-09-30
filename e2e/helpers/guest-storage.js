'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Guest portal must never inherit agent storageState from playwright.config.
 * Prefer E2E_GUEST_STORAGE_STATE / .auth/guest-storage-state.json from global-setup.
 */
function resolveGuestStorageState() {
	if (process.env.E2E_GUEST_STORAGE_STATE) {
		const resolved = path.resolve(process.env.E2E_GUEST_STORAGE_STATE);
		if (fs.existsSync(resolved)) {
			return resolved;
		}
	}
	const autoPath = path.join(__dirname, '..', '..', '.auth', 'guest-storage-state.json');
	if (fs.existsSync(autoPath)) {
		return autoPath;
	}
	return { cookies: [], origins: [] };
}

module.exports = { resolveGuestStorageState };

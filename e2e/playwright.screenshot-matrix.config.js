// @ts-check
/**
 * Dedicated config for the ATLAS screenshot-matrix capture
 * (e2e/capture-screenshot-matrix.spec.js). The matrix adds ~60 PNG captures
 * to a run — it gets its own config rather than slowing every default
 * `npx playwright test` (the main chromium projects testIgnore it).
 *
 * Inherits env loading, baseURL, timeouts, storageState, and the shared
 * instance lock (global setup/teardown) from playwright.config.js.
 *
 * Run (from the app dir):
 *   npx playwright test -c e2e/playwright.screenshot-matrix.config.js
 */
const { devices } = require('@playwright/test');
const path = require('path');
const baseConfig = require('../playwright.config.js');

module.exports = {
	...baseConfig,
	testDir: __dirname,
	testMatch: 'capture-screenshot-matrix.spec.js',
	// The base config testIgnores this spec; re-include it here.
	testIgnore: [],
	// Base config paths are relative to the app root — re-anchor them here.
	globalSetup: path.join(__dirname, 'global-setup.js'),
	globalTeardown: path.join(__dirname, 'global-teardown.mjs'),
	projects: [{ name: 'matrix', use: { ...devices['Desktop Chrome'] } }],
};

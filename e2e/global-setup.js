#!/usr/bin/env node
'use strict';

/**
 * Optional: log in once and write Playwright storage state when credentials are set.
 * - E2E_USER + E2E_PASSWORD → .auth/storage-state.json (agent / staff)
 * - E2E_GUEST_USER + E2E_GUEST_PASSWORD → .auth/guest-storage-state.json (portal)
 * Enables CI/local runs without manual npm run e2e:auth.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');

async function loginAndSave({ user, pass, loginUrl, outputPath, label }) {
	fs.mkdirSync(path.dirname(outputPath), { recursive: true });

	const browser = await chromium.launch({ headless: true });
	const context = await browser.newContext();
	const page = await context.newPage();

	await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });

	// NC 34+ mounts a Vue login form into #login — wait for hydration.
	const accountField = page.locator('input#user, input[name="user"]').first();
	const passwordField = page.locator('input#password, input[name="password"]').first();
	try {
		await accountField.waitFor({ state: 'visible', timeout: 30_000 });
	} catch {
		await browser.close();
		console.warn(
			`[ticketcheck:e2e] global-setup: ${label} login form not ready (Vue #login). Related tests will skip.`,
		);
		return false;
	}
	await accountField.fill(user);
	await passwordField.fill(pass);
	const submit = page.locator('button[type="submit"], input[type="submit"]').first();
	await submit.click();

	try {
		await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 });
	} catch {
		await browser.close();
		console.warn(
			`[ticketcheck:e2e] global-setup: ${label} login failed (check credentials). Related tests will skip.`,
		);
		return false;
	}

	await context.storageState({ path: outputPath });
	await browser.close();
	console.log(`[ticketcheck:e2e] ${label} storage state written: ${outputPath}`);
	return true;
}

module.exports = async function globalSetup() {
	const base = (process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '');
	const loginUrl = process.env.E2E_LOGIN_URL || `${base}/index.php/login`;
	const authDir = path.resolve(__dirname, '..', '.auth');

	const agentUser = process.env.E2E_USER;
	const agentPass = process.env.E2E_PASSWORD || process.env.E2E_PASS;
	if (agentUser && agentPass) {
		const outputPath = process.env.E2E_STORAGE_STATE
			? path.resolve(process.env.E2E_STORAGE_STATE)
			: path.join(authDir, 'storage-state.json');
		const ok = await loginAndSave({
			user: agentUser,
			pass: agentPass,
			loginUrl,
			outputPath,
			label: 'agent',
		});
		if (ok) {
			process.env.E2E_STORAGE_STATE = outputPath;
		}
	}

	const guestUser = process.env.E2E_GUEST_USER;
	const guestPass = process.env.E2E_GUEST_PASSWORD;
	if (guestUser && guestPass) {
		const outputPath = process.env.E2E_GUEST_STORAGE_STATE
			? path.resolve(process.env.E2E_GUEST_STORAGE_STATE)
			: path.join(authDir, 'guest-storage-state.json');
		const ok = await loginAndSave({
			user: guestUser,
			pass: guestPass,
			loginUrl,
			outputPath,
			label: 'guest',
		});
		if (ok) {
			process.env.E2E_GUEST_STORAGE_STATE = outputPath;
		}
	}
};

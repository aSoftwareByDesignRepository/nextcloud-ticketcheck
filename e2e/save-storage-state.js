#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');

async function main() {
  const loginUrl = process.env.E2E_LOGIN_URL;
  if (!loginUrl) {
    console.error('[ticketcheck:e2e:auth] Missing E2E_LOGIN_URL');
    console.error('[ticketcheck:e2e:auth] Example: export E2E_LOGIN_URL="https://your-host/index.php/login"');
    process.exit(1);
  }

  const outputPath = process.env.E2E_STORAGE_STATE_OUTPUT
    ? path.resolve(process.env.E2E_STORAGE_STATE_OUTPUT)
    : path.resolve(process.cwd(), '.auth', 'storage-state.json');

  fs.mkdirSync(path.dirname(outputPath), { recursive: true });

  console.log('[ticketcheck:e2e:auth] Opening browser for interactive login...');
  console.log('[ticketcheck:e2e:auth] No Docker changes are performed.');

  const browser = await chromium.launch({ headless: false });
  const context = await browser.newContext();
  const page = await context.newPage();

  await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });
  console.log('[ticketcheck:e2e:auth] Please log in manually in the opened browser window.');

  const expectedPath = process.env.E2E_AUTH_SUCCESS_PATH || '/index.php/apps/ticketcheck';
  await page.waitForURL(
    (url) => {
      try {
        return url.pathname.includes(expectedPath);
      } catch (e) {
        return false;
      }
    },
    { timeout: 180000 }
  );

  await context.storageState({ path: outputPath });
  console.log(`[ticketcheck:e2e:auth] Storage state saved to: ${outputPath}`);

  await browser.close();
}

main().catch((error) => {
  console.error('[ticketcheck:e2e:auth] Failed:', error.message);
  process.exit(1);
});

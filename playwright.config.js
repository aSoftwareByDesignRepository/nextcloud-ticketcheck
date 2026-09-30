const fs = require('fs');
const path = require('path');
const { defineConfig, devices } = require('@playwright/test');

function resolveStorageState() {
  if (process.env.E2E_STORAGE_STATE) {
    const resolved = path.resolve(process.env.E2E_STORAGE_STATE);
    return fs.existsSync(resolved) ? resolved : undefined;
  }
  const autoPath = path.join(__dirname, '.auth', 'storage-state.json');
  if ((process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS)) && fs.existsSync(autoPath)) {
    return autoPath;
  }
  return undefined;
}

const baseURL = (process.env.E2E_BASE || process.env.BASE_URL || process.env.NC_BASE_URL || 'http://localhost:8081').replace(/\/$/, '');

module.exports = defineConfig({
  globalSetup: require.resolve('./e2e/global-setup.js'),
  testDir: './e2e',
  timeout: 60_000,
  expect: {
    timeout: 10_000,
    toHaveScreenshot: {
      animations: 'disabled',
      caret: 'hide',
      maxDiffPixelRatio: 0.04,
    },
  },
  use: {
    baseURL,
    headless: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    // Default = agent/staff. Guest specs override via test.use({ storageState }).
    storageState: resolveStorageState(),
  },
  projects: [
    {
      name: 'chromium-1280',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } },
    },
    {
      name: 'chromium-320',
      use: { ...devices['Desktop Chrome'], viewport: { width: 320, height: 640 } },
    },
  ],
  reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
});

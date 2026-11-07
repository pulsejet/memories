import type { PlaywrightTestConfig } from '@playwright/test';
import { devices } from '@playwright/test';
import { cpus } from 'os';

const config: PlaywrightTestConfig = {
  testDir: './e2e',
  timeout: 120 * 1000,
  expect: {
    timeout: 10000,
  },
  fullyParallel: true,
  workers: Math.max(2, Math.min(16, Math.floor(cpus().length / 2))),
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: [
    ['html', { open: 'never' }],
    ['list', { printSteps: true }],
    ['json', { outputFile: 'playwright-results.json' }],
  ],
  use: {
    actionTimeout: 30000,
    trace: 'retain-on-failure',
    screenshot: 'on',
    video: {
      mode: process.env.E2E_VIDEO === '1' ? 'on' : 'off',
      size: { width: 1280, height: 720 },
    },
    viewport: { width: 1280, height: 720 },
    timezoneId: 'America/Los_Angeles',
    // Local instances may redirect to https behind a self-signed forwarder
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'setup',
      testMatch: /auth\.setup\.ts/,
    },
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        storageState: 'e2e/.state/user.json',
      },
      dependencies: ['setup'],
    },
  ],
};

export default config;

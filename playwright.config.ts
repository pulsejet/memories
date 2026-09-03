import type { PlaywrightTestConfig } from '@playwright/test';
import { devices } from '@playwright/test';
import { cpus } from 'os';

const config: PlaywrightTestConfig = {
  testDir: './e2e',
  timeout: 600 * 1000,
  expect: {
    timeout: 10000,
    toHaveScreenshot: {
      maxDiffPixelRatio: 1.0,
    },
  },
  snapshotPathTemplate: '{testDir}/screenshots/{testFilePath}/{arg}{ext}',
  fullyParallel: true,
  workers: Math.max(2, Math.min(16, Math.floor(cpus().length / 2))),
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: [
    ['html', { open: 'never' }],
    ['list', { printSteps: true }],
  ],
  use: {
    actionTimeout: 30000,
    trace: 'on-first-retry',
    screenshot: 'on',
    viewport: { width: 1280, height: 720 },
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

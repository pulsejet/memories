import type { PlaywrightTestConfig } from '@playwright/test';
import { devices } from '@playwright/test';

const config: PlaywrightTestConfig = {
  testDir: './e2e',
  timeout: 600 * 1000,
  expect: {
    timeout: 30000,
    toMatchSnapshot: {
      maxDiffPixelRatio: 0.1,
    },
  },
  snapshotPathTemplate: '{testDir}/screenshots/{testFilePath}/{arg}{ext}',
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: [['html', { open: 'never' }]],
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

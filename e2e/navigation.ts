import { test } from '@playwright/test';
import type { Page } from '@playwright/test';

export const username = process.env.E2E_USER || process.env.TEST_USER || 'admin';
export const password = process.env.E2E_PASSWORD || process.env.TEST_PASSWORD || 'password';
export const defaultBaseUrl = process.env.CI ? 'http://localhost:8080' : 'http://localhost';
export const baseUrl = (process.env.E2E_BASE_URL || process.env.BASE_URL || defaultBaseUrl).replace(/\/+$/, '');
export const appUrl = `${baseUrl}/index.php/apps/memories`;

// Each worker runs a single test at any given time, so we
// can use the same buffer for all tests in the worker.
let logBuffer: string[] = [];

export async function bootstrap({ page }: { page: Page }) {
  page.on('console', (msg) => {
    const timestamp = new Date().toISOString();
    const tag = msg.type().toUpperCase();
    logBuffer.push(`[${timestamp}]\t${tag}\t${msg.text()}`);
  });

  await page.clock.install({ time: new Date('2026-07-31T08:00:00') });
}

export async function teardown({}: {}) {
  if (logBuffer.length > 0) {
    await test.info().attach('js-console.log', {
      body: logBuffer.join('\n'),
    });
    logBuffer = [];
  }
}

export function e2eHeaders(params?: { timelinePath?: string }): Record<string, string> {
  const entries = Object.entries({
    // Skip CSRF check for all requests.
    'OCS-APIREQUEST': 'true',
    // Delete the files permanently, skipping trashbin.
    // This also prevents locking conflicts.
    'X-NC-SKIP-TRASHBIN': 'true',
    // Overwrite the timeline path if provided.
    'X-TIMELINE-PATH': params?.timelinePath ?? null,
  })
    // Filter out all entries with non-string values
    .filter((p): p is [string, string] => typeof p[1] === 'string')
    // Replace parameters with values
    .map(([k, v]) => [k, psub(v)]);

  return Object.fromEntries(entries);
}

export function psub(input: string): string {
  return input.replace(/%wid/g, process.env.TEST_WORKER_INDEX || '0');
}

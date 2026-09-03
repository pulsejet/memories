import * as fs from 'fs';
import * as path from 'path';
import type { Page } from '@playwright/test';

export const username = process.env.E2E_USER || process.env.TEST_USER || 'admin';
export const password = process.env.E2E_PASSWORD || process.env.TEST_PASSWORD || 'password';
export const defaultBaseUrl = process.env.CI ? 'http://localhost:8080' : 'http://localhost';
export const baseUrl = (process.env.E2E_BASE_URL || process.env.BASE_URL || defaultBaseUrl).replace(/\/+$/, '');
export const appUrl = `${baseUrl}/index.php/apps/memories`;

const logDir = process.env.E2E_LOG_DIR || path.resolve(__dirname, '../e2e_logs');
const logFile = path.join(logDir, 'js_console.log');

const tagMap: Record<string, string> = {
  warning: 'WARN',
  error: 'ERROR',
  info: 'INFO',
  log: 'LOG',
  debug: 'DEBUG',
  trace: 'TRACE',
};

let logStream: fs.WriteStream | null = null;
function getLogStream(): fs.WriteStream {
  if (!logStream) {
    fs.mkdirSync(logDir, { recursive: true });
    logStream = fs.createWriteStream(logFile, { flags: 'a' });
  }
  return logStream;
}

export async function bootstrap(page: Page) {
  page.on('console', (msg) => {
    const tag = tagMap[msg.type()] || msg.type().toUpperCase();
    getLogStream().write(`[${tag}] ${msg.text()}\n`);
  });

  await page.clock.install({ time: new Date('2026-07-31T08:00:00') });
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

import * as fs from 'fs';
import * as path from 'path';
import { expect, type PlaywrightTestArgs } from '@playwright/test';

export const username = process.env.E2E_USER || process.env.TEST_USER || 'admin';
export const password = process.env.E2E_PASSWORD || process.env.TEST_PASSWORD || 'password';
export const defaultBaseUrl = process.env.CI ? 'http://localhost:8080' : 'http://localhost';
export const baseUrl = (process.env.E2E_BASE_URL || process.env.BASE_URL || defaultBaseUrl).replace(/\/+$/, '');
export const appUrl = `${baseUrl}/index.php/apps/memories`;

export const ocsHeaders = {
  'OCS-APIREQUEST': 'true',
};

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

export function navigate(route: string) {
  return async ({ page }: PlaywrightTestArgs) => {
    page.on('console', (msg) => {
      const tag = tagMap[msg.type()] || msg.type().toUpperCase();
      getLogStream().write(`[${tag}] ${msg.text()}\n`);
    });

    const targetUrl = appUrl + route;
    await page.goto(targetUrl);
    await expect(page).toHaveURL(targetUrl);
  };
}

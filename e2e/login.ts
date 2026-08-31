import { expect, type PlaywrightTestArgs } from '@playwright/test';

export const defaultBaseUrl = process.env.CI ? 'http://localhost:8080' : 'http://localhost';
export const baseUrl = (process.env.E2E_BASE_URL || process.env.BASE_URL || defaultBaseUrl).replace(/\/+$/, '');
export const username = process.env.E2E_USER || process.env.TEST_USER || 'admin';
export const password = process.env.E2E_PASSWORD || process.env.TEST_PASSWORD || 'password';
export const appUrl = `${baseUrl}/index.php/apps/memories`;

export const authHeaders = {
  'OCS-APIREQUEST': 'true',
  Authorization: `Basic ${Buffer.from(`${username}:${password}`).toString('base64')}`,
};

export function login(route: string) {
  return async ({ page }: PlaywrightTestArgs) => {
    page.on('console', (msg) => {
      switch (msg.type()) {
        case 'error':
          console.error('js_console=' + msg.text());
          break;
        case 'warning':
          console.warn('js_console=' + msg.text());
          break;
        default:
          console.log('js_console=' + msg.text());
      }
    });

    await page.setViewportSize({ width: 800, height: 600 });
    const targetUrl = `${baseUrl}/index.php/apps/memories${route}`;
    await page.goto(targetUrl);

    await page.locator('#user').click();
    await page.locator('#user').fill(username);
    await page.locator('#user').press('Tab');
    await page.locator('#password').fill(password);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(targetUrl);
  };
}

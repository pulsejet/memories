import { expect, type PlaywrightTestArgs } from '@playwright/test';

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
    await page.goto('http://localhost:8080/index.php/apps/memories' + route);

    await page.locator('#user').click();
    await page.locator('#user').fill('admin');
    await page.locator('#user').press('Tab');
    await page.locator('#password').fill('password');
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL('http://localhost:8080/index.php/apps/memories' + route);
  };
}

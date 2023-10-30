import { expect, type PlaywrightTestArgs } from '@playwright/test';

export function login(route: string) {
  return async ({ page }: PlaywrightTestArgs) => {
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

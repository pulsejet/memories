import { expect, PlaywrightTestArgs } from '@playwright/test';

export function login(route: string) {
  return async ({ page }: PlaywrightTestArgs) => {
    await page.setViewportSize({ width: 800, height: 600 })
    await page.goto('http://localhost:8080/apps/memories' + route)

    await page.locator('[placeholder="Username or email"]').click();
    await page.locator('[placeholder="Username or email"]').fill('admin');
    await page.locator('[placeholder="Username or email"]').press('Tab');
    await page.locator('[placeholder="Password"]').fill('password');
    await page.locator('input:has-text("Log in")').click();
    await expect(page).toHaveURL('http://localhost:8080/apps/memories' + route);
    await page.waitForSelector('img[src^="/core/preview"]');
  }
}
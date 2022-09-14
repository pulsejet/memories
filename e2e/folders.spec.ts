import { test, expect } from '@playwright/test';
import { login } from './login';

test.beforeEach(login('/folders'));

test.describe('Open', () => {
  test('Look for Folders', async ({ page }) => {
    expect(await page.locator('.big-icon > .icon-folder').count(), 'Number of folders').toBeGreaterThan(3);
  });

  test('Open folder', async ({ page }) => {
    await page.locator('text=Local').click();
    await expect(page).toHaveURL(/http:\/\/localhost:8080\/apps\/memories\/folders\/\.*/);
    await page.waitForSelector('img[src*="core/preview"]');
  });
});
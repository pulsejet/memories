import { test, expect } from '@playwright/test';
import { login } from './login';

test.beforeEach(login('/folders'));

test.describe('Open', () => {
  test('Look for Folders', async ({ page }) => {
    expect(await page.locator('.big-icon > .folder-icon').count(), 'Number of folders').toBe(2);
  });

  test('Open folder', async ({ page }) => {
    await page.locator('text=Local').click();
    await expect(page).toHaveURL(/\/apps\/memories\/folders\/[0-9]*/);
    await page.waitForSelector('img[src*="core/preview"]');
  });
});
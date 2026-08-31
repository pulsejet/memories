import { test, expect } from '@playwright/test';
import { login } from './login';

test.beforeEach(login('/folders'));

test.describe('Folder view and navigation', () => {
  test.beforeEach(async ({ page }) => {
    await page.waitForSelector('.big-icon');
    await page.waitForTimeout(500);
  });

  test('Look for Folders', async ({ page }) => {
    const ct = await page.locator('.big-icon:visible').count();
    expect(ct, 'Number of folders').toBe(2);
  });
});

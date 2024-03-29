import { test, expect } from '@playwright/test';
import { login } from './login';

test.beforeEach(login('/folders'));

test.describe('Open', () => {
  test.beforeEach(async ({ page }) => {
    await page.waitForSelector('.big-icon');
    await page.waitForTimeout(500);
  });

  test('Look for Folders', async ({ page }) => {
    const ct = await page.locator('.big-icon:visible').count();
    expect(ct, 'Number of folders').toBe(2);
  });

  test('Open folder', async ({ page }) => {
    await page.locator('text=Local').click();
    await page.waitForTimeout(2000);
    const elems = await page.locator('.img-outer:visible').all();
    expect(elems.length, 'Number of files').toEqual(3);
  });
});

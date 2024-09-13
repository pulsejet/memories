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

  test('Select image and move out of folder', async ({ page }) => {
    await page.locator('text=Local').click();
    await page.waitForTimeout(2000);
    let elems = await page.locator('.img-outer:visible').all();
    expect(elems.length, 'Number of files').toEqual(3);

    // This also tests the SQL triggers since move has no hooks
    await page.locator('.img-outer').nth(1).hover();
    await page.locator('.p-outer:visible > .select').nth(1).click();
    await page.waitForTimeout(500);

    // click selection menu button
    await page.locator('.top-bar button[aria-label="Actions"]').click();
    await page.waitForTimeout(500);

    // click move button
    await page.locator('text=Move to folder').click();
    await page.waitForTimeout(2000); // slow
    await page.locator('tr[data-filename="Photos"]').click();

    // Action button
    await page.locator('.dialog button[aria-label^="Move"]').click();
    await page.waitForTimeout(2000);

    // Check if the file is moved
    elems = await page.locator('.img-outer:visible').all();
    expect(elems.length, 'Number of files').toEqual(2);
  });
});

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

    // Click selection menu button
    const actionButton = page.locator('.top-bar button[aria-label="Actions"]');
    await actionButton.waitFor();
    await actionButton.click();
    await page.waitForTimeout(200);

    // Move to folder
    const moveButton = page.locator('text=Move to folder');
    await moveButton.waitFor();
    await moveButton.click();
    const photosFolder = page.locator('tr[data-filename="Photos"]');
    await photosFolder.waitFor();
    await photosFolder.click();

    // Action button
    await page.locator('.dialog button[aria-label="Move"]').click();
    await page.waitForSelector('.dialog', { state: 'detached' });
    await page.waitForTimeout(2000); // animation to move the file away

    // Check if the file is moved
    elems = await page.locator('.img-outer:visible').all();
    expect(elems.length, 'Number of files').toEqual(2);
  });
});

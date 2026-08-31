import { test, expect } from '@playwright/test';
import { login } from './login';

test.describe('Folder file operations @destructive', () => {
  test.beforeEach(login('/folders'));

  test.beforeEach(async ({ page }) => {
    await page.waitForSelector('.big-icon');
    await page.waitForTimeout(500);
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

test.describe('Timeline photo deletion @destructive', () => {
  test.beforeEach(login('/'));

  test.beforeEach(async ({ page }) => {
    await page.waitForSelector('.img-outer');
    await page.waitForTimeout(500);
  });

  test('Select two images and delete', async ({ page }) => {
    const src1 = await page.locator('.img-outer > img').nth(1).getAttribute('src');
    const src2 = await page.locator('.img-outer > img').nth(2).getAttribute('src');

    expect(await page.locator(`img[src="${src1}"]`).count()).toBe(1);
    expect(await page.locator(`img[src="${src2}"]`).count()).toBe(1);

    await page.locator('.img-outer').nth(1).hover();
    await page.locator('.p-outer > .select').nth(1).click();
    await page.locator('.img-outer').nth(2).click();
    await page.waitForTimeout(1000);

    await page.locator('[aria-label="Delete"]').click();
    await page.waitForTimeout(1000);
    await page
      .locator(
        [
          '.oc-dialog button.error', // NC <=29
          '.dialog .button-vue--vue-primary', // NC 30-32
          '.dialog .button-vue--primary', // NC >=33
        ].join(', '),
      )
      .click();
    await page.waitForTimeout(2000);
    expect(await page.locator(`img[src="${src1}"]`).count()).toBe(0);
    expect(await page.locator(`img[src="${src2}"]`).count()).toBe(0);
  });
});

import { test, expect } from '@playwright/test';
import { appUrl, bootstrap } from './navigation';
import { getFileIdByBasename } from './utils';

test.describe('@ui Timeline feed and photo preview', () => {
  let fileid1: number;

  test.beforeAll(async ({ request }) => {
    fileid1 = await getFileIdByBasename(request, 20696, 'NKcupJh-Dos.jpg');
  });

  test.beforeEach(async ({ page }) => {
    await bootstrap(page);
  });

  test('Look for Images', async ({ page }) => {
    await page.goto(appUrl);
    await page.waitForSelector(`.p-outer--${fileid1}`);
    expect(await page.locator('.p-outer').count()).toBeGreaterThan(4);
  });

  test('Open one image', async ({ page }) => {
    await page.goto(appUrl);
    await page.locator(`.p-outer--${fileid1}`).click();
    await page.waitForTimeout(1000);
    await page.locator('button[title="Close"]').first().click();
  });
});

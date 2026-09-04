import { test, expect } from '@playwright/test';
import { appUrl, bootstrap, teardown } from './navigation';
import { getFileId } from './utils';

test.describe('@ui Timeline feed and photo preview', () => {
  let fileid1: number;

  test.beforeAll(async ({ request }) => {
    fileid1 = await getFileId(request, '/for-default/CbBbaNTmsAc.jpg');
  });

  test.beforeEach(bootstrap);
  test.afterEach(teardown);

  test('Look for Images', async ({ page }) => {
    await page.goto(appUrl);
    await page.waitForSelector(`.p-outer--${fileid1}`);
    expect(await page.locator('.p-outer').count()).toBeGreaterThan(4);
    await page.waitForTimeout(500); // img load
  });

  test('Open one image', async ({ page }) => {
    await page.goto(appUrl);
    await page.locator(`.p-outer--${fileid1}`).click();
    await page.waitForSelector('body.viewer-fully-opened');
    await page.keyboard.press('Escape');
    await page.locator('.memories_viewer').waitFor({ state: 'detached' });
  });
});

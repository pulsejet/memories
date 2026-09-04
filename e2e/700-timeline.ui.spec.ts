import { test, expect } from '@playwright/test';
import { appUrl, bootstrap, teardown } from './navigation';
import { DavClient } from './utils';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.describe('@ui Timeline feed and photo preview', () => {
  let fileid1: number;

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    fileid1 = await dav.fileid('/for-default/CbBbaNTmsAc.jpg');
  });

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

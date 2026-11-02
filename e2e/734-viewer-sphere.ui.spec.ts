import { expect } from '@playwright/test';
import { test, appUrl, e2eHeaders, openViewer } from './navigation';
import { DavClient } from './utils';

test.use({
  extraHTTPHeaders: e2eHeaders({
    timelinePath: '/for-sphere',
  }),
});

test.describe('@ui Viewer panorama sphere', () => {
  let fileid: number;

  test.beforeAll(async ({ request }) => {
    fileid = await new DavClient(request).fileid('/for-sphere/unknown_pano_01.jpg');
  });

  test('Equirectangular panorama opens in PhotoSphere viewer', async ({ page }) => {
    await test.step('Open viewer', async () => {
      await page.goto(appUrl);
      await page.waitForSelector(`.p-outer--${fileid}`);
      await openViewer(page, fileid);
    });

    await test.step('PhotoSphere loads', async () => {
      const sphere = page.locator('.memories-viewer .memories-photosphere');
      await expect(sphere).toBeVisible();
      await expect(sphere.locator('.psv-container')).toBeVisible();
      await expect(sphere.locator('canvas').first()).toBeAttached();
    });
  });
});

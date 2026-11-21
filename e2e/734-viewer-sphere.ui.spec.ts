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
  let videoFileid: number;

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    fileid = await dav.fileid('/for-sphere/unknown_pano_01.jpg');
    videoFileid = await dav.fileid('/for-sphere/unknown_pano_01.mp4');
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

  test('Equirectangular video opens in PhotoSphere viewer', async ({ page }) => {
    await test.step('Open viewer', async () => {
      await page.goto(appUrl);
      await page.waitForSelector(`.p-outer--${videoFileid}`);
      await openViewer(page, videoFileid);
    });

    // The sphere draws from the slide's player, below its controls.
    const sphere = page.locator('.memories-viewer .video-container .memories-photosphere');
    await test.step('PhotoSphere loads over the player', async () => {
      await expect(sphere).toBeVisible();
      await expect(sphere.locator('canvas').first()).toBeAttached();
      await expect(page.locator('.memories-viewer .video-container viewer-controls')).toBeAttached();
    });

    await test.step('View panorama closes it and keeps the player', async () => {
      await page.getByRole('button', { name: 'View panorama' }).first().click();
      await expect(sphere).toHaveCount(0);
      await expect(page.locator('.memories-viewer .memories-video-stage video')).toBeAttached();
    });
  });
});

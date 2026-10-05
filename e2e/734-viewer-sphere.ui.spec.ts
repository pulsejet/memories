import { test, expect, type Page } from '@playwright/test';
import { loadImage, createCanvas } from '@napi-rs/canvas';
import { appUrl, bootstrap, e2eHeaders, teardown } from './navigation';
import { DavClient } from './utils';
import { snap } from './screenshots';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.use({
  extraHTTPHeaders: e2eHeaders({
    timelinePath: '/for-sphere',
  }),
});

/**
 * Fraction of black pixels in the middle of the sphere, where the user is
 * looking. Near the edge of a partial panorama some black at the borders of
 * the frame is expected; black in the middle means the view is off the image.
 */
async function blackAtCentre(page: Page): Promise<number> {
  const png = await page.locator('.memories-photosphere').screenshot();
  const img = await loadImage(png);
  const canvas = createCanvas(img.width, img.height);
  const ctx = canvas.getContext('2d');
  ctx.drawImage(img, 0, 0);

  const [w, h] = [Math.floor(img.width / 2), Math.floor(img.height / 2)];
  const { data } = ctx.getImageData(Math.floor(img.width / 4), Math.floor(img.height / 4), w, h);

  let black = 0;
  for (let i = 0; i < data.length; i += 4) {
    if (data[i] < 8 && data[i + 1] < 8 && data[i + 2] < 8) black++;
  }
  return black / (w * h);
}

test.describe('@ui Viewer panorama sphere', () => {
  let fileid: number;
  let partialid: number;

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    fileid = await dav.fileid('/for-sphere/unknown_pano_01.jpg');
    partialid = await dav.fileid('/for-sphere/partial_01.jpg');
  });

  test('Equirectangular panorama opens in PhotoSphere viewer', async ({ page }) => {
    await test.step('Open viewer', async () => {
      await page.goto(appUrl);
      await page.waitForSelector(`.p-outer--${fileid}`);
      await page.locator(`.p-outer--${fileid} > .img-outer`).click();
      await page.waitForSelector('.memories-viewer.fully-opened');
    });

    await test.step('PhotoSphere loads', async () => {
      const sphere = page.locator('.memories-viewer .memories-photosphere');
      await expect(sphere).toBeVisible();
      await expect(sphere.locator('.psv-container')).toBeVisible();
      await expect(sphere.locator('canvas').first()).toBeAttached();
    });
  });

  test('Partial panorama opens on the image', async ({ page }) => {
    const sphere = page.locator('.memories-viewer .memories-photosphere');

    await test.step('Open viewer', async () => {
      await page.goto(appUrl);
      await page.waitForSelector(`.p-outer--${partialid}`);
      await page.locator(`.p-outer--${partialid} > .img-outer`).click();
      await page.waitForSelector('.memories-viewer.fully-opened');
      await expect(sphere.locator('canvas').first()).toBeVisible();
      await expect(sphere.locator('.psv-loader-container')).toBeHidden({ timeout: 30000 });
      await page.waitForTimeout(1000); // first frames
      await snap(page, 'viewer-photosphere-partial');
    });

    // The crop is away from where the viewer looks by default
    await test.step('View starts on the image', async () => {
      expect(await blackAtCentre(page)).toBeLessThan(0.05);
    });

    await test.step('Dragging far past the edge stays on the image', async () => {
      const box = (await sphere.boundingBox())!;
      const cx = box.x + box.width / 2;
      const cy = box.y + box.height / 2;
      await page.mouse.move(cx, cy);
      await page.mouse.down();
      await page.mouse.move(cx + 2000, cy + 1000, { steps: 20 });
      await page.mouse.up();
      await page.waitForTimeout(1000);
      expect(await blackAtCentre(page)).toBeLessThan(0.05);
    });
  });
});

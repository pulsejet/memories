import { test, expect, type Locator } from '@playwright/test';
import { appUrl, e2eHeaders, bootstrap, teardown } from './navigation';
import { DavClient } from './utils';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.use({
  extraHTTPHeaders: e2eHeaders({
    timelinePath: '/for-livephoto',
  }),
});

test.describe('@ui Live photo', () => {
  let fileid: number;

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    fileid = await dav.fileid('/for-livephoto/apple_h264_boy_01.jpg');
  });

  test('Timeline live photo hover', async ({ page }) => {
    let pOuter!: Locator;
    let livePhoto!: Locator;
    let livePhotoIcon!: Locator;

    await test.step('Verify initial state', async () => {
      await page.goto(appUrl);
      await page.waitForSelector(`.p-outer--${fileid}`);

      pOuter = page.locator('.p-outer');
      await expect(pOuter).toHaveCount(1);
      await expect(page.locator(`.p-outer--${fileid}`)).toBeVisible();

      livePhoto = pOuter.locator('.memories-livephoto');
      await expect(livePhoto).toBeVisible();

      livePhotoIcon = pOuter.locator('.flag.top-right .livephoto');
      await expect(livePhotoIcon).toBeVisible();
    });

    await test.step('Verify not playing initially', async () => {
      await expect(livePhoto).not.toHaveClass(/playing/);
    });

    await test.step('Verify playback on hover', async () => {
      await livePhotoIcon.hover();
      await expect(livePhoto).toHaveClass(/canplay/);
      await expect(livePhoto).toHaveClass(/playing/);
      await expect(livePhoto.locator('video')).toBeVisible();
    });

    await test.step('Verify stopped after un-hover', async () => {
      await page.mouse.move(0, 0);
      await expect(livePhoto).not.toHaveClass(/playing/);
      await expect(livePhoto).toHaveClass(/canplay/);
      await expect(livePhoto.locator('video')).not.toBeVisible();
    });
  });

  test('Viewer live photo play', async ({ page }) => {
    let playButton!: Locator;
    let viewerLivePhoto!: Locator;

    await test.step('Open Viewer', async () => {
      await page.goto(appUrl);
      await page.waitForSelector(`.p-outer--${fileid}`);

      await page.locator(`.p-outer--${fileid}`).click();
      await page.waitForSelector('body.viewer-fully-opened');

      playButton = page.getByLabel('Play Live Photo');
      await expect(playButton).toBeVisible();

      viewerLivePhoto = page.locator('.pswp .memories-livephoto');
      await expect(viewerLivePhoto).toBeVisible();
    });

    await test.step('Verify not playing initially', async () => {
      await expect(viewerLivePhoto).not.toHaveClass(/playing/);
      await expect(playButton.locator('svg.pause')).not.toBeAttached();
    });

    await test.step('Play Live Photo', async () => {
      await playButton.click();
      await expect(viewerLivePhoto).toHaveClass(/playing/);
      await expect(playButton.locator('svg.pause')).toBeVisible();
    });

    await test.step('Verify after finish', async () => {
      await expect(viewerLivePhoto).not.toHaveClass(/playing/);
      await expect(playButton.locator('svg.pause')).not.toBeAttached();
    });
  });
});

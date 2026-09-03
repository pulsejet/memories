import { test, expect } from '@playwright/test';
import { appUrl, ocsHeaders, bootstrap } from './navigation';
import { getFileId } from './utils';

test.use({
  extraHTTPHeaders: {
    ...ocsHeaders,
    'X-Timeline-Path': '/for-livephoto',
  },
});

test.describe('@ui Live photo', () => {
  let fileid: number;

  test.beforeAll(async ({ request }) => {
    fileid = await getFileId(request, '/for-livephoto/apple_h264_boy_01.jpg');
  });

  test.beforeEach(async ({ page }) => {
    await bootstrap(page);
  });

  test('Timeline live photo play on hover', async ({ page }) => {
    await page.goto(appUrl);
    await page.waitForSelector(`.p-outer--${fileid}`);

    // Verify exactly one p-outer exists on the timeline
    const pOuter = page.locator('.p-outer');
    await expect(pOuter).toHaveCount(1);
    await expect(page.locator(`.p-outer--${fileid}`)).toBeVisible();

    const livePhoto = pOuter.locator('.memories-livephoto');
    await expect(livePhoto).toBeVisible();

    const livePhotoIcon = pOuter.locator('.flag.top-right .livephoto');
    await expect(livePhotoIcon).toBeVisible();

    // Not playing initially
    await expect(livePhoto).not.toHaveClass(/playing/);

    // Hover over live photo icon to start playback
    await livePhotoIcon.hover();

    // Video should be ready to play and currently playing
    await expect(livePhoto).toHaveClass(/canplay/);
    await expect(livePhoto).toHaveClass(/playing/);
    await expect(livePhoto.locator('video')).toBeVisible();

    // Stop hovering
    await page.mouse.move(0, 0);

    // Should stop playing but preserve canplay class
    await expect(livePhoto).not.toHaveClass(/playing/);
    await expect(livePhoto).toHaveClass(/canplay/);
    await expect(livePhoto.locator('video')).not.toBeVisible();
  });

  test('Viewer live photo play', async ({ page }) => {
    await page.goto(appUrl);
    await page.waitForSelector(`.p-outer--${fileid}`);

    // Open the photo in viewer
    await page.locator(`.p-outer--${fileid}`).click();
    await page.waitForSelector('body.viewer-fully-opened');

    const playButton = page.getByLabel('Play Live Photo');
    await expect(playButton).toBeVisible();

    const viewerLivePhoto = page.locator('.pswp .memories-livephoto');
    await expect(viewerLivePhoto).toBeVisible();

    // Autoplay is disabled by default: initially not playing
    await expect(viewerLivePhoto).not.toHaveClass(/playing/);
    await expect(playButton.locator('svg.pause')).not.toBeAttached();

    // Click button to start playback
    await playButton.click();

    // Verify it is playing
    await expect(viewerLivePhoto).toHaveClass(/playing/);
    await expect(playButton.locator('svg.pause')).toBeVisible();

    // Wait for video to finish playing
    await expect(viewerLivePhoto).not.toHaveClass(/playing/);
    await expect(playButton.locator('svg.pause')).not.toBeAttached();
  });
});

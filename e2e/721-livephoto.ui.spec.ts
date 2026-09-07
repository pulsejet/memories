import { test, expect, type Locator } from '@playwright/test';
import { appUrl, e2eHeaders, bootstrap, teardown } from './navigation';
import { DavClient } from './utils';
import { snap } from './screenshots';

import type { IPhoto } from '@typings';

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
      await snap(page, 'viewer-livephoto');
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

test.describe('@api Live photo', () => {
  test('Missing liveid is rejected', async ({ request }) => {
    const dav = new DavClient(request);
    const fileid = await dav.fileid('/for-default/Nested 1/test_01.jpg');
    expect((await request.get(`${appUrl}/api/video/livephoto/${fileid}`)).status()).toBe(400);
  });

  test('Photo without live video is rejected', async ({ request }) => {
    const dav = new DavClient(request);
    const fileid = await dav.fileid('/for-default/Nested 1/test_01.jpg');
    const url = new URL(`${appUrl}/api/video/livephoto/${fileid}`);
    url.searchParams.set('liveid', 'sidecar');
    expect((await request.get(url.toString())).status()).toBe(404);
  });

  test('Live photo JSON and blob', async ({ request }) => {
    const daysRes = await request.get(`${appUrl}/api/days?nopreload=1`);
    expect(daysRes.ok()).toBeTruthy();

    const dayIds = ((await daysRes.json()) as { dayid: number }[]).map((d) => d.dayid);
    const detailRes = await request.post(`${appUrl}/api/days`, { data: { dayIds } });
    const photos: IPhoto[] = await detailRes.json();
    const photo = photos.find((p) => p.liveid);
    expect(photo).toBeDefined();

    const dav = new DavClient(request);
    const liveFileid = photo!.fileid;
    const liveid = photo!.liveid!;

    const jsonUrl = new URL(`${appUrl}/api/video/livephoto/${liveFileid}`);
    jsonUrl.searchParams.set('liveid', liveid);
    jsonUrl.searchParams.set('format', 'json');
    const jsonRes = await request.get(jsonUrl.toString());
    expect(jsonRes.ok()).toBeTruthy();
    expect(typeof (await jsonRes.json()).fileid).toBe('number');

    const blobUrl = new URL(`${appUrl}/api/video/livephoto/${liveFileid}`);
    blobUrl.searchParams.set('liveid', liveid);
    const blobRes = await request.get(blobUrl.toString());
    expect(blobRes.ok()).toBeTruthy();
    expect(blobRes.headers()['content-type']).toContain('video/');
    expect((await blobRes.body()).length).toBeGreaterThan(0);

    expect((await dav.imageInfo(liveFileid)).fileid).toBe(liveFileid);
  });
});

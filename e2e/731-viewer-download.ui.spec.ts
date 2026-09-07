import * as fs from 'fs';
import { test, expect } from '@playwright/test';
import { appUrl, bootstrap, e2eHeaders, teardown } from './navigation';
import { DavClient } from './utils';
import { snap } from './screenshots';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('@ui Viewer image download', () => {
  test('Download image from viewer', async ({ request, page }) => {
    const dav = new DavClient(request);
    const fileid = await dav.fileid('/for-default/Nested 1/test_01.jpg');
    const size = (await dav.imageInfo(fileid)).size;

    await page.goto(appUrl);
    await page.locator(`.p-outer--${fileid} > .img-outer`).click();
    await page.waitForSelector('body.viewer-fully-opened');
    await snap(page, 'viewer-image');

    const downloadPromise = page.waitForEvent('download');
    await page.locator('.memories_viewer').getByRole('button', { name: 'Actions' }).click();
    await page.getByRole('menuitem', { name: 'Download', exact: true }).click();
    const download = await downloadPromise;

    expect(download.suggestedFilename()).toBe('test_01.jpg');

    const dlPath = await download.path();
    expect(dlPath).toBeTruthy();
    expect(fs.statSync(dlPath!).size).toBe(size);
  });
});

test.describe('@ui Viewer live video download', () => {
  test.use({
    extraHTTPHeaders: e2eHeaders({
      timelinePath: '/for-livephoto',
    }),
  });

  test('Download live video from viewer', async ({ request, page }) => {
    const dav = new DavClient(request);
    const fileid = await dav.fileid('/for-livephoto/apple_h264_boy_01.jpg');

    await page.goto(appUrl);
    await page.locator(`.p-outer--${fileid} > .img-outer`).click();
    await page.waitForSelector('body.viewer-fully-opened');

    const downloadPromise = page.waitForEvent('download');
    await page.locator('.memories_viewer').getByRole('button', { name: 'Actions' }).click();
    await page.getByRole('menuitem', { name: 'Download Video' }).click();
    const download = await downloadPromise;

    expect(download.suggestedFilename()).toBe('apple_h264_boy_01.mov');

    const dlPath = await download.path();
    expect(dlPath).toBeTruthy();
    const buf = fs.readFileSync(dlPath!);
    expect(buf.length).toBeGreaterThan(0);
    expect(buf.subarray(4, 8).toString()).toBe('ftyp');
  });
});

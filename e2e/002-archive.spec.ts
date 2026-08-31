import { test, expect } from '@playwright/test';
import { appUrl, ocsHeaders } from './navigation';
import { cleanupPhoto, getFileIdByBasename, getImageInfo } from './utils';

import type { IDay, IPhoto } from '@typings';

import assetArchivedApiDays from './assets/primary-api/archived-days.json';
import assetArchivedApiDay19354 from './assets/primary-api/archived-day-19354.json';

test.use({ extraHTTPHeaders: ocsHeaders });

test.describe('@api Archive', () => {
  test('Query archived days endpoint', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/days?nopreload=1&archive=1`);
    expect(res.ok()).toBeTruthy();

    const data: IDay[] = await res.json();
    expect(data).toStrictEqual(assetArchivedApiDays satisfies IDay[]);
  });

  test('Query archived day endpoint', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/days/19354?archive=1`);
    expect(res.ok()).toBeTruthy();

    const data: IPhoto[] = await res.json();
    data.forEach(cleanupPhoto);

    expect(data).toStrictEqual(assetArchivedApiDay19354 satisfies IPhoto[]);
  });
});

test.describe.serial('@api Archive file', () => {
  // Hardcoded: test user day with 2 photos (from generate-golden).
  // Contains test_05.jpg which is deeply nested — good for testing archive path resolution.
  const DAY_ID = 19375;
  const FILE_PATH_BASE = '/Photos/Nested 1/Nested 1_1/test_05.jpg';
  const FILE_PATH_ARCH = '/Photos/.archive/Nested 1/Nested 1_1/test_05.jpg';
  let fileid: number;

  test.beforeAll(async ({ request }) => {
    fileid = await getFileIdByBasename(request, DAY_ID, 'test_05.jpg');
  });

  test('Archive file', async ({ request }) => {
    // Verify path before archival
    const infoBefore = await getImageInfo(request, fileid);
    expect(infoBefore.filename).toBe(FILE_PATH_BASE);

    // Archive
    const patchRes = await request.patch(`${appUrl}/api/archive/${fileid}`);
    expect(patchRes.ok()).toBeTruthy();

    // Verify path during archival (now in archive)
    const infoDuring = await getImageInfo(request, fileid);
    expect(infoDuring.filename).toBe(FILE_PATH_ARCH);

    // Verify in archive
    const checkRes = await request.get(`${appUrl}/api/days/${DAY_ID}?archive=1`);
    expect(checkRes.ok()).toBeTruthy();

    const archived: IPhoto[] = await checkRes.json();
    expect(archived.some((p) => p.fileid === fileid)).toBeTruthy();

    // Verify gone from main
    const mainRes = await request.get(`${appUrl}/api/days/${DAY_ID}`);
    expect(mainRes.ok()).toBeTruthy();

    const main: IPhoto[] = await mainRes.json();
    expect(main.some((p) => p.fileid === fileid)).toBeFalsy();
  });

  test('Unarchive file', async ({ request }) => {
    // Unarchive
    const patchRes = await request.patch(`${appUrl}/api/archive/${fileid}`, {
      data: { archive: false },
    });
    expect(patchRes.ok()).toBeTruthy();

    // Verify path after unarchival (back in main)
    const infoAfter = await getImageInfo(request, fileid);
    expect(infoAfter.filename).toBe(FILE_PATH_BASE);

    // Verify back in main
    const checkRes = await request.get(`${appUrl}/api/days/${DAY_ID}`);
    expect(checkRes.ok()).toBeTruthy();

    const photos: IPhoto[] = await checkRes.json();
    expect(photos.some((p) => p.fileid === fileid)).toBeTruthy();

    // Verify gone from archive
    const archRes = await request.get(`${appUrl}/api/days/${DAY_ID}?archive=1`);
    expect(archRes.ok()).toBeTruthy();

    const archived: IPhoto[] = await archRes.json();
    expect(archived.some((p) => p.fileid === fileid)).toBeFalsy();
  });
});

test.describe.serial('@ui Archive', () => {
  let fileid1: number;
  let fileid2: number;

  test.beforeAll(async ({ request }) => {
    fileid1 = await getFileIdByBasename(request, 20696, 'NKcupJh-Dos.jpg');
    fileid2 = await getFileIdByBasename(request, 20696, 'CbBbaNTmsAc.jpg');
  });

  test('Archive file', async ({ request, page }) => {
    await page.goto(appUrl);

    await page.hover(`.p-outer--${fileid1}`);
    await page.locator(`.p-outer--${fileid1} > div.select`).click();
    await page.hover(`.p-outer--${fileid2}`);
    await page.locator(`.p-outer--${fileid2} > div.select`).click();

    await page.getByRole('button', { name: 'Actions' }).click();
    await page.getByRole('menuitem', { name: 'Archive' }).click();

    await expect(page.locator(`.p-outer--${fileid1}`)).toHaveCount(0);
    await expect(page.locator(`.p-outer--${fileid2}`)).toHaveCount(0);

    expect((await getImageInfo(request, fileid1)).filename?.includes('archive')).toBeTruthy();
    expect((await getImageInfo(request, fileid2)).filename?.includes('archive')).toBeTruthy();
  });

  test('Unarchive file', async ({ page, request }) => {
    await page.goto(`${appUrl}/archive`);

    await page.hover(`.p-outer--${fileid1}`);
    await page.locator(`.p-outer--${fileid1} > div.select`).click();
    await page.hover(`.p-outer--${fileid2}`);
    await page.locator(`.p-outer--${fileid2} > div.select`).click();

    await page.getByRole('button', { name: 'Actions' }).click();
    await page.getByRole('menuitem', { name: 'Unarchive' }).click();

    await expect(page.locator(`.p-outer--${fileid1}`)).toHaveCount(0);
    await expect(page.locator(`.p-outer--${fileid2}`)).toHaveCount(0);

    expect((await getImageInfo(request, fileid1)).filename?.includes('archive')).toBeFalsy();
    expect((await getImageInfo(request, fileid2)).filename?.includes('archive')).toBeFalsy();
  });
});

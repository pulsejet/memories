import { test, expect } from '@playwright/test';
import { appUrl, authHeaders as auth } from './login';
import { cleanupPhoto, getImageInfo } from './utils';

import type { IDay, IPhoto } from '@typings';

import assetArchivedApiDays from './assets/primary-api/archived-days.json';
import assetArchivedApiDay19354 from './assets/primary-api/archived-day-19354.json';

test.describe('@api Archive', () => {
  test('Query archived days endpoint', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/days?nopreload=1&archive=1`, { headers: auth });
    expect(res.ok()).toBeTruthy();

    const data: IDay[] = await res.json();
    expect(data).toStrictEqual(assetArchivedApiDays satisfies IDay[]);
  });

  test('Query archived day endpoint', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/days/19354?archive=1`, { headers: auth });
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

  test('Archive file', async ({ request }) => {
    // Get fileid from main day
    const getRes = await request.get(`${appUrl}/api/days/${DAY_ID}`, { headers: auth });
    expect(getRes.ok()).toBeTruthy();

    const photos: IPhoto[] = await getRes.json();
    expect(photos.length).toBeGreaterThan(0);
    fileid = photos[0].fileid;
    expect(fileid).toBeGreaterThan(0);

    // Verify path before archival
    const infoBefore = await getImageInfo(request, fileid);
    expect(infoBefore.filename).toBe(FILE_PATH_BASE);

    // Archive
    const patchRes = await request.patch(`${appUrl}/api/archive/${fileid}`, { headers: auth });
    expect(patchRes.ok()).toBeTruthy();

    // Verify path during archival (now in archive)
    const infoDuring = await getImageInfo(request, fileid);
    expect(infoDuring.filename).toBe(FILE_PATH_ARCH);

    // Verify in archive
    const checkRes = await request.get(`${appUrl}/api/days/${DAY_ID}?archive=1`, { headers: auth });
    expect(checkRes.ok()).toBeTruthy();

    const archived: IPhoto[] = await checkRes.json();
    expect(archived.some((p) => p.fileid === fileid)).toBeTruthy();

    // Verify gone from main
    const mainRes = await request.get(`${appUrl}/api/days/${DAY_ID}`, { headers: auth });
    expect(mainRes.ok()).toBeTruthy();

    const main: IPhoto[] = await mainRes.json();
    expect(main.some((p) => p.fileid === fileid)).toBeFalsy();
  });

  test('Unarchive file', async ({ request }) => {
    // Unarchive
    const patchRes = await request.patch(`${appUrl}/api/archive/${fileid}`, {
      headers: auth,
      data: { archive: false },
    });
    expect(patchRes.ok()).toBeTruthy();

    // Verify path after unarchival (back in main)
    const infoAfter = await getImageInfo(request, fileid);
    expect(infoAfter.filename).toBe(FILE_PATH_BASE);

    // Verify back in main
    const checkRes = await request.get(`${appUrl}/api/days/${DAY_ID}`, { headers: auth });
    expect(checkRes.ok()).toBeTruthy();

    const photos: IPhoto[] = await checkRes.json();
    expect(photos.some((p) => p.fileid === fileid)).toBeTruthy();

    // Verify gone from archive
    const archRes = await request.get(`${appUrl}/api/days/${DAY_ID}?archive=1`, { headers: auth });
    expect(archRes.ok()).toBeTruthy();

    const archived: IPhoto[] = await archRes.json();
    expect(archived.some((p) => p.fileid === fileid)).toBeFalsy();
  });
});

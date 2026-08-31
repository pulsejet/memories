import { test, expect } from '@playwright/test';
import { randomBytes } from 'crypto';
import { appUrl, ocsHeaders } from './navigation';
import { getFileIdByBasename, getImageInfo } from './utils';

test.use({ extraHTTPHeaders: ocsHeaders });

test.describe('@api Image setExif', () => {
  let fileid: number;

  test.beforeAll(async ({ request }) => {
    fileid = await getFileIdByBasename(request, 19421, 'test_03.jpg');
  });

  test('Set and verify description via setExif', async ({ request }) => {
    // Generate random hex strings for description and title
    const randomDesc = randomBytes(16).toString('hex');
    const randomTitle = randomBytes(16).toString('hex');

    // Get info and verify description doesn't match our random value
    const before = await getImageInfo(request, fileid);
    expect(before.exif?.Description).not.toBe(randomDesc);
    expect(before.exif?.Title).not.toBe(randomTitle);

    // Set description and title using setExif
    const setRes = await request.patch(`${appUrl}/api/image/set-exif/${fileid}`, {
      data: {
        id: fileid,
        raw: {
          Description: randomDesc,
          Title: randomTitle,
        },
      },
    });
    expect(setRes.ok()).toBeTruthy();

    // Get info and verify description and title match
    const after = await getImageInfo(request, fileid);
    expect(after.exif?.Description).toBe(randomDesc);
    expect(after.exif?.Title).toBe(randomTitle);
  });
});

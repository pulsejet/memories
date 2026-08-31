import { test, expect } from '@playwright/test';
import { appUrl, authHeaders, username } from './login';
import type { IImageInfo } from '@typings';
import { getFileIdByBasename } from './utils';

import assetImageInfoTest01 from './assets/primary-api/image-info-test_01.jpg.json';

test.describe('@api Image info', () => {
  test('Query image info for test_01.jpg', async ({ request }) => {
    const fileid = await getFileIdByBasename(request, 19532, 'test_01.jpg');

    const res = await request.get(`${appUrl}/api/image/info/${fileid}`, { headers: authHeaders });
    expect(res.ok()).toBeTruthy();

    const data: IImageInfo = await res.json();

    // Compare owner with current user.
    expect(data.owneruid).toBe(username);
    data.owneruid = '<uid>';
    expect(data.ownername).toBe(username);
    data.ownername = '<uid>';

    // Replace sentinel values.
    expect(typeof data.etag).toBe('string');
    expect(data.etag.length).toBeGreaterThan(0);
    data.etag = '<etag>';
    expect(data.fileid).toBeGreaterThan(0);
    data.fileid = 0;
    expect(data.mtime).toBeGreaterThan(0);
    data.mtime = 0;

    // These depend on reverse geocoding, not setup yet.
    delete data.address;
    delete data.exif?.DateTimeEpoch;
    delete data.exif?.LocationTZID;

    expect(data).toStrictEqual(assetImageInfoTest01 satisfies IImageInfo);
  });
});

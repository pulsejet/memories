import { test, expect } from '@playwright/test';
import { appUrl, e2eHeaders, username } from './navigation';
import { DavClient } from './utils';
import { goldImageInfo } from './dataset-measurements';

import type { IImageInfo } from '@typings';

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('@api Image Info', () => {
  test('Query image info for test_01.jpg', async ({ request }) => {
    const dav = new DavClient(request);
    const fileid = await dav.fileid('/for-default/Nested 1/test_01.jpg');

    const res = await request.get(`${appUrl}/api/image/info/${fileid}`);
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
    delete data.exif?.ExifVersion;
    delete data.exif?.ColorSpace;

    expect(data).toStrictEqual(goldImageInfo('primary/for-default/Nested 1/test_01.jpg'));
  });
});

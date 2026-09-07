import { test, expect } from '@playwright/test';
import { imageSize } from 'image-size';
import { appUrl, e2eHeaders, psub, username } from './navigation';
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

  test('Info with current EXIF', async ({ request }) => {
    const dav = new DavClient(request);
    const fileid = await dav.fileid('/for-default/Nested 1/test_01.jpg');

    const url = new URL(`${appUrl}/api/image/info/${fileid}`);
    url.searchParams.set('current', '1');
    const res = await request.get(url.toString());
    expect(res.ok()).toBeTruthy();

    const data: IImageInfo & { current?: Record<string, unknown> } = await res.json();
    expect(data.fileid).toBe(fileid);
    expect(data.current).toBeDefined();
  });

  test('Info with clusters', async ({ request }) => {
    const dav = new DavClient(request);
    const fileid = await dav.fileid('/for-default/Nested 1/test_01.jpg');

    // Only albums supports file filtering (tags/places throw); mirrors sidebar usage.
    const url = new URL(`${appUrl}/api/image/info/${fileid}`);
    url.searchParams.set('clusters', 'albums');
    const res = await request.get(url.toString());
    expect(res.ok()).toBeTruthy();

    const data: IImageInfo & { clusters?: Record<string, unknown[]> } = await res.json();
    expect(data.clusters).toBeDefined();
    expect(Array.isArray(data.clusters!.albums)).toBeTruthy();
  });
});

test.describe('@api Image decodable', () => {
  test('Decodable JPEG returns image', async ({ request }) => {
    const dav = new DavClient(request);
    const fileid = await dav.fileid('/for-default/Nested 1/test_01.jpg');

    const res = await request.get(`${appUrl}/api/image/decodable/${fileid}`);
    expect(res.ok()).toBeTruthy();
    expect(res.headers()['content-type']).toContain('image/');

    const body = await res.body();
    expect(body.length).toBeGreaterThan(0);
    expect(imageSize(new Uint8Array(body)).type).toBe('jpg');
  });
});

test.describe('@api Image delete via API', () => {
  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    await dav.deleteFile(psub('/for-api-delete-%wid.jpg'), true);
    await dav.copyFile('/for-other/RmjH76vMWrI.jpg', psub('/for-api-delete-%wid.jpg'));
  });

  test.afterAll(async ({ request }) => {
    await new DavClient(request).deleteFile(psub('/for-api-delete-%wid.jpg'), true);
  });

  test('Delete file removes it from timeline', async ({ request }) => {
    const dav = new DavClient(request);
    const fileid = await dav.fileid(psub('/for-api-delete-%wid.jpg'));

    const delRes = await request.delete(`${appUrl}/api/image/delete/${fileid}`);
    expect(delRes.ok()).toBeTruthy();
    expect((await delRes.json()).deleted).toBe(true);

    const infoRes = await request.get(`${appUrl}/api/image/info/${fileid}`);
    expect(infoRes.ok()).toBeFalsy();
  });
});

test.describe('@api Image edit copy', () => {
  const src = psub('/for-api-edit-%wid.jpg');
  const copy = psub('/for-api-edit-copy-%wid.jpg');

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    await dav.deleteFile(src, true);
    await dav.deleteFile(copy, true);
    await dav.copyFile('/for-other/dHLhDeEgxsg.jpg', src);
  });

  test.afterAll(async ({ request }) => {
    const dav = new DavClient(request);
    await dav.deleteFile(src, true);
    await dav.deleteFile(copy, true);
  });

  test('Edit to a copy creates new file', async ({ request }) => {
    const dav = new DavClient(request);
    const fileid = await dav.fileid(src);
    const info: IImageInfo = await dav.imageInfo(fileid);
    const copyName = psub('for-api-edit-copy-%wid.jpg');

    const res = await request.put(`${appUrl}/api/image/edit/${fileid}`, {
      data: {
        name: copyName,
        width: info.w,
        height: info.h,
        quality: 0.9,
        extension: 'jpg',
        state: [],
      },
    });
    expect(res.ok()).toBeTruthy();

    const data: IImageInfo = await res.json();
    expect(data.fileid).toBeGreaterThan(0);
    expect(data.fileid).not.toBe(fileid);
    expect(data.basename).toBe(copyName);

    const copyId = await dav.fileid(copy);
    expect(copyId).toBe(data.fileid);
  });
});

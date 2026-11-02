import { expect } from '@playwright/test';
import { imageSize } from 'image-size';
import { test, appUrl, e2eHeaders, psub, username, buildUrl } from './navigation';
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

    // Fields that depend on the places database or aren't in the golden data.
    delete data.address;
    delete data.address_short;
    delete data.exif?.DateTimeEpoch;
    delete data.exif?.LocationTZID;
    delete data.exif?.ExifVersion;
    delete data.exif?.ColorSpace;

    expect(data).toStrictEqual(goldImageInfo('primary/for-default/Nested 1/test_01.jpg'));
  });

  test('Info with current EXIF', async ({ request }) => {
    const dav = new DavClient(request);
    const fileid = await dav.fileid('/for-default/Nested 1/test_01.jpg');

    const url = buildUrl(`/api/image/info/${fileid}`, { current: '1' });
    const res = await request.get(url);
    expect(res.ok()).toBeTruthy();

    const data: IImageInfo & { current?: Record<string, unknown> } = await res.json();
    const gold = goldImageInfo('primary/for-default/Nested 1/test_01.jpg');
    expect(data.fileid).toBe(fileid);
    expect(data.current?.ImageWidth).toBe(gold.w);
    expect(data.current?.DateTimeOriginal).toBe(gold.exif?.DateTimeOriginal);
  });

  test('Info with clusters', async ({ request }) => {
    const dav = new DavClient(request);
    const fileid = await dav.fileid('/for-default/Nested 1/test_01.jpg');

    // Only albums supports file filtering (tags/places throw); mirrors sidebar usage.
    const url = buildUrl(`/api/image/info/${fileid}`, { clusters: 'albums' });

    const albumName = psub('E2E Info Album %wid');
    const albumPath = `photos/${username}/albums/${albumName}`;
    const albumNames = async () => {
      const res = await request.get(url);
      expect(res.ok()).toBeTruthy();
      const data: IImageInfo = await res.json();
      return data.clusters!.albums!.map((a) => a.name);
    };

    await dav.del(albumPath, true);
    await dav.mkcol(albumPath);
    try {
      expect(await albumNames()).not.toContain(albumName);
      await dav.copy(`files/${username}/for-default/Nested 1/test_01.jpg`, `${albumPath}/test_01.jpg`);
      expect(await albumNames()).toContain(albumName);
    } finally {
      await dav.del(albumPath, true);
    }
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
    expect(infoRes.status()).toBe(404);
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
    const copyName = psub('for-api-edit-copy-%wid.jpg');

    const res = await request.put(`${appUrl}/api/image/edit/${fileid}`, {
      data: {
        name: copyName,
        extension: 'jpg',
        edits: [],
        quality: 90,
        copy: true,
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

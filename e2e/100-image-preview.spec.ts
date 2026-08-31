import { test, expect } from '@playwright/test';
import { appUrl, authHeaders } from './login';
import { getFileIdByBasename } from './utils';
import { imageSize } from 'image-size';

test.describe('@api Image preview', () => {
  let fileid: number;

  test.beforeAll(async ({ request }) => {
    // JPEG 640x360 test image
    fileid = await getFileIdByBasename(request, 19532, 'test_01.jpg');
  });

  test('Get 1024x1024 preview', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/image/preview/${fileid}`, {
      headers: authHeaders,
      params: { x: 1024, y: 1024, a: 1 },
    });
    expect(res.ok()).toBeTruthy();

    const body = await res.body();
    expect(body.length).toBeGreaterThan(0);

    // The exact values depend on the configuration, just assert
    // it has been scaled down to a preview size.
    const size = imageSize(new Uint8Array(body));
    expect(size.height).toBeGreaterThanOrEqual(128);
    expect(size.height).toBeLessThanOrEqual(1024);
    expect(size.width).toBeGreaterThan(size.height);
  });

  test('Get 32x32 square preview', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/image/preview/${fileid}`, {
      headers: authHeaders,
      params: { x: 32, y: 32 },
    });
    expect(res.ok()).toBeTruthy();

    const body = await res.body();
    expect(body.length).toBeGreaterThan(0);

    const size = imageSize(new Uint8Array(body));
    expect(size.height).toBeGreaterThanOrEqual(8);
    expect(size.height).toBeLessThanOrEqual(128);
    expect(size.width).toEqual(size.height);
  });

  test('Get 1024x1024 square preview', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/image/preview/${fileid}`, {
      headers: authHeaders,
      params: { x: 1024, y: 1024 },
    });
    expect(res.ok()).toBeTruthy();

    const body = await res.body();
    expect(body.length).toBeGreaterThan(0);

    const size = imageSize(new Uint8Array(body));
    expect(size.height).toBeGreaterThanOrEqual(128);
    expect(size.height).toBeLessThanOrEqual(1024);
    expect(size.width).toEqual(size.height);
  });
});

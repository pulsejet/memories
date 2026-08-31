import { test, expect } from '@playwright/test';
import { appUrl, ocsHeaders } from './navigation';
import { getFileIdByBasename } from './utils';
import { imageSize } from 'image-size';

test.use({ extraHTTPHeaders: ocsHeaders });

// We need to run this test serially so that previews are
// always generated for the last multipreview test.
test.describe.serial('@api Image preview', () => {
  let fileid1: number;
  let fileid2: number;

  test.beforeAll(async ({ request }) => {
    // JPEG 640x360 test image
    fileid1 = await getFileIdByBasename(request, 19532, 'test_01.jpg');
    // JPEG 640x480 test image
    fileid2 = await getFileIdByBasename(request, 19468, 'test_02.jpg');
  });

  test('Get 32x32 preview', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/image/preview/${fileid1}`, {
      params: { x: 32, y: 32, a: 1 },
    });
    expect(res.ok()).toBeTruthy();

    const body = await res.body();
    expect(body.length).toBeGreaterThan(0);

    // The exact values depend on the configuration, just assert
    // it has been scaled down to a preview size.
    const size = imageSize(new Uint8Array(body));
    expect(size.height).toBeGreaterThanOrEqual(8);
    expect(size.height).toBeLessThanOrEqual(256);
    expect(size.width).toBeGreaterThan(size.height);
  });

  test('Get 32x32 square preview', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/image/preview/${fileid2}`, {
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
    const res = await request.get(`${appUrl}/api/image/preview/${fileid2}`, {
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

  test('Get multipreview for two images', async ({ request }) => {
    const res = await request.post(`${appUrl}/api/image/multipreview`, {
      data: {
        files: [
          { reqid: 'f1', fileid: fileid1, x: 32, y: 32, a: '1' },
          { reqid: 'f2', fileid: fileid2, x: 32, y: 32, a: '0' },
        ],
      },
    });
    expect(res.ok()).toBeTruthy();

    const buf = await res.body();
    expect(buf.length).toBeGreaterThan(0);

    // Parse multipreview response: for each preview: 1 byte json len + json + image
    let offset = 0;
    const previews: { reqid: string; width: number; height: number }[] = [];

    while (offset < buf.length) {
      const jsonLen = buf[offset];
      offset += 1;

      const jsonBuf = buf.subarray(offset, offset + jsonLen);
      const json = JSON.parse(jsonBuf.toString());
      offset += jsonLen;

      const imgBuf = buf.subarray(offset, offset + json.len);
      offset += json.len;

      const size = imageSize(new Uint8Array(imgBuf));
      previews.push({ reqid: json.reqid, width: size.width!, height: size.height! });
    }

    expect(previews).toHaveLength(2);

    // test_01.jpg (a=1, non-square): 640x360 -> crop to 32x32
    const p1 = previews.find((p) => p.reqid === 'f1');
    expect(p1).toBeDefined();
    expect(p1!.height).toBeLessThanOrEqual(128);
    expect(p1!.height).toBeGreaterThanOrEqual(8);
    expect(p1!.width).toBeGreaterThan(p1!.height);

    // test_02.jpg (a=0, square crop): 640x480 -> fill to 32x32
    const p2 = previews.find((p) => p.reqid === 'f2');
    expect(p2).toBeDefined();
    expect(p2!.height).toBeLessThanOrEqual(128);
    expect(p2!.height).toBeGreaterThanOrEqual(8);
    expect(p2!.width).toEqual(p2!.height);
  });
});

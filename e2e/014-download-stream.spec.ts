import { test, expect } from '@playwright/test';
import { appUrl, e2eHeaders } from './navigation';
import { DavClient } from './utils';

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('@api Download and stream', () => {
  let fileid1: number;
  let fileid2: number;
  let fileSize: number;

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    fileid1 = await dav.fileid('/for-default/Nested 1/test_01.jpg');
    fileid2 = await dav.fileid('/for-default/Nested 1/test_02.jpg');
    fileSize = (await dav.imageInfo(fileid1)).size!;
    expect(fileSize).toBeGreaterThan(0);
  });

  test('Download single file via handle', async ({ request }) => {
    const reqRes = await request.post(`${appUrl}/api/download`, { data: { files: [fileid1] } });
    expect(reqRes.ok()).toBeTruthy();
    const { handle } = await reqRes.json();
    expect(typeof handle).toBe('string');

    const res = await request.get(`${appUrl}/api/download/${handle}`);
    expect(res.ok()).toBeTruthy();
    expect(res.headers()['content-type']).toContain('image/');
    const body = await res.body();
    expect(body.length).toBe(fileSize);
  });

  test('Download multiple files as zip', async ({ request }) => {
    const reqRes = await request.post(`${appUrl}/api/download`, {
      data: { files: [fileid1, fileid2] },
    });
    expect(reqRes.ok()).toBeTruthy();
    const { handle } = await reqRes.json();

    const res = await request.get(`${appUrl}/api/download/${handle}`);
    expect(res.ok()).toBeTruthy();
    expect(res.headers()['content-type']).toContain('zip');
    const body = await res.body();
    expect(body.length).toBeGreaterThan(0);
    expect(body[0]).toBe(0x50);
    expect(body[1]).toBe(0x4b);
  });

  test('Download invalid handle is rejected', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/download/invalid-handle-xyz`);
    expect(res.ok()).toBeFalsy();
    expect(res.status()).toBe(404);
  });

  test('Stream full file', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/stream/${fileid1}`);
    expect(res.ok()).toBeTruthy();
    expect(res.headers()['accept-ranges']).toBe('bytes');
    expect(Number(res.headers()['content-length'])).toBe(fileSize);
    expect((await res.body()).length).toBe(fileSize);
  });

  test('Stream byte range', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/stream/${fileid1}`, {
      headers: { Range: 'bytes=0-99' },
    });
    expect(res.status()).toBe(206);
    expect(res.headers()['content-range']).toBe(`bytes 0-99/${fileSize}`);
    expect((await res.body()).length).toBe(100);

    const suffix = await request.get(`${appUrl}/api/stream/${fileid1}`, {
      headers: { Range: 'bytes=-100' },
    });
    expect(suffix.status()).toBe(206);
    expect((await suffix.body()).length).toBe(100);
  });

  test('Stream unsatisfiable range', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/stream/${fileid1}`, {
      headers: { Range: `bytes=${fileSize}-${fileSize + 100}` },
    });
    expect(res.status()).toBe(416);
  });

  test('Stream HEAD request', async ({ request }) => {
    const res = await request.fetch(`${appUrl}/api/stream/${fileid1}`, { method: 'HEAD' });
    expect(res.ok()).toBeTruthy();
    expect(Number(res.headers()['content-length'])).toBe(fileSize);
    expect((await res.body()).length).toBe(0);
  });
});

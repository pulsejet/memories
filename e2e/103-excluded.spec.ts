import * as fs from 'fs';
import * as path from 'path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';
import { appUrl, e2eHeaders, psub } from './navigation';
import { DavClient } from './utils';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('@api Excluded folders are not indexed', () => {
  const excludedPaths = [
    '/for-excluded/@Recycle/recycle_01.jpg',
    '/for-excluded/@eaDir/eadir_01.jpg',
    '/for-excluded/.trashed-12345/trashed_01.jpg',
    '/for-excluded/Nested/@Recycle/nested_recycle_01.jpg',
    '/for-excluded/with-nomedia/photo_01.jpg',
    '/for-excluded/with-nomemories/photo_02.jpg',
  ];

  for (const filePath of excludedPaths) {
    test(`Image info errors for ${filePath}`, async ({ request }) => {
      const dav = new DavClient(request);
      const fileid = await dav.fileid(filePath);

      const res = await request.get(`${appUrl}/api/image/info/${fileid}`);
      expect(res.ok()).toBeFalsy();
    });
  }
});

test.describe('@api Excluded folders upload', () => {
  const trashedFile = psub('/for-excluded/.trashed-12345/e2e_upload_%wid.jpg');
  const nomediaFile = psub('/for-excluded/with-nomedia/e2e_upload_%wid.jpg');
  const nomemoriesFile = psub('/for-excluded/with-nomemories/e2e_upload_%wid.jpg');
  const normalFile = psub('/for-excluded/e2e_upload_%wid.jpg');

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    const img = fs.readFileSync(path.resolve(__dirname, '../tests/assets/apple_h264_boy_01.jpg'));

    await dav.putFile(trashedFile, img, 'image/jpeg');
    await dav.putFile(nomediaFile, img, 'image/jpeg');
    await dav.putFile(nomemoriesFile, img, 'image/jpeg');
    await dav.putFile(normalFile, img, 'image/jpeg');
  });

  test.afterAll(async ({ request }) => {
    const dav = new DavClient(request);
    await dav.deleteFile(trashedFile, true);
    await dav.deleteFile(nomediaFile, true);
    await dav.deleteFile(nomemoriesFile, true);
    await dav.deleteFile(normalFile, true);
  });

  for (const [name, filePath] of [
    ['trashed', trashedFile],
    ['nomedia', nomediaFile],
    ['nomemories', nomemoriesFile],
  ] as const) {
    test(`Uploaded image is not indexed in ${name} folder`, async ({ request }) => {
      const dav = new DavClient(request);
      const fileid = await dav.fileid(filePath);

      const res = await request.get(`${appUrl}/api/image/info/${fileid}`);
      expect(res.ok()).toBeFalsy();
    });
  }

  test('Uploaded image is indexed in normal folder', async ({ request }) => {
    const dav = new DavClient(request);
    const fileid = await dav.fileid(normalFile);

    const res = await request.get(`${appUrl}/api/image/info/${fileid}`);
    expect(res.ok()).toBeTruthy();
  });
});

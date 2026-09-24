import { test, expect } from '@playwright/test';
import { appUrl, e2eHeaders } from './navigation';
import { DavClient } from './utils';

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

import { test, expect } from '@playwright/test';
import { bootstrap, teardown, appUrl, e2eHeaders, psub } from './navigation';
import { getFileId, getImageInfo, deletePath, copyPath } from './utils';

import type { IPhoto } from '@typings';

test.use({
  extraHTTPHeaders: e2eHeaders({
    timelinePath: '/for-archive-%wid',
  }),
});

test.describe('Archive', () => {
  // Hardcoded: day with 2 photos (test_04.jpg and test_05.jpg).
  // Contains test_05.jpg which is deeply nested — tests archive path resolution.
  const DAY_ID = 19375;
  const FILE_PATH_BASE = psub('/for-archive-%wid/Nested 1/Nested 1_1/test_05.jpg');
  const FILE_PATH_ARCH = psub('/for-archive-%wid/.archive/Nested 1/Nested 1_1/test_05.jpg');

  test.beforeAll(async ({ request }) => {
    await deletePath(request, '/for-archive-%wid', true);
    await copyPath(request, '/for-archive', '/for-archive-%wid');
  });

  test.afterAll(async ({ request }) => {
    await deletePath(request, '/for-archive-%wid');
  });

  test.beforeEach(bootstrap);
  test.afterEach(teardown);

  test('@api Archive from API', async ({ request }) => {
    const fileid = await getFileId(request, FILE_PATH_BASE);

    await test.step('Archive', async () => {
      await test.step('Verify path before archive', async () => {
        const infoBefore = await getImageInfo(request, fileid, { basic: '1' });
        expect(infoBefore.filename).toBe(FILE_PATH_BASE);
      });

      await test.step('Submit', async () => {
        const patchRes = await request.patch(`${appUrl}/api/archive/${fileid}`);
        expect(patchRes.ok()).toBeTruthy();
      });

      await test.step('Verify path after archive', async () => {
        const infoDuring = await getImageInfo(request, fileid, { basic: '1' });
        expect(infoDuring.filename).toBe(FILE_PATH_ARCH);
      });

      await test.step('Verify file is in archive', async () => {
        const checkRes = await request.get(`${appUrl}/api/days/${DAY_ID}?archive=1`);
        expect(checkRes.ok()).toBeTruthy();

        const archived: IPhoto[] = await checkRes.json();
        expect(archived.some((p) => p.fileid === fileid)).toBeTruthy();
      });

      await test.step('Verify file is gone from main', async () => {
        const mainRes = await request.get(`${appUrl}/api/days/${DAY_ID}`);
        expect(mainRes.ok()).toBeTruthy();

        const main: IPhoto[] = await mainRes.json();
        expect(main.some((p) => p.fileid === fileid)).toBeFalsy();
      });
    });

    await test.step('Unarchive file', async () => {
      await test.step('Unarchive', async () => {
        const patchRes = await request.patch(`${appUrl}/api/archive/${fileid}`, {
          data: { archive: false },
        });
        expect(patchRes.ok()).toBeTruthy();
      });

      await test.step('Verify path after unarchive', async () => {
        const infoAfter = await getImageInfo(request, fileid, { basic: '1' });
        expect(infoAfter.filename).toBe(FILE_PATH_BASE);
      });

      await test.step('Verify file is back in main', async () => {
        const checkRes = await request.get(`${appUrl}/api/days/${DAY_ID}`);
        expect(checkRes.ok()).toBeTruthy();

        const photos: IPhoto[] = await checkRes.json();
        expect(photos.some((p) => p.fileid === fileid)).toBeTruthy();
      });

      await test.step('Verify file is gone from archive', async () => {
        const archRes = await request.get(`${appUrl}/api/days/${DAY_ID}?archive=1`);
        expect(archRes.ok()).toBeTruthy();

        const archived: IPhoto[] = await archRes.json();
        expect(archived.some((p) => p.fileid === fileid)).toBeFalsy();
      });
    });
  });

  test('@ui Archive from UI', async ({ page, request }) => {
    const fileid1 = await getFileId(request, '/for-archive-%wid/ui_test_01.jpg');
    const fileid2 = await getFileId(request, '/for-archive-%wid/ui_test_02.jpg');

    await test.step('Archive', async () => {
      await test.step('Archive files', async () => {
        await page.goto(appUrl);

        await page.hover(`.p-outer--${fileid1}`);
        await page.locator(`.p-outer--${fileid1} > div.select`).click();
        await page.hover(`.p-outer--${fileid2}`);
        await page.locator(`.p-outer--${fileid2} > div.select`).click();

        await page.getByRole('button', { name: 'Actions' }).click();
        await page.getByRole('menuitem', { name: 'Archive' }).click();

        await expect(page.locator(`.p-outer--${fileid1}`)).toHaveCount(0);
        await expect(page.locator(`.p-outer--${fileid2}`)).toHaveCount(0);
      });

      await test.step('Verify files are in archive', async () => {
        expect((await getImageInfo(request, fileid1, { basic: '1' })).filename?.includes('.archive/')).toBeTruthy();
        expect((await getImageInfo(request, fileid2, { basic: '1' })).filename?.includes('.archive/')).toBeTruthy();
      });
    });

    await test.step('Unarchive', async () => {
      await test.step('Submit', async () => {
        await page.goto(`${appUrl}/archive`);

        await page.hover(`.p-outer--${fileid1}`);
        await page.locator(`.p-outer--${fileid1} > div.select`).click();
        await page.hover(`.p-outer--${fileid2}`);
        await page.locator(`.p-outer--${fileid2} > div.select`).click();

        await page.getByRole('button', { name: 'Actions' }).click();
        await page.getByRole('menuitem', { name: 'Unarchive' }).click();

        await expect(page.locator(`.p-outer--${fileid1}`)).toHaveCount(0);
        await expect(page.locator(`.p-outer--${fileid2}`)).toHaveCount(0);
      });

      await test.step('Verify files are back in main', async () => {
        expect((await getImageInfo(request, fileid1, { basic: '1' })).filename?.includes('.archive/')).toBeFalsy();
        expect((await getImageInfo(request, fileid2, { basic: '1' })).filename?.includes('.archive/')).toBeFalsy();
      });
    });
  });
});

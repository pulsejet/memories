import { test, expect } from '@playwright/test';
import { appUrl, e2eHeaders, bootstrap, psub, teardown } from './navigation';
import { DavClient } from './utils';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('@ui Folder file operations', () => {
  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    await dav.deleteFile('/for-move-%wid', true);
    await dav.copyFile('/for-move', '/for-move-%wid');
  });

  test.afterAll(async ({ request }) => {
    await new DavClient(request).deleteFile('/for-move-%wid');
  });

  test('Select image and move out of folder', async ({ request, page }) => {
    const dav = new DavClient(request);
    const fileid1 = await dav.fileid('/for-move-%wid/source/move_01.jpg');
    const fileid2 = await dav.fileid('/for-move-%wid/source/move_02.jpg');

    await page.goto(psub(`${appUrl}/folders/for-move-%wid`));

    await test.step('Select src files', async () => {
      await page.getByRole('link', { name: 'source' }).click();

      await page.hover(`.p-outer--${fileid1}`);
      await page.locator(`.p-outer--${fileid1} > div.select`).click();
      await page.hover(`.p-outer--${fileid2}`);
      await page.locator(`.p-outer--${fileid2} > div.select`).click();
    });

    await test.step('Move files to dest folder', async () => {
      await page.getByRole('button', { name: 'Actions' }).click();
      await page.getByRole('menuitem', { name: 'Move to folder' }).click();
      await page.getByRole('cell', { name: psub('for-move-%wid') }).click();
      await page.getByRole('cell', { name: 'dest' }).click();
      await page.getByRole('button', { name: 'Move', exact: true }).click();
    });

    await test.step('Verify gone from src', async () => {
      await expect(page.locator(`.p-outer--${fileid1}`)).toHaveCount(0);
      await expect(page.locator(`.p-outer--${fileid2}`)).toHaveCount(0);
    });

    await test.step('Verify in dest', async () => {
      await page.goto(psub(`${appUrl}/folders/for-move-%wid/dest`));
      await expect(page.locator(`.p-outer--${fileid1}`)).toHaveCount(1);
      await expect(page.locator(`.p-outer--${fileid2}`)).toHaveCount(1);
    });
  });
});

import { test, expect } from '@playwright/test';
import { appUrl, ocsHeaders, bootstrap } from './navigation';
import { getFileId } from './utils';

test.use({ extraHTTPHeaders: ocsHeaders });

test.describe('@ui @destructive Folder file operations', () => {
  let fileid1: number;
  let fileid2: number;

  test.beforeAll(async ({ request }) => {
    fileid1 = await getFileId(request, '/for-move/source/move_01.jpg');
    fileid2 = await getFileId(request, '/for-move/source/move_02.jpg');
  });

  test.beforeEach(async ({ page }) => {
    await bootstrap(page);
  });

  test('Select image and move out of folder', async ({ page }) => {
    await page.goto(`${appUrl}/folders/for-move`);

    await page.getByRole('link', { name: 'source' }).click();

    await page.hover(`.p-outer--${fileid1}`);
    await page.locator(`.p-outer--${fileid1} > div.select`).click();
    await page.hover(`.p-outer--${fileid2}`);
    await page.locator(`.p-outer--${fileid2} > div.select`).click();

    await page.getByRole('button', { name: 'Actions' }).click();
    await page.getByRole('menuitem', { name: 'Move to folder' }).click();
    await page.getByRole('cell', { name: 'for-move' }).getByTestId('row-name').click();
    await page.getByRole('cell', { name: 'dest' }).getByTestId('row-name').click();
    await page.getByRole('button', { name: 'Move', exact: true }).click();

    await expect(page.locator(`.p-outer--${fileid1}`)).toHaveCount(0);
    await expect(page.locator(`.p-outer--${fileid2}`)).toHaveCount(0);

    await page.goto(`${appUrl}/folders/for-move/dest`);
    await expect(page.locator(`.p-outer--${fileid1}`)).toHaveCount(1);
    await expect(page.locator(`.p-outer--${fileid2}`)).toHaveCount(1);
  });
});

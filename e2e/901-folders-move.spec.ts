import { test, expect } from '@playwright/test';
import { appUrl, bootstrap } from './navigation';
import { getFileId } from './utils';

test.describe('@ui @destructive Folder file operations', () => {
  let fileid1: number;
  let fileid2: number;

  test.beforeAll(async ({ request }) => {
    fileid1 = await getFileId(request, '/Local/dHLhDeEgxsg.jpg');
    fileid2 = await getFileId(request, '/Local/kvRlouf0RTs.jpg');
  });

  test.beforeEach(async ({ page }) => {
    await bootstrap(page);
  });

  test('Select image and move out of folder', async ({ page }) => {
    await page.goto(`${appUrl}/folders`);

    await page.getByRole('link', { name: 'Local' }).click();

    await page.hover(`.p-outer--${fileid1}`);
    await page.locator(`.p-outer--${fileid1} > div.select`).click();
    await page.hover(`.p-outer--${fileid2}`);
    await page.locator(`.p-outer--${fileid2} > div.select`).click();

    await page.getByRole('button', { name: 'Actions' }).click();
    await page.getByRole('menuitem', { name: 'Move to folder' }).click();
    await page.getByRole('cell', { name: 'Photos' }).getByTestId('row-name').click();
    await page.getByRole('cell', { name: 'Nested 2' }).getByTestId('row-name').click();
    await page.getByRole('button', { name: 'Move', exact: true }).click();

    await expect(page.locator(`.p-outer--${fileid1}`)).toHaveCount(0);
    await expect(page.locator(`.p-outer--${fileid2}`)).toHaveCount(0);

    await page.goto(`${appUrl}/folders/Photos/Nested 2`);
    await expect(page.locator(`.p-outer--${fileid1}`)).toHaveCount(1);
    await expect(page.locator(`.p-outer--${fileid2}`)).toHaveCount(1);
  });
});

import { test, expect } from '@playwright/test';
import { appUrl, bootstrap } from './navigation';
import { getFileId } from './utils';

test.describe('@ui @destructive Timeline photo deletion', () => {
  let fileid1: number;
  let fileid2: number;

  test.beforeAll(async ({ request }) => {
    fileid1 = await getFileId(request, '/Photos/NKcupJh-Dos.jpg');
    fileid2 = await getFileId(request, '/Photos/CbBbaNTmsAc.jpg');
  });

  test.beforeEach(async ({ page }) => {
    await bootstrap(page);
  });

  test('Select two images and delete', async ({ page }) => {
    await page.goto(appUrl);

    await page.hover(`.p-outer--${fileid1}`);
    await page.locator(`.p-outer--${fileid1} > div.select`).click();
    await page.hover(`.p-outer--${fileid2}`);
    await page.locator(`.p-outer--${fileid2} > div.select`).click();

    await page.getByRole('button', { name: 'Delete' }).click();
    await page.getByRole('button', { name: 'Yes' }).click();

    await expect(page.locator(`.p-outer--${fileid1}`)).toHaveCount(0);
    await expect(page.locator(`.p-outer--${fileid2}`)).toHaveCount(0);
  });
});

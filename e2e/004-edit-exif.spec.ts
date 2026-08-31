import { test, expect } from '@playwright/test';
import { appUrl, ocsHeaders, bootstrap } from './navigation';
import { getFileId } from './utils';

test.use({ extraHTTPHeaders: ocsHeaders });

test.describe.serial.only('@ui Favorites', () => {
  let fileid1: number;

  test.beforeAll(async ({ request }) => {
    fileid1 = await getFileId(request, '/Photos/NKcupJh-Dos.jpg');
  });

  test('Edit metadata through viewer', async ({ page }) => {
    await page.goto(appUrl);

    await page.locator(`.p-outer.fill-block.p-outer--${fileid1} > .img-outer`).click();

    await page.getByRole('button', { name: 'Actions' }).click();
    await page.getByRole('menuitem', { name: 'Edit metadata' }).click();

    const random = Math.floor(Math.random() * 1000000);
    const testTitle = `Test title ${random}`;
    const testDescription = `Test description ${random}`;
    await page.getByRole('textbox', { name: 'Title' }).fill(testTitle);
    await page.getByRole('textbox', { name: 'Description' }).fill(testDescription);
    await expect(page).toHaveScreenshot();
    await page.getByRole('button', { name: 'Save' }).click();

    await expect(page.locator('.memories_viewer .exif.title')).toHaveText(testTitle);
    await expect(page.locator('.memories_viewer .exif.description')).toHaveText(testDescription);
  });
});

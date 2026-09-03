import { test, expect } from '@playwright/test';
import { appUrl, ocsHeaders, bootstrap } from './navigation';
import { getFileId, copyPath, deletePath } from './utils';

test.use({
  extraHTTPHeaders: {
    ...ocsHeaders,
    'X-Timeline-Path': '/for-delete-now',
  },
});

test.describe.serial('@ui @destructive Timeline photo deletion', () => {
  let fileid1: number;
  let fileid2: number;
  let fileid3: number;

  test.beforeAll(async ({ request }) => {
    await deletePath(request, '/for-delete-now', true);
    await copyPath(request, '/for-delete', '/for-delete-now');

    fileid1 = await getFileId(request, '/for-delete-now/delete_01.jpg');
    fileid2 = await getFileId(request, '/for-delete-now/delete_02.jpg');
    fileid3 = await getFileId(request, '/for-delete-now/delete_03.jpg');
  });

  test.afterAll(async ({ request }) => {
    await deletePath(request, '/for-delete-now');
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

  test('Delete image from viewer', async ({ page }) => {
    await page.goto(appUrl);

    await page.locator(`.p-outer.fill-block.p-outer--${fileid3} > .img-outer`).click();
    await page.waitForSelector('body.viewer-fully-opened');

    const activeBefore = await page.locator('.pswp__item.active img.ximg--full').getAttribute('src');

    await page.getByRole('button', { name: 'Actions' }).click();
    await page.getByRole('button', { name: 'Delete' }).click();
    await page.getByRole('button', { name: 'Yes' }).click();

    await page.waitForFunction(async (activeBefore) => {
      const activeAfter = document.querySelector('.pswp__item.active img.ximg--full')?.getAttribute('src');
      return activeBefore !== activeAfter;
    }, activeBefore);

    await page.keyboard.press('Escape');
    await page.locator('.memories_viewer').waitFor({ state: 'detached' });

    await expect(page.locator(`.p-outer--${fileid3}`)).not.toBeVisible();
  });
});

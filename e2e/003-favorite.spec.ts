import { test, expect } from '@playwright/test';
import { appUrl, ocsHeaders, bootstrap } from './navigation';
import { getFileId } from './utils';

test.use({ extraHTTPHeaders: ocsHeaders });

test.describe.serial('@ui Favorites', () => {
  let fileid1: number;
  let fileid2: number;

  test.beforeAll(async ({ request }) => {
    fileid1 = await getFileId(request, '/Photos/NKcupJh-Dos.jpg');
    fileid2 = await getFileId(request, '/Photos/CbBbaNTmsAc.jpg');
  });

  test.beforeEach(async ({ page }) => {
    await bootstrap(page);
  });

  test('Favorite file', async ({ page }) => {
    await page.goto(appUrl);

    await page.hover(`.p-outer--${fileid1}`);
    await page.locator(`.p-outer--${fileid1} > div.select`).click();
    await page.hover(`.p-outer--${fileid2}`);
    await page.locator(`.p-outer--${fileid2} > div.select`).click();

    await expect(page.locator(`.p-outer--${fileid1} .flag.bottom-right > .star-icon`)).not.toBeVisible();
    await expect(page.locator(`.p-outer--${fileid2} .flag.bottom-right > .star-icon`)).not.toBeVisible();

    await page.getByRole('button', { name: 'Actions' }).click();
    await page.getByRole('menuitem', { name: 'Favorite' }).click();

    await expect(page.locator(`.p-outer--${fileid1} .flag.bottom-right > .star-icon`)).toBeVisible();
    await expect(page.locator(`.p-outer--${fileid2} .flag.bottom-right > .star-icon`)).toBeVisible();
  });

  test('Unfavorite file', async ({ page }) => {
    await page.goto(appUrl);

    await expect(page.locator(`.p-outer--${fileid1} .flag.bottom-right > .star-icon`)).toBeVisible();
    await expect(page.locator(`.p-outer--${fileid2} .flag.bottom-right > .star-icon`)).toBeVisible();

    await page.hover(`.p-outer--${fileid1}`);
    await page.locator(`.p-outer--${fileid1} > div.select`).click();
    await page.hover(`.p-outer--${fileid2}`);
    await page.locator(`.p-outer--${fileid2} > div.select`).click();

    await page.getByRole('button', { name: 'Actions' }).click();
    await page.getByRole('menuitem', { name: 'Favorite' }).click();

    await expect(page.locator(`.p-outer--${fileid1} .flag.bottom-right > .star-icon`)).not.toBeVisible();
    await expect(page.locator(`.p-outer--${fileid2} .flag.bottom-right > .star-icon`)).not.toBeVisible();
  });

  test('Favorite through viewer', async ({ page }) => {
    await page.goto(appUrl);
    await page.locator(`.p-outer--${fileid1} > .img-outer`).click();
    await page.waitForSelector('body.viewer-fully-opened');
    const favBtn = page.getByRole('button', { name: 'Favorite' });
    await expect(favBtn.locator('.star-outline-icon')).toBeVisible();
    await favBtn.click();
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await expect(page.locator(`.p-outer--${fileid1} .flag.bottom-right > .star-icon`)).toBeVisible();
  });

  test('Unfavorite through viewer', async ({ page }) => {
    await page.goto(appUrl);
    await page.locator(`.p-outer--${fileid1} > .img-outer`).click();
    await page.waitForSelector('body.viewer-fully-opened');
    const favBtn = page.getByRole('button', { name: 'Favorite' });
    await expect(favBtn.locator('.star-icon')).toBeVisible();
    await favBtn.click();
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await expect(page.locator(`.p-outer--${fileid1} .flag.bottom-right > .star-icon`)).not.toBeVisible();
  });
});

import { test, expect } from '@playwright/test';
import { appUrl, ocsHeaders, bootstrap } from './navigation';
import { getFileId } from './utils';

test.use({ extraHTTPHeaders: ocsHeaders });

test.describe.serial('@ui Favorites viewer', () => {
  let fileid: number;

  test.beforeAll(async ({ request }) => {
    fileid = await getFileId(request, '/Photos/3fUXeoW5Sso.jpg');
  });

  test.beforeEach(async ({ page }) => {
    await bootstrap(page);
  });

  // Due to a bug in Nextcloud, a single file must be marked favorite to create
  // the internal categories, before multiple can be done simultaneously.
  test('Favorite through viewer', async ({ page }) => {
    await page.goto(appUrl);
    await page.locator(`.p-outer--${fileid} > .img-outer`).click();
    await page.waitForSelector('body.viewer-fully-opened');
    const favBtn = page.getByRole('button', { name: 'Favorite' });
    await expect(favBtn.locator('.star-outline-icon')).toBeVisible();
    await favBtn.click();
    await page.locator('.pswp__button--close').click();
    await expect(page.locator(`.p-outer--${fileid} .flag.bottom-right > .star-icon`)).toBeVisible();
  });

  test('Unfavorite through viewer', async ({ page }) => {
    await page.goto(appUrl);
    await page.locator(`.p-outer--${fileid} > .img-outer`).click();
    await page.waitForSelector('body.viewer-fully-opened');
    const favBtn = page.getByRole('button', { name: 'Favorite' });
    await expect(favBtn.locator('.star-icon')).toBeVisible();
    await favBtn.click();
    await page.locator('.pswp__button--close').click();
    await expect(page.locator(`.p-outer--${fileid} .flag.bottom-right > .star-icon`)).not.toBeVisible();
  });
});

test.describe.serial('@ui Favorites batch', () => {
  let fileid1: number;
  let fileid2: number;

  test.beforeAll(async ({ request }) => {
    fileid1 = await getFileId(request, '/Photos/CbBbaNTmsAc.jpg');
    fileid2 = await getFileId(request, '/Photos/NDPmLyPXnZU.jpg');
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
});

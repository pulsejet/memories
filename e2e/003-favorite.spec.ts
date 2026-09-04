import { test, expect } from '@playwright/test';
import { appUrl, e2eHeaders, bootstrap, teardown } from './navigation';
import { DavClient } from './utils';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe.serial('@ui Favorites', () => {
  // Due to a bug in Nextcloud, a single file must be marked favorite to create
  // the internal categories, before multiple can be done simultaneously.
  test('Favorite from Viewer', async ({ request, page }) => {
    const dav = new DavClient(request);
    const fileid3 = await dav.fileid('/for-default/3fUXeoW5Sso.jpg');

    await test.step('Favorite', async () => {
      await page.goto(appUrl);
      await page.locator(`.p-outer--${fileid3} > .img-outer`).click();
      await page.waitForSelector('body.viewer-fully-opened');
      const favBtn = page.getByRole('button', { name: 'Favorite' });
      await expect(favBtn.locator('.star-outline-icon')).toBeVisible();
      await favBtn.click();
      await page.keyboard.press('Escape');
      await expect(page.locator(`.p-outer--${fileid3} .flag.bottom-right > .star-icon`)).toBeVisible();
    });

    await test.step('Unfavorite', async () => {
      await page.goto(appUrl);
      await page.locator(`.p-outer--${fileid3} > .img-outer`).click();
      await page.waitForSelector('body.viewer-fully-opened');
      const favBtn = page.getByRole('button', { name: 'Favorite' });
      await expect(favBtn.locator('.star-icon')).toBeVisible();
      await favBtn.click();
      await page.keyboard.press('Escape');
      await expect(page.locator(`.p-outer--${fileid3} .flag.bottom-right > .star-icon`)).not.toBeVisible();
    });
  });

  test('Favorite from Timeline', async ({ request, page }) => {
    const dav = new DavClient(request);
    const fileid1 = await dav.fileid('/for-default/CbBbaNTmsAc.jpg');
    const fileid2 = await dav.fileid('/for-default/NDPmLyPXnZU.jpg');

    await test.step('Favorite', async () => {
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

    await test.step('Unfavorite', async () => {
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
});

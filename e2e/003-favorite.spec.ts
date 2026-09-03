import { test, expect } from '@playwright/test';
import { appUrl, e2eHeaders, bootstrap } from './navigation';
import { getFileId } from './utils';

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe.serial('@ui Favorites', () => {
  test.beforeEach(async ({ page }) => {
    await bootstrap(page);
  });

  // Due to a bug in Nextcloud, a single file must be marked favorite to create
  // the internal categories, before multiple can be done simultaneously.
  test('Favorite from Viewer', async ({ page }) => {
    const fileid3 = await getFileId(page.request, '/Photos/3fUXeoW5Sso.jpg');

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

  test('Favorite from Timeline', async ({ page }) => {
    const fileid1 = await getFileId(page.request, '/Photos/CbBbaNTmsAc.jpg');
    const fileid2 = await getFileId(page.request, '/Photos/NDPmLyPXnZU.jpg');

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

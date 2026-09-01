import { test, expect } from '@playwright/test';
import { appUrl, ocsHeaders, bootstrap } from './navigation';
import { getFileId } from './utils';

test.use({ extraHTTPHeaders: ocsHeaders });

test.describe.serial('@ui Albums', () => {
  const random = Math.floor(Math.random() * 1000000);
  const albumName = `E2E Test Album ${random}`;
  const renamedAlbumName = `${albumName} Renamed`;

  let fileid1: number;
  let fileid2: number;
  let fileid3: number;
  let fileid4: number;

  test.beforeAll(async ({ request }) => {
    fileid1 = await getFileId(request, '/Photos/NKcupJh-Dos.jpg');
    fileid2 = await getFileId(request, '/Photos/CbBbaNTmsAc.jpg');
    fileid3 = await getFileId(request, '/Photos/Nested 1/test_01.jpg');
    fileid4 = await getFileId(request, '/Photos/ipZPm7u6aPA.jpg');
  });

  test.beforeEach(async ({ page }) => {
    await bootstrap(page);
  });

  test('Create album with selected photos', async ({ page }) => {
    await page.goto(appUrl);

    await page.hover(`.p-outer--${fileid1}`);
    await page.locator(`.p-outer--${fileid1} > div.select`).click();
    await page.hover(`.p-outer--${fileid2}`);
    await page.locator(`.p-outer--${fileid2} > div.select`).click();
    await page.hover(`.p-outer--${fileid3}`);
    await page.locator(`.p-outer--${fileid3} > div.select`).click();

    await page.getByRole('button', { name: 'Actions' }).click();
    await page.getByRole('menuitem', { name: 'Add to album' }).click();

    await page.getByRole('button', { name: 'Create new album.' }).click();
    await page.getByRole('textbox', { name: 'Album Name' }).click();
    await page.getByRole('textbox', { name: 'Album Name' }).fill(albumName);
    await page.getByRole('button', { name: 'Create album' }).click();
    await page.getByRole('button', { name: 'Save changes' }).click();

    await page.locator('.memories-modal').waitFor({ state: 'detached' });
  });

  test('View album and open photo in viewer', async ({ page }) => {
    await page.goto(`${appUrl}/albums`);

    await page.getByRole('link', { name: albumName }).click();

    await expect(page.locator('.dtm-container .header')).toHaveText(albumName);
    await expect(page.locator('.p-outer')).toHaveCount(3);
    await expect(page.locator(`.p-outer--${fileid1}`)).toBeVisible();
    await expect(page.locator(`.p-outer--${fileid2}`)).toBeVisible();
    await expect(page.locator(`.p-outer--${fileid3}`)).toBeVisible();

    await page.locator(`.p-outer--${fileid1} > .img-outer`).click();
    await page.waitForSelector('body.viewer-fully-opened');

    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.locator('.memories_viewer').waitFor({ state: 'detached' });
  });

  test('Add image to existing album', async ({ page }) => {
    await page.goto(appUrl);

    await page.hover(`.p-outer--${fileid1}`);
    await page.locator(`.p-outer--${fileid1} > div.select`).click();
    await page.hover(`.p-outer--${fileid4}`);
    await page.locator(`.p-outer--${fileid4} > div.select`).click();

    await page.getByRole('button', { name: 'Actions' }).click();
    await page.getByRole('menuitem', { name: 'Add to album' }).click();

    await page.getByRole('link', { name: new RegExp(albumName) }).click();
    await page.getByRole('button', { name: 'Save changes' }).click();

    await page.locator('.memories-modal').waitFor({ state: 'detached' });

    await page.goto(`${appUrl}/albums`);
    await page.getByRole('link', { name: albumName }).click();

    await expect(page.locator('.dtm-container .header')).toHaveText(albumName);
    await expect(page.locator('.p-outer')).toHaveCount(4);
    await expect(page.locator(`.p-outer--${fileid1}`)).toBeVisible();
    await expect(page.locator(`.p-outer--${fileid2}`)).toBeVisible();
    await expect(page.locator(`.p-outer--${fileid3}`)).toBeVisible();
    await expect(page.locator(`.p-outer--${fileid4}`)).toBeVisible();
  });

  test('Rename album', async ({ page }) => {
    await page.goto(`${appUrl}/albums`);

    await page.getByRole('link', { name: albumName }).click();
    await expect(page.locator('.dtm-container .header')).toHaveText(albumName);

    await page.getByRole('button', { name: 'Edit album details' }).click();
    await page.getByRole('textbox', { name: 'Album Name' }).click();
    await page.getByRole('textbox', { name: 'Album Name' }).fill(renamedAlbumName);
    await page.getByRole('button', { name: 'Save' }).click();

    await page.locator('.memories-modal').waitFor({ state: 'detached' });
    await expect(page.locator('.dtm-container .header')).toHaveText(renamedAlbumName);
  });

  test('Delete album', async ({ page }) => {
    await page.goto(`${appUrl}/albums`);

    await page.getByRole('link', { name: renamedAlbumName }).click();
    await page.getByRole('button', { name: 'Actions' }).click();
    await page.getByRole('menuitem', { name: 'Remove album' }).click();
    await page.getByRole('button', { name: 'Delete' }).click();

    await page.locator('.memories-modal').waitFor({ state: 'detached' });
    await expect(page.getByRole('link', { name: renamedAlbumName })).toHaveCount(0);
  });
});

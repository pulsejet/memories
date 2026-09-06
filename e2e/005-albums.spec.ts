import { test, expect } from '@playwright/test';
import { appUrl, e2eHeaders, bootstrap, username, teardown } from './navigation';
import { DavClient } from './utils';

import type { IAlbum } from '@typings';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.use({ extraHTTPHeaders: e2eHeaders() });

function uiUrl(name: string) {
  return `${appUrl}/albums/${username}/${encodeURIComponent(name)}`;
}

test.describe.serial('@ui Albums', () => {
  const random = Math.floor(Math.random() * 1000000);
  const albumName = `E2E Test Album ${random}`;
  const renamedAlbumName = `${albumName} Renamed`;

  let fileid1: number;
  let fileid2: number;
  let fileid3: number;
  let fileid4: number;

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    fileid1 = await dav.fileid('/for-default/NKcupJh-Dos.jpg');
    fileid2 = await dav.fileid('/for-default/CbBbaNTmsAc.jpg');
    fileid3 = await dav.fileid('/for-default/Nested 1/test_01.jpg');
    fileid4 = await dav.fileid('/for-default/ipZPm7u6aPA.jpg');
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
    await page.waitForSelector(`.album[aria-label="${albumName}"] div.album-selected`);

    await page.getByRole('button', { name: 'Save changes' }).click();
    await page.locator('.memories-modal').waitFor({ state: 'detached' });
  });

  test('View album and open photo in viewer', async ({ page }) => {
    await page.goto(`${appUrl}/albums`);

    await page.getByRole('link', { name: albumName }).click();

    await expect(page).toHaveURL(uiUrl(albumName));
    await expect(page.locator('.dtm-container .header')).toHaveText(albumName);
    await expect(page.locator('.p-outer')).toHaveCount(3);
    await expect(page.locator(`.p-outer--${fileid1}`)).toBeVisible();
    await expect(page.locator(`.p-outer--${fileid2}`)).toBeVisible();
    await expect(page.locator(`.p-outer--${fileid3}`)).toBeVisible();

    await page.locator(`.p-outer--${fileid1} > .img-outer`).click();
    await page.waitForSelector('body.viewer-fully-opened');
  });

  test('Add image to existing album', async ({ request, page }) => {
    await test.step('Add image via UI', async () => {
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
    });

    await test.step('Check last_added_photo', async () => {
      const res = await request.get(`${appUrl}/api/clusters/albums`);
      expect(res.ok()).toBeTruthy();

      const albums: IAlbum[] = await res.json();
      const album = albums.find((a) => a.name === albumName);

      expect(album).toBeDefined();
      expect(album!.count).toBe(4);
      expect(album!.last_added_photo).toBe(fileid4);
      expect(album!.last_added_photo_etag).toBeTruthy();
      expect(album!.cover).toBeFalsy();
      expect(album!.cover_etag).toBeFalsy();
    });
  });

  test('Set cover image on album', async ({ request, page }) => {
    await test.step('Set cover image via UI', async () => {
      await page.goto(uiUrl(albumName));
      await page.hover(`.p-outer--${fileid1}`);
      await page.locator(`.p-outer--${fileid1} > div.select`).click();

      const setCoverPromise = page.waitForResponse((r) => r.url().includes('/albums/set-cover') && r.status() === 200);
      await page.getByRole('button', { name: 'Actions' }).click();
      await page.getByRole('menuitem', { name: 'Set as cover image' }).click();
      await setCoverPromise;
    });

    await test.step('Check cover image via API', async () => {
      const res = await request.get(`${appUrl}/api/clusters/albums`);
      expect(res.ok()).toBeTruthy();

      const albums: IAlbum[] = await res.json();
      const album = albums.find((a) => a.name === albumName);
      expect(album).toBeDefined();
      expect(album!.cover).toBe(fileid1);
      expect(album!.cover_etag).toBeTruthy();
    });
  });

  test('Remove image from album', async ({ request, page }) => {
    await test.step('Remove image via UI', async () => {
      await page.goto(uiUrl(albumName));
      await page.hover(`.p-outer--${fileid1}`);
      await page.locator(`.p-outer--${fileid1} > div.select`).click();

      await page.getByRole('button', { name: 'Remove from album' }).click();
      await page.getByRole('button', { name: 'Yes' }).click();
      await expect(page.locator(`.p-outer--${fileid1}`)).toHaveCount(0);
    });

    await test.step('Check removed cover is gone', async () => {
      const res = await request.get(`${appUrl}/api/clusters/albums`);
      expect(res.ok()).toBeTruthy();

      const albums: IAlbum[] = await res.json();
      const album = albums.find((a) => a.name === albumName);

      expect(album).toBeDefined();
      expect(album!.cover).toBeFalsy();
      expect(album!.cover_etag).toBeFalsy();
    });
  });

  test('Rename album', async ({ page }) => {
    await page.goto(uiUrl(albumName));
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

test.describe('@ui Empty album', () => {
  const random = Math.floor(Math.random() * 1000000);
  const emptyAlbumName = `E2E Empty Album ${random}`;

  test('Empty album shows empty view', async ({ request, page }) => {
    const dav = new DavClient(request);
    const albumPath = `photos/${username}/albums/${emptyAlbumName}`;

    await test.step('Create empty album via WebDAV', async () => {
      await dav.mkcol(albumPath);
    });

    await test.step('Empty album shows empty content', async () => {
      await page.goto(uiUrl(emptyAlbumName));

      await expect(page).toHaveURL(uiUrl(emptyAlbumName));
      await expect(page.locator('.dtm-container .header')).toHaveText(emptyAlbumName);
      await expect(page.locator('.p-outer')).toHaveCount(0);
      await expect(page.getByText('Nothing to show here')).toBeVisible();
      await expect(page.getByText('Add photos to albums by selecting them on your timeline.')).toBeVisible();
    });

    await test.step('Delete empty album via WebDAV', async () => {
      await dav.del(albumPath);
    });
  });
});

import { expect } from '@playwright/test';
import { test, appUrl, e2eHeaders, username, buildUrl, selectPhoto, openViewer } from './navigation';
import { DavClient } from './utils';
import { snap } from './screenshots';

import type { IAlbum } from '@typings';

test.use({ extraHTTPHeaders: e2eHeaders() });

function uiUrl(name: string) {
  return `${appUrl}/albums/${username}/${encodeURIComponent(name)}`;
}

test.describe.serial('Albums', () => {
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

  test.afterAll(async ({ request }) => {
    const dav = new DavClient(request);
    await dav.del(`photos/${username}/albums/${albumName}`, true);
    await dav.del(`photos/${username}/albums/${renamedAlbumName}`, true);
  });

  test('@ui Create album with selected photos', async ({ page }) => {
    await page.goto(appUrl);

    await selectPhoto(page, fileid1);
    await selectPhoto(page, fileid2);
    await selectPhoto(page, fileid3);

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

  test('@ui View album and open photo in viewer', async ({ page }) => {
    await page.goto(`${appUrl}/albums`);

    await page.getByRole('link', { name: albumName }).click();

    await expect(page).toHaveURL(uiUrl(albumName));
    await expect(page.locator('.dtm-container .header')).toHaveText(albumName);
    await expect(page.locator('.p-outer:visible')).toHaveCount(3);
    await expect(page.locator(`.p-outer--${fileid1}`)).toBeVisible();
    await expect(page.locator(`.p-outer--${fileid2}`)).toBeVisible();
    await expect(page.locator(`.p-outer--${fileid3}`)).toBeVisible();

    await openViewer(page, fileid1);
  });

  test('@ui Add image to existing album', async ({ request, page }) => {
    await test.step('Add image via UI', async () => {
      await page.goto(appUrl);

      await selectPhoto(page, fileid1);
      await selectPhoto(page, fileid4);

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

  test('@ui Set cover image on album', async ({ request, page }) => {
    await test.step('Set cover image via UI', async () => {
      await page.goto(uiUrl(albumName));
      await selectPhoto(page, fileid1);

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

  test('@ui Remove image from album', async ({ request, page }) => {
    await test.step('Remove image via UI', async () => {
      await page.goto(uiUrl(albumName));
      await selectPhoto(page, fileid1);

      await page.getByRole('button', { name: 'Remove from album' }).click();
      await page.getByRole('button', { name: 'Yes' }).click();
      await expect(page.locator(`.p-outer--${fileid1}:visible`)).toHaveCount(0);
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

  test('@ui Rename album', async ({ page }) => {
    await page.goto(uiUrl(albumName));
    await expect(page.locator('.dtm-container .header')).toHaveText(albumName);

    await page.getByRole('button', { name: 'Edit album details' }).click();
    await page.getByRole('textbox', { name: 'Album Name' }).click();
    await page.getByRole('textbox', { name: 'Album Name' }).fill(renamedAlbumName);
    await page.getByRole('button', { name: 'Save' }).click();

    await page.locator('.memories-modal').waitFor({ state: 'detached' });
    await expect(page.locator('.dtm-container .header')).toHaveText(renamedAlbumName);
  });

  test('@ui Copy public link uses server base url', async ({ context, page }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.goto(uiUrl(renamedAlbumName));
    await page.getByRole('button', { name: 'Share album' }).click();
    await page.getByRole('button', { name: 'Share via public link' }).click();
    await page.getByRole('button', { name: 'Copy the public link' }).click();
    await expect(page.getByText('Public link copied!')).toBeVisible();

    const link = await page.evaluate(() => navigator.clipboard.readText());
    const token = link.split('/').pop();
    expect(token).toMatch(/^[A-Za-z0-9]{32}$/);
    expect(link).toBe(`${appUrl}/a/${token}`);
  });

  test('@api Download album returns handle', async ({ request }) => {
    const url = buildUrl('/api/clusters/albums/download', { name: `${username}/${renamedAlbumName}` });
    const res = await request.post(url);
    expect(res.ok()).toBeTruthy();
    expect(typeof (await res.json()).handle).toBe('string');
  });

  test('@api Preview missing album is rejected', async ({ request }) => {
    const url = buildUrl('/api/clusters/albums/preview', {
      name: `${username}/no-such-album-xyz`,
      cover: '1',
      cover_etag: 'null',
    });
    expect((await request.get(url)).status()).toBe(404);
  });

  test('@api Set cover for photo outside album is rejected', async ({ request }) => {
    const dav = new DavClient(request);
    const outside = await dav.fileid('/for-default/NDPmLyPXnZU.jpg');
    const res = await request.post(`${appUrl}/api/clusters/albums/set-cover`, {
      data: { name: `${username}/${renamedAlbumName}`, fileid: outside },
    });
    expect(res.ok()).toBeFalsy();
    expect(res.status()).toBe(404);
  });

  test('@ui Delete album', async ({ page }) => {
    await page.goto(`${appUrl}/albums`);
    await expect(page.getByRole('link', { name: renamedAlbumName })).toBeVisible();
    await snap(page, 'album-list');

    await page.getByRole('link', { name: renamedAlbumName }).click();
    await page.locator('.memories-timeline').getByRole('button', { name: 'Actions' }).click();
    await page.getByRole('menuitem', { name: 'Remove album' }).click();
    await page.getByRole('button', { name: 'Delete' }).click();

    await page.locator('.memories-modal').waitFor({ state: 'detached' });
    await expect(page.getByRole('link', { name: renamedAlbumName })).toHaveCount(0);
  });
});

test.describe('@ui Empty album', () => {
  const random = Math.floor(Math.random() * 1000000);
  const emptyAlbumName = `E2E Empty Album ${random}`;
  const albumPath = `photos/${username}/albums/${emptyAlbumName}`;

  test.afterAll(async ({ request }) => {
    await new DavClient(request).del(albumPath, true);
  });

  test('Empty album shows empty view', async ({ request, page }) => {
    const dav = new DavClient(request);

    await test.step('Create empty album via WebDAV', async () => {
      await dav.mkcol(albumPath);
    });

    await test.step('Empty album shows empty content', async () => {
      await page.goto(uiUrl(emptyAlbumName));

      await expect(page).toHaveURL(uiUrl(emptyAlbumName));
      await expect(page.locator('.dtm-container .header')).toHaveText(emptyAlbumName);
      await expect(page.locator('.p-outer:visible')).toHaveCount(0);
      await expect(page.getByText('Nothing to show here')).toBeVisible();
      await expect(page.getByText('Add photos to albums by selecting them on your timeline.')).toBeVisible();
    });
  });
});

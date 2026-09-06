import { test, expect } from '@playwright/test';
import { appUrl, e2eHeaders, psub, username } from './navigation';
import { DavClient, withPublicAPI, withPublicPage } from './utils';

import type { APIRequestContext, APIResponse } from '@playwright/test';
import type { IDay, IPhoto } from '@typings';

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('Public album share', () => {
  const albumName = psub('E2E Public Album %wid');
  const albumFiles = ['NKcupJh-Dos.jpg', 'CbBbaNTmsAc.jpg'];
  const outsideFile = '/for-default/ipZPm7u6aPA.jpg';

  let albumToken: string;
  let albumFileids: number[];
  let albumDayId: number;
  let outsideFileid: number;

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    const albums = new AlbumShareAPI(request);

    await test.step('Setup isolated album share', async () => {
      await albums.remove(albumName, true);
      await albums.create(albumName);

      albumFileids = [];
      for (const file of albumFiles) {
        const fileid = await dav.fileid(`/for-default/${file}`);
        albumFileids.push(fileid);
        await albums.addPhoto(albumName, `/for-default/${file}`, file);
      }

      await albums.createPublicLink(albumName);
      albumToken = await albums.getPublicLinkToken(albumName);

      const daysRes = await albums.days(albumToken);
      expect(daysRes.ok()).toBeTruthy();

      const days: IDay[] = await daysRes.json();
      expect(days).toHaveLength(1);
      expect(days[0].count).toBe(albumFiles.length);
      albumDayId = days[0].dayid;
    });

    outsideFileid = await dav.fileid(outsideFile);
  });

  test.afterAll(async ({ request }) => {
    const albums = new AlbumShareAPI(request);
    await albums.remove(albumName, true);
  });

  test('@api Album public link is listed', async ({ request }) => {
    const token = await new AlbumShareAPI(request).getPublicLinkToken(albumName);

    expect(token).toBe(albumToken);
  });

  test('@api Public album days', async () => {
    await withPublicAPI(async (request) => {
      const albums = new AlbumShareAPI(request);
      const res = await albums.days(albumToken);
      expect(res.ok()).toBeTruthy();

      const data: IDay[] = await res.json();
      expect(data).toHaveLength(1);
      expect(data[0].dayid).toBe(albumDayId);
      expect(data[0].count).toBe(albumFiles.length);
    });
  });

  test('@api Public album day photos', async () => {
    await withPublicAPI(async (request) => {
      const albums = new AlbumShareAPI(request);
      const res = await albums.day(albumToken, albumDayId);
      expect(res.ok()).toBeTruthy();

      const data: IPhoto[] = await res.json();
      expect(data).toHaveLength(albumFiles.length);
      expect(data.map((p) => p.fileid).sort()).toStrictEqual([...albumFileids].sort());
    });
  });

  test('@api Public album image info and access control', async () => {
    await withPublicAPI(async (request) => {
      const albums = new AlbumShareAPI(request);
      await test.step('Image in album loads with token', async () => {
        const res = await albums.imageInfo(albumToken, albumFileids[0]);
        expect(res.ok()).toBeTruthy();

        const data = await res.json();
        expect(data.fileid).toBe(albumFileids[0]);
        expect(data.permissions).toContain('R');
      });

      await test.step('Image without albums param is not found', async () => {
        const res = await albums.imageInfo(albumToken, albumFileids[0], null);
        expect(res.ok()).toBe(false);
        expect(res.status()).toBe(404);
      });

      await test.step('Image outside album is not found', async () => {
        const res = await albums.imageInfo(albumToken, outsideFileid);
        expect(res.ok()).toBe(false);
        expect(res.status()).toBe(404);
      });
    });
  });

  test('@api Public album preview and access control', async () => {
    await withPublicAPI(async (request) => {
      const albums = new AlbumShareAPI(request);
      await test.step('Preview in album loads with token', async () => {
        const res = await albums.preview(albumToken, albumFileids[0]);
        expect(res.ok()).toBeTruthy();
        expect(res.headers()['content-type']).toContain('image/');

        const body = await res.body();
        expect(body.length).toBeGreaterThan(0);
      });

      await test.step('Preview without token is not found', async () => {
        const res = await albums.preview(null, albumFileids[0]);
        expect(res.ok()).toBe(false);
        expect(res.status()).toBe(404);
      });
    });
  });

  test('@api Public album invalid token is rejected', async () => {
    await withPublicAPI(async (request) => {
      const albums = new AlbumShareAPI(request);
      const res = await albums.days('invalid-token');
      expect(res.ok()).toBe(false);
    });
  });

  test('@api Public album download', async () => {
    await withPublicAPI(async (request) => {
      const albums = new AlbumShareAPI(request);
      const res = await albums.download(albumToken);
      expect(res.ok()).toBeTruthy();

      const body = await res.body();
      expect(body.length).toBeGreaterThan(0);
    });
  });

  test('@ui Public album share shows photos', async ({ browser }) => {
    await withPublicPage(browser, async (page) => {
      await page.goto(`${appUrl}/a/${albumToken}`);

      await expect(page).toHaveURL(`${appUrl}/a/${albumToken}`);
      await expect(page.locator('.dtm-container .header')).toHaveText(albumName);
      await expect(page.locator('.p-outer')).toHaveCount(albumFiles.length);
      for (const fileid of albumFileids) {
        await expect(page.locator(`.p-outer--${fileid}`)).toBeVisible();
      }
    });
  });

  test('@ui Public album photo opens in viewer', async ({ browser }) => {
    await withPublicPage(browser, async (page) => {
      await page.goto(`${appUrl}/a/${albumToken}`);
      await expect(page.locator(`.p-outer--${albumFileids[0]}`)).toBeVisible();

      await page.locator(`.p-outer--${albumFileids[0]} > .img-outer`).click();
      await page.waitForSelector('body.viewer-fully-opened');
    });
  });
});

// Photos album public link client for e2e tests.
class AlbumShareAPI {
  constructor(private request: APIRequestContext) {}

  async create(name: string): Promise<void> {
    const dav = new DavClient(this.request);
    await dav.mkcol(`photos/${username}/albums/${name}`);
  }

  async remove(name: string, ignoreMissing: boolean = false): Promise<void> {
    const dav = new DavClient(this.request);
    await dav.del(`photos/${username}/albums/${name}`, ignoreMissing);
  }

  async addPhoto(album: string, srcPath: string, basename: string): Promise<void> {
    const dav = new DavClient(this.request);
    await dav.copy(`files/${username}/${srcPath.replace(/^\/+/, '')}`, `photos/${username}/albums/${album}/${basename}`);
  }

  async createPublicLink(name: string): Promise<void> {
    const dav = new DavClient(this.request);
    await dav.proppatch(`photos/${username}/albums/${name}`, {
      'nc:collaborators': JSON.stringify([{ id: '', label: 'Public link', type: 3 }]),
    });
  }

  async getPublicLinkToken(name: string): Promise<string> {
    const dav = new DavClient(this.request);
    const props = await dav.propfind(`photos/${username}/albums/${name}`, { 'nc:collaborators': '' });
    const collaborator = props[0]?.collaborators?.collaborator;
    const token = Array.isArray(collaborator) ? collaborator[0]?.id : collaborator?.id;
    expect(token).toBeTruthy();
    return String(token);
  }

  async days(token: string): Promise<APIResponse> {
    const url = new URL(`${appUrl}/api/days`);
    url.searchParams.set('nopreload', '1');
    url.searchParams.set('token', token);
    url.searchParams.set('albums', token);
    return this.request.get(url.toString());
  }

  async day(token: string, dayid: number): Promise<APIResponse> {
    const url = new URL(`${appUrl}/api/days/${dayid}`);
    url.searchParams.set('token', token);
    url.searchParams.set('albums', token);
    return this.request.get(url.toString());
  }

  async imageInfo(token: string, fileid: number, albums: string | null = token): Promise<APIResponse> {
    const url = new URL(`${appUrl}/api/image/info/${fileid}`);
    url.searchParams.set('token', token);
    if (albums) {
      url.searchParams.set('albums', albums);
    }
    return this.request.get(url.toString());
  }

  async preview(token: string | null, fileid: number, albums: string | null = token): Promise<APIResponse> {
    const url = new URL(`${appUrl}/api/image/preview/${fileid}`);
    if (token) {
      url.searchParams.set('token', token);
    }
    if (albums) {
      url.searchParams.set('albums', albums);
    }
    url.searchParams.set('x', '64');
    url.searchParams.set('y', '64');
    return this.request.get(url.toString());
  }

  async download(token: string): Promise<APIResponse> {
    const url = new URL(`${appUrl}/a/${token}/download`);
    url.searchParams.set('albums', '1');
    return this.request.get(url.toString());
  }
}

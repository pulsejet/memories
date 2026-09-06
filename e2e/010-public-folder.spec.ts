import { test, expect } from '@playwright/test';
import { appUrl, e2eHeaders, psub } from './navigation';
import { DavClient, withPublicAPI, withPublicPage } from './utils';

import type { APIRequestContext } from '@playwright/test';
import type { IDay, IPhoto, IShare } from '@typings';

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('Public folder share', () => {
  const folderDir = psub('/for-public-%wid');
  const folderName = psub('for-public-%wid');
  const folderFiles = ['RmjH76vMWrI.jpg', 'dHLhDeEgxsg.jpg', 'kvRlouf0RTs.jpg'];
  const outsideFile = '/for-default/ipZPm7u6aPA.jpg';

  let folderToken: string;
  let folderShareId: string;
  let folderFileids: number[];
  let folderDayId: number;
  let outsideFileid: number;

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    const folders = new FolderShareAPI(request);

    await test.step('Setup isolated folder share', async () => {
      await dav.deleteFile(folderDir, true);
      await dav.copyFile('/for-other', folderDir);

      folderFileids = [];
      for (const file of folderFiles) {
        folderFileids.push(await dav.fileid(`${folderDir}/${file}`));
      }

      const share = await folders.create(folderDir);
      folderToken = share.token;
      folderShareId = share.id;

      const url = new URL(`${appUrl}/api/days`);
      url.searchParams.set('nopreload', '1');
      url.searchParams.set('token', folderToken);
      const daysRes = await request.get(url.toString());
      expect(daysRes.ok()).toBeTruthy();

      const days: IDay[] = await daysRes.json();
      expect(days).toHaveLength(1);
      expect(days[0].count).toBe(folderFiles.length);
      folderDayId = days[0].dayid;
    });

    outsideFileid = await dav.fileid(outsideFile);
  });

  test.afterAll(async ({ request }) => {
    const folders = new FolderShareAPI(request);
    const dav = new DavClient(request);

    if (folderShareId) {
      await folders.remove(folderShareId).catch(() => {});
    }
    await dav.deleteFile(folderDir, true);
  });

  test('@api Folder share link is listed', async ({ request }) => {
    const shares = await new FolderShareAPI(request).list(folderDir);
    const match = shares.find((s: IShare) => s.token === folderToken);

    expect(match).toBeDefined();
    expect(match!.id).toBe(folderShareId);
  });

  test('@api Public folder days', async () => {
    await withPublicAPI(async (request) => {
      const url = new URL(`${appUrl}/api/days`);
      url.searchParams.set('nopreload', '1');
      url.searchParams.set('token', folderToken);
      const res = await request.get(url.toString());
      expect(res.ok()).toBeTruthy();

      const data: IDay[] = await res.json();
      expect(data).toHaveLength(1);
      expect(data[0].dayid).toBe(folderDayId);
      expect(data[0].count).toBe(folderFiles.length);
    });
  });

  test('@api Public folder day photos', async () => {
    await withPublicAPI(async (request) => {
      const url = new URL(`${appUrl}/api/days/${folderDayId}`);
      url.searchParams.set('token', folderToken);
      const res = await request.get(url.toString());
      expect(res.ok()).toBeTruthy();

      const data: IPhoto[] = await res.json();
      expect(data).toHaveLength(folderFiles.length);
      expect(data.map((p) => p.fileid).sort()).toStrictEqual([...folderFileids].sort());
    });
  });

  test('@api Public folder image info and access control', async () => {
    await withPublicAPI(async (request) => {
      await test.step('Image in share loads with token', async () => {
        const url = new URL(`${appUrl}/api/image/info/${folderFileids[0]}`);
        url.searchParams.set('token', folderToken);
        const res = await request.get(url.toString());
        expect(res.ok()).toBeTruthy();

        const data = await res.json();
        expect(data.fileid).toBe(folderFileids[0]);
        expect(data.permissions).toContain('R');
      });

      await test.step('Image without token is not found', async () => {
        const res = await request.get(`${appUrl}/api/image/info/${folderFileids[0]}`);
        expect(res.ok()).toBe(false);
        expect(res.status()).toBe(404);
      });

      await test.step('Image outside share is not found', async () => {
        const url = new URL(`${appUrl}/api/image/info/${outsideFileid}`);
        url.searchParams.set('token', folderToken);
        const res = await request.get(url.toString());
        expect(res.ok()).toBe(false);
        expect(res.status()).toBe(404);
      });
    });
  });

  test('@api Public folder preview and access control', async () => {
    await withPublicAPI(async (request) => {
      await test.step('Preview in share loads with token', async () => {
        const url = new URL(`${appUrl}/api/image/preview/${folderFileids[0]}`);
        url.searchParams.set('token', folderToken);
        url.searchParams.set('x', '64');
        url.searchParams.set('y', '64');
        const res = await request.get(url.toString());
        expect(res.ok()).toBeTruthy();
        expect(res.headers()['content-type']).toContain('image/');

        const body = await res.body();
        expect(body.length).toBeGreaterThan(0);
      });

      await test.step('Preview without token is not found', async () => {
        const url = new URL(`${appUrl}/api/image/preview/${folderFileids[0]}`);
        url.searchParams.set('x', '64');
        url.searchParams.set('y', '64');
        const res = await request.get(url.toString());
        expect(res.ok()).toBe(false);
        expect(res.status()).toBe(404);
      });
    });
  });

  test('@api Public folder invalid token is rejected', async () => {
    await withPublicAPI(async (request) => {
      const url = new URL(`${appUrl}/api/days`);
      url.searchParams.set('nopreload', '1');
      url.searchParams.set('token', 'invalid-token');
      const res = await request.get(url.toString());
      expect(res.ok()).toBe(false);
      expect(res.status()).toBe(412);
    });
  });

  test('@ui Public folder share shows photos', async ({ browser }) => {
    await withPublicPage(browser, async (page) => {
      await page.goto(`${appUrl}/s/${folderToken}`);

      await expect(page.locator('.top-matter')).toContainText(folderName);
      await expect(page.locator('.p-outer')).toHaveCount(folderFiles.length);
      for (const fileid of folderFileids) {
        await expect(page.locator(`.p-outer--${fileid}`)).toBeVisible();
      }
    });
  });

  test('@ui Public folder photo opens in viewer', async ({ browser }) => {
    await withPublicPage(browser, async (page) => {
      await page.goto(`${appUrl}/s/${folderToken}`);
      await expect(page.locator(`.p-outer--${folderFileids[0]}`)).toBeVisible();

      await page.locator(`.p-outer--${folderFileids[0]} > .img-outer`).click();
      await page.waitForSelector('body.viewer-fully-opened');
    });
  });
});

// Memories link share API client for e2e tests.
class FolderShareAPI {
  constructor(private request: APIRequestContext) {}

  async create(path: string): Promise<IShare> {
    const res = await this.request.post(`${appUrl}/api/share/node`, {
      data: { path },
    });
    expect(res.ok()).toBeTruthy();
    return res.json();
  }

  async list(path: string): Promise<IShare[]> {
    const url = new URL(`${appUrl}/api/share/links`);
    url.searchParams.set('path', path);
    const res = await this.request.get(url.toString());
    expect(res.ok()).toBeTruthy();
    return res.json();
  }

  async remove(id: string): Promise<void> {
    const res = await this.request.post(`${appUrl}/api/share/delete`, {
      data: { id },
    });
    expect(res.ok()).toBeTruthy();
  }
}

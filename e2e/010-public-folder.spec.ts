import * as path from 'path';
import { test, expect } from '@playwright/test';
import { appUrl, baseUrl, e2eHeaders, psub } from './navigation';
import { DavClient, withPublicAPI, withPublicPage } from './utils';

import type { APIRequestContext, APIResponse } from '@playwright/test';
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

      const daysRes = await folders.days(folderToken);
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
      const res = await new FolderShareAPI(request).days(folderToken);
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
      const res = await new FolderShareAPI(request).days('invalid-token');
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

  test('@ui Public folder breadcrumbs navigate nested share', async ({ browser, request }) => {
    const folders = new FolderShareAPI(request);
    const share = await folders.create('/for-default/Nested 1');
    try {
      await withPublicPage(browser, async (page) => {
        await page.goto(`${appUrl}/s/${share.token}/Nested%201_1`);

        const crumbs = page.locator('.top-matter nav');
        await expect(crumbs.getByRole('link', { name: 'Nested 1', exact: true })).toBeVisible();
        await expect(crumbs.getByRole('link', { name: 'Nested 1_1', exact: true })).toBeVisible();

        await crumbs.getByRole('link', { name: 'Nested 1', exact: true }).click();
        await expect(page).toHaveURL(`${appUrl}/s/${share.token}`);
      });
    } finally {
      await folders.remove(share.id).catch(() => {});
    }
  });
});

test.describe('Password protected folder share', () => {
  const folderDir = psub('/for-public-pw-%wid');
  const folderFiles = ['RmjH76vMWrI.jpg', 'dHLhDeEgxsg.jpg', 'kvRlouf0RTs.jpg'];
  const sharePassword = 'Sup3r-s3cret!';

  let folderToken: string;
  let folderShareId: string;
  let folderFileids: number[];

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    const folders = new FolderShareAPI(request);

    await test.step('Setup isolated password share', async () => {
      await dav.deleteFile(folderDir, true);
      await dav.copyFile('/for-other', folderDir);

      folderFileids = [];
      for (const file of folderFiles) {
        folderFileids.push(await dav.fileid(`${folderDir}/${file}`));
      }

      const share = await folders.create(folderDir);
      folderToken = share.token;
      folderShareId = share.id;

      await folders.setPassword(folderShareId, sharePassword);
    });
  });

  test.afterAll(async ({ request }) => {
    const folders = new FolderShareAPI(request);
    const dav = new DavClient(request);

    if (folderShareId) {
      await folders.remove(folderShareId).catch(() => {});
    }
    await dav.deleteFile(folderDir, true);
  });

  test('@api Unauthenticated access to protected share is rejected', async () => {
    await withPublicAPI(async (request) => {
      const res = await new FolderShareAPI(request).days(folderToken);
      expect(res.ok()).toBe(false);
      expect(res.status()).toBe(403);
    });
  });

  test('@ui Protected share shows photos after entering the password', async ({ browser }) => {
    await withPublicPage(browser, async (page) => {
      await page.goto(`${appUrl}/s/${folderToken}`);

      await test.step('Unlock share with password', async () => {
        await page.getByRole('textbox', { name: 'Password' }).fill(sharePassword);
        await page.getByRole('button', { name: 'Submit' }).click();
      });

      await test.step('Timeline shows shared photos', async () => {
        for (const fileid of folderFileids) {
          await expect(page.locator(`.p-outer--${fileid}`)).toBeVisible();
        }
      });

      await test.step('Refresh does not ask for password again', async () => {
        await page.reload();
        await expect(page.getByRole('textbox', { name: 'Password' })).toHaveCount(0);
        for (const fileid of folderFileids) {
          await expect(page.locator(`.p-outer--${fileid}`)).toBeVisible();
        }
      });
    });
  });

  test('@ui Wrong password is rejected', async ({ browser }) => {
    await withPublicPage(browser, async (page) => {
      await page.goto(`${appUrl}/s/${folderToken}`);

      await page.getByRole('textbox', { name: 'Password' }).fill('definitely-wrong');
      await page.getByRole('button', { name: 'Submit' }).click();

      await expect(page.getByRole('textbox', { name: 'Password' })).toBeVisible();
      await expect(page.locator('.p-outer')).toHaveCount(0);
    });
  });
});

test.describe('Public folder upload', () => {
  const folderDir = psub('/for-upload-public-%wid');

  let folderToken: string;
  let folderShareId: string;

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    const folders = new FolderShareAPI(request);

    await dav.deleteFile(folderDir, true);
    await dav.copyFile('/for-upload', folderDir);

    const share = await folders.create(folderDir);
    folderToken = share.token;
    folderShareId = share.id;
    await folders.updatePermissions(folderShareId, 15);
  });

  test.afterAll(async ({ request }) => {
    const folders = new FolderShareAPI(request);
    const dav = new DavClient(request);

    if (folderShareId) {
      await folders.remove(folderShareId).catch(() => {});
    }
    await dav.deleteFile(folderDir, true);
  });

  test('@ui Public folder upload image and video', async ({ browser, request }) => {
    const dav = new DavClient(request);
    const uploadFilePaths = [
      path.resolve(__dirname, '../tests/assets/apple_h264_boy_01.jpg'),
      // Large enough to force chunked upload (see #1634)
      path.resolve(__dirname, '../tests/assets/samsung_s21_03.mp4'),
    ];

    await withPublicPage(browser, async (page) => {
      await test.step('Select files', async () => {
        await page.goto(`${appUrl}/s/${folderToken}`);
        await expect(page.getByRole('button', { name: 'Upload files' })).toBeVisible();

        // Force chunked upload for the video
        // https://github.com/pulsejet/memories/issues/1634
        const maxChunkSize = await page.evaluate(() => {
          return window.OC?.appConfig?.files?.max_chunk_size;
        });
        expect(typeof maxChunkSize).toBe('number');
        await page.evaluate(() => {
          window.OC.appConfig.files.max_chunk_size = 1024 * 1024;
        });

        const fileChooserPromise = page.waitForEvent('filechooser');
        await page.getByRole('button', { name: 'Upload files' }).click();
        const fileChooser = await fileChooserPromise;
        await fileChooser.setFiles(uploadFilePaths);
      });

      await test.step('Verify upload', async () => {
        // Video uses chunked upload (see #1634); toast confirms both files landed.
        await expect(page.getByText('Successfully uploaded 2 files', { exact: true })).toBeVisible({ timeout: 120000 });

        const imageId = await dav.fileid(`${folderDir}/apple_h264_boy_01.jpg`);
        const videoId = await dav.fileid(`${folderDir}/samsung_s21_03.mp4`);
        expect(videoId).toBeGreaterThan(0);
        await expect(page.locator(`.p-outer--${imageId}`)).toBeVisible();
      });
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

  async setPassword(fullId: string, password: string): Promise<void> {
    const id = fullId.split(':').pop();
    const url = new URL(`${baseUrl}/ocs/v2.php/apps/files_sharing/api/v1/shares/${id}`);
    url.searchParams.set('format', 'json');
    const res = await this.request.put(url.toString(), {
      form: { password },
    });
    expect(res.ok()).toBeTruthy();
  }

  async updatePermissions(fullId: string, permissions: number): Promise<void> {
    const id = fullId.split(':').pop();
    const url = new URL(`${baseUrl}/ocs/v2.php/apps/files_sharing/api/v1/shares/${id}`);
    url.searchParams.set('format', 'json');
    const res = await this.request.put(url.toString(), {
      form: { permissions },
    });
    expect(res.ok()).toBeTruthy();
  }

  async days(token: string): Promise<APIResponse> {
    const url = new URL(`${appUrl}/api/days`);
    url.searchParams.set('nopreload', '1');
    url.searchParams.set('token', token);
    return this.request.get(url.toString());
  }
}

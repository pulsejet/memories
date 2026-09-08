import { test, expect } from '@playwright/test';
import { appUrl, baseUrl, e2eHeaders, psub } from './navigation';
import { DavClient, withPublicAPI, withPublicPage } from './utils';

import type { APIRequestContext } from '@playwright/test';
import type { IShare } from '@typings';

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('Password protected folder share', () => {
  const folderDir = psub('/for-public-pw-%wid');
  const folderFiles = ['RmjH76vMWrI.jpg', 'dHLhDeEgxsg.jpg', 'kvRlouf0RTs.jpg'];
  const sharePassword = 'Sup3r-s3cret!';

  let folderToken: string;
  let folderShareId: string;

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    const shares = new ShareAPI(request);

    await dav.deleteFile(folderDir, true);
    await dav.copyFile('/for-other', folderDir);

    const share = await shares.create(folderDir);
    folderToken = share.token;
    folderShareId = share.id;

    await shares.setPassword(folderShareId, sharePassword);
  });

  test.afterAll(async ({ request }) => {
    const shares = new ShareAPI(request);
    const dav = new DavClient(request);

    if (folderShareId) {
      await shares.remove(folderShareId).catch(() => {});
    }
    await dav.deleteFile(folderDir, true);
  });

  test('@api Unauthenticated access to protected share is rejected', async () => {
    await withPublicAPI(async (request) => {
      const url = new URL(`${appUrl}/api/days`);
      url.searchParams.set('nopreload', '1');
      url.searchParams.set('token', folderToken);
      const res = await request.get(url.toString());
      expect(res.ok()).toBe(false);
      expect(res.status()).toBe(403);
    });
  });

  test('@ui Protected share shows photos after entering the password', async ({ browser }) => {
    await withPublicPage(browser, async (page) => {
      await page.goto(`${appUrl}/s/${folderToken}`);

      // Nextcloud's public share authentication page (core/templates/publicshareauth)
      const authForm = page.locator('#core-public-share-auth');
      await expect(authForm).toBeVisible();
      await authForm.locator('input[name="password"]').fill(sharePassword);
      await authForm.locator('button[type="submit"]').click();

      // The timeline is populated through the API using the same session
      await expect(page.locator('.p-outer')).toHaveCount(folderFiles.length);

      // The same session must also be authenticated for WebDAV, which is what
      // the regular share page, download links and clients use.
      const dav = await page.request.fetch(`${baseUrl}/public.php/dav/files/${folderToken}/`, {
        method: 'PROPFIND',
        headers: { Depth: '1' },
      });
      expect(dav.status()).toBe(207);
    });
  });

  test('@ui Wrong password is rejected', async ({ browser }) => {
    await withPublicPage(browser, async (page) => {
      await page.goto(`${appUrl}/s/${folderToken}`);

      const authForm = page.locator('#core-public-share-auth');
      await authForm.locator('input[name="password"]').fill('definitely-wrong');
      await authForm.locator('button[type="submit"]').click();

      await expect(page.locator('#core-public-share-auth')).toBeVisible();
      await expect(page.locator('.p-outer')).toHaveCount(0);
    });
  });
});

// Minimal share helper: create/delete through the Memories API, set the
// password through the OCS sharing API (Memories has no endpoint for that).
class ShareAPI {
  constructor(private request: APIRequestContext) {}

  async create(path: string): Promise<IShare> {
    const res = await this.request.post(`${appUrl}/api/share/node`, {
      data: { path },
    });
    expect(res.ok()).toBeTruthy();
    return res.json();
  }

  async setPassword(fullId: string, password: string): Promise<void> {
    // Memories returns IShare::getFullId() ("ocinternal:123"), OCS wants the numeric part
    const id = fullId.split(':').pop();
    const res = await this.request.put(`${baseUrl}/ocs/v2.php/apps/files_sharing/api/v1/shares/${id}?format=json`, {
      form: { password },
    });
    expect(res.ok()).toBeTruthy();
  }

  async remove(id: string): Promise<void> {
    const res = await this.request.post(`${appUrl}/api/share/delete`, {
      data: { id },
    });
    expect(res.ok()).toBeTruthy();
  }
}

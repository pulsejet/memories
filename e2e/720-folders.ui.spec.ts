import { test, expect } from '@playwright/test';
import { appUrl, bootstrap, e2eHeaders, teardown } from './navigation';
import { DavClient } from './utils';
import { snap } from './screenshots';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('@ui Folder view and navigation', () => {
  let fileid1: number;
  let fileid2: number;

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    fileid1 = await dav.fileid('/for-default/NKcupJh-Dos.jpg');
    fileid2 = await dav.fileid('/for-default/Nested 1/test_01.jpg');
  });

  test('Look for Folders', async ({ page }) => {
    await page.goto(`${appUrl}/folders`);
    await page.waitForSelector('.folder--for-other');
    await page.waitForSelector('.folder--for-default');
    await snap(page, 'folders');

    await page.locator('.folder--for-default').click();
    await page.waitForSelector(`.p-outer--${fileid1}`);
  });

  test('Folders timeline view', async ({ page }) => {
    await page.goto(`${appUrl}/folders`);

    await test.step('Verify initial state', async () => {
      await page.locator('.folder--for-default').click();
      await expect(page.locator('.folder--Nested1')).toBeInViewport();
      await expect(page.locator('.folder--Nested2')).toBeInViewport();
      await expect(page.locator(`.p-outer--${fileid1}`)).toHaveCount(1);
      await expect(page.locator(`.p-outer--${fileid1}`)).toBeInViewport();
      await expect(page.locator(`.p-outer--${fileid2}`)).toHaveCount(0);
    });

    await test.step('Verify timeline view', async () => {
      await page.getByRole('button', { name: 'Timeline view' }).click();
      await expect(page.locator('.folder--Nested1')).not.toBeInViewport();
      await expect(page.locator('.folder--Nested2')).not.toBeInViewport();
      await expect(page.locator(`.p-outer--${fileid1}`)).toHaveCount(1);
      await expect(page.locator(`.p-outer--${fileid1}`)).toBeInViewport();
      await expect(page.locator(`.p-outer--${fileid2}`)).toHaveCount(1);
      await expect(page.locator(`.p-outer--${fileid2}`)).toBeInViewport();
    });

    await test.step('Verify folder view', async () => {
      await page.getByRole('button', { name: 'Folder view' }).click();
      await expect(page.locator('.folder--Nested1')).toBeInViewport();
      await expect(page.locator('.folder--Nested2')).toBeInViewport();
      await expect(page.locator(`.p-outer--${fileid1}`)).toHaveCount(1);
      await expect(page.locator(`.p-outer--${fileid1}`)).toBeInViewport();
      await expect(page.locator(`.p-outer--${fileid2}`)).toHaveCount(0);
    });
  });
});

test.describe('@api Folders sub', () => {
  test('List root subfolders', async ({ request }) => {
    const url = new URL(`${appUrl}/api/folders/sub`);
    url.searchParams.set('folder', '/');
    const res = await request.get(url.toString());
    expect(res.ok()).toBeTruthy();

    const data: { fileid: number; name: string; previews: { fileid: number }[] }[] = await res.json();
    expect(data.length).toBeGreaterThan(0);

    const names = data.map((f) => f.name);
    expect(names).toContain('for-default');

    for (const f of data) {
      expect(typeof f.fileid).toBe('number');
      expect(typeof f.name).toBe('string');
      expect(Array.isArray(f.previews)).toBeTruthy();
      expect(f.previews.length).toBeLessThanOrEqual(4);
    }
  });

  test('List nested subfolders', async ({ request }) => {
    const url = new URL(`${appUrl}/api/folders/sub`);
    url.searchParams.set('folder', '/for-default');
    const res = await request.get(url.toString());
    expect(res.ok()).toBeTruthy();

    const data: { fileid: number; name: string }[] = await res.json();
    const names = data.map((f) => f.name);
    expect(names).toContain('Nested 1');
    expect(names).toContain('Nested 2');
  });

  test('Invalid folder errors', async ({ request }) => {
    const missingUrl = new URL(`${appUrl}/api/folders/sub`);
    missingUrl.searchParams.set('folder', '/no-such-folder-xyz');
    const missing = await request.get(missingUrl.toString());
    expect(missing.ok()).toBeFalsy();
    expect(missing.status()).toBe(404);

    const fileUrl = new URL(`${appUrl}/api/folders/sub`);
    fileUrl.searchParams.set('folder', '/for-default/Nested 1/test_01.jpg');
    const file = await request.get(fileUrl.toString());
    expect(file.ok()).toBeFalsy();
    expect(file.status()).toBe(400);
  });
});

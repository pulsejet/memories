import { test, expect } from '@playwright/test';
import { appUrl, bootstrap, teardown } from './navigation';
import { DavClient } from './utils';

test.beforeEach(bootstrap);
test.afterEach(teardown);

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

    await page.locator('.folder--for-default').click();
    await page.waitForSelector(`.p-outer--${fileid1}`);
  });

  test('Folders timeline view', async ({ page }) => {
    await page.goto(`${appUrl}/folders`);

    await test.step('Verify initial state', async () => {
      await page.locator('.folder--for-default').click();
      await expect(page.locator('.folder--Nested1')).toBeInViewport();
      await expect(page.locator('.folder--Nested2')).toBeInViewport();
      await expect(page.locator(`.p-outer--${fileid2}`)).not.toBeInViewport();
    });

    await test.step('Verify timeline view', async () => {
      await page.getByRole('button', { name: 'Timeline view' }).click();
      await expect(page.locator('.folder--Nested1')).not.toBeInViewport();
      await expect(page.locator('.folder--Nested2')).not.toBeInViewport();
      await expect(page.locator(`.p-outer--${fileid2}`)).toBeInViewport();
    });

    await test.step('Verify folder view', async () => {
      await page.getByRole('button', { name: 'Folder view' }).click();
      await expect(page.locator('.folder--Nested1')).toBeInViewport();
      await expect(page.locator('.folder--Nested2')).toBeInViewport();
      await expect(page.locator(`.p-outer--${fileid2}`)).not.toBeInViewport();
    });
  });
});

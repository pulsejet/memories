import { test, expect } from '@playwright/test';
import { appUrl, bootstrap } from './navigation';
import { getFileId } from './utils';

test.describe('@ui Folder view and navigation', () => {
  let fileid1: number;
  let fileid2: number;

  test.beforeAll(async ({ request }) => {
    fileid1 = await getFileId(request, '/Photos/NKcupJh-Dos.jpg');
    fileid2 = await getFileId(request, '/Photos/Nested 1/test_01.jpg');
  });

  test.beforeEach(async ({ page }) => {
    await bootstrap(page);
  });

  test('Look for Folders', async ({ page }) => {
    await page.goto(`${appUrl}/folders`);
    await page.waitForSelector('.folder--Local');
    await page.waitForSelector('.folder--Photos');

    await page.locator('.folder--Photos').click();
    await page.waitForSelector(`.p-outer--${fileid1}`);
  });

  test('Folders timeline view', async ({ page }) => {
    await page.goto(`${appUrl}/folders`);

    await page.locator('.folder--Photos').click();
    await expect(page.locator('.folder--Nested1')).toBeInViewport();
    await expect(page.locator('.folder--Nested2')).toBeInViewport();
    await expect(page.locator(`.p-outer--${fileid2}`)).not.toBeInViewport();

    await page.getByRole('button', { name: 'Timeline view' }).click();
    await expect(page.locator('.folder--Nested1')).not.toBeInViewport();
    await expect(page.locator('.folder--Nested2')).not.toBeInViewport();
    await expect(page.locator(`.p-outer--${fileid2}`)).toBeInViewport();

    await page.getByRole('button', { name: 'Folder view' }).click();
    await expect(page.locator('.folder--Nested1')).toBeInViewport();
    await expect(page.locator('.folder--Nested2')).toBeInViewport();
    await expect(page.locator(`.p-outer--${fileid2}`)).not.toBeInViewport();
  });
});

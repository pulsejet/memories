import { test } from '@playwright/test';
import { appUrl, bootstrap } from './navigation';
import { getFileIdByBasename } from './utils';

test.describe('@ui Folder view and navigation', () => {
  let fileid1: number;

  test.beforeAll(async ({ request }) => {
    fileid1 = await getFileIdByBasename(request, 20696, 'NKcupJh-Dos.jpg');
  });

  test.beforeEach(async ({ page }) => {
    await bootstrap(page);
  });

  test('Look for Folders', async ({ page }) => {
    await page.goto(`${appUrl}/folders`);
    await page.waitForSelector('.folder--Local');
    await page.waitForSelector('.folder--Photos');

    await page.getByRole('link', { name: 'Photos' }).click();
    await page.waitForSelector(`.p-outer--${fileid1}`);
  });
});

import { test, expect } from '@playwright/test';
import { appUrl, bootstrap, e2eHeaders, teardown, username } from './navigation';
import { DavClient } from './utils';
import { snap } from './screenshots';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('@ui Sidebar', () => {
  const random = Math.floor(Math.random() * 1000000);
  const albumName = `E2E Sidebar Album ${random}`;
  const albumPhoto = 'test_01.jpg';

  let fileid: number;

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    fileid = await dav.fileid('/for-default/Nested 1/test_01.jpg');

    await test.step('Setup album with photo', async () => {
      await dav.mkcol(`photos/${username}/albums`, true);
      await dav.mkcol(`photos/${username}/albums/${albumName}`, true);
      await dav.copy(
        `files/${username}/for-default/Nested 1/test_01.jpg`,
        `photos/${username}/albums/${albumName}/${albumPhoto}`,
      );
    });
  });

  test.afterAll(async ({ request }) => {
    const dav = new DavClient(request);
    await dav.del(`photos/${username}/albums/${albumName}`, true);
  });

  test('Timeline viewer opens native sidebar with sharing tab', async ({ page }) => {
    await page.goto(appUrl);
    await page.locator(`.p-outer--${fileid} > .img-outer`).click();
    await page.waitForSelector('body.viewer-fully-opened');

    await page.getByRole('button', { name: 'Info', exact: true }).click();
    const sidebar = page.locator('#app-sidebar-native');
    await expect(sidebar).toBeVisible();
    await expect(sidebar.getByRole('tab', { name: 'Info', exact: true })).toBeVisible();
    await expect(sidebar.getByRole('tab', { name: 'Sharing', exact: true })).toBeVisible();
    await expect(page.locator('#app-sidebar-vue')).toHaveCount(0);
    await snap(page, 'sidebar-native');

    await test.step('Reopen sidebar loads tab content', async () => {
      await sidebar.getByRole('button', { name: 'Close sidebar' }).click();
      await expect(sidebar).toHaveCount(0);

      await page.getByRole('button', { name: 'Info', exact: true }).click();
      await expect(sidebar).toBeVisible();
      await expect(sidebar.getByRole('tab', { name: 'Sharing', exact: true })).toBeVisible();
      await expect(sidebar.getByRole('tabpanel', { name: 'Info' })).toContainText('Metadata');
    });
  });

  test('Album viewer opens reduced sidebar', async ({ page }) => {
    await page.goto(`${appUrl}/albums/${username}/${encodeURIComponent(albumName)}`);
    await page.locator('.p-outer > .img-outer').first().click();
    await page.waitForSelector('body.viewer-fully-opened');

    await page.getByRole('button', { name: 'Info', exact: true }).click();
    const sidebar = page.locator('#app-sidebar-vue');
    await expect(sidebar).toBeVisible();
    await expect(sidebar.locator('h2')).toHaveText(albumPhoto);
    await expect(page.locator('#app-sidebar-native')).toHaveCount(0);
    await snap(page, 'sidebar-reduced');
  });
});

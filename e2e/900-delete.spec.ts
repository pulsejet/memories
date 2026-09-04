import { test, expect } from '@playwright/test';
import { appUrl, e2eHeaders, bootstrap, teardown } from './navigation';
import { DavClient } from './utils';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.use({
  extraHTTPHeaders: e2eHeaders({
    timelinePath: '/for-delete-%wid',
  }),
});

test.describe('@ui Timeline photo deletion', () => {
  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    await dav.deleteFile('/for-delete-%wid', true);
    await dav.copyFile('/for-delete', '/for-delete-%wid');
  });

  test.afterAll(async ({ request }) => {
    await new DavClient(request).deleteFile('/for-delete-%wid');
  });

  test('Select two images and delete', async ({ request, page }) => {
    const dav = new DavClient(request);
    const fileid1 = await dav.fileid('/for-delete-%wid/delete_01.jpg');
    const fileid2 = await dav.fileid('/for-delete-%wid/delete_02.jpg');
    await page.goto(appUrl);

    await test.step('Select two images', async () => {
      await page.hover(`.p-outer--${fileid1}`);
      await page.locator(`.p-outer--${fileid1} > div.select`).click();
      await page.hover(`.p-outer--${fileid2}`);
      await page.locator(`.p-outer--${fileid2} > div.select`).click();
    });

    await test.step('Submit delete', async () => {
      await page.getByRole('button', { name: 'Delete' }).click();
      await page.getByRole('button', { name: 'Yes' }).click();
    });

    await test.step('Verify timeline', async () => {
      await expect(page.locator(`.p-outer--${fileid1}`)).toHaveCount(0);
      await expect(page.locator(`.p-outer--${fileid2}`)).toHaveCount(0);
    });
  });

  test('Delete image from viewer', async ({ request, page }) => {
    const dav = new DavClient(request);
    const fileid3 = await dav.fileid('/for-delete-%wid/delete_03.jpg');

    await test.step('Open viewer', async () => {
      await page.goto(appUrl);
      await page.locator(`.p-outer--${fileid3}`).click();
      await page.waitForSelector('body.viewer-fully-opened');
    });

    // Get the the current active image so we can recheck.
    await page.waitForTimeout(200);
    const activeSelector = '.pswp__item.active img.ximg--full';
    const activePrev = await page.locator(activeSelector).getAttribute('src');
    expect(activePrev).toBeTruthy();
    await expect(page.locator(activeSelector)).toHaveAttribute('src', activePrev!);

    await test.step('Delete image', async () => {
      const viewer = page.locator('.memories_viewer');
      await viewer.getByRole('button', { name: 'Delete' }).click();
      await page.getByRole('button', { name: 'Yes' }).click();
    });

    await test.step('Wait for viewer to change', async () => {
      await expect(page.locator(activeSelector)).not.toHaveAttribute('src', activePrev!);
      const activeNew = await page.locator(activeSelector).getAttribute('src');
      expect(activeNew).toBeTruthy();
      expect(activeNew).not.toEqual(activePrev);
    });

    await test.step('Close viewer', async () => {
      await page.keyboard.press('Escape');
      await page.locator('.memories_viewer').waitFor({ state: 'detached' });
    });

    await test.step('Verify timeline', async () => {
      await expect(page.locator(`.p-outer--${fileid3}`)).not.toBeVisible();
    });
  });
});

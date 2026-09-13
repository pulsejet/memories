import { test, expect, devices } from '@playwright/test';
import { appUrl, bootstrap, e2eHeaders, teardown } from './navigation';
import { DavClient } from './utils';
import { snap } from './screenshots';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('@ui Viewer mobile bottom bar', () => {
  test.use({
    viewport: devices['Pixel 7'].viewport,
    hasTouch: true,
    isMobile: true,
    userAgent: devices['Pixel 7'].userAgent,
    deviceScaleFactor: devices['Pixel 7'].deviceScaleFactor,
  });

  let fileid: number;

  test.beforeAll(async ({ request }) => {
    fileid = await new DavClient(request).fileid('/for-default/Nested 1/test_01.jpg');
  });

  test('Primary actions show in bottom bar, not in menu', async ({ page }) => {
    await page.goto(appUrl);
    const thumb = page.locator(`.p-outer--${fileid} > .img-outer`);
    await thumb.waitFor();
    await page.waitForTimeout(500); // let the recycler settle
    await thumb.click();
    await page.waitForSelector('.memories-viewer.fully-opened');

    const bar = page.locator('.viewer-mobile-actions');
    await expect(bar).toHaveCSS('opacity', '1');
    await expect(bar.getByRole('button')).toHaveText(['Share', 'Edit', 'Add to', 'Delete']);
    await snap(page, 'viewer-mobile-actions');

    await page.locator('.memories-viewer').getByRole('button', { name: 'Actions' }).click();
    for (const name of ['Share', 'Edit', 'Add to album', 'Delete']) {
      await expect(page.getByRole('menuitem', { name, exact: true })).toHaveCount(0);
    }
    await expect(page.getByRole('menuitem', { name: 'Download', exact: true })).toBeVisible();
  });
});

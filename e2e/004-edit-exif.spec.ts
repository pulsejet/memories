import { test, expect } from '@playwright/test';
import { appUrl, e2eHeaders, bootstrap, teardown } from './navigation';
import { DavClient } from './utils';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.use({
  extraHTTPHeaders: e2eHeaders({
    timelinePath: '/for-edit-exif',
  }),
});

test.describe('Metadata', () => {
  test('@ui Edit metadata through Viewer', async ({ request, page }) => {
    const dav = new DavClient(request);
    const fileid1 = await dav.fileid('/for-edit-exif/ui_edit.jpg');
    const random = Math.floor(Math.random() * 1000000);
    const testTitle = `Test title ${random}`;
    const testDescription = `Test description ${random}`;

    await test.step('Open dialog', async () => {
      await page.goto(appUrl);
      await page.locator(`.p-outer.fill-block.p-outer--${fileid1} > .img-outer`).click();

      await page.getByRole('button', { name: 'Actions' }).click();
      await page.getByRole('menuitem', { name: 'Edit metadata' }).click();
    });

    await test.step('Submit request', async () => {
      await page.getByRole('textbox', { name: 'Title' }).fill(testTitle);
      await page.getByRole('textbox', { name: 'Description' }).fill(testDescription);
      await page.getByRole('button', { name: 'Save' }).click();
    });

    await test.step('Verify in viewer', async () => {
      await expect(page.locator('.memories_viewer .exif.title')).toHaveText(testTitle);
      await expect(page.locator('.memories_viewer .exif.description')).toHaveText(testDescription);
    });
  });
});

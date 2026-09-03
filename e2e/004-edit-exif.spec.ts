import { test, expect } from '@playwright/test';
import { appUrl, ocsHeaders, bootstrap } from './navigation';
import { getFileId } from './utils';

test.use({
  extraHTTPHeaders: {
    ...ocsHeaders,
    'X-Timeline-Path': '/for-edit-exif',
  },
});

test.describe('Metadata', () => {
  let fileid1: number;

  test.beforeAll(async ({ request }) => {
    fileid1 = await getFileId(request, '/for-edit-exif/ui_edit.jpg');
  });

  test('@ui Edit metadata through Viewer', async ({ page }) => {
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

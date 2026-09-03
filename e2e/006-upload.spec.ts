import * as path from 'path';
import { test, expect } from '@playwright/test';
import { appUrl, ocsHeaders, bootstrap } from './navigation';
import { getFileId } from './utils';

test.use({ extraHTTPHeaders: ocsHeaders });

test.describe('@ui Upload Workflow', () => {
  let uFileId1: number;
  let uFileId2: number;
  let uFileId3: number;

  test.beforeEach(async ({ page }) => {
    await bootstrap(page);
  });

  test('Upload files to folder', async ({ request, page }) => {
    const uploadFilePaths = [
      path.resolve(__dirname, '../tests/assets/apple_h264_boy_01.jpg'),
      path.resolve(__dirname, '../tests/assets/apple_h264_boy_01.mov'),
      path.resolve(__dirname, '../tests/assets/apple_h264_girl_01.jpg'),
    ];

    await test.step('Select files', async () => {
      await page.goto(`${appUrl}/folders`);
      await page.locator('.folder--for-upload').click();

      const fileChooserPromise = page.waitForEvent('filechooser');
      await page.locator('.upload-menu').click();
      const fileChooser = await fileChooserPromise;
      await fileChooser.setFiles(uploadFilePaths);
    });

    await test.step('Submit', async () => {
      await page.getByLabel('Upload 3 files').getByRole('button', { name: 'Upload' }).click();
      await page.locator('.memories-modal').waitFor({ state: 'detached' });
    });

    await test.step('Verify', async () => {
      uFileId1 = await getFileId(request, '/for-upload/apple_h264_boy_01.jpg');
      uFileId2 = await getFileId(request, '/for-upload/apple_h264_girl_01.jpg');
      uFileId3 = await getFileId(request, '/for-upload/apple_h264_boy_01.mov');
      await expect(page.locator(`.p-outer--${uFileId1}`)).toBeVisible();
      await expect(page.locator(`.p-outer--${uFileId2}`)).toBeVisible();
      await expect(page.locator(`.p-outer--${uFileId3}`)).not.toBeVisible();
    });

    await test.step('Cleanup', async () => {
      await page.goto(`${appUrl}/folders/for-upload`);

      await expect(page.locator(`.p-outer--${uFileId1}`)).toBeVisible();
      await expect(page.locator(`.p-outer--${uFileId2}`)).toBeVisible();

      await page.hover(`.p-outer--${uFileId1}`);
      await page.locator(`.p-outer--${uFileId1} > div.select`).click();
      await page.hover(`.p-outer--${uFileId2}`);
      await page.locator(`.p-outer--${uFileId2} > div.select`).click();

      await page.getByRole('button', { name: 'Delete' }).click();
      await page.getByRole('button', { name: 'Yes' }).click();

      await test.step('Verify', async () => {
        await expect(page.locator(`.p-outer--${uFileId1}`)).toHaveCount(0);
        await expect(page.locator(`.p-outer--${uFileId2}`)).toHaveCount(0);
      });
    });
  });
});

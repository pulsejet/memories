import * as path from 'path';
import { test, expect } from '@playwright/test';
import { appUrl, e2eHeaders, bootstrap } from './navigation';
import { getFileId } from './utils';

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('@ui Upload Workflow', () => {
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

    let fids: number[] = [0, 0, 0];
    await test.step('Verify', async () => {
      fids[0] = await getFileId(request, '/for-upload/apple_h264_boy_01.jpg');
      fids[1] = await getFileId(request, '/for-upload/apple_h264_girl_01.jpg');
      fids[2] = await getFileId(request, '/for-upload/apple_h264_boy_01.mov');
      await expect(page.locator(`.p-outer--${fids[0]}`)).toBeVisible();
      await expect(page.locator(`.p-outer--${fids[1]}`)).toBeVisible();
      await expect(page.locator(`.p-outer--${fids[2]}`)).not.toBeVisible();
    });

    await test.step('Cleanup', async () => {
      await page.goto(`${appUrl}/folders/for-upload`);

      await expect(page.locator(`.p-outer--${fids[0]}`)).toBeVisible();
      await expect(page.locator(`.p-outer--${fids[1]}`)).toBeVisible();

      await page.hover(`.p-outer--${fids[0]}`);
      await page.locator(`.p-outer--${fids[0]} > div.select`).click();
      await page.hover(`.p-outer--${fids[1]}`);
      await page.locator(`.p-outer--${fids[1]} > div.select`).click();

      await page.getByRole('button', { name: 'Delete' }).click();
      await page.getByRole('button', { name: 'Yes' }).click();

      await test.step('Verify', async () => {
        await expect(page.locator(`.p-outer--${fids[0]}`)).toHaveCount(0);
        await expect(page.locator(`.p-outer--${fids[1]}`)).toHaveCount(0);
      });
    });
  });
});

import * as path from 'path';
import { test, expect } from '@playwright/test';
import { appUrl, e2eHeaders, bootstrap, psub } from './navigation';
import { getFileId, copyPath, deletePath } from './utils';

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('@ui Upload Workflow', () => {
  test.beforeAll(async ({ request }) => {
    await deletePath(request, '/for-upload-%wid', true);
    await copyPath(request, '/for-upload', '/for-upload-%wid');
  });

  test.afterAll(async ({ request }) => {
    await deletePath(request, '/for-upload-%wid');
  });

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
      await page.goto(psub(`${appUrl}/folders/for-upload-%wid`));
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
      fids[0] = await getFileId(request, '/for-upload-%wid/apple_h264_boy_01.jpg');
      fids[1] = await getFileId(request, '/for-upload-%wid/apple_h264_girl_01.jpg');
      fids[2] = await getFileId(request, '/for-upload-%wid/apple_h264_boy_01.mov');
      await expect(page.locator(`.p-outer--${fids[0]}`)).toBeVisible();
      await expect(page.locator(`.p-outer--${fids[1]}`)).toBeVisible();
      await expect(page.locator(`.p-outer--${fids[2]}`)).not.toBeVisible();
    });
  });
});

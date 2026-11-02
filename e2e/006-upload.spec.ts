import * as path from 'path';
import { fileURLToPath } from 'node:url';
import { expect } from '@playwright/test';
import { test, appUrl, e2eHeaders, psub } from './navigation';
import { DavClient } from './utils';
import type { Page, Request } from '@playwright/test';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const testFolder = '/for-upload-%wid';

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('@ui Upload Workflow', () => {
  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    await dav.deleteFile(testFolder, true);
    await dav.copyFile('/for-upload', testFolder);
  });

  test.afterAll(async ({ request }) => {
    await new DavClient(request).deleteFile(testFolder);
  });

  test('Upload files to folder', async ({ request, page }) => {
    const dav = new DavClient(request);
    const uploadFilePaths = [
      path.resolve(__dirname, '../tests/assets/apple_h264_boy_01.jpg'),
      path.resolve(__dirname, '../tests/assets/apple_h264_boy_01.mov'),
      path.resolve(__dirname, '../tests/assets/apple_h264_girl_01.jpg'),
    ];

    await test.step('Select files', async () => {
      await openFolder(page);
      await selectFiles(page, uploadFilePaths);
    });

    await test.step('Submit', async () => {
      await page.getByLabel('Upload 3 files').getByRole('button', { name: 'Upload' }).click();
      await page.locator('.memories-modal').waitFor({ state: 'detached' });
    });

    let fids: number[] = [0, 0, 0];
    await test.step('Verify', async () => {
      fids[0] = await dav.fileid(`${testFolder}/apple_h264_boy_01.jpg`);
      fids[1] = await dav.fileid(`${testFolder}/apple_h264_girl_01.jpg`);
      fids[2] = await dav.fileid(`${testFolder}/apple_h264_boy_01.mov`);
      await expect(page.locator(`.p-outer--${fids[0]}`)).toBeVisible();
      await expect(page.locator(`.p-outer--${fids[1]}`)).toBeVisible();
      await expect(page.locator(`.p-outer--${fids[2]}`)).not.toBeVisible();
    });
  });

  test('Upload chunked file', async ({ request, page }) => {
    const dav = new DavClient(request);
    const file = 'samsung_s21_03.mp4';
    await dav.deleteFile(`${testFolder}/${file}`, true);

    await openFolder(page);
    const isChunkRequest = (method: string) => (r: Request) =>
      r.method() === method && r.url().includes('/remote.php/dav/uploads/');
    const chunk = page.waitForRequest(isChunkRequest('PUT'));
    const assemble = page.waitForRequest(isChunkRequest('MOVE'));

    await selectFiles(page, [path.resolve(__dirname, `../tests/assets/${file}`)]);
    await page.getByLabel('Upload 1 file').getByRole('button', { name: 'Upload' }).click();
    await Promise.all([chunk, assemble]);

    // The modal only closes once the file ID of the upload is known
    await page.locator('.memories-modal').waitFor({ state: 'detached' });
    expect(await dav.fileid(`${testFolder}/${file}`)).toBeGreaterThan(0);
  });
});

// Open the upload folder, with chunking enabled for files above the library minimum.
async function openFolder(page: Page) {
  await page.goto(psub(`${appUrl}/folders${testFolder}`));
  await page.evaluate(() => {
    window.OC.appConfig.files.max_chunk_size = 5 * 1024 * 1024;
  });
}

async function selectFiles(page: Page, filePaths: string[]) {
  const fileChooserPromise = page.waitForEvent('filechooser');
  await page.locator('.upload-menu').click();
  const fileChooser = await fileChooserPromise;
  await fileChooser.setFiles(filePaths);
}

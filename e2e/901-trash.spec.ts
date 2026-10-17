import { test, expect } from '@playwright/test';
import { appUrl, bootstrap, teardown, username, psub } from './navigation';
import { DavClient } from './utils';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.describe('Trash', () => {
  const FOLDER = psub('/for-trash-%wid');

  // Unique per worker: same-second trash names would collide across workers.
  const WID = process.env.TEST_WORKER_INDEX || '0';

  const FILES = {
    listRestore: `trash_${WID}_restore.jpg`,
    listDelete: `trash_${WID}_delete.jpg`,
    viewerRestore: `trash_${WID}_viewer.jpg`,
    viewerDelete: `trash_${WID}_viewer_delete.jpg`,
  };

  const trashLoc = (f: string) => `for-trash-${WID}/${f}`;

  // Trash fileids snapshotted once: entries are only removed by our own tests.
  let trashIds = {} as Record<string, number>;

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    await dav.cleanupTrashEntries(Object.values(FILES).map((f) => `${FOLDER}/${f}`));
    await dav.deleteFile(FOLDER, true);
    await dav.mkcol(`files/${username}/${FOLDER.replace(/^\/+/, '')}`, true);
    await dav.copyFile('/for-delete/delete_01.jpg', `${FOLDER}/${FILES.listRestore}`);
    await dav.copyFile('/for-delete/delete_02.jpg', `${FOLDER}/${FILES.listDelete}`);
    await dav.copyFile('/for-delete/delete_03.jpg', `${FOLDER}/${FILES.viewerRestore}`);
    await dav.copyFile('/for-delete/delete_04.jpg', `${FOLDER}/${FILES.viewerDelete}`);

    // Real deletes (no SKIP-TRASHBIN) so files land in the trashbin.
    for (const f of Object.values(FILES)) {
      await dav.trashDelete(`${FOLDER}/${f}`);
    }

    const entries = await dav.listTrash();
    for (const f of Object.values(FILES)) {
      const entry = entries.find((e) => e.originalLocation === trashLoc(f));
      if (!entry) throw new Error(`missing trash entry for ${f}`);
      trashIds[f] = entry.fileid;
    }
  });

  test.afterAll(async ({ request }) => {
    await new DavClient(request).deleteFile(FOLDER);
  });

  test('@ui Restore photo from trash list', async ({ request, page }) => {
    const dav = new DavClient(request);
    const trashId = trashIds[FILES.listRestore];
    await page.goto(`${appUrl}/trash`);
    await expect(page.locator(`.p-outer--trash-${trashId}`)).toHaveCount(1);
    await page.hover(`.p-outer--trash-${trashId}`);
    await page.locator(`.p-outer--trash-${trashId} > div.select`).click();

    await page.getByRole('button', { name: 'Restore' }).click();
    await expect(page.locator(`.p-outer--trash-${trashId}`)).toHaveCount(0);
    expect(await dav.fileid(`${FOLDER}/${FILES.listRestore}`)).toBeGreaterThan(0);
  });

  test('@ui Delete photo permanently from trash list', async ({ request, page }) => {
    const dav = new DavClient(request);
    const trashId = trashIds[FILES.listDelete];
    await page.goto(`${appUrl}/trash`);
    await expect(page.locator(`.p-outer--trash-${trashId}`)).toHaveCount(1);
    await page.hover(`.p-outer--trash-${trashId}`);
    await page.locator(`.p-outer--trash-${trashId} > div.select`).click();

    await page.getByRole('button', { name: 'Delete permanently' }).first().click();

    const dialog = page.getByRole('dialog');
    await dialog.getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(page.locator(`.p-outer--trash-${trashId}`)).toHaveCount(0);

    const entries = await dav.listTrash();
    expect(entries.some((e) => e.originalLocation === trashLoc(FILES.listDelete))).toBeFalsy();
  });

  test('@ui Trash viewer restore action', async ({ request, page }) => {
    const dav = new DavClient(request);
    const trashId = trashIds[FILES.viewerRestore];
    await page.goto(`${appUrl}/trash`);
    await expect(page.locator(`.p-outer--trash-${trashId}`)).toHaveCount(1);

    await page.locator(`.p-outer--trash-${trashId}`).click();
    await page.waitForSelector('.memories-viewer.fully-opened');

    const viewer = page.locator('.memories-viewer');
    await viewer.getByRole('button', { name: 'Restore' }).click();
    await expect(page.locator(`.p-outer--trash-${trashId}`)).toHaveCount(0);
    expect(await dav.fileid(`${FOLDER}/${FILES.viewerRestore}`)).toBeGreaterThan(0);
  });

  test('@ui Trash viewer delete action', async ({ request, page }) => {
    const dav = new DavClient(request);
    const deleteId = trashIds[FILES.viewerDelete];
    await page.goto(`${appUrl}/trash`);
    await expect(page.locator(`.p-outer--trash-${deleteId}`)).toHaveCount(1);

    await page.locator(`.p-outer--trash-${deleteId}`).click();
    await page.waitForSelector('.memories-viewer.fully-opened');

    const viewer = page.locator('.memories-viewer');
    await viewer.getByRole('button', { name: 'Delete', exact: true }).click();

    const dialog = page.getByRole('dialog');
    await dialog.getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(page.locator(`.p-outer--trash-${deleteId}`)).toHaveCount(0);

    const entries = await dav.listTrash();
    expect(entries.some((e) => e.originalLocation === trashLoc(FILES.viewerDelete))).toBeFalsy();
  });
});

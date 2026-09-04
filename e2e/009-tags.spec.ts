import { test, expect } from '@playwright/test';
import { imageSize } from 'image-size';
import { appUrl, baseUrl, e2eHeaders, bootstrap, teardown, psub } from './navigation';
import { DavClient } from './utils';

import type { APIRequestContext } from '@playwright/test';
import type { ICluster, IDay, IPhoto } from '@typings';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.use({
  extraHTTPHeaders: e2eHeaders({
    timelinePath: '/for-tags-%wid',
  }),
});

test.describe('Tags', () => {
  // Stable per-worker names: tags are global and cannot be deleted
  // via DAV by non-admins, so reuse them instead of accumulating new ones.
  const tagA = psub('e2e-tags-a-%wid');
  const tagB = psub('e2e-tags-b-%wid');
  const tagEmpty = psub('e2e-tags-empty-%wid');

  let img1: number;
  let img2: number;
  let img3: number;

  let tagAId: number;
  let tagBId: number;

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    const tags = new TagAPI(request);

    await test.step('Copy isolated timeline folder', async () => {
      await dav.deleteFile('/for-tags-%wid', true);
      await dav.copyFile('/for-other', '/for-tags-%wid');

      img1 = await dav.fileid('/for-tags-%wid/RmjH76vMWrI.jpg');
      img2 = await dav.fileid('/for-tags-%wid/dHLhDeEgxsg.jpg');
      img3 = await dav.fileid('/for-tags-%wid/kvRlouf0RTs.jpg');
    });

    await test.step('Create tags and assign to photos', async () => {
      tagAId = await tags.create(tagA);
      tagBId = await tags.create(tagB);
      await tags.create(tagEmpty);

      // img2 gets both tags: one image can have multiple tags.
      await tags.set(img1, [tagAId]);
      await tags.set(img2, [tagAId, tagBId]);
      await tags.set(img3, [tagBId]);
    });
  });

  test.afterAll(async ({ request }) => {
    await new DavClient(request).deleteFile('/for-tags-%wid', true);
  });

  test('@api Tags clusters show only timeline tags', async ({ request }) => {
    const clusters = await new TagAPI(request).clusters();
    const byName = new Map(clusters.map((c) => [c.name, c]));

    await test.step('Tags in timeline show up with photo counts', async () => {
      expect(byName.get(tagA)?.count).toBe(2);
      expect(byName.get(tagB)?.count).toBe(2);

      for (const tag of [tagA, tagB]) {
        const cluster = byName.get(tag)!;
        expect(cluster.cluster_type).toBe('tags');
        expect(cluster.cluster_id).toBe(tag);
      }
    });

    await test.step('Tag without photos is hidden', async () => {
      expect(byName.has(tagEmpty)).toBe(false);
    });
  });

  test('@api Tagged image has multiple tags', async ({ request }) => {
    const dav = new DavClient(request);

    const info2 = await dav.imageInfo(img2, { tags: '1' });
    expect(Object.keys(info2.tags ?? {})).toHaveLength(2);
    expect(info2.tags?.[String(tagAId)]).toBe(tagA);
    expect(info2.tags?.[String(tagBId)]).toBe(tagB);

    const info1 = await dav.imageInfo(img1, { tags: '1' });
    expect(Object.keys(info1.tags ?? {})).toHaveLength(1);
    expect(info1.tags?.[String(tagAId)]).toBe(tagA);
  });

  test('@api Timeline filtered by tag', async ({ request }) => {
    const tags = new TagAPI(request);

    expect(await tags.taggedFileids(tagA)).toStrictEqual(expect.arrayContaining([img1, img2]));
    expect(await tags.taggedFileids(tagA)).toHaveLength(2);

    expect(await tags.taggedFileids(tagB)).toStrictEqual(expect.arrayContaining([img2, img3]));
    expect(await tags.taggedFileids(tagB)).toHaveLength(2);
  });

  test('@api Tag covers point at tagged photos', async ({ request }) => {
    const tags = new TagAPI(request);

    await test.step('Generate covers via previews', async () => {
      const random = Math.floor(Math.random() * 1000000);
      const responses = await Promise.all([tagA, tagB].map((name) => tags.preview(name, random)));
      for (const res of responses) {
        expect(res.headers()['content-type']).toContain('image/jpeg');

        const body = await res.body();
        expect(body.length).toBeGreaterThan(0);
        expect(imageSize(new Uint8Array(body)).type).toBe('jpg');
      }
    });

    await test.step('Covers are photos carrying the tag', async () => {
      const clusters = await tags.clusters();
      for (const name of [tagA, tagB]) {
        const cover = clusters.find((c) => c.name === name)?.cover;
        expect(typeof cover).toBe('number');
        expect(await tags.taggedFileids(name)).toContain(cover);
      }
    });
  });

  test('@ui Tags view lists timeline tags', async ({ page }) => {
    await page.goto(`${appUrl}/tags`);

    await expect(page.locator(`.cluster[aria-label="${tagA}"]`)).toBeVisible();
    await expect(page.locator(`.cluster[aria-label="${tagB}"]`)).toBeVisible();
    await expect(page.locator(`.cluster[aria-label="${tagEmpty}"]`)).toHaveCount(0);
  });

  test('@ui Open tag shows tagged images', async ({ page }) => {
    await page.goto(`${appUrl}/tags`);
    await page.locator(`.cluster[aria-label="${tagA}"]`).click();

    await expect(page).toHaveURL(`${appUrl}/tags/${encodeURIComponent(tagA)}`);
    await expect(page.locator('.top-matter .name')).toHaveText(tagA);
    await expect(page.locator('.p-outer')).toHaveCount(2);
    await expect(page.locator(`.p-outer--${img1}`)).toBeVisible();
    await expect(page.locator(`.p-outer--${img2}`)).toBeVisible();
    await expect(page.locator(`.p-outer--${img3}`)).toHaveCount(0);
  });

  test('@ui Open second tag shows its images', async ({ page }) => {
    await page.goto(`${appUrl}/tags`);
    await page.locator(`.cluster[aria-label="${tagB}"]`).click();

    await expect(page).toHaveURL(`${appUrl}/tags/${encodeURIComponent(tagB)}`);
    await expect(page.locator('.top-matter .name')).toHaveText(tagB);
    await expect(page.locator('.p-outer')).toHaveCount(2);
    await expect(page.locator(`.p-outer--${img2}`)).toBeVisible();
    await expect(page.locator(`.p-outer--${img3}`)).toBeVisible();
    await expect(page.locator(`.p-outer--${img1}`)).toHaveCount(0);
  });
});

// System tags API client for e2e tests.
class TagAPI {
  constructor(private request: APIRequestContext) {}

  /** Create a system tag, reusing the existing one on name conflict. */
  async create(name: string): Promise<number> {
    const res = await this.request.post(`${baseUrl}/remote.php/dav/systemtags/`, {
      headers: e2eHeaders(),
      data: {
        name,
        userVisible: true,
        userAssignable: true,
      },
    });

    // Tag from a previous run: look up its id.
    if (res.status() === 409) {
      return this.findId(name);
    }
    expect(res.status()).toBe(201);

    const location = res.headers()['content-location'] ?? '';
    const id = Number(location.split('/').filter(Boolean).pop());
    expect(id).toBeGreaterThan(0);
    return id;
  }

  /** Assign/unassign tags on a file via the Memories API. */
  async set(fileid: number, add: number[] = [], remove: number[] = []) {
    const res = await this.request.patch(`${appUrl}/api/tags/set/${fileid}`, {
      data: { add, remove },
    });
    expect(res.ok()).toBeTruthy();
  }

  /** List tag clusters in the current timeline. */
  async clusters(): Promise<ICluster[]> {
    const res = await this.request.get(`${appUrl}/api/clusters/tags`);
    expect(res.ok()).toBeTruthy();
    return res.json();
  }

  /** Fetch a tag preview, generating its cover as a side effect. */
  async preview(name: string, cover: number) {
    const url = new URL(`${appUrl}/api/clusters/tags/preview`);
    url.searchParams.set('name', name);
    url.searchParams.set('cover', String(cover));
    url.searchParams.set('cover_etag', 'null');
    const res = await this.request.get(url.toString());
    expect(res.ok()).toBeTruthy();
    return res;
  }

  /** Get fileids of all timeline photos carrying the given tag. */
  async taggedFileids(tag: string): Promise<number[]> {
    const url = new URL(`${appUrl}/api/days`);
    url.searchParams.set('tags', tag);
    const res = await this.request.get(url.toString());
    expect(res.ok()).toBeTruthy();

    const days: IDay[] = await res.json();
    return days.flatMap((d) => (d.detail ?? []).map((p: IPhoto) => p.fileid));
  }

  /** Find a system tag id by display name via WebDAV. */
  async findId(name: string): Promise<number> {
    const dav = new DavClient(this.request);
    const props = await dav.propfind('systemtags/', { 'oc:display-name': '', 'oc:id': '' }, 1);
    const match = props.find((p: any) => p?.['display-name'] === name);
    if (match?.id === undefined) {
      throw new Error(`Tag not found: ${name}`);
    }
    return parseInt(String(match.id), 10);
  }
}

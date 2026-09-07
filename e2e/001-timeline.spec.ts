import { test, expect } from '@playwright/test';
import { appUrl, e2eHeaders } from './navigation';
import { goldDays, goldDayPhotos } from './dataset-measurements';

import type { IDay, IPhoto } from '@typings';

const TIMELINE_PATH = 'primary/for-default/';
const TEST_DAY_IDS = [20696, 18962, 18955, 19468, 19221];

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('@api Timeline', () => {
  // Tests OCA\Memories\Controller\DaysController::days()
  test('Query days endpoint', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/days?nopreload=1`);
    expect(res.ok()).toBeTruthy();

    const data: IDay[] = await res.json();
    expect(data).toStrictEqual(goldDays(TIMELINE_PATH));
  });

  test('Query days preload', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/days`);
    expect(res.ok()).toBeTruthy();

    // Maximum of 5 days are preloaded (or 50 photos)
    const data: IDay[] = await res.json();
    for (let i = 0; i < 5; i++) {
      const day = data[i];
      expect(day.detail).toBeDefined();
      expect(day.detail!.length).toBeGreaterThan(0);
      day.detail!.forEach(cleanupPhoto);
      expect(day.detail).toStrictEqual(goldDayPhotos(TIMELINE_PATH, day.dayid));
      delete day.detail;
    }

    expect(data).toStrictEqual(goldDays(TIMELINE_PATH));
  });

  // Tests OCA\Memories\Controller\DaysController::day()
  test(`Query day POST endpoint`, async ({ request }) => {
    const res = await request.post(`${appUrl}/api/days`, {
      data: {
        dayIds: [20696, 18955, 500],
      },
    });
    expect(res.ok()).toBeTruthy();

    const data: IPhoto[] = await res.json();
    data.forEach(cleanupPhoto);

    expect(data).toStrictEqual([...goldDayPhotos(TIMELINE_PATH, 20696), ...goldDayPhotos(TIMELINE_PATH, 18955)]);
  });

  // Tests OCA\Memories\Controller\DaysController::dayGet()
  for (const testDayId of TEST_DAY_IDS) {
    test(`Query day(${testDayId}) GET endpoint`, async ({ request }) => {
      const res = await request.get(`${appUrl}/api/days/${testDayId}`);
      expect(res.ok()).toBeTruthy();

      const data: IPhoto[] = await res.json();
      data.forEach(cleanupPhoto);

      expect(data).toStrictEqual(goldDayPhotos(TIMELINE_PATH, testDayId));
    });
  }

  test('Query archived days endpoint', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/days?nopreload=1&archive=1`);
    expect(res.ok()).toBeTruthy();

    const data: IDay[] = await res.json();
    expect(data).toStrictEqual(goldDays(TIMELINE_PATH, true));
  });

  test('Query archived day endpoint', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/days/19354?archive=1`);
    expect(res.ok()).toBeTruthy();

    const data: IPhoto[] = await res.json();
    data.forEach(cleanupPhoto);

    expect(data).toStrictEqual(goldDayPhotos(TIMELINE_PATH, 19354, true));
  });

  test('Filter by video', async ({ request }) => {
    await test.step('Image-only timeline has no videos', async () => {
      const url = new URL(`${appUrl}/api/days`);
      url.searchParams.set('nopreload', '1');
      url.searchParams.set('vid', '1');
      const res = await request.get(url.toString());
      expect(res.ok()).toBeTruthy();
      expect(await res.json()).toStrictEqual([]);
    });

    // Standalone video from the generated dataset (see e2e_generate_datasets).
    const headers = e2eHeaders({ timelinePath: '/for-vid' });

    const total: IDay[] = await test.step('Video timeline days', async () => {
      const totalUrl = new URL(`${appUrl}/api/days`);
      totalUrl.searchParams.set('nopreload', '1');
      const total: IDay[] = await (await request.get(totalUrl.toString(), { headers })).json();
      expect(total).toHaveLength(1);
      expect(total[0].count).toBe(1);

      const vidUrl = new URL(`${appUrl}/api/days`);
      vidUrl.searchParams.set('nopreload', '1');
      vidUrl.searchParams.set('vid', '1');
      const vidDays: IDay[] = await (await request.get(vidUrl.toString(), { headers })).json();
      expect(vidDays).toStrictEqual(total);
      return total;
    });

    await test.step('Video day photos', async () => {
      const detailUrl = new URL(`${appUrl}/api/days`);
      detailUrl.searchParams.set('vid', '1');
      const photos: IPhoto[] = await (
        await request.post(detailUrl.toString(), {
          headers,
          data: { dayIds: total.map((d) => d.dayid) },
        })
      ).json();
      expect(photos).toHaveLength(1);
      expect(Boolean(photos[0].isvideo)).toBe(true);
      expect(photos[0].basename).toBe('clip.mp4');
    });
  });

  test('Filter by folder views', async ({ request }) => {
    // /for-default/Nested 1 directly holds test_01.jpg (2023-06-24) and test_02.jpg (2023-04-21),
    // with test_04.jpg and test_05.jpg nested one level deeper (2023-01-18).
    await test.step('Folder view lists immediate children', async () => {
      const url = new URL(`${appUrl}/api/days`);
      url.searchParams.set('nopreload', '1');
      url.searchParams.set('folder', '/for-default/Nested 1');
      const days: IDay[] = await (await request.get(url.toString())).json();
      expect(days).toStrictEqual([
        { dayid: 19532, count: 1 },
        { dayid: 19468, count: 1 },
      ]);
    });

    await test.step('Flat view includes nested photos', async () => {
      const url = new URL(`${appUrl}/api/days`);
      url.searchParams.set('nopreload', '1');
      url.searchParams.set('folder', '/for-default/Nested 1');
      url.searchParams.set('recursive', '1');
      const days: IDay[] = await (await request.get(url.toString())).json();
      expect(days).toStrictEqual([
        { dayid: 19532, count: 1 },
        { dayid: 19468, count: 1 },
        { dayid: 19375, count: 2 },
      ]);
    });
  });

  test('Reverse order', async ({ request }) => {
    const gold = goldDays(TIMELINE_PATH);
    expect(gold.length).toBeGreaterThan(1);

    const revUrl = new URL(`${appUrl}/api/days`);
    revUrl.searchParams.set('nopreload', '1');
    revUrl.searchParams.set('reverse', '1');
    const rev: IDay[] = await (await request.get(revUrl.toString())).json();
    expect(rev).toStrictEqual([...gold].reverse());

    const dayId = gold[0].dayid;
    const revDayUrl = new URL(`${appUrl}/api/days/${dayId}`);
    revDayUrl.searchParams.set('reverse', '1');
    const revDay: IPhoto[] = await (await request.get(revDayUrl.toString())).json();
    expect(revDay.map((p) => p.basename)).toStrictEqual(
      goldDayPhotos(TIMELINE_PATH, dayId).map((p) => p.basename).reverse(),
    );
  });

  test('Limit day query', async ({ request }) => {
    const dayIds = goldDays(TIMELINE_PATH).slice(0, 3).map((d) => d.dayid);
    const expected = dayIds.reduce((n, id) => n + goldDayPhotos(TIMELINE_PATH, id).length, 0);
    expect(expected).toBeGreaterThan(1);

    const oneUrl = new URL(`${appUrl}/api/days`);
    oneUrl.searchParams.set('limit', '1');
    const one: IPhoto[] = await (await request.post(oneUrl.toString(), { data: { dayIds } })).json();
    expect(one).toHaveLength(1);

    const bigUrl = new URL(`${appUrl}/api/days`);
    bigUrl.searchParams.set('limit', '200');
    const big: IPhoto[] = await (await request.post(bigUrl.toString(), { data: { dayIds } })).json();
    expect(big.length).toBe(expected);
  });

  test('Month view aggregation', async ({ request }) => {
    const days = goldDays('primary/for-default/');
    expect(days.length).toBeGreaterThan(0);

    const url = new URL(`${appUrl}/api/days`);
    url.searchParams.set('nopreload', '1');
    url.searchParams.set('monthView', '1');
    const months: IDay[] = await (await request.get(url.toString())).json();

    const expected = new Map<number, number>();
    for (const d of days) {
      const dt = new Date(d.dayid * 86400 * 1000);
      const monthId = Date.UTC(dt.getUTCFullYear(), dt.getUTCMonth(), 1) / 86400000;
      expected.set(monthId, (expected.get(monthId) ?? 0) + d.count);
    }
    const gold = [...expected.entries()]
      .map(([dayid, count]) => ({ dayid, count }))
      .sort((a, b) => b.dayid - a.dayid);

    expect(months).toStrictEqual(gold);
  });
});

// Cleanup unpredictable values from photo object.
function cleanupPhoto(item: IPhoto): void {
  if (typeof item.etag !== 'string' || item.etag.length === 0) {
    throw new Error(`Invalid etag: ${item.etag}`);
  }
  if (typeof item.fileid !== 'number' || item.fileid <= 0) {
    throw new Error(`Invalid fileid: ${item.fileid}`);
  }

  delete item.etag;
  item.fileid = 0;
  item.flag = 0;
}

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

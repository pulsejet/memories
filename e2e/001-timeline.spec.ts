import { test, expect } from '@playwright/test';
import { appUrl, authHeaders } from './login';
import { cleanupPhoto } from './utils';

import type { IDay, IPhoto } from '@typings';

import assetPrimaryApiDays from './assets/primary-api/main-days.json';
import assetPrimaryApiDay20696 from './assets/primary-api/main-day-20696.json';
import assetPrimaryApiDay18962 from './assets/primary-api/main-day-18962.json';
import assetPrimaryApiDay18955 from './assets/primary-api/main-day-18955.json';
import assetPrimaryApiDay19221 from './assets/primary-api/main-day-19221.json';
import assetPrimaryApiDay19468 from './assets/primary-api/main-day-19468.json';

const TIMELINE_DAY_MAP: Record<any, IPhoto[]> = {
  '20696': assetPrimaryApiDay20696 satisfies IPhoto[],
  '18962': assetPrimaryApiDay18962 satisfies IPhoto[],
  '18955': assetPrimaryApiDay18955 satisfies IPhoto[],
  '19468': assetPrimaryApiDay19468 satisfies IPhoto[],
  '19221': assetPrimaryApiDay19221 satisfies IPhoto[],
};

test.describe('@api Timeline', () => {
  // Tests OCA\Memories\Controller\DaysController::days()
  test('Query days endpoint', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/days?nopreload=1`, {
      headers: authHeaders,
    });
    expect(res.ok()).toBeTruthy();

    const data: IDay[] = await res.json();

    expect(data).toStrictEqual(assetPrimaryApiDays satisfies IDay[]);
  });

  test('Query days preload', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/days`, {
      headers: authHeaders,
    });
    expect(res.ok()).toBeTruthy();

    // Maximum of 5 days are preloaded (or 50 photos)
    const data: IDay[] = await res.json();
    for (let i = 0; i < 5; i++) {
      const day = data[i];
      expect(day.detail).toBeDefined();
      expect(day.detail!.length).toBeGreaterThan(0);
      day.detail!.forEach(cleanupPhoto);
      if (day.dayid in TIMELINE_DAY_MAP) {
        expect(day.detail).toStrictEqual(TIMELINE_DAY_MAP[day.dayid]);
      }
      delete day.detail;
    }

    expect(data).toStrictEqual(assetPrimaryApiDays satisfies IDay[]);
  });

  // Tests OCA\Memories\Controller\DaysController::day()
  test(`Query day POST endpoint`, async ({ request }) => {
    const res = await request.post(`${appUrl}/api/days`, {
      headers: authHeaders,
      data: {
        dayIds: [20696, 18955, 500],
      },
    });
    expect(res.ok()).toBeTruthy();

    const data: IPhoto[] = await res.json();
    data.forEach(cleanupPhoto);

    expect(data).toStrictEqual([...TIMELINE_DAY_MAP[20696], ...TIMELINE_DAY_MAP[18955]]);
  });

  // Tests OCA\Memories\Controller\DaysController::dayGet()
  for (const testDayId of Object.keys(TIMELINE_DAY_MAP)) {
    test(`Query day(${testDayId}) GET endpoint`, async ({ request }) => {
      const res = await request.get(`${appUrl}/api/days/${testDayId}`, {
        headers: authHeaders,
      });
      expect(res.ok()).toBeTruthy();

      const data: IPhoto[] = await res.json();
      data.forEach(cleanupPhoto);

      expect(data).toStrictEqual(TIMELINE_DAY_MAP[testDayId]);
    });
  }
});

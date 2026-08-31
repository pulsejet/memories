import { test, expect } from '@playwright/test';
import { login, appUrl, authHeaders } from './login';
import type { IDay, IPhoto } from '@typings';

import assetPrimaryApiDays from './assets/primary-api/days.json';
import assetPrimaryApiDay20696 from './assets/primary-api/day-20696.json';
import assetPrimaryApiDay18962 from './assets/primary-api/day-18962.json';
import assetPrimaryApiDay18955 from './assets/primary-api/day-18955.json';
import assetPrimaryApiDay19221 from './assets/primary-api/day-19221.json';

const TIMELINE_DAY_MAP: Record<any, IPhoto[]> = {
  '20696': assetPrimaryApiDay20696 satisfies IPhoto[],
  '18962': assetPrimaryApiDay18962 satisfies IPhoto[],
  '18955': assetPrimaryApiDay18955 satisfies IPhoto[],
  '19221': assetPrimaryApiDay19221 satisfies IPhoto[],
};

// Cleanup unpredictable values from photo object.
function cleanupPhoto(item: IPhoto): void {
  expect(typeof item.etag).toBe('string');
  expect(item.etag?.length).toBeGreaterThan(0);
  delete item.etag; // randomized

  expect(typeof item.auid).toBe('string');
  expect(item.auid?.length).toBeGreaterThan(0);
  delete item.auid; // unpredictable

  expect(typeof item.epoch).toBe('number');
  expect(item.epoch).toBeGreaterThan(0);
  delete item.epoch; // unpredictable

  expect(typeof item.fileid).toBe('number');
  expect(item.fileid).toBeGreaterThan(0);
  item.fileid = 0; // required
  item.flag = 0; // required
}

test.describe('Timeline API', () => {
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

    const data: IDay[] = await res.json();
    data.forEach((day) => day.detail?.forEach(cleanupPhoto));

    expect(data).toStrictEqual(
      (assetPrimaryApiDays satisfies IDay[]).map((day) => ({
        ...day,
        detail: TIMELINE_DAY_MAP[day.dayid] ?? undefined,
      })),
    );
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

test.describe('Timeline feed and photo preview', () => {
  test.beforeEach(login('/'));

  test.beforeEach(async ({ page }) => {
    await page.waitForSelector('.img-outer');
    await page.waitForTimeout(500);
  });

  test('Look for Images', async ({ page }) => {
    expect(await page.locator('.img-outer').count(), 'Number of previews').toBeGreaterThan(4);
  });

  test('Open one image', async ({ page }) => {
    await page.locator('.img-outer').first().click();
    await page.waitForTimeout(1000);
    await page.locator('button[title="Close"]').first().click();
  });
});

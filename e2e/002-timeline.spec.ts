import { test, expect } from '@playwright/test';
import { login, appUrl, authHeaders } from './login';
import type { IDay } from '@typings';

import assetPrimaryApiDays from './assets/primary-api/days.json';

test.describe('Timeline API', () => {
  // Tests OCA\Memories\Controller\DaysController::days() in lib/Controller/DaysController.php
  test('Query days endpoint', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/days`, {
      headers: authHeaders,
    });
    expect(res.ok()).toBeTruthy();

    const data = (await res.json()) as IDay[];

    // Validate presence and remove dynamic fields (etag, fileid)
    for (const day of data) {
      for (const item of day.detail || []) {
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
    }

    expect(data).toEqual(assetPrimaryApiDays satisfies IDay[]);
  });
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

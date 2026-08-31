import { test, expect } from '@playwright/test';
import { appUrl, authHeaders } from './login';
import type { IDay, IPhoto } from '@typings';
import { cleanupPhoto } from './utils';

import assetArchivedApiDays from './assets/primary-api/archived-days.json';
import assetArchivedApiDay19354 from './assets/primary-api/archived-day-19354.json';

test.describe('@api Archive', () => {
  test('Query archived days endpoint', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/days?nopreload=1&archive=1`, {
      headers: authHeaders,
    });
    expect(res.ok()).toBeTruthy();

    const data: IDay[] = await res.json();
    expect(data).toStrictEqual(assetArchivedApiDays satisfies IDay[]);
  });

  test('Query archived day endpoint', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/days/19354?archive=1`, {
      headers: authHeaders,
    });
    expect(res.ok()).toBeTruthy();

    const data: IPhoto[] = await res.json();
    data.forEach(cleanupPhoto);

    expect(data).toStrictEqual(assetArchivedApiDay19354 satisfies IPhoto[]);
  });
});

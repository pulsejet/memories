import { test, expect } from '@playwright/test';
import { appUrl, baseUrl, e2eHeaders, username } from './navigation';

import type { IConfig } from '@typings';

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('@api Config and describe', () => {
  test('Get user config', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/config`);
    expect(res.ok()).toBeTruthy();

    const data: IConfig = await res.json();
    expect(typeof data.version).toBe('string');
    expect(typeof data.timeline_path).toBe('string');
    expect(typeof data.systemtags_enabled).toBe('boolean');
    expect(typeof data.albums_enabled).toBe('boolean');
    expect(typeof data.folders_path).toBe('string');
    expect(typeof data.video_loop).toBe('boolean');
    expect(typeof data.onthisday_day_range).toBe('number');
    expect(typeof data.onthisday_photos_per_year).toBe('number');
  });

  test('Set and restore user config', async ({ request }) => {
    const before: IConfig = await (await request.get(`${appUrl}/api/config`)).json();
    const flipped = !before.video_loop;

    const putRes = await request.put(`${appUrl}/api/config/videoLoop`, {
      data: { value: String(flipped) },
    });
    expect(putRes.ok()).toBeTruthy();

    try {
      const after: IConfig = await (await request.get(`${appUrl}/api/config`)).json();
      expect(after.video_loop).toBe(flipped);
    } finally {
      const restoreRes = await request.put(`${appUrl}/api/config/videoLoop`, {
        data: { value: String(before.video_loop) },
      });
      expect(restoreRes.ok()).toBeTruthy();
    }

    const restored: IConfig = await (await request.get(`${appUrl}/api/config`)).json();
    expect(restored.video_loop).toBe(before.video_loop);
  });

  test('Describe API', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/describe`);
    expect(res.ok()).toBeTruthy();

    const data: { version: string; baseUrl: string; loginFlowUrl: string; uid: string | null } =
      await res.json();
    expect(typeof data.version).toBe('string');
    expect(data.baseUrl).toContain('/apps/memories');
    expect(data.baseUrl.startsWith(baseUrl)).toBeTruthy();
    expect(data.uid).toBe(username);
    expect(res.headers()['access-control-allow-origin']).toBe('*');
  });
});

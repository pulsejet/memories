import { test, expect } from '@playwright/test';
import { appUrl, ocsHeaders } from './navigation';
import { getImageInfo } from './utils';
import { GEO_DATASET_FILES } from './generate-geo-dataset';

import type { IMapCluster } from '@typings';

test.use({ extraHTTPHeaders: ocsHeaders });

test.describe.serial('@api Map', () => {
  // Set timeline root to test dataset folder before running map tests.
  test.beforeAll(async ({ request }) => {
    const res = await request.put(`${appUrl}/api/config/timelinePath`, {
      data: { value: '/geo-test' },
    });
    expect(res.ok()).toBeTruthy();
  });

  // Restore timeline root back to default.
  test.afterAll(async ({ request }) => {
    const res = await request.put(`${appUrl}/api/config/timelinePath`, {
      data: { value: '/Photos' },
    });
    expect(res.ok()).toBeTruthy();
  });

  // Query map clusters for the Santa Monica + Venice bounding box at zoom level 13.
  test('Query map clusters for Santa Monica and Venice', async ({ request }) => {
    const url = new URL(`${appUrl}/api/map/clusters`);
    url.searchParams.set('bounds', '33.920842,34.084143,-118.553975,-118.411067');
    url.searchParams.set('zoom', '13');

    const res = await request.get(url.toString());
    expect(res.ok()).toBeTruthy();

    const clusters: IMapCluster[] = await res.json();
    expect(clusters.length).toBeGreaterThan(0);

    // Approximate cluster center coordinates and distance threshold (~3km).
    const SANTA_MONICA_CENTER = [34.015, -118.495];
    const VENICE_CENTER = [33.987, -118.467];
    const MAX_RADIUS_DEG = 0.03;

    const distance = (c1: [number, number], c2: number[]) => {
      return Math.hypot(c1[0] - c2[0], c1[1] - c2[1]);
    };

    let santaMonicaCount = 0;
    let veniceCount = 0;

    await Promise.all(
      clusters.map(async (cluster) => {
        expect(typeof cluster.id).toBe('number');
        expect(cluster.count).toBeGreaterThan(0);
        expect(cluster.center).toHaveLength(2);
        expect(cluster.preview).toBeDefined();
        expect(typeof cluster.preview.fileid).toBe('number');

        const dSM = distance(cluster.center, SANTA_MONICA_CENTER);
        const dVenice = distance(cluster.center, VENICE_CENTER);

        let expectedCity: 'Santa Monica' | 'Venice' | null = null;
        if (dSM <= MAX_RADIUS_DEG) {
          santaMonicaCount += cluster.count;
          expectedCity = 'Santa Monica';
        } else if (dVenice <= MAX_RADIUS_DEG) {
          veniceCount += cluster.count;
          expectedCity = 'Venice';
        }

        // Ensure every cluster in this viewport falls into either.
        expect(expectedCity).not.toBeNull();

        // Verify the preview photo belongs to the expected city.
        const info = await getImageInfo(request, cluster.preview.fileid, { basic: '1' });
        expect(info.basename).toBeDefined();

        const entry = GEO_DATASET_FILES[info.basename!];
        expect(entry).toBeDefined();
        expect(entry.city).toBe(expectedCity);
      }),
    );

    // Santa Monica has 6 photos and Venice has 4 photos.
    expect(santaMonicaCount).toBe(6);
    expect(veniceCount).toBe(4);
  });
});

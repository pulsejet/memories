import { test, expect } from '@playwright/test';
import { appUrl, e2eHeaders } from './navigation';
import { getImageInfo } from './utils';

import type { IMapCluster, IDay, IPhoto } from '@typings';
import { DATASET } from './dataset';

test.use({
  extraHTTPHeaders: e2eHeaders({
    timelinePath: '/for-geo',
  }),
});

// Approximate Euclidean distance in degrees.
const distance = (c1: [number, number], c2: [number, number]) => {
  return Math.hypot(c1[0] - c2[0], c1[1] - c2[1]);
};

test.describe('@api Map', () => {
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
    const SANTA_MONICA_CENTER: [number, number] = [34.015, -118.495];
    const VENICE_CENTER: [number, number] = [33.987, -118.467];
    const MAX_RADIUS_DEG = 0.03;

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

        const entry = DATASET[`primary/for-geo/${info.basename!}`];
        expect(entry).toBeDefined();
        expect(entry.params?.city).toBe(expectedCity);
      }),
    );

    // Santa Monica has 6 photos and Venice has 4 photos.
    expect(santaMonicaCount).toBe(6);
    expect(veniceCount).toBe(4);
  });

  // Query zoomed-out map clusters covering California (zoom level 6).
  test('Query zoomed-out map clusters for California', async ({ request }) => {
    const url = new URL(`${appUrl}/api/map/clusters`);
    url.searchParams.set('bounds', '24.199194,44.871665,-129.699097,-111.406860');
    url.searchParams.set('zoom', '6');

    const res = await request.get(url.toString());
    expect(res.ok()).toBeTruthy();

    // At zoom 6, California photos are grouped into 2 clusters.
    const clusters: IMapCluster[] = await res.json();
    expect(clusters).toHaveLength(2);

    const LA_CENTER: [number, number] = [34.0522, -118.2437];
    const SF_CENTER: [number, number] = [37.7749, -122.4194];
    const MAX_RADIUS_DEG = 0.5; // ~50 km

    let laCluster: IMapCluster | undefined;
    let sfCluster: IMapCluster | undefined;

    for (const cluster of clusters) {
      if (distance(cluster.center, LA_CENTER) <= MAX_RADIUS_DEG) {
        laCluster = cluster;
      } else if (distance(cluster.center, SF_CENTER) <= MAX_RADIUS_DEG) {
        sfCluster = cluster;
      }
    }

    expect(laCluster).toBeDefined();
    expect(laCluster!.count).toBe(20);

    expect(sfCluster).toBeDefined();
    expect(sfCluster!.count).toBe(10);
  });

  // Query timeline photos filtered by map bounds.
  test('Query timeline days filtered by map bounds', async ({ request }) => {
    // Match the test data from the New York area
    const mapbounds = '40.621510,40.820565,-74.106216,-73.915672';
    const url = new URL(`${appUrl}/api/days`);
    url.searchParams.set('mapbounds', mapbounds);
    url.searchParams.set('nopreload', '1');

    const res = await request.get(url.toString());
    expect(res.ok()).toBeTruthy();

    const days: IDay[] = await res.json();
    expect(days).toStrictEqual([
      { dayid: 19550, count: 3 },
      { dayid: 19549, count: 3 },
      { dayid: 19548, count: 4 },
    ]);

    // Query single day details filtered by the same map bounds.
    const dayUrl = new URL(`${appUrl}/api/days/19550`);
    dayUrl.searchParams.set('mapbounds', mapbounds);

    const dayRes = await request.get(dayUrl.toString());
    expect(dayRes.ok()).toBeTruthy();

    const photos: IPhoto[] = await dayRes.json();
    expect(photos).toHaveLength(3);
  });
});

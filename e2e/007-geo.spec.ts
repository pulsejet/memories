import { test, expect } from '@playwright/test';
import { appUrl, ocsHeaders } from './navigation';
import { getImageInfo } from './utils';
import { GEO_DATASET } from './generate-geo-dataset';
import { imageSize } from 'image-size';

import type { ICluster } from '@typings';

test.use({ extraHTTPHeaders: ocsHeaders });

test.describe('@api Geo', () => {
  test.skip(!!process.env.NO_PLANET_DB, 'Skipping geo tests: NO_PLANET_DB is set');

  // Set timeline root to test dataset folder before running geo tests.
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

  // Verify reverse-geocoded photo counts aggregated at the country level.
  test('Query top-level places clusters', async ({ request }) => {
    const res = await request.get(`${appUrl}/api/clusters/places?inside=-1`);
    expect(res.ok()).toBeTruthy();

    const data: ICluster[] = await res.json();
    // Sort clusters by name to account for arbitrary database ordering.
    const places = data
      .map((item) => ({ name: item.name, count: item.count }))
      .sort((a, b) => a.name.localeCompare(b.name));

    expect(places).toStrictEqual([
      { name: 'Australia', count: 10 },
      { name: 'France', count: 10 },
      { name: 'Italy', count: 10 },
      { name: 'Japan', count: 20 },
      { name: 'United Kingdom', count: 10 },
      { name: 'United States', count: 40 },
    ]);
  });

  // Verify top place clusters with cover photo IDs and ensure
  // each cover matches the cluster's location.
  test('Query places clusters with covers', async ({ request }) => {
    // Initial fetch to get the list of clusters (covers may not be initialized yet).
    const initialRes = await request.get(`${appUrl}/api/clusters/places?covers=1`);
    expect(initialRes.ok()).toBeTruthy();

    const initialClusters: ICluster[] = await initialRes.json();
    for (const cluster of initialClusters) {
      expect(typeof cluster.cluster_id).toBe('number');
    }

    // Force generation and persistence of cluster cover photos by requesting
    // previews for each cluster in parallel.
    const previewResponses = await Promise.all(
      initialClusters.map((cluster) => {
        const url = new URL(`${appUrl}/api/clusters/places/preview`);
        url.searchParams.set('name', String(cluster.cluster_id));
        url.searchParams.set('cover', String(Math.random()));
        url.searchParams.set('cover_etag', 'null');
        return request.get(url.toString());
      }),
    );
    for (const res of previewResponses) {
      expect(res.ok()).toBeTruthy();
      expect(res.headers()['content-type']).toContain('image/jpeg');

      const body = await res.body();
      expect(body.length).toBeGreaterThan(0);
      const size = imageSize(new Uint8Array(body));
      expect(size.type).toBe('jpg');
    }

    // Re-fetch clusters endpoint now that covers have been populated.
    const res = await request.get(`${appUrl}/api/clusters/places?covers=1`);
    expect(res.ok()).toBeTruthy();

    const data: ICluster[] = await res.json();
    expect(data.length).toBeGreaterThan(0);

    // Map cluster names to expected cities in the test dataset.
    // This test may fail when the planet database is updated.
    // Update this map if that happens to reflect the new state.
    const clusterToCitiesMap: Record<string, string[]> = {
      'Los Angeles': ['Los Angeles', 'Venice'],
      'Santa Monica': ['Santa Monica'],
      'San Francisco': ['San Francisco'],
      Manhattan: ['New York'],
      Paris: ['Paris'],
      'City of Westminster': ['London'],
      'Council of the City of Sydney': ['Sydney'],
      'Higashiyama Ward': ['Kyoto'],
      Rome: ['Rome'],
    };

    for (const cluster of data) {
      expect(cluster.cover).toBeDefined();
      expect(typeof cluster.cover).toBe('number');

      // Resolve cover photo file metadata via basic image info.
      const info = await getImageInfo(request, cluster.cover as number, { basic: '1' });
      expect(info.basename).toBeDefined();

      // Extract dataset index from filename (e.g., "geo-test-026.jpg" -> 26).
      const match = info.basename?.match(/^geo-test-(\d+)\.jpg$/);
      expect(match).not.toBeNull();

      const index = parseInt(match![1], 10);
      const entry = GEO_DATASET[index - 1];
      expect(entry).toBeDefined();

      // Ensure the cover image belongs to the cluster's expected location.
      const expectedCities = clusterToCitiesMap[cluster.name];
      expect(expectedCities).toBeDefined();
      expect(expectedCities).toContain(entry.city);
    }
  });
});

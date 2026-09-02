import { test, expect } from '@playwright/test';
import { appUrl, ocsHeaders } from './navigation';
import { getFileId, getImageInfo } from './utils';
import { GEO_DATASET_FILES } from './generate-geo-dataset';
import { imageSize } from 'image-size';

import type { ICluster, IDay } from '@typings';

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
    const random = Math.floor(Math.random() * 1000000);
    const previewResponses = await Promise.all(
      initialClusters.map((cluster) => {
        const url = new URL(`${appUrl}/api/clusters/places/preview`);
        url.searchParams.set('name', String(cluster.cluster_id));
        url.searchParams.set('cover', String(random));
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

      const entry = GEO_DATASET_FILES[info.basename!];
      expect(entry).toBeDefined();

      // Ensure the cover image belongs to the cluster's expected location.
      const expectedCities = clusterToCitiesMap[cluster.name];
      expect(expectedCities).toBeDefined();
      expect(expectedCities).toContain(entry.city);
    }
  });

  // Query timeline photos filtered by a specific place cluster.
  test('Query timeline for a specific place cluster', async ({ request }) => {
    const targetPlace = 'Santa Monica';

    // Retrieve place clusters to get the cluster ID for the target place.
    const clustersRes = await request.get(`${appUrl}/api/clusters/places`);
    expect(clustersRes.ok()).toBeTruthy();

    const clusters: ICluster[] = await clustersRes.json();
    const targetCluster = clusters.find((c) => c.name === targetPlace);
    expect(targetCluster).toBeDefined();
    expect(typeof targetCluster!.cluster_id).toBe('number');

    // Query days endpoint filtered by the place cluster ID.
    const url = new URL(`${appUrl}/api/days`);
    url.searchParams.set('places', String(targetCluster!.cluster_id));
    const daysRes = await request.get(url.toString());
    expect(daysRes.ok()).toBeTruthy();

    // Verify the the result match the test dataset.
    const days: IDay[] = await daysRes.json();
    expect(days).toHaveLength(2);
    expect(days[0].count).toBe(2);
    expect(days[1].count).toBe(4);
    expect(days[0].dayid).toBeGreaterThan(days[1].dayid);

    // Verify each photo in the day details corresponds to the target place in the test dataset.
    for (const day of days) {
      expect(day.detail).toBeDefined();
      expect(day.detail).toHaveLength(day.count);

      for (const photo of day.detail!) {
        expect(photo.basename).toBeDefined();
        const entry = GEO_DATASET_FILES[photo.basename!];
        expect(entry).toBeDefined();
        expect(entry.city).toBe(targetPlace);
      }
    }
  });

  // Verify reverse-geocoded address field on image info across different regions.
  test('Query image info address field', async ({ request }) => {
    const testCases = [
      {
        path: '/geo-test/geo-test-001.jpg',
        addresses: ['Los Angeles, Los Angeles County, California, United States'],
      },
      {
        path: '/geo-test/geo-test-051.jpg',
        addresses: ['City of Westminster, Greater London, England, United Kingdom'],
      },
      {
        path: '/geo-test/geo-test-061.jpg',
        addresses: ['Shibuya, Tokyo, Kanto, Japan', 'Udagawachō, Shibuya, Tokyo, Kanto, Japan'],
      },
    ];

    await Promise.all(
      testCases.map(async (tc) => {
        const fileid = await getFileId(request, tc.path);
        const info = await getImageInfo(request, fileid);
        expect(tc.addresses).toContain(info.address);
      }),
    );
  });
});

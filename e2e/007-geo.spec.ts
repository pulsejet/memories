import { test, expect } from '@playwright/test';
import { appUrl, e2eHeaders } from './navigation';
import { DavClient } from './utils';
import { imageSize } from 'image-size';

import type { ICluster, IDay } from '@typings';
import { DATASET } from './dataset';

test.use({
  extraHTTPHeaders: e2eHeaders({
    timelinePath: '/for-geo',
  }),
});

test.describe('@api Geo', () => {
  test.skip(!!process.env.NO_PLANET_DB, 'Skipping geo tests: NO_PLANET_DB is set');

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
    const dav = new DavClient(request);

    // Initial fetch to get the list of clusters (covers may not be initialized yet).
    let initialClusters: ICluster[];
    await test.step('Fetch initial clusters', async () => {
      const initialRes = await request.get(`${appUrl}/api/clusters/places?covers=1`);
      expect(initialRes.ok()).toBeTruthy();

      initialClusters = await initialRes.json();
      for (const cluster of initialClusters) {
        expect(typeof cluster.cluster_id).toBe('number');
      }
    });

    // Force generation and persistence of cluster cover photos by requesting
    // previews for each cluster in parallel.
    await test.step('Generate covers via previews', async () => {
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
    });

    await test.step('Verify covers belong to cluster locations', async () => {
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
        const info = await dav.imageInfo(cluster.cover as number, { basic: '1' });
        expect(info.basename).toBeDefined();

        const entry = DATASET[`primary/for-geo/${info.basename!}`];
        expect(entry).toBeDefined();

        // Ensure the cover image belongs to the cluster's expected location.
        const expectedCities = clusterToCitiesMap[cluster.name];
        expect(expectedCities).toBeDefined();
        expect(expectedCities).toContain(entry.params?.city);
      }
    });
  });

  // Query timeline photos filtered by a specific place cluster.
  test('Query timeline for a specific place cluster', async ({ request }) => {
    const targetPlace = 'Santa Monica';

    // Retrieve place clusters to get the cluster ID for the target place.
    let targetCluster: ICluster;
    await test.step('Find target place cluster', async () => {
      const clustersRes = await request.get(`${appUrl}/api/clusters/places`);
      expect(clustersRes.ok()).toBeTruthy();

      const clusters: ICluster[] = await clustersRes.json();
      targetCluster = clusters.find((c) => c.name === targetPlace)!;
      expect(targetCluster).toBeDefined();
      expect(typeof targetCluster!.cluster_id).toBe('number');
    });

    let days: IDay[];
    await test.step('Query days filtered by cluster', async () => {
      const url = new URL(`${appUrl}/api/days`);
      url.searchParams.set('places', String(targetCluster.cluster_id));
      const daysRes = await request.get(url.toString());
      expect(daysRes.ok()).toBeTruthy();

      // Verify the result matches the test dataset.
      days = await daysRes.json();
      expect(days).toHaveLength(2);
      expect(days[0].count).toBe(2);
      expect(days[1].count).toBe(4);
      expect(days[0].dayid).toBeGreaterThan(days[1].dayid);
    });

    await test.step('Verify photos match the target place', async () => {
      for (const day of days) {
        expect(day.detail).toBeDefined();
        expect(day.detail).toHaveLength(day.count);

        for (const photo of day.detail!) {
          expect(photo.basename).toBeDefined();
          const entry = DATASET[`primary/for-geo/${photo.basename!}`];
          expect(entry).toBeDefined();
          expect(entry.params?.city).toBe(targetPlace);
        }
      }
    });
  });

  // Verify reverse-geocoded address field on image info across different regions.
  test('Query image info address field', async ({ request }) => {
    const testCases = [
      {
        path: '/for-geo/for-geo-001.jpg',
        addresses: ['Los Angeles, Los Angeles County, California, United States'],
      },
      {
        path: '/for-geo/for-geo-051.jpg',
        addresses: ['City of Westminster, Greater London, England, United Kingdom'],
      },
      {
        path: '/for-geo/for-geo-061.jpg',
        addresses: ['Shibuya, Tokyo, Kanto, Japan', 'Udagawachō, Shibuya, Tokyo, Kanto, Japan'],
      },
    ];

    await Promise.all(
      testCases.map(async (tc) => {
        const dav = new DavClient(request);
        const fileid = await dav.fileid(tc.path);
        const info = await dav.imageInfo(fileid);
        expect(tc.addresses).toContain(info.address);
      }),
    );
  });
});

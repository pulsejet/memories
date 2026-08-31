// Generate golden measurement files for e2e tests.
// Usage: npx tsx e2e/generate-golden.ts
// Env vars: same as login.ts (E2E_BASE_URL, E2E_USER, E2E_PASSWORD).

import * as fs from 'fs';
import * as path from 'path';
import { appUrl, ocsHeaders, username, password } from './navigation';
import { cleanupPhoto } from './utils';

import type { IDay, IImageInfo, IPhoto } from '@typings';

const outDir = path.join(__dirname, 'assets', 'primary-api');

// Map of basename -> fileid for main timeline photos.
const mainFileIds = new Map<string, number>();

async function fetchJson<T>(url: string): Promise<T> {
  const res = await fetch(url, {
    headers: {
      Authorization: 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64'),
      ...ocsHeaders,
    },
  });
  if (!res.ok) throw new Error(`HTTP ${res.status} for ${url}`);
  return res.json() as Promise<T>;
}

function writeJson(filename: string, data: unknown) {
  fs.mkdirSync(outDir, { recursive: true });
  fs.writeFileSync(path.join(outDir, filename), JSON.stringify(data, null, 2) + '\n');
}

function cleanupJsonFiles() {
  fs.mkdirSync(outDir, { recursive: true });
  for (const file of fs.readdirSync(outDir)) {
    if (file.endsWith('.json')) {
      fs.unlinkSync(path.join(outDir, file));
    }
  }
}

/**
 * Generate golden JSON files for days and day endpoints.
 * @param prefix  Filename prefix, "archived" -> "archived-days.json".
 * @param params  Query params appended to both the days and day endpoints.
 */
async function generateGoldenDays(prefix: string, params: Record<string, string>) {
  const qs = '?' + new URLSearchParams({ nopreload: '1', ...params }).toString();

  const days = await fetchJson<IDay[]>(`${appUrl}/api/days${qs}`);
  console.log(`Got ${days.length} days`);
  writeJson(`${prefix}-days.json`, days);

  await Promise.all(
    days.map(async (day) => {
      const photos = await fetchJson<IPhoto[]>(`${appUrl}/api/days/${day.dayid}${qs}`);
      for (const photo of photos) {
        if (prefix === 'main') {
          mainFileIds.set(photo.basename ?? '', photo.fileid);
        }
      }
      photos.forEach(cleanupPhoto);
      console.log(`  day ${day.dayid}: ${photos.length} photos`);
      writeJson(`${prefix}-day-${day.dayid}.json`, photos);
    }),
  );
}

/**
 * Generate golden JSON file for image info endpoint.
 * @param basename  Photo basename to look up.
 * @param fileid    File ID of the photo.
 */
async function generateGoldenFileInfo(basename: string, fileid: number) {
  const info = await fetchJson<IImageInfo>(`${appUrl}/api/image/info/${fileid}`);

  info.owneruid = '<uid>';
  info.ownername = '<uid>';
  info.etag = '<etag>';
  info.fileid = 0;
  info.mtime = 0;

  // These depend on reverse geocoding, not setup yet.
  delete info.address;
  delete info.exif?.DateTimeEpoch;
  delete info.exif?.LocationTZID;

  console.log(`image info ${basename}: fileid=${fileid}`);
  writeJson(`image-info-${basename}.json`, info);
}

async function main() {
  cleanupJsonFiles();

  await generateGoldenDays('main', {});
  await generateGoldenDays('archived', { archive: '1' });

  await generateGoldenFileInfo('test_01.jpg', mainFileIds.get('test_01.jpg')!);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});

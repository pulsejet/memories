// Generate golden measurement files for e2e tests.
// Usage: npx tsx e2e/generate-golden.ts
// Env vars: same as login.ts (E2E_BASE_URL, E2E_USER, E2E_PASSWORD).

import * as fs from 'fs';
import * as path from 'path';
import { appUrl, authHeaders } from './login';
import type { IDay, IPhoto } from '@typings';

const outDir = path.join(__dirname, 'assets', 'primary-api');

async function fetchJson<T>(url: string): Promise<T> {
  const res = await fetch(url, { headers: authHeaders });
  if (!res.ok) throw new Error(`HTTP ${res.status} for ${url}`);
  return res.json() as Promise<T>;
}

async function main() {
  fs.mkdirSync(outDir, { recursive: true });

  // Get all days without preload.
  const days = await fetchJson<IDay[]>(`${appUrl}/api/days?nopreload=1`);
  console.log(`Got ${days.length} days`);
  fs.writeFileSync(path.join(outDir, 'days.json'), JSON.stringify(days, null, 2) + '\n');

  // Fetch photo details for each day and clean up.
  for (const day of days) {
    const photos = await fetchJson<IPhoto[]>(`${appUrl}/api/days/${day.dayid}`);
    for (const photo of photos) {
      delete photo.etag;
      photo.fileid = 0;
      photo.flag = 0;
    }
    console.log(`  day ${day.dayid}: ${photos.length} photos`);
    fs.writeFileSync(path.join(outDir, `day-${day.dayid}.json`), JSON.stringify(photos, null, 2) + '\n');
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});

// Generate golden measurement files for e2e tests.
// Usage: npx tsx e2e/generate-golden.ts
// Env vars: same as login.ts (E2E_BASE_URL, E2E_USER, E2E_PASSWORD).

import * as fs from 'fs';
import * as path from 'path';
import { appUrl, authHeaders } from './login';
import { cleanupPhoto } from './utils';

import type { IDay, IPhoto } from '@typings';

const outDir = path.join(__dirname, 'assets', 'primary-api');

async function fetchJson<T>(url: string): Promise<T> {
  const res = await fetch(url, { headers: authHeaders });
  if (!res.ok) throw new Error(`HTTP ${res.status} for ${url}`);
  return res.json() as Promise<T>;
}

/**
 * Generate golden JSON files for the primary API.
 * @param prefix  Filename prefix, "archived" -> "archived-days.json".
 * @param params  Query params appended to both the days and day endpoints.
 */
async function generateGolden(prefix: string, params: Record<string, string>) {
  fs.mkdirSync(outDir, { recursive: true });

  const qs = '?' + new URLSearchParams({ nopreload: '1', ...params }).toString();

  const days = await fetchJson<IDay[]>(`${appUrl}/api/days${qs}`);
  console.log(`Got ${days.length} days`);
  fs.writeFileSync(path.join(outDir, `${prefix}-days.json`), JSON.stringify(days, null, 2) + '\n');

  await Promise.all(
    days.map(async (day) => {
      const photos = await fetchJson<IPhoto[]>(`${appUrl}/api/days/${day.dayid}${qs}`);
      photos.forEach(cleanupPhoto);
      console.log(`  day ${day.dayid}: ${photos.length} photos`);
      fs.writeFileSync(path.join(outDir, `${prefix}-day-${day.dayid}.json`), JSON.stringify(photos, null, 2) + '\n');
    }),
  );
}

async function main() {
  fs.mkdirSync(outDir, { recursive: true });
  for (const file of fs.readdirSync(outDir)) {
    if (file.endsWith('.json')) {
      fs.unlinkSync(path.join(outDir, file));
    }
  }

  await generateGolden('main', {});
  await generateGolden('archived', { archive: '1' });
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});

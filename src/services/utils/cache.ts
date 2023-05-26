import { getCurrentUser } from '@nextcloud/auth';
import config from '../static-config';

/** Cache keys */
const uid = getCurrentUser()?.uid || 'guest';

async function getCacheName() {
  const ver = await config.get('version');
  return `memories-data-${ver}-${uid}`;
}

// Clear all caches except the current one
(async function clearCaches() {
  if (uid === 'guest') return;

  const keys = await window.caches?.keys();
  if (!keys?.length) return;

  const cacheName = await getCacheName();

  for (const key of keys) {
    if (key.match(/^memories-data-/) && key !== cacheName) {
      window.caches.delete(key);
    }
  }
})();

/** Singleton cache instance */
let staticCache: Cache | null = null;
export async function openCache() {
  try {
    return (staticCache ??= (await window.caches?.open(await getCacheName())) ?? null);
  } catch {
    return null;
  }
}

/** Get data from the cache */
export async function getCachedData<T>(url: string): Promise<T | null> {
  if (!window.caches) return null;
  const cache = staticCache ?? (await openCache());
  if (!cache) return null;

  const cachedResponse = await cache.match(url);
  if (!cachedResponse || !cachedResponse.ok) return null;
  return await cachedResponse.json();
}

/** Store data in the cache */
export function cacheData(url: string, data: Object) {
  if (!window.caches) return;
  const str = JSON.stringify(data);

  (async () => {
    const cache = staticCache ?? (await openCache());
    if (!cache) return;

    const response = new Response(str);
    const encoded = new TextEncoder().encode(str);
    response.headers.set('Content-Type', 'application/json');
    response.headers.set('Content-Length', encoded.length.toString());
    response.headers.set('Cache-Control', 'max-age=604800'); // 1 week
    response.headers.set('Vary', 'Accept-Encoding');
    await cache.put(url, response);
  })();
}

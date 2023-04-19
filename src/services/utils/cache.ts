import { getCurrentUser } from "@nextcloud/auth";
import { loadState } from "@nextcloud/initial-state";

/** Cache store */
let staticCache: Cache | null = null;
let cacheName: string;
let memoriesVersion: string;

try {
  memoriesVersion = loadState("memories", "version");
  const uid = getCurrentUser()?.uid;
  cacheName = `memories-${memoriesVersion}-${uid}`;
  openCache().then((cache) => (staticCache = cache));
} catch (e) {
  console.warn("Failed to open cache");
}

// Clear all caches except the current one
window.caches?.keys().then((keys) => {
  keys
    .filter((key) => key.startsWith("memories-") && key !== cacheName)
    .forEach((key) => {
      window.caches.delete(key);
    });
});

/** Open the cache */
export async function openCache() {
  try {
    return await window.caches?.open(cacheName);
  } catch {
    console.warn("Failed to get cache", cacheName);
    return null;
  }
}

/** Get data from the cache */
export async function getCachedData<T>(url: string): Promise<T | null> {
  if (!window.caches) return null;
  const cache = staticCache || (await openCache());
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
    const cache = staticCache || (await openCache());
    if (!cache) return;

    const response = new Response(str);
    const encoded = new TextEncoder().encode(str);
    response.headers.set("Content-Type", "application/json");
    response.headers.set("Content-Length", encoded.length.toString());
    response.headers.set("Cache-Control", "max-age=604800"); // 1 week
    response.headers.set("Vary", "Accept-Encoding");
    await cache.put(url, response);
  })();
}

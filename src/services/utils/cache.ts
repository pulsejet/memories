import { getCurrentUser } from "@nextcloud/auth";
import { loadState } from "@nextcloud/initial-state";

/** Cache keys */
const memoriesVersion: string = loadState("memories", "version", "");
const uid = getCurrentUser()?.uid || "guest";
const cacheName = `memories-${memoriesVersion}-${uid}`;

// Clear all caches except the current one
(async function clearCaches() {
  if (!memoriesVersion || uid === "guest") return;

  const keys = await window.caches?.keys();
  if (!keys?.length) return;

  for (const key of keys) {
    if (key.startsWith("memories-") && key !== cacheName) {
      window.caches.delete(key);
    }
  }
})();

/** Singleton cache instance */
let staticCache: Cache | null = null;
export async function openCache() {
  if (!memoriesVersion) return null;

  try {
    return (staticCache ??= (await window.caches?.open(cacheName)) ?? null);
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
    response.headers.set("Content-Type", "application/json");
    response.headers.set("Content-Length", encoded.length.toString());
    response.headers.set("Cache-Control", "max-age=604800"); // 1 week
    response.headers.set("Vary", "Accept-Encoding");
    await cache.put(url, response);
  })();
}

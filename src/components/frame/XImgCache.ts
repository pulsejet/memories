import { API } from '@services/API';
import { onDOMLoaded } from '@services/utils';
import { importWorker } from 'webworker-typed';
import type XImgWorker from './XImgWorker';

// Global web worker to fetch images
let worker: typeof XImgWorker;

// Memcache for blob URLs
const BLOB_CACHE = new Map<string, object>() as Map<string, [number, string]>;
const BLOB_CACHE_GC = 10000;
const BLOB_CACHE_LIFETIME = 30000;
const BLOB_STICKY = new Map<string, number>();

// Start and configure the worker
function startWorker() {
  if (worker || _m.mode !== 'user') return;

  // Get typed worker
  worker = importWorker(new Worker(new URL('./XImgWorkerStub.ts', import.meta.url)));

  // Configure worker
  worker.configure({
    multiUrl: API.IMAGE_MULTIPREVIEW(),
  });
}

// Set up garbage collection after DOM is loaded
onDOMLoaded(() => {
  if (_m.mode !== 'user') return;

  // Periodic blob cache cleaner
  window.setInterval(() => {
    for (const [src, cache] of BLOB_CACHE.entries()) {
      // Skip if sticky
      if (BLOB_STICKY.has(cache[1])) {
        cache[0] = BLOB_CACHE_LIFETIME; // reset timer
        continue;
      }

      // Decrement timer and revoke if expired
      if ((cache[0] -= BLOB_CACHE_GC) <= 0) {
        URL.revokeObjectURL(cache[1]);
        BLOB_CACHE.delete(src);
      }
    }
  }, BLOB_CACHE_GC);
});

/** Change stickiness for a BLOB url */
export async function sticky(url: string, delta: number) {
  if (!BLOB_STICKY.has(url)) BLOB_STICKY.set(url, 0);
  const val = BLOB_STICKY.get(url)! + delta;
  if (val <= 0) {
    BLOB_STICKY.delete(url);
  } else {
    BLOB_STICKY.set(url, val);
  }
}

// Whether the worker failed to respond in time; once set, requests are
// served directly from the main thread instead
let workerUnresponsive = false;

/**
 * Fetch an image on the main thread (cache first).
 *
 * The worker cannot start while offline on Android WebView: worker
 * script requests are not routed through the service worker, so the
 * fetch of the worker script itself hangs without connectivity.
 */
async function fetchImageDirect(url: string): Promise<string> {
  const cached = await window.caches?.open('memories-images').then((c) => c.match(url));
  const blob = cached ? await cached.blob() : await (await fetch(url)).blob();
  return URL.createObjectURL(blob);
}

/** Fetch through the worker, falling back to the main thread on timeout */
async function fetchImageSafe(url: string): Promise<string> {
  if (workerUnresponsive) return await fetchImageDirect(url);

  return await new Promise<string>((resolve, reject) => {
    const timer = window.setTimeout(() => {
      workerUnresponsive = true;
      fetchImageDirect(url).then(resolve, reject);
    }, 5000);

    worker.fetchImageSrc(url).then(
      (blobUrl) => {
        window.clearTimeout(timer);
        workerUnresponsive = false;
        resolve(blobUrl);
      },
      (err) => {
        window.clearTimeout(timer);
        reject(err);
      },
    );
  });
}

export async function fetchImage(url: string) {
  // Start worker
  startWorker();

  // Check memcache entry
  let entry = BLOB_CACHE.get(url);
  if (entry) return entry[1];

  // Fetch image
  const blobUrl = await fetchImageSafe(url);

  // Check memcache entry again and revoke if it was added in the meantime
  if ((entry = BLOB_CACHE.get(url))) {
    URL.revokeObjectURL(blobUrl);
    return entry[1];
  }

  // Create new memecache entry
  BLOB_CACHE.set(url, [BLOB_CACHE_LIFETIME, blobUrl]); // 30s expiration
  return blobUrl;
}

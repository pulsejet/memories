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

// Worker health: null = unknown yet, false = did not answer the ping
let workerAlive: boolean | null = null;
let workerPing: Promise<boolean> | null = null;
let workerPingTime = 0;

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

/** Check once whether the worker actually runs (2s deadline) */
function pingWorker(): Promise<boolean> {
  workerPingTime = Date.now();
  return (workerPing ??= new Promise<boolean>((resolve) => {
    const timer = window.setTimeout(() => resolve(false), 2000);
    worker.ping().then(
      () => {
        window.clearTimeout(timer);
        resolve(true);
      },
      () => {
        window.clearTimeout(timer);
        resolve(false);
      },
    );
  }).then((alive) => (workerAlive = alive)));
}

/** Fetch through the worker, or on the main thread if it does not run */
async function fetchImageSafe(url: string): Promise<string> {
  // Probe again periodically: the worker may become able to start
  // after connectivity returns
  if (workerAlive === false && Date.now() - workerPingTime > 30000) {
    workerAlive = null;
    workerPing = null;
  }

  if (workerAlive === null) await pingWorker();
  if (!workerAlive) return await fetchImageDirect(url);
  return await worker.fetchImageSrc(url);
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

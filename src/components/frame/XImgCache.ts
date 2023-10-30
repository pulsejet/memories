import { API } from '@services/API';
import { onDOMLoaded } from '@services/utils';
import { workerImporter } from '../../worker';
import type * as w from './XImgWorker';

// Global web worker to fetch images
let worker: Worker;
let importer: ReturnType<typeof workerImporter>;

// Memcache for blob URLs
const BLOB_CACHE = new Map<string, object>() as Map<string, [number, string]>;
const BLOB_CACHE_GC = 10000;
const BLOB_CACHE_LIFETIME = 30000;
const BLOB_STICKY = new Map<string, number>();

// Start and configure the worker
function startWorker() {
  if (worker || _m.mode !== 'user') return;

  // Start worker
  worker = new Worker(new URL('./XImgWorkerStub.ts', import.meta.url));
  importer = workerImporter(worker);

  // Configure worker
  importer<typeof w.configure>('configure')({
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

export async function fetchImage(url: string) {
  // Start worker
  startWorker();

  // Check memcache entry
  let entry = BLOB_CACHE.get(url);
  if (entry) return entry[1];

  // Fetch image
  const blobUrl = await importer<typeof w.fetchImageSrc>('fetchImageSrc')(url);

  // Check memcache entry again and revoke if it was added in the meantime
  if ((entry = BLOB_CACHE.get(url))) {
    URL.revokeObjectURL(blobUrl);
    return entry[1];
  }

  // Create new memecache entry
  BLOB_CACHE.set(url, [BLOB_CACHE_LIFETIME, blobUrl]); // 30s expiration
  return blobUrl;
}

import { API } from "../../services/API";
import { workerImporter } from "../../worker";
import type * as w from "./XImgWorker";

// Global web worker to fetch images
const worker = new Worker(new URL("./XImgWorker.ts", import.meta.url));

// Import worker functions
const importer = workerImporter(worker);
const fetchImageSrc = importer<typeof w.fetchImageSrc>("fetchImageSrc");

// Configure worker on startup
document.addEventListener("DOMContentLoaded", () =>
  importer<typeof w.configure>("configure")({
    multiUrl: API.IMAGE_MULTIPREVIEW(),
  })
);

// Memcache for blob URLs
const BLOB_CACHE = new Map<string, object>() as Map<string, [number, string]>;
const BLOB_STICKY = new Map<string, number>();

// Periodic blob cache cleaner
window.setInterval(() => {
  for (const [src, cache] of BLOB_CACHE.entries()) {
    // Skip if sticky
    if (BLOB_STICKY.has(cache[1])) {
      cache[0] = 30; // reset timer
      continue;
    }

    // Decrement timer and revoke if expired
    if ((cache[0] -= 3) <= 0) {
      URL.revokeObjectURL(cache[1]);
      BLOB_CACHE.delete(src);
    }
  }
}, 3000);

/** Change stickiness for a BLOB url */
export async function sticky(url: string, delta: number) {
  if (!BLOB_STICKY.has(url)) BLOB_STICKY.set(url, 0);
  const val = BLOB_STICKY.get(url) + delta;
  if (val <= 0) {
    BLOB_STICKY.delete(url);
  } else {
    BLOB_STICKY.set(url, val);
  }
}

export async function fetchImage(url: string) {
  // Check memcache entry
  if (BLOB_CACHE.has(url)) return BLOB_CACHE.get(url)[1];

  // Fetch image
  const blobUrl = await fetchImageSrc(url);

  // Check memcache entry again and revoke if it was added in the meantime
  if (BLOB_CACHE.has(url)) {
    URL.revokeObjectURL(blobUrl);
    return BLOB_CACHE.get(url)[1];
  }

  // Create new memecache entry
  BLOB_CACHE.set(url, [30, blobUrl]); // 30s expiration
  return blobUrl;
}

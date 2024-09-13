import { CacheExpiration } from 'workbox-expiration';
import { exportWorker } from 'webworker-typed';

declare var self: ServiceWorkerGlobalScope;

interface BlobCallback {
  resolve: (blob: Blob) => void;
  reject: (err: Error) => void;
}

// Queue of requests to fetch preview images
interface FetchPreviewObject {
  origUrl: string;
  url: URL;
  fileid: number;
  reqid: number;
  done?: boolean;
}
let fetchPreviewQueue: FetchPreviewObject[] = [];

// Pending requests
const pendingUrls = new Map<string, BlobCallback[]>();

// Cache for preview images
const cacheName = 'memories-images';
let imageCache: Cache | undefined;
(async function openCache() {
  try {
    imageCache = await self.caches?.open(cacheName);
  } catch {
    console.warn('Failed to open cache in worker');
  }
})();

// Expiration for cache
const expirationManager = new CacheExpiration(cacheName, {
  maxAgeSeconds: 3600 * 24 * 7, // days
  maxEntries: 20000, // 20k images
});

// Start fetching with multipreview
let fetchPreviewTimer: number;

/** Flushes the queue of preview image requests */
async function flushPreviewQueue() {
  // Clear timer
  if (fetchPreviewTimer) {
    self.clearTimeout(fetchPreviewTimer);
    fetchPreviewTimer = 0;
  }

  // Check if queue is empty
  if (fetchPreviewQueue.length === 0) return;

  // Copy queue and clear
  const fetchPreviewQueueCopy = fetchPreviewQueue;
  fetchPreviewQueue = [];

  // Respond to URL
  const resolve = async (url: string, res: Response, blob?: Blob) => {
    // Response body can be read only once
    const clone = res.clone();

    // In case this throws, let the outer catch handle it
    // This is because we want to ignore this response in case
    // it came from a multipreview, so that we can try fetching
    // the single image instead
    blob ??= await res.blob();
    pendingUrls.get(url)?.forEach((cb) => cb?.resolve?.(blob!));
    pendingUrls.delete(url);

    // Cache response
    cacheResponse(url, clone);
  };

  // Throw error on URL
  const reject = (url: string, e: any): void => {
    pendingUrls.get(url)?.forEach((cb) => cb?.reject?.(e));
    pendingUrls.delete(url);
  };

  // Make a single-file request
  const fetchOneSafe = async (p: FetchPreviewObject) => {
    try {
      await resolve(p.origUrl, await fetchOneImage(p.origUrl));
    } catch (e) {
      reject(p.origUrl, e);
    }
  };

  // Check if only one request, not worth a multipreview
  if (fetchPreviewQueueCopy.length === 1) {
    await fetchOneSafe(fetchPreviewQueueCopy[0]);
    return;
  }

  // Create aggregated request body
  const files = fetchPreviewQueueCopy.map((p) => ({
    fileid: p.fileid,
    x: Number(p.url.searchParams.get('x')),
    y: Number(p.url.searchParams.get('y')),
    a: p.url.searchParams.get('a'),
    reqid: p.reqid,
  }));

  try {
    // Fetch multipreview
    const res = await fetchMultipreview(files);
    if (res.status !== 200 || !res.body) throw new Error('Error fetching multi-preview');

    // Create fake headers for 7-day expiry
    const headers = {
      'cache-control': 'max-age=604800',
      expires: new Date(Date.now() + 604800000).toUTCString(),
    };

    // Read blob
    const reader = res.body.getReader();

    // 512KB buffer for reading data into
    let buffer = new Uint8Array(512 * 1024);
    let bufSize = 0;

    // Parameters of the image we're currently reading
    let params: {
      reqid: number;
      len: number;
      type: string;
    } | null = null;

    // Index at which we are currently reading
    let idx = 0;

    while (true) {
      // Read data from the response
      const { value, done } = await reader.read();
      if (done) break; // End of stream

      // Check in case 1/3 the buffer is full then reset it
      if (idx > buffer.length / 3) {
        buffer.set(buffer.slice(idx));
        bufSize -= idx;
        idx = 0;
      }

      // Double the length of the buffer until it fits
      // Hopefully this never happens
      while (bufSize + value.length > buffer.length) {
        const newBuffer = new Uint8Array(buffer.length * 2);
        newBuffer.set(buffer);
        buffer = newBuffer;
        console.warn('Doubling multipreview buffer size', buffer.length);
      }

      // Copy data into buffer
      buffer.set(value, bufSize);
      bufSize += value.length;

      // Process the buffer until we exhaust it or need more data
      while (true) {
        if (!params) {
          // Read the length of the JSON as a single byte
          if (bufSize - idx < 1) break;
          const jsonLen = buffer[idx];
          const jsonStart = idx + 1;

          // Read the JSON
          if (bufSize - jsonStart < jsonLen) break;
          const jsonB = buffer.slice(jsonStart, jsonStart + jsonLen);
          const jsonT = new TextDecoder().decode(jsonB);
          idx = jsonStart + jsonLen;
          params = JSON.parse(jsonT);
          params = params!;
        }

        // Read the image data
        if (bufSize - idx < params!.len) break;
        const imgBlob = new Blob([buffer.slice(idx, idx + params!.len)], {
          type: params!.type,
        });
        idx += params!.len;

        // Initiate callbacks
        for (const p of fetchPreviewQueueCopy) {
          if (p.reqid === params.reqid && !p.done) {
            try {
              const dummy = getResponse(imgBlob, params!.type, headers);
              await resolve(p.origUrl, dummy, imgBlob);
              p.done = true;
            } catch (e) {
              // In case of error, we want to try fetching the single
              // image instead, so we don't reject here
            }
          }
        }

        // Reset for next iteration
        params = null;
      }
    }
  } catch (e) {
    console.error('Multipreview error', e);
  }

  // Initiate callbacks for failed requests
  fetchPreviewQueueCopy.filter((p) => !p.done).forEach(fetchOneSafe);
}

/** Accepts a URL and returns a promise with a blob */
async function fetchImage(url: string): Promise<Blob> {
  // Check if in cache
  const cache = await imageCache?.match(url);
  if (cache) return await cache.blob();

  // Just fetch if not a preview
  const regex = /\/memories\/api\/image\/preview\/\d+(\?.*)?$/;
  if (!regex.test(url)) {
    const res = await fetchOneImage(url);
    cacheResponse(url, res);
    return await res.blob();
  }

  // Get file id from URL
  const urlObj = new URL(url, self.location.origin);
  const fileid = Number(urlObj.pathname.split('/').pop());

  return await new Promise((resolve, reject) => {
    if (pendingUrls.has(url)) {
      // Already in queue, just add callback
      pendingUrls.get(url)?.push({ resolve, reject });
    } else {
      // Add to queue
      fetchPreviewQueue.push({
        origUrl: url,
        url: urlObj,
        fileid,
        reqid: Math.round(Math.random() * 1e8),
      });

      // Add to pending
      pendingUrls.set(url, [{ resolve, reject }]);

      // Start timer for flushing queue
      if (!fetchPreviewTimer) {
        fetchPreviewTimer = self.setTimeout(flushPreviewQueue, 20);
      }

      // If queue has >20 items, flush immediately
      // This will internally clear the timer
      if (fetchPreviewQueue.length >= 20) {
        flushPreviewQueue();
      }
    }
  });
}

function cacheResponse(url: string, res: Response) {
  try {
    // Skip if no-cache is present
    if (res.headers.get('cache-control')?.toLowerCase().includes('no-cache')) return;

    // Cache valid responses
    if (res.status === 200) {
      imageCache?.put(url, res.clone());
      expirationManager.updateTimestamp(url.toString());
    }

    // Run expiration once in every 100 requests
    if (Math.random() < 0.01) {
      expirationManager.expireEntries();
    }
  } catch (e) {
    console.error('Error caching response', e);
  }
}

/** Creates a dummy response from a blob and headers */
function getResponse(blob: Blob, type: string | null, headers: any = {}) {
  return new Response(blob, {
    status: 200,
    headers: {
      'Content-Type': type || headers['content-type'],
      'Content-Length': blob.size.toString(),
      'Cache-Control': headers['cache-control'],
      Expires: headers.expires,
    },
  });
}

/** Fetch single image with axios */
async function fetchOneImage(url: string) {
  const res = await fetch(url);
  if (res.status !== 200 || !res.body) {
    const text = res.body ? await res.text() : 'unknown';
    throw new Error(`Error fetching single preview: ${text}`);
  }
  return res;
}

/** Fetch multipreview with axios */
async function fetchMultipreview(files: any[]) {
  return await fetch(config.multiUrl, {
    method: 'POST',
    body: JSON.stringify({ files }),
    headers: { 'Content-Type': 'application/json' },
  });
}

/** Will be configured after the worker starts */
let config: { multiUrl: string };
function configure(_config: typeof config) {
  config = _config;
}

/** Get BLOB url for image */
async function fetchImageSrc(url: string) {
  return URL.createObjectURL(await fetchImage(url));
}

// Exports to main thread
export default exportWorker({ fetchImageSrc, configure });

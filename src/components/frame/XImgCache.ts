import { CacheExpiration } from "workbox-expiration";
import { API } from "../../services/API";
import axios from "@nextcloud/axios";

// Queue of requests to fetch preview images
interface FetchPreviewObject {
  origUrl: string;
  url: URL;
  fileid: number;
  reqid: number;
  callback: (blob: Response) => void;
}
let fetchPreviewQueue: FetchPreviewObject[] = [];

// Cache for preview images
const cacheName = "images";
let imageCache: Cache;
(async () => {
  imageCache = await caches.open(cacheName);
})();

// Expiration for cache
const expirationManager = new CacheExpiration(cacheName, {
  maxAgeSeconds: 3600 * 24 * 7, // days
  maxEntries: 20000, // 20k images
});

// Start fetching with multipreview
let fetchPreviewTimer: any;

/** Flushes the queue of preview image requests */
async function flushPreviewQueue() {
  if (fetchPreviewQueue.length === 0) return;

  // Clear timer
  if (fetchPreviewTimer) {
    window.clearTimeout(fetchPreviewTimer);
    fetchPreviewTimer = 0;
  }

  // Copy queue and clear
  const fetchPreviewQueueCopy = fetchPreviewQueue;
  fetchPreviewQueue = [];

  // Check if only one request
  if (fetchPreviewQueueCopy.length === 1) {
    const p = fetchPreviewQueueCopy[0];
    return p.callback(await fetchOneImage(p.origUrl));
  }

  // Create aggregated request body
  const files = fetchPreviewQueueCopy.map((p) => ({
    fileid: p.fileid,
    x: Number(p.url.searchParams.get("x")),
    y: Number(p.url.searchParams.get("y")),
    a: p.url.searchParams.get("a"),
    reqid: p.reqid,
  }));

  try {
    // Fetch multipreview
    const res = await fetchMultipreview(files);
    if (res.status !== 200) throw new Error("Error fetching multi-preview");

    // Read blob
    const blob = res.data;

    let idx = 0;
    while (idx < blob.size) {
      // Read a line of JSON from blob
      const line = await blob.slice(idx, idx + 256).text();
      const newlineIndex = line?.indexOf("\n");
      const jsonParsed = JSON.parse(line?.slice(0, newlineIndex));
      const imgLen = jsonParsed["Content-Length"];
      const imgType = jsonParsed["Content-Type"];
      const reqid = jsonParsed["reqid"];
      idx += newlineIndex + 1;

      // Read the image data
      const imgBlob = blob.slice(idx, idx + imgLen);
      idx += imgLen;

      // Initiate callbacks
      fetchPreviewQueueCopy
        .filter((p) => p.reqid === reqid)
        .forEach((p) => {
          p.callback(getResponse(imgBlob, imgType, res.headers));
          p.callback = null;
        });
    }
  } catch (e) {
    console.error("Multipreview error", e);
  }

  // Initiate callbacks for failed requests
  fetchPreviewQueueCopy.forEach((fetchPreviewObject) => {
    fetchPreviewObject.callback?.(
      new Response("Image not found", {
        status: 404,
        statusText: "Image not found",
      })
    );
  });
}

/** Accepts a URL and returns a promise with a blob */
export async function fetchImage(url: string): Promise<Blob> {
  // Check if in cache
  const cache = await imageCache?.match(url);
  if (cache) return await cache.blob();

  // Get file id from URL
  const urlObj = new URL(url, window.location.origin);
  const fileid = Number(urlObj.pathname.split("/").pop());

  // Check if preview image
  const regex = /^.*\/apps\/memories\/api\/image\/preview\/.*/;

  // Aggregate requests
  let res: Response;

  if (regex.test(url)) {
    res = await new Promise((callback) => {
      // Add to queue
      fetchPreviewQueue.push({
        origUrl: url,
        url: urlObj,
        fileid,
        reqid: Math.random(),
        callback,
      });

      // Start timer for flushing queue
      if (!fetchPreviewTimer) {
        fetchPreviewTimer = setTimeout(flushPreviewQueue, 10);
      }

      // If queue has >10 items, flush immediately
      // This will internally clear the timer
      if (fetchPreviewQueue.length >= 10) {
        flushPreviewQueue();
      }
    });
  }

  // Fallback to single request
  if (!res || res.status !== 200) {
    res = await fetchOneImage(url);
  }

  // Cache response
  if (res.status === 200) {
    imageCache?.put(url, res.clone());
    expirationManager.updateTimestamp(url.toString());
  }

  // Run expiration once in every 100 requests
  if (Math.random() < 0.01) {
    expirationManager.expireEntries();
  }

  return await res.blob();
}

/** Creates a dummy response from a blob and headers */
function getResponse(blob: Blob, type: string | null, headers: any = {}) {
  return new Response(blob, {
    status: 200,
    headers: {
      "Content-Type": type || headers["content-type"],
      "Content-Length": blob.size.toString(),
      "Cache-Control": headers["cache-control"],
      Expires: headers["expires"],
    },
  });
}

/** Fetch single image with axios */
export async function fetchOneImage(url: string) {
  const res = await axios.get(url, {
    responseType: "blob",
  });
  return getResponse(res.data, null, res.headers);
}

/** Fetch multipreview with axios */
export async function fetchMultipreview(files: any[]) {
  const multiUrl = API.IMAGE_MULTIPREVIEW();

  return await axios.post(multiUrl, files, {
    responseType: "blob",
  });
}

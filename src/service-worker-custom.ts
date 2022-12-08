import { registerRoute } from "workbox-routing";
import { CacheExpiration } from "workbox-expiration";

// Queue of requests to fetch preview images
interface FetchPreviewObject {
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
async function flushPreviewQueue() {
  if (fetchPreviewQueue.length === 0) return;

  fetchPreviewTimer = 0;
  const fetchPreviewQueueCopy = fetchPreviewQueue;
  fetchPreviewQueue = [];

  // Check if only one request
  if (fetchPreviewQueueCopy.length === 1) {
    const p = fetchPreviewQueueCopy[0];
    return p.callback(await fetch(p.url));
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
    // infer the url from the first file
    const firstUrl = fetchPreviewQueueCopy[0].url;
    const url = new URL(firstUrl.toString());
    const path = url.pathname.split("/");
    const previewIndex = path.indexOf("preview");
    url.pathname = path.slice(0, previewIndex).join("/") + "/multipreview";
    url.searchParams.delete("x");
    url.searchParams.delete("y");
    url.searchParams.delete("a");
    url.searchParams.delete("c");

    // Fetch multipreview
    const res = await fetch(url, {
      method: "POST",
      body: JSON.stringify(files),
    });

    // Get blob
    if (res.status !== 200) throw new Error("Error fetching multi-preview");
    const blob = await res.blob();

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

      console.debug("multi-preview", jsonParsed);

      // Read the image data
      const imgBlob = blob.slice(idx, idx + imgLen);
      idx += imgLen;

      // Initiate callbacks
      fetchPreviewQueueCopy
        .filter((p) => p.reqid === reqid)
        .forEach((p) => {
          p.callback(
            new Response(imgBlob, {
              status: 200,
              headers: {
                "Content-Type": imgType,
                "Content-Length": imgLen,
                Expires: res.headers.get("Expires"),
                "Cache-Control": res.headers.get("Cache-Control"),
              },
            })
          );
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

// Intercept preview requests
registerRoute(
  /^.*\/apps\/memories\/api\/image\/preview\/.*/,
  async ({ url, request }) => {
    // Check if in cache
    const cache = await imageCache?.match(url);
    if (cache) return cache;

    // Get file id from URL
    const fileid = Number(url.pathname.split("/").pop());

    // Aggregate requests
    let res: Response = await new Promise((callback) => {
      fetchPreviewQueue.push({
        url,
        fileid,
        reqid: Math.random(),
        callback,
      });
      if (!fetchPreviewTimer) {
        fetchPreviewTimer = setTimeout(flushPreviewQueue, 50);
      }
    });

    // Fallback to single request
    if (res.status !== 200) {
      res = await fetch(url);
    }

    // Cache response
    if (res.status === 200) {
      imageCache?.put(request, res.clone());
      expirationManager.updateTimestamp(request.url);
    }

    // Run expiration once in every 20 requests
    if (Math.random() < 0.05) {
      expirationManager.expireEntries();
    }

    return res;
  }
);

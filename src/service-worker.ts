import { precacheAndRoute, cleanupOutdatedCaches } from 'workbox-precaching';
import { NetworkFirst, CacheFirst } from 'workbox-strategies';
import { registerRoute } from 'workbox-routing';
import { ExpirationPlugin } from 'workbox-expiration';

declare var self: ServiceWorkerGlobalScope;

type PrecacheEntry = Exclude<(typeof self.__WB_MANIFEST)[number], string>;

// Paths are updated in PHP. See OtherController.php
const manifest = self.__WB_MANIFEST as Array<PrecacheEntry>;

// Only include JS files
const filteredManifest = manifest.filter((entry) => /\.js(\?.*)?$/.test(entry.url));

precacheAndRoute(filteredManifest);
cleanupOutdatedCaches();

registerRoute(
  /\/apps\/memories\/api\/video\/livephoto\/.*/,
  new CacheFirst({
    cacheName: 'memories-livephotos',
    plugins: [
      new ExpirationPlugin({
        maxAgeSeconds: 3600 * 24 * 7, // days
        maxEntries: 1000, // 1k videos
      }),
    ],
  }),
);

// Use CacheFirst for static assets
const cachefirst = [
  /\.(?:js|css|woff2?|png|jpg|jpeg|gif|svg|ico)$/i, // Static assets
  /\/apps\/theming\/(icon|favicon|manifest)/i, // Theming
  /\/avatar/i, // User avatars
];

// Cache static file assets
registerRoute(
  ({ url }) => url.origin === self.location.origin && cachefirst.some((regex) => regex.test(url.pathname)),
  new CacheFirst({
    cacheName: 'memories-pages',
    plugins: [
      new ExpirationPlugin({
        maxAgeSeconds: 3600 * 24 * 7, // days
        maxEntries: 2000, // assets
      }),
    ],
  }),
);

// Important: Using the NetworkOnly strategy and not registering
// a route are NOT equivalent. The NetworkOnly strategy will
// strip certain headers such as HTTP-Range, which is required
// for proper playback of videos.
const netonly = [
  /\/(api|ocs)\//i, // API calls
  /\/csrftoken/i, // CSRF token (https://github.com/pulsejet/memories/issues/835)
];

// Use NetworkFirst for HTML pages for initial state and CSRF token
registerRoute(
  ({ url }) => url.origin === self.location.origin && !netonly.some((regex) => regex.test(url.pathname)),
  new NetworkFirst({
    cacheName: 'memories-pages',
  }),
);

self.addEventListener('activate', (event) => {
  // Take control of all pages under this SW's scope immediately,
  // instead of waiting for reload/navigation.
  event.waitUntil(self.clients.claim());
});

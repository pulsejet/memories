import { precacheAndRoute, cleanupOutdatedCaches } from 'workbox-precaching';
import { NetworkFirst, CacheFirst } from 'workbox-strategies';
import { registerRoute } from 'workbox-routing';
import { ExpirationPlugin } from 'workbox-expiration';

precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

registerRoute(
  /^.*\/apps\/memories\/api\/video\/livephoto\/.*/,
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

// Important: Using the NetworkOnly strategy and not registering
// a route are NOT equivalent. The NetworkOnly strategy will
// strip certain headers such as HTTP-Range, which is required
// for proper playback of videos.

const networkOnly = [/^.*\/api\/.*/];

// Use network-first for memories page for initial state such as theming
registerRoute(
  ({ url }) => url.origin === self.location.origin && url.pathname.endsWith('/apps/memories/'),
  new NetworkFirst({
    cacheName: 'memories-pages',
  }),
);

// Cache pages for same-origin requests only
registerRoute(
  ({ url }) => url.origin === self.location.origin && !networkOnly.some((regex) => regex.test(url.href)),
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

self.addEventListener('activate', (event) => {
  // Take control of all pages under this SW's scope immediately,
  // instead of waiting for reload/navigation.
  event.waitUntil(self.clients.claim());
});

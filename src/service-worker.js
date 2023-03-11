import { precacheAndRoute } from "workbox-precaching";
import { NetworkFirst, CacheFirst, NetworkOnly } from "workbox-strategies";
import { registerRoute } from "workbox-routing";
import { ExpirationPlugin } from "workbox-expiration";

precacheAndRoute(self.__WB_MANIFEST);

registerRoute(/^.*\/apps\/memories\/api\/video\/livephoto\/.*/, new CacheFirst({
  cacheName: "livephotos",
  plugins: [
    new ExpirationPlugin({
      maxAgeSeconds: 3600 * 24 * 7, // days
      maxEntries: 1000, // 1k videos
    }),
  ],
}));

registerRoute(/^.*\/apps\/memories\/api\/.*/, new NetworkOnly());

// Cache pages for same-origin requests only\
registerRoute(
  ({ url }) => url.origin === self.location.origin,
  new NetworkFirst({
    cacheName: "pages",
    plugins: [
      new ExpirationPlugin({
        maxAgeSeconds: 3600 * 24 * 7, // days
        maxEntries: 2000, // assets
      }),
    ],
  })
);

self.addEventListener("activate", (event) => {
  // Take control of all pages under this SW's scope immediately,
  // instead of waiting for reload/navigation.
  event.waitUntil(self.clients.claim());
});

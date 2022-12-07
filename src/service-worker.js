import { precacheAndRoute } from 'workbox-precaching';
import { NetworkFirst, CacheFirst, NetworkOnly } from 'workbox-strategies';
import { registerRoute } from 'workbox-routing';
import { ExpirationPlugin } from 'workbox-expiration';

precacheAndRoute(self.__WB_MANIFEST);

import './service-worker-custom';

registerRoute(/^.*\/apps\/memories\/api\/video\/transcode\/.*/, new NetworkOnly());
registerRoute(/^.*\/apps\/memories\/api\/image\/jpeg\/.*/, new NetworkOnly());
registerRoute(/^.*\/remote.php\/.*/, new NetworkOnly());
registerRoute(/^.*\/apps\/files\/ajax\/download.php?.*/, new NetworkOnly());

const imageCache = new CacheFirst({
    cacheName: 'images',
    plugins: [
        new ExpirationPlugin({
            maxAgeSeconds: 3600 * 24 * 7, // days
            maxEntries: 20000, // 20k images
        }),
    ],
});

registerRoute(/^.*\/apps\/memories\/api\/image\/preview\/.*/, imageCache);
registerRoute(/^.*\/apps\/memories\/api\/video\/livephoto\/.*/, imageCache);
registerRoute(/^.*\/apps\/memories\/api\/faces\/preview\/.*/, imageCache);
registerRoute(/^.*\/apps\/memories\/api\/tags\/preview\/.*/, imageCache);

registerRoute(/^.*\/apps\/memories\/api\/.*/, new NetworkOnly());

registerRoute(/^.*\/.*$/, new NetworkFirst({
    cacheName: 'pages',
    plugins: [
        new ExpirationPlugin({
            maxAgeSeconds: 3600 * 24 * 7, // days
            maxEntries: 2000, // assets
        }),
    ],
}));

self.addEventListener('activate', event => {
    // Take control of all pages under this SW's scope immediately,
    // instead of waiting for reload/navigation.
    event.waitUntil(self.clients.claim());
});

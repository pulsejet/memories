const webpackConfig = require('@nextcloud/webpack-vue-config')
const WorkboxPlugin = require('workbox-webpack-plugin')
const path = require('path')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'

webpackConfig.module.rules.push({
    test: /\.ts?$/,
    loader: 'ts-loader',
    exclude: /node_modules/,
    options: {
        appendTsSuffixTo: [/\.vue$/],
    },
});
webpackConfig.resolve.extensions.push('.ts');
webpackConfig.resolve.alias = {
    'vue$': 'vue/dist/vue.esm.js',
}
webpackConfig.entry.main = path.resolve(path.join('src', 'main'));

webpackConfig.watchOptions = {
    ignored: /node_modules/,
    aggregateTimeout: 300,
};

if (!isDev) {
    const imageCacheOpts = (expiryDays) => ({
        handler: 'CacheFirst',

        options: {
            cacheName: 'images',
            expiration: {
                maxAgeSeconds: 3600 * 24 * expiryDays, // days
                maxEntries: 20000, // 20k images
            },
        },
    });

    webpackConfig.plugins.push(
        new WorkboxPlugin.GenerateSW({
            swDest: 'memories-service-worker.js',
            clientsClaim: true,
            skipWaiting: true,
            exclude: [new RegExp('.*')], // don't do precaching
            inlineWorkboxRuntime: true,
            sourcemap: false,

            // Define runtime caching rules.
            runtimeCaching: [{
                // Do not cache video related files
                urlPattern: /^.*\/apps\/memories\/api\/video\/.*/,
                handler: 'NetworkOnly',
            }, {
                // Do not cache raw editing files
                urlPattern: /^.*\/apps\/memories\/api\/image\/jpeg\/.*/,
                handler: 'NetworkOnly',
            }, {
                // Do not cache webdav
                urlPattern: /^.*\/remote.php\/.*/,
                handler: 'NetworkOnly',
            }, {
                // Do not cache downloads
                urlPattern: /^.*\/apps\/files\/ajax\/download.php?.*/,
                handler: 'NetworkOnly',
            }, {
                // Preview file request from core
                urlPattern: /^.*\/core\/preview\?fileId=.*/,
                ...imageCacheOpts(7),
            }, {
                // Albums from Photos
                urlPattern: /^.*\/apps\/photos\/api\/v1\/preview\/.*/,
                ...imageCacheOpts(7),
            }, {
                // Face previews from Memories
                urlPattern: /^.*\/apps\/memories\/api\/faces\/preview\/.*/,
                ...imageCacheOpts(1),
            }, {
                // Match page requests
                urlPattern: /^.*\/.*$/,
                handler: 'NetworkFirst',
                options: {
                    cacheName: 'pages',
                    expiration: {
                        maxAgeSeconds: 3600 * 24 * 7, // one week
                        maxEntries: 2000, // assets
                    },
                },
            }],
        })
    );
}

module.exports = webpackConfig

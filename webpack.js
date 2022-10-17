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
delete webpackConfig.optimization.splitChunks;

webpackConfig.watchOptions = {
    ignored: /node_modules/,
    aggregateTimeout: 300,
};

if (!isDev) {
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
                // Match any preview file request
                urlPattern: /^.*\/core\/preview\?fileId=.*/,
                handler: 'CacheFirst',

                options: {
                    cacheName: 'images',
                    expiration: {
                        maxAgeSeconds: 3600 * 24 * 7, // one week
                        maxEntries: 20000, // 20k images
                    },
                },
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

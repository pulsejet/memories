const webpackConfig = require('./webpack-base')
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
webpackConfig.entry.main = path.resolve(path.join('src', 'main'));

webpackConfig.watchOptions = {
    ignored: /node_modules/,
    aggregateTimeout: 300,
};

if (!isDev) {
    webpackConfig.plugins.push(
        new WorkboxPlugin.InjectManifest({
            swSrc: path.resolve(path.join('src', 'service-worker.js')),
            swDest: 'memories-service-worker.js',
        })
    );
}

module.exports = webpackConfig

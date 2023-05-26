const webpack = require('webpack');
const path = require('path');
const webpackConfig = require('@nextcloud/webpack-vue-config');
const WorkboxPlugin = require('workbox-webpack-plugin');
const TerserPlugin = require('terser-webpack-plugin');

const buildMode = process.env.NODE_ENV;
const isDev = buildMode === 'development';

// Entry points
webpackConfig.entry = {
  main: path.resolve(path.join('src', 'main')),
  admin: path.resolve(path.join('src', 'admin')),
};

// Enable TypeScript
webpackConfig.module.rules.push({
  test: /\.ts?$/,
  loader: 'ts-loader',
  exclude: /node_modules/,
  options: {
    appendTsSuffixTo: [/\.vue$/],
  },
});

// Exclude node_modules from watch
webpackConfig.watchOptions = {
  ignored: /node_modules/,
  aggregateTimeout: 300,
};

// Bundle service worker
webpackConfig.plugins.push(
  new WorkboxPlugin.InjectManifest({
    swSrc: path.resolve(path.join('src', 'service-worker.js')),
    swDest: 'memories-service-worker.js',
  })
);

// Exclusions from minification
const minifyExclude = [/filerobot-image-editor/];

webpackConfig.optimization.minimizer[0] = new TerserPlugin({
  exclude: minifyExclude,
  terserOptions: {
    output: {
      comments: false,
    },
  },
  extractComments: true,
});

// Disable source maps in production
webpackConfig.devtool = isDev ? 'cheap-source-map' : false;

// Configure source map public path
webpackConfig.plugins.push(
  new webpack.SourceMapDevToolPlugin({
    filename: '[file].map',
    publicPath: path.join('/apps/', process.env.npm_package_name, '/js/'),
  })
);

// Enable caching
webpackConfig.cache = true;

module.exports = webpackConfig;

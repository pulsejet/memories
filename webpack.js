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
  'hooks-clear-cache': path.resolve(path.join('src', 'hooks', 'clear-cache')),
};

// Enable TypeScript for Vue
const tsRule = webpackConfig.module.rules.find((rule) => rule.use?.includes('ts-loader'));
console.assert(tsRule, 'Could not find ts-loader rule');
tsRule.use = [
  { loader: 'babel-loader' },
  {
    loader: 'ts-loader',
    options: {
      appendTsSuffixTo: [/\.vue$/],
    },
  },
];

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
  }),
);

// Minification
webpackConfig.optimization.minimizer = [
  new TerserPlugin({
    exclude: [/filerobot-image-editor/],
    terserOptions: {
      output: {
        comments: false,
      },
    },
    extractComments: true,
  }),
];

// Disable source maps in production
webpackConfig.devtool = isDev ? 'cheap-source-map' : false;

// Configure source map public path
webpackConfig.plugins.push(
  new webpack.SourceMapDevToolPlugin({
    filename: '[file].map',
    publicPath: path.join('/apps/', process.env.npm_package_name, '/js/'),
  }),
);

// Enable caching
webpackConfig.cache = true;

module.exports = webpackConfig;

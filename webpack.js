const webpackConfig = require('@nextcloud/webpack-vue-config');
const WorkboxPlugin = require('workbox-webpack-plugin');
const path = require('path');
const TerserPlugin = require('terser-webpack-plugin');

const buildMode = process.env.NODE_ENV;
const isDev = buildMode === 'development';

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
  vue$: 'vue/dist/vue.esm.js',
};
webpackConfig.entry.main = path.resolve(path.join('src', 'main'));

webpackConfig.watchOptions = {
  ignored: /node_modules/,
  aggregateTimeout: 300,
};

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

module.exports = webpackConfig;

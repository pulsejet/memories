const webpack = require('webpack');
const path = require('path');

const WorkboxPlugin = require('workbox-webpack-plugin');
const TerserPlugin = require('terser-webpack-plugin');
const NodePolyfillPlugin = require('node-polyfill-webpack-plugin');
const { VueLoaderPlugin } = require('vue-loader');

const appName = process.env.npm_package_name;
const appVersion = process.env.npm_package_version;
const buildMode = process.env.NODE_ENV;
const isDev = buildMode === 'development';
console.info('Building', appName, appVersion, '\n');

module.exports = {
  target: 'web',
  mode: buildMode,
  devtool: isDev ? 'cheap-source-map' : false,
  cache: isDev,

  entry: {
    main: path.resolve(path.join(__dirname, 'src', 'main')),
    admin: path.resolve(path.join(__dirname, 'src', 'admin')),
    'hooks-clear-cache': path.resolve(path.join(__dirname, 'src', 'hooks', 'clear-cache')),
  },

  output: {
    path: path.resolve(__dirname, 'js'),
    publicPath: path.join('/apps/', appName, '/js/'),

    // Output file names
    filename: `${appName}-[name].js?v=[contenthash]`,
    chunkFilename: `${appName}-[name].js?v=[contenthash]`,

    // Clean output before each build
    clean: true,

    // Make sure sourcemaps have a proper path and do not
    // leak local paths https://github.com/webpack/webpack/issues/3603
    devtoolNamespace: appName,
    devtoolModuleFilenameTemplate(info) {
      const rootDir = process.cwd();
      const rel = path.relative(rootDir, info.absoluteResourcePath);
      return `webpack:///${appName}/${rel}`;
    },
  },

  watchOptions: {
    ignored: /node_modules/,
    aggregateTimeout: 300,
  },

  optimization: {
    chunkIds: 'named',
    splitChunks: {
      automaticNameDelimiter: '-',
    },
    minimize: !isDev,
    minimizer: [
      new TerserPlugin({
        exclude: [/filerobot-image-editor/],
        terserOptions: {
          output: {
            comments: false,
          },
        },
        extractComments: true,
      }),
    ],
  },

  module: {
    rules: [
      {
        test: /\.(png|jpe?g|gif|svg|woff2?|eot|ttf)$/,
        type: 'asset/inline',
      },
      {
        test: /\.s?css$/,
        use: ['style-loader', 'css-loader', 'sass-loader'],
      },
      {
        test: /\.vue$/,
        loader: 'vue-loader',
      },
      {
        test: /\.tsx?$/,
        use: [
          {
            loader: 'ts-loader',
            options: {
              appendTsSuffixTo: [/\.vue$/],
            },
          },
        ],
        exclude: /node_modules/,
      },
    ],
  },

  plugins: [
    new VueLoaderPlugin(),

    // Make sure we auto-inject node polyfills on demand
    // https://webpack.js.org/blog/2020-10-10-webpack-5-release/#automatic-nodejs-polyfills-removed
    new NodePolyfillPlugin({
      includeAliases: ['stream', 'process'], // webdav
    }),

    // Configure source map public path
    new webpack.SourceMapDevToolPlugin({
      filename: '[file].map',
      publicPath: path.join('/apps/', process.env.npm_package_name, '/js/'),
    }),

    // Bundle service worker
    new WorkboxPlugin.InjectManifest({
      swSrc: path.resolve(path.join('src', 'service-worker.js')),
      swDest: 'memories-service-worker.js',
    }),

    // Make appName & appVersion available as a constant
    new webpack.DefinePlugin({ appName: JSON.stringify(appName) }),
    new webpack.DefinePlugin({ appVersion: JSON.stringify(appVersion) }),

    // Bundle analyzer (npm i --no-save webpack-bundle-analyzer)
    // new (require('webpack-bundle-analyzer').BundleAnalyzerPlugin)()
  ],

  resolve: {
    extensions: ['.ts', '.js', '.vue'],
    symlinks: false,
    // Ensure npm does not duplicate vue dependency, and that npm link works for vue 3
    // See https://github.com/vuejs/core/issues/1503
    // See https://github.com/nextcloud/nextcloud-vue/issues/3281
    alias: {
      vue$: path.resolve('./node_modules/vue'),
    },
  },
};

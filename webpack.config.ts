const webpack = require('webpack');
const path = require('path');

const WorkboxPlugin = require('workbox-webpack-plugin');
const TerserPlugin = require('terser-webpack-plugin');
const { VueLoaderPlugin } = require('vue-loader');
const NodePolyfillPlugin = require('node-polyfill-webpack-plugin');

const appName = process.env.npm_package_name!;
const appVersion = process.env.npm_package_version!;
const buildMode = process.env.NODE_ENV;
const isDev = buildMode === 'development';
console.info('Building', appName, appVersion, '\n');

module.exports = {
  target: 'web',
  mode: buildMode,
  devtool: 'source-map',
  cache: isDev,

  context: path.resolve(__dirname, 'src'),

  entry: {
    main: './main',
    admin: './admin',
    'hooks-clear-cache': './hooks/clear-cache',
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
    devtoolModuleFilenameTemplate(info: any) {
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
        resourceQuery: /raw/,
        type: 'asset/source',
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

    // @nextcloud/dialogs depends on path
    // This is really frustrating, but it's the only way
    new NodePolyfillPlugin({
      onlyAliases: ['path', 'process'],
    }),

    // Bundle service worker
    new WorkboxPlugin.InjectManifest({
      swSrc: path.resolve(path.join('src', 'service-worker.ts')),
      swDest: 'memories-service-worker.js',
      maximumFileSizeToCacheInBytes: (isDev ? 10 : 4) * 1024 * 1024,
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
    alias: {
      // Ensure npm does not duplicate vue dependency, and that npm link works for vue 3
      // See https://github.com/vuejs/core/issues/1503
      // See https://github.com/nextcloud/nextcloud-vue/issues/3281
      vue$: path.resolve(__dirname, 'node_modules', 'vue'),

      // You also need to update tsconfig.json
      '@services': path.resolve(__dirname, 'src', 'services'),
      '@assets': path.resolve(__dirname, 'src', 'assets'),
      '@components': path.resolve(__dirname, 'src', 'components'),
      '@mixins': path.resolve(__dirname, 'src', 'mixins'),
      '@native': path.resolve(__dirname, 'src', 'native'),
    },
  },
};

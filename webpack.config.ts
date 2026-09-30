import * as path from 'path';
import { fileURLToPath } from 'url';

import webpack from 'webpack';
import NodePolyfillPlugin from 'node-polyfill-webpack-plugin';
import TerserPlugin from 'terser-webpack-plugin';
import { VueLoaderPlugin } from 'vue-loader';
import { WebpackManifestPlugin } from 'webpack-manifest-plugin';
import WorkboxPlugin from 'workbox-webpack-plugin';

// Explicit `.ts` extensions are required by Node's ESM loader.
// @ts-expect-error TS5097: extension is intentional, do not drop it
import { L10nBundlePlugin } from './webpack.l10n-bundle-plugin.ts';
// @ts-expect-error TS5097: extension is intentional, do not drop it
import { ManifestSignPlugin } from './webpack.manifest-sign-plugin.ts';

// npm i --no-save webpack-bundle-analyzer to enable
// import { BundleAnalyzerPlugin } from 'webpack-bundle-analyzer';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const MiB = 1024 * 1024;
const appName = process.env.npm_package_name!;
const appVersion = process.env.npm_package_version!;
const buildMode = process.env.NODE_ENV;
const isDev = buildMode === 'development';
console.info('Building', appName, appVersion, '\n');

const manifestFileName = `${appName}-manifest.json`;
const manifestSigFileName = `${appName}-manifest.sig.json`;

export default {
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

    // Use a cryptographic hash of the file content for cache busting.
    // We will use this as a transparency proof.
    hashFunction: 'sha256',
    hashDigestLength: 64,

    // Output file names
    filename: `${appName}-[name].js?v=[contenthash]`,
    chunkFilename: `${appName}-[name].js?v=[contenthash]`,

    // Sourcemaps without query string: a ?v=<hash> here would be part of
    // the JS content (sourceMappingURL comment) and break the correspondence
    // between [contenthash] and the actual file content hash
    sourceMapFilename: '[file].map',

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
    realContentHash: true,
    splitChunks: {
      automaticNameDelimiter: '-',
    },
    minimize: !isDev,
    minimizer: [
      new TerserPlugin({
        exclude: [/filerobot-image-editor/],
        terserOptions: {
          ecma: 2022,
          output: {
            comments: false,
          },
        },
        extractComments: true,
      }),
    ],
  },

  performance: {
    maxAssetSize: (isDev ? 15 : 3) * MiB,
    maxEntrypointSize: (isDev ? 15 : 3) * MiB,
    hints: 'error',
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
        sideEffects: true,
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
              transpileOnly: true,
            },
          },
        ],
        exclude: /node_modules/,
      },
      {
        test: /\.m?js/, // https://github.com/webpack/webpack/issues/11467
        resolve: {
          fullySpecified: false,
        },
      },
    ],
  },

  plugins: [
    new VueLoaderPlugin(),

    // Bundle all l10n/*.json into memories-l10n.js as globalThis.__packed_l10n.
    new L10nBundlePlugin(appName, path.resolve(__dirname, 'l10n')),

    // Manifest of all built files (base name -> {hash, href}).
    // The standalone shell uses this to know every chunk up front.
    new WebpackManifestPlugin({
      fileName: manifestFileName,
      generate: (seed: any, files: any[]) =>
        Object.fromEntries(
          files.map((file) => {
            const name = file.path.split('/').pop() ?? '';
            const [basename, hash] = name.split('?v=');
            return [basename, { hash: hash ?? '', href: file.path }];
          }),
        ),
    }),

    // Signature over manifest with a pinned public key.
    new ManifestSignPlugin(manifestFileName, manifestSigFileName),

    // @nextcloud/dialogs depends on path
    // This is really frustrating, but it's the only way
    new NodePolyfillPlugin({
      onlyAliases: ['path', 'process'],
    }),

    // Bundle service worker
    new WorkboxPlugin.InjectManifest({
      swSrc: path.resolve(path.join('src', 'service-worker.ts')),
      swDest: 'memories-service-worker.js',
      maximumFileSizeToCacheInBytes: (isDev ? 50 : 20) * MiB,
    }),

    // Make appName & appVersion available as a constant
    new webpack.DefinePlugin({ appName: JSON.stringify(appName) }),
    new webpack.DefinePlugin({ appVersion: JSON.stringify(appVersion) }),

    // Vue 3 compile-time feature flags (required to silence the
    // esm-bundler warning and enable proper tree-shaking)
    new webpack.DefinePlugin({
      __VUE_OPTIONS_API__: true,
      __VUE_PROD_DEVTOOLS__: false,
      __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: false,
    }),

    // Bundle analyzer (uncomment the import above to use)
    // new BundleAnalyzerPlugin(),
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
    fallback: {
      stream: fileURLToPath(import.meta.resolve('stream-browserify')),
    },
  },
};

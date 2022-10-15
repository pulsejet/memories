const webpackConfig = require('@nextcloud/webpack-vue-config')
const path = require('path')

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

module.exports = webpackConfig

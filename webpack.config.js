const Encore = require('@symfony/webpack-encore');

if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore
    .setOutputPath('public/')
    .setPublicPath('/bundles/markocupiccontaobackendcolumntoggle')
    .setManifestKeyPrefix('')

    // Produces public/backend.js and public/backend.css.
    // The file names are referenced in ColumnToggleListener::addAssets(),
    // therefore versioning is intentionally disabled.
    .addEntry('backend', './assets/backend.js')

    .disableSingleRuntimeChunk()
    .cleanupOutputBeforeBuild()
    .enableSourceMaps(!Encore.isProduction())

    // enables @babel/preset-env polyfills
    .configureBabelPresetEnv((config) => {
        config.useBuiltIns = 'usage';
        config.corejs = 3;
    })

    // Preprocessing SCSS to CSS
    .enableSassLoader()
;

module.exports = Encore.getWebpackConfig();

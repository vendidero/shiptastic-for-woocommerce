const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const WooCommerceDependencyExtractionWebpackPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );
const MiniCssExtractPlugin = require( 'mini-css-extract-plugin' );
const TerserJSPlugin = require( 'terser-webpack-plugin' );
const CssMinimizerPlugin = require("css-minimizer-webpack-plugin");
const { omit } = require( 'lodash' );
const fs = require("fs");
const CopyWebpackPlugin = require( 'copy-webpack-plugin' );
const glob = require( 'glob' );
const { paramCase } = require( 'change-case' );

// This is a simple webpack plugin to delete the JS files generated by MiniCssExtractPlugin.

function RemoveFilesPlugin( filePath = '' ) {
    this.filePath = filePath;
}

RemoveFilesPlugin.prototype.apply = function ( compiler ) {
    compiler.hooks.afterEmit.tap( 'afterEmit', () => {
        const files = glob.sync( this.filePath );
        files.forEach( ( f ) => {
            fs.unlink( f, ( err ) => {
                if ( err ) {
                    /* eslint-disable-next-line no-console */
                    console.log( `There was an error removing ${ f }.`, err );
                }
            } );
        } );
    } );
};

function findModuleMatch( module, match ) {
    if ( module.request && match.test( module.request ) ) {
        return true;
    } else if ( module.issuer ) {
        return findModuleMatch( module.issuer, match );
    }
    return false;
}

// Remove SASS rule from the default config so we can define our own.
const defaultRules = defaultConfig.module.rules.filter( ( rule ) => {
    return String( rule.test ) !== String( /\.(sc|sa)ss$/ );
} );

const blocks = {
    'checkout-pickup-location-select': {}
};

const getBlockEntries = ( relativePath ) => {
    return Object.fromEntries(
        Object.entries( blocks )
            .map( ( [ blockCode, config ] ) => {
                const filePaths = glob.sync(
                    `./assets/js/blocks/${ config.customDir || blockCode }/` +
                    relativePath
                );
                if ( filePaths.length > 0 ) {
                    return [ blockCode, filePaths ];
                }
                return null;
            } )
            .filter( Boolean )
    );
};

const staticCss = glob.sync('./assets/css/*.scss').reduce(function(obj, el){
    obj[ path.parse(el).name + '-styles' ] = el;
    return obj
},{});

const staticJs = glob.sync('./assets/js/static/*.js').reduce(function(obj, el){
    obj[ path.parse(el).name ] = el;
    return obj
},{});

const staticEntry = { ...staticCss, ...staticJs }

const entries = {
    styling: {
        // Shared blocks code
        'wc-gzd-shipments-blocks': './assets/js/index.js',
        ...getBlockEntries( '{index,block,frontend}.{t,j}s{,x}' ),
    },
    core: {
        blocksCheckout: './assets/js/packages/checkout/index.js',
    },
    main: {
        // Shared blocks code
        'wc-gzd-shipments-blocks': './assets/js/index.js',
        // Blocks
        ...getBlockEntries( 'index.{t,j}s{,x}' )
    },
    frontend: {
        ...getBlockEntries( 'frontend.{t,j}s{,x}' ),
    },
    'static': staticEntry,
};

const getEntryConfig = ( type = 'main', exclude = [] ) => {
    return omit( entries[ type ], exclude );
};

const getAlias = ( options = {} ) => {
    return {};
};

const wcDepMap = {
    '@woocommerce/blocks-registry': [ 'wc', 'wcBlocksRegistry' ],
    '@woocommerce/settings': [ 'wc', 'wcSettings' ],
    '@woocommerce/block-data': [ 'wc', 'wcBlocksData' ],
    '@woocommerce/data': [ 'wc', 'data' ],
    '@woocommerce/shared-context': [ 'wc', 'wcBlocksSharedContext' ],
    '@woocommerce/shared-hocs': [ 'wc', 'wcBlocksSharedHocs' ],
    '@woocommerce/price-format': [ 'wc', 'priceFormat' ],
    '@woocommerce/blocks-checkout': [ 'wc', 'blocksCheckout' ],
    '@woocommerce/interactivity': [ 'wc', '__experimentalInteractivity' ],
    '@woocommerceGzdShipments/blocks-checkout': [ 'wcGzdShipments', 'blocksCheckout' ],
};

const wcHandleMap = {
    '@woocommerce/blocks-registry': 'wc-blocks-registry',
    '@woocommerce/settings': 'wc-settings',
    '@woocommerce/block-data': 'wc-blocks-data-store',
    '@woocommerce/data': 'wc-store-data',
    '@woocommerce/shared-context': 'wc-blocks-shared-context',
    '@woocommerce/shared-hocs': 'wc-blocks-shared-hocs',
    '@woocommerce/price-format': 'wc-price-format',
    '@woocommerce/blocks-checkout': 'wc-blocks-checkout',
    '@woocommerce/interactivity': 'wc-interactivity',
    '@woocommerceGzdShipments/blocks-checkout': 'wc-gzd-shipments-blocks-checkout',
};

const requestToExternal = ( request ) => {
    if ( wcDepMap[ request ] ) {
        return wcDepMap[ request ];
    }
};

const requestToHandle = ( request ) => {
    if ( wcHandleMap[ request ] ) {
        return wcHandleMap[ request ];
    }
};

const getBaseConfig = ( entry ) => {
    return {
        ...defaultConfig,
        entry: getEntryConfig( entry, [] ),
        output: {
            filename: ( chunkData ) => {
                return `${ paramCase( chunkData.chunk.name ) }.js`;
            },
            path: path.resolve( __dirname, 'build/' ),
            library: [ 'wcGzdShipments', '[name]' ],
            libraryTarget: 'window',
            // This fixes an issue with multiple webpack projects using chunking
            // overwriting each other's chunk loader function.
            // See https://webpack.js.org/configuration/output/#outputjsonpfunction
            chunkLoadingGlobal: 'webpackWcShipmentsBlocksJsonp',
        },
        module: {
            ...defaultConfig.module,
            rules: [
                ...defaultRules,
                {
                    test: /\.(sc|sa)ss$/,
                    exclude: /node_modules/,
                    use: [
                        MiniCssExtractPlugin.loader,
                        { loader: 'css-loader', options: { importLoaders: 1 } },
                        {
                            loader: 'sass-loader',
                            options: {
                                sassOptions: {
                                    includePaths: [ 'assets/css' ],
                                },
                                additionalData: ( content, loaderContext ) => {
                                    const {
                                        resourcePath,
                                        rootContext,
                                    } = loaderContext;
                                    const relativePath = path.relative(
                                        rootContext,
                                        resourcePath
                                    );

                                    if (
                                        relativePath.startsWith( 'assets/css/' )
                                    ) {
                                        return content;
                                    }

                                    // Add code here to prepend to all .scss/.sass files.
                                    return (
                                        content
                                    );
                                },
                            },
                        },
                    ],
                },
            ],
        },
        resolve: {
            alias: getAlias()
        },
        plugins: [
            ...defaultConfig.plugins.filter(
                ( plugin ) =>
                    plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
            ),
            new WooCommerceDependencyExtractionWebpackPlugin( {
                requestToExternal,
                requestToHandle,
            } ),
            new MiniCssExtractPlugin( {
                filename: `[name].css`,
            } ),
        ],
    };
};

const StaticConfig = {
    ...defaultConfig,
    entry: getEntryConfig( 'static', [] ),
    resolve: {
        extensions: ['.js', '.css', '.scss']
    },
    output: {
        path: path.resolve( __dirname, './build/static/' ),
        filename: "[name].js",
        devtoolNamespace: 'wcGzdShipments',
        library: [ 'wcGzdShipments', 'static', '[name]' ],
        libraryTarget: 'window',
    }
};

const FrontendConfig = {
    ...getBaseConfig( 'frontend' ),
    output: {
        devtoolNamespace: 'wcGzdShipments',
        path: path.resolve( __dirname, 'build/' ),
        // This is a cache busting mechanism which ensures that the script is loaded via the browser with a ?ver=hash
        // string. The hash is based on the built file contents.
        // @see https://github.com/webpack/webpack/issues/2329
        // Using the ?ver string is needed here so the filename does not change between builds. The WordPress
        // i18n system relies on the hash of the filename, so changing that frequently would result in broken
        // translations which we must avoid.
        // @see https://github.com/Automattic/jetpack/pull/20926
        chunkFilename: `[name]-frontend.js?ver=[contenthash]`,
        filename: `[name]-frontend.js`,
        // This fixes an issue with multiple webpack projects using chunking
        // overwriting each other's chunk loader function.
        // See https://webpack.js.org/configuration/output/#outputjsonpfunction
        // This can be removed when moving to webpack 5:
        // https://webpack.js.org/blog/2020-10-10-webpack-5-release/#automatic-unique-naming
        chunkLoadingGlobal: 'webpackWcShipmentsBlocksJsonp',
    }
};

const innerCoreConfig = getBaseConfig( 'core' );

const CoreConfig = {
    ...innerCoreConfig
};

const innerMainConfig = getBaseConfig( 'main' );

const MainConfig = {
    ...innerMainConfig
};

const innerStylingConfig = getBaseConfig( 'styling' );

const StylingConfig = {
    ...innerStylingConfig,
    optimization: {
        splitChunks: {
            minSize: 0,
            automaticNameDelimiter: '--',
            cacheGroups: {
                editorStyle: {
                    // Capture all `editor` stylesheets and editor-components stylesheets.
                    test: ( module = {} ) =>
                        module.constructor.name === 'CssModule' &&
                        ( findModuleMatch( module, /editor\.scss$/ ) ||
                            findModuleMatch(
                                module,
                                /[\\/]assets[\\/]js[\\/]editor-components[\\/]/
                            ) ),
                    name: 'wc-shipments-blocks-editor-style',
                    chunks: 'all',
                    priority: 10,
                },
            },
        },
    },
    output: {
        devtoolNamespace: 'wcGzdPro',
        path: path.resolve( __dirname, 'build/' ),
        filename: `[name]-style.js`,
        library: [ 'wcShipments', 'blocks', '[name]' ],
        libraryTarget: 'window',
        // This fixes an issue with multiple webpack projects using chunking
        // overwriting each other's chunk loader function.
        // See https://webpack.js.org/configuration/output/#outputjsonpfunction
        // This can be removed when moving to webpack 5:
        // https://webpack.js.org/blog/2020-10-10-webpack-5-release/#automatic-unique-naming
        chunkLoadingGlobal: 'webpackWcShipmentsBlocksJsonp'
    },
    resolve: {
        alias: getAlias(),
        extensions: [ '.js' ],
    },
    plugins: [
        ...innerStylingConfig.plugins,
        // Remove JS files generated by MiniCssExtractPlugin.
        new RemoveFilesPlugin( `./build/*style.js` ),
    ],
};

module.exports = [
    StaticConfig,
    CoreConfig,
    MainConfig,
    FrontendConfig,
    StylingConfig
];
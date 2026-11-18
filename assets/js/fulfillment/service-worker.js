/**
 * Service worker of the fulfillment app: keeps the app (page + assets) available offline, e.g. to reload the page
 * without connection. The app's data (current run, outbox) lives in IndexedDB.
 *
 * Served via admin-ajax.php?action=stc_fulfillment_sw (a URL below /wp-admin/) and registered with the app page as
 * scope (wp-admin/admin.php?page=wc-shiptastic-fulfillment), so it controls the fulfillment app only. Other requests
 * (REST API, other origins, non-GET requests) pass through untouched.
 *
 * - App page: network first, the last successful response is the offline fallback (query args such as the run are
 *   ignored, the app restores the run itself).
 * - Same-origin scripts, styles, images and fonts: network first (with a timeout), cached copy as fallback.
 */
const VERSION = new URL( self.location.href ).searchParams.get( 'v' ) || '1';
const PREFIX = 'stc-fulfillment-';
const CACHE = PREFIX + VERSION;
const TIMEOUT = 4000;

const scope = () => new URL( self.registration.scope );

/**
 * @param {URL} url
 * @return {boolean} Whether the URL is the app page (any query args).
 */
function isAppPage( url ) {
    const app = scope();

    return url.origin === app.origin && url.pathname === app.pathname && url.searchParams.get( 'page' ) === app.searchParams.get( 'page' );
}

/**
 * @param {Request} request
 * @param {URL}     url
 * @return {boolean} Whether the request loads an asset of the app.
 */
function isAsset( request, url ) {
    return url.origin === self.location.origin && [ 'script', 'style', 'image', 'font' ].includes( request.destination ) && ! url.pathname.includes( '/wp-json/' ) && ! url.searchParams.has( 'rest_route' );
}

function withTimeout( promise ) {
    return new Promise( ( resolve, reject ) => {
        const timer = setTimeout( () => reject( new Error( 'timeout' ) ), TIMEOUT );

        promise.then( ( response ) => {
            clearTimeout( timer );
            resolve( response );
        }, ( error ) => {
            clearTimeout( timer );
            reject( error );
        } );
    } );
}

/**
 * @param {Request}        request
 * @param {string|Request} cacheKey
 * @param {Function}       cacheable Whether a response may be cached.
 * @return {Promise<Response>}
 */
async function networkFirst( request, cacheKey, cacheable ) {
    const cache = await caches.open( CACHE );
    const network = fetch( request );

    try {
        const response = await withTimeout( network );

        if ( cacheable( response ) ) {
            await cache.put( cacheKey, response.clone() );
        }

        return response;
    } catch ( error ) {
        const cached = await cache.match( cacheKey );

        if ( cached ) {
            // The (slow) network response still updates the cache.
            network.then( ( response ) => cacheable( response ) && cache.put( cacheKey, response.clone() ) ).catch( () => {} );
            return cached;
        }

        return network;
    }
}

self.addEventListener( 'install', () => self.skipWaiting() );

self.addEventListener( 'activate', ( event ) => {
    event.waitUntil(
        caches.keys()
            .then( ( keys ) => Promise.all( keys.filter( ( key ) => key.startsWith( PREFIX ) && key !== CACHE ).map( ( key ) => caches.delete( key ) ) ) )
            .then( () => self.clients.claim() )
    );
} );

self.addEventListener( 'fetch', ( event ) => {
    const request = event.request;

    if ( 'GET' !== request.method ) {
        return;
    }

    const url = new URL( request.url );

    if ( 'navigate' === request.mode ) {
        if ( isAppPage( url ) ) {
            // Only the app itself is cached, e.g. not a redirect to the login page.
            const cacheable = ( response ) => response.ok && ! response.redirected && isAppPage( new URL( response.url ) );

            event.respondWith( networkFirst( request, scope().href, cacheable ) );
        }

        return;
    }

    if ( isAsset( request, url ) ) {
        event.respondWith( networkFirst( request, request, ( response ) => response.ok && 'basic' === response.type ) );
    }
} );

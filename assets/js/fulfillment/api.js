/**
 * REST API client for the fulfillment app.
 *
 * - Sends the REST nonce, refreshes it once via core (admin-ajax.php?action=rest-nonce) in case it expired.
 * - Throws errors with code/status from the response.
 */
let config = {
    restUrl: '',
    restRoot: '',
    nonce: '',
    nonceUrl: '',
};

export function setup( appConfig ) {
    config = { ...config, ...appConfig };
}

export async function refreshNonce() {
    const response = await fetch( config.nonceUrl, { credentials: 'same-origin' } );

    if ( response.ok ) {
        const nonce = ( await response.text() ).trim();

        if ( nonce && '0' !== nonce && '-1' !== nonce ) {
            config.nonce = nonce;
            return true;
        }
    }

    return false;
}

/**
 * @param {string} path Relative to the fulfillment namespace, or to the REST root in case `root` is set (e.g. wc/v3/orders/1).
 * @param {Object} options method, body (object sent as JSON or FormData sent as multipart), query, root (use the REST
 *                         root instead of the fulfillment namespace)
 */
export async function request( path, { method = 'GET', body = null, query = null, root = false } = {} ) {
    let url = ( root ? config.restRoot : config.restUrl ).replace( /\/$/, '' ) + '/' + path.replace( /^\//, '' );

    if ( query ) {
        url += ( url.includes( '?' ) ? '&' : '?' ) + new URLSearchParams( query ).toString();
    }

    // FormData (file uploads) is sent as multipart, the browser sets the content type incl. boundary.
    const isForm = 'undefined' !== typeof FormData && body instanceof FormData;
    const send = () => fetch( url, {
        method,
        credentials: 'same-origin',
        headers: isForm ? { 'X-WP-Nonce': config.nonce } : {
            'Content-Type': 'application/json',
            'X-WP-Nonce': config.nonce,
        },
        body: isForm ? body : ( body ? JSON.stringify( body ) : undefined ),
    } );

    let response = await send();

    if ( 403 === response.status ) {
        const data = await response.clone().json().catch( () => null );

        if ( data && 'rest_cookie_invalid_nonce' === data.code && await refreshNonce() ) {
            response = await send();
        }
    }

    const data = await response.json().catch( () => null );

    if ( ! response.ok ) {
        const error = new Error( data && data.message ? data.message : response.statusText );

        error.code = data && data.code ? data.code : 'http_error';
        error.status = response.status;
        error.data = data;

        throw error;
    }

    return data;
}

export function uuid() {
    if ( window.crypto && window.crypto.randomUUID ) {
        return window.crypto.randomUUID();
    }

    return Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2 );
}

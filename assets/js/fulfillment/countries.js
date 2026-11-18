/**
 * Countries (incl. states) from WooCommerce core (wc/v3/data/countries), used by the order details (address form)
 * and the workflow editor (country filter).
 *
 * - Preloaded in the background once the app opened (preloadCountries()), so nobody waits for them later on.
 * - Cached in IndexedDB per language: later app loads use the cached list right away, a cache older than a day is
 *   refreshed in the background (the list rarely changes).
 * - loadCountries() is memoized: concurrent callers share one request.
 */
import { store } from '@wordpress/interactivity';
import * as api from './api.js';
import * as db from './db.js';

const NAMESPACE = 'shiptastic/fulfillment';
const MAX_AGE = 24 * 60 * 60 * 1000;

let request = null;

// Country names depend on the user's language.
const cacheKey = () => 'countries-' + ( document.documentElement.lang || 'default' );

function apply( countries ) {
    const { state } = store( NAMESPACE );
    state.countries = countries;
}

/**
 * @param {Object[]} countries wc/v3/data/countries response
 * @return {Object[]} code, name, states (code, name), sorted by name
 */
function normalize( countries ) {
    return ( countries || [] )
        .map( ( country ) => ( {
            code: country.code,
            name: country.name,
            states: ( country.states || [] ).map( ( region ) => ( { code: region.code, name: region.name } ) ),
        } ) )
        .sort( ( a, b ) => a.name.localeCompare( b.name ) );
}

function fetchCountries() {
    return api.request( 'wc/v3/data/countries', { root: true } ).then( ( response ) => {
        const countries = normalize( response );

        apply( countries );
        db.set( cacheKey(), { time: Date.now(), countries } );

        return countries;
    } );
}

/**
 * Loads the countries once (cache first).
 *
 * @return {Promise<Object[]>}
 */
export function loadCountries() {
    if ( ! request ) {
        request = db.get( cacheKey() )
            .then( ( cached ) => {
                if ( cached && Array.isArray( cached.countries ) && cached.countries.length > 0 ) {
                    apply( cached.countries );

                    // Stale: refreshed in the background, the cached list is used meanwhile.
                    if ( Date.now() - ( cached.time || 0 ) > MAX_AGE ) {
                        fetchCountries().catch( () => {} );
                    }

                    return cached.countries;
                }

                return fetchCountries();
            } )
            .catch( ( error ) => {
                // Retried with the next call (e.g. offline).
                request = null;
                throw error;
            } );
    }

    return request;
}

/**
 * Loads the countries in the background once the browser is idle (app start).
 */
export function preloadCountries() {
    const load = () => loadCountries().catch( () => {} );

    if ( 'requestIdleCallback' in window ) {
        window.requestIdleCallback( load, { timeout: 3000 } );
    } else {
        window.setTimeout( load, 1000 );
    }
}

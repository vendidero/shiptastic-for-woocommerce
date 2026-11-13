/**
 * Minimal IndexedDB key-value store, e.g. to resume a run after a reload or while offline.
 * Resolves gracefully (null/undefined) in case IndexedDB is not available.
 */
const DB_NAME = 'shiptastic-fulfillment';
const STORE = 'kv';

let dbPromise = null;

function open() {
    if ( ! ( 'indexedDB' in window ) ) {
        return Promise.resolve( null );
    }

    if ( ! dbPromise ) {
        dbPromise = new Promise( ( resolve ) => {
            const req = window.indexedDB.open( DB_NAME, 1 );

            req.onupgradeneeded = () => {
                if ( ! req.result.objectStoreNames.contains( STORE ) ) {
                    req.result.createObjectStore( STORE );
                }
            };

            req.onsuccess = () => resolve( req.result );
            req.onerror = () => resolve( null );
        } );
    }

    return dbPromise;
}

async function run( mode, callback ) {
    const db = await open();

    if ( ! db ) {
        return null;
    }

    return new Promise( ( resolve ) => {
        const transaction = db.transaction( STORE, mode );
        const req = callback( transaction.objectStore( STORE ) );

        req.onsuccess = () => resolve( req.result );
        req.onerror = () => resolve( null );
    } );
}

export function get( key ) {
    return run( 'readonly', ( objectStore ) => objectStore.get( key ) );
}

export function set( key, value ) {
    // Store plain data (state proxies cannot be cloned).
    return run( 'readwrite', ( objectStore ) => objectStore.put( JSON.parse( JSON.stringify( value ) ), key ) );
}

export function remove( key ) {
    return run( 'readwrite', ( objectStore ) => objectStore.delete( key ) );
}

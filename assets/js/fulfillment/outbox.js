/**
 * Outbox of the fulfillment app: queue mutations (step submits, skip, put back) made while offline, stored in
 * IndexedDB and replayed in order once the connection is back (see actions.sync in app.js).
 *
 * Entries: id (= idempotency key, the server dedupes replays), itemId (queue item), path (relative to the fulfillment
 * namespace, e.g. queue/12/steps/pick), body (incl. token and idempotency_key), type (step|shipments|skip|release),
 * stepKey, created (timestamp).
 *
 * Mutations of one queue item are replayed in the order they were made. Once an item has entries, further mutations
 * of the item go through the outbox as well (otherwise they would overtake the stored ones).
 */
import * as db from './db.js';

let key = 'outbox';
let entries = [];
let loaded = null;

/**
 * @param {number} userId Entries are stored per user (shared devices).
 * @return {Promise<Object[]>}
 */
export function load( userId ) {
    key = 'outbox-' + userId;

    if ( ! loaded ) {
        loaded = db.get( key ).then( ( stored ) => {
            entries = Array.isArray( stored ) ? stored : [];
            return entries;
        } );
    }

    return loaded;
}

function save() {
    return db.set( key, entries );
}

/**
 * @return {Object[]} A copy of the entries (oldest first).
 */
export function all() {
    return entries.slice();
}

export function count() {
    return entries.length;
}

/**
 * @param {number} itemId
 * @return {Object[]}
 */
export function forItem( itemId ) {
    return entries.filter( ( entry ) => entry.itemId === itemId );
}

export function hasItem( itemId ) {
    return entries.some( ( entry ) => entry.itemId === itemId );
}

/**
 * @param {Object} entry
 * @return {Promise}
 */
export function add( entry ) {
    entries = [ ...entries, { created: Date.now(), ...entry } ];

    return save();
}

/**
 * @param {string} id
 * @return {Promise}
 */
export function remove( id ) {
    entries = entries.filter( ( entry ) => entry.id !== id );

    return save();
}

/**
 * Updates entries, e.g. to replace temporary shipment keys in step paths once the server created the shipments.
 *
 * @param {Function} callback entry => updated entry
 * @return {Promise}
 */
export function update( callback ) {
    entries = entries.map( ( entry ) => callback( { ...entry } ) );

    return save();
}

/**
 * Removes entries of an item, e.g. after a conflict or in case a step is reopened (the entries from that step on).
 *
 * @param {number}   itemId
 * @param {Function} filter Optional: only entries matching the filter are removed.
 * @return {Promise<Object[]>} The removed entries.
 */
export async function removeItem( itemId, filter = null ) {
    const removed = entries.filter( ( entry ) => entry.itemId === itemId && ( ! filter || filter( entry ) ) );

    entries = entries.filter( ( entry ) => ! removed.includes( entry ) );
    await save();

    return removed;
}

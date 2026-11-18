/**
 * Unit tests for the offline outbox (assets/js/fulfillment/outbox.js). IndexedDB is not available in node: entries
 * are kept in memory only.
 *
 * Run: npm run test:js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

globalThis.window = globalThis.window || {};

const outbox = await import( '../../assets/js/fulfillment/outbox.js' );

test( 'entries keep their order, per item lookups and removal', async () => {
    await outbox.load( 1 );

    await outbox.add( { id: 'a', itemId: 1, type: 'step', stepKey: 'pick' } );
    await outbox.add( { id: 'b', itemId: 2, type: 'step', stepKey: 'pick' } );
    await outbox.add( { id: 'c', itemId: 1, type: 'step', stepKey: 'pack:5' } );
    await outbox.add( { id: 'd', itemId: 1, type: 'release' } );

    assert.equal( outbox.count(), 4 );
    assert.deepEqual( outbox.all().map( ( entry ) => entry.id ), [ 'a', 'b', 'c', 'd' ] );
    assert.deepEqual( outbox.forItem( 1 ).map( ( entry ) => entry.id ), [ 'a', 'c', 'd' ] );
    assert.ok( outbox.all()[ 0 ].created > 0 );

    // Reopening a step discards the step's entry and the following ones of the item.
    const removed = await outbox.removeItem( 1, ( entry ) => [ 'c', 'd' ].includes( entry.id ) );

    assert.deepEqual( removed.map( ( entry ) => entry.id ), [ 'c', 'd' ] );
    assert.deepEqual( outbox.all().map( ( entry ) => entry.id ), [ 'a', 'b' ] );

    await outbox.remove( 'a' );
    assert.equal( outbox.hasItem( 1 ), false );
    assert.equal( outbox.hasItem( 2 ), true );

    // Conflict: all entries of an item.
    await outbox.removeItem( 2 );
    assert.equal( outbox.count(), 0 );
} );

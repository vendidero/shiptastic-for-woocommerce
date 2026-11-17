/**
 * Unit tests for the packaging suggestions (assets/js/fulfillment/packing.js).
 *
 * Run: npm run test:js (node --test, no browser or WordPress required).
 */
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { getPackagingOptions, proposeShipments, checkFit, compare, toUnits, clearCache, choosePackaging } from '../../assets/js/fulfillment/packing.js';

const box = ( id, width, length, depth, extra = {} ) => ( {
    id,
    title: 'Box ' + id,
    providers: extra.providers || [],
    packing: { width, length, depth, emptyWeight: extra.emptyWeight || 100, maxWeight: undefined === extra.maxWeight ? 10000 : extra.maxWeight, costs: extra.costs || 0, shippingClasses: extra.shippingClasses || [] },
} );

const item = ( id, quantity, width, length, depth, weight = 500, shippingClass = 0 ) => ( { id, name: 'Item ' + id, quantity, packing: { width, length, depth, weight, shippingClass } } );

const small = box( 1, 200, 150, 100 );
const medium = box( 2, 300, 250, 200 );
const large = box( 3, 600, 400, 400, { maxWeight: 30000 } );
const packaging = [ large, small, medium ];

beforeEach( () => clearCache() );

test( 'single item fits: smallest fitting packaging is suggested', () => {
    const result = getPackagingOptions( { packaging, items: [ item( 10, 1, 100, 100, 50 ) ] } );

    assert.equal( result.suggestion, 1 );
    assert.deepEqual( result.options.map( ( option ) => option.id ), [ 1, 2, 3 ] );
    assert.ok( result.options.every( ( option ) => option.fits ) );
    assert.ok( result.options[ 0 ].fillVolume > 0 && result.options[ 0 ].fillVolume < 1 );
} );

test( 'item too large for all packaging', () => {
    const result = getPackagingOptions( { packaging, items: [ item( 10, 1, 900, 100, 100 ) ] } );

    assert.equal( result.suggestion, 0 );
    assert.deepEqual( result.tooLarge, [ 10 ] );
    assert.ok( result.options.every( ( option ) => ! option.fits ) );
} );

test( 'items are rotated to fit', () => {
    // 140 x 190 x 90 fits the small box (200 x 150 x 100) when rotated.
    assert.equal( getPackagingOptions( { packaging, items: [ item( 10, 1, 140, 190, 90 ) ] } ).suggestion, 1 );
} );

test( '3D packing: volume fits but the arrangement does not', () => {
    // Two 150 x 150 x 60 items: the volume fits the small box (200 x 150 x 100) but they cannot be placed side by side or stacked.
    const result = checkFit( small, toUnits( [ item( 10, 2, 150, 150, 60 ) ] ) );

    assert.equal( result.fits, false );
    assert.equal( getPackagingOptions( { packaging, items: [ item( 10, 2, 150, 150, 60 ) ] } ).suggestion, 2 );
} );

test( 'max content weight', () => {
    const light = box( 4, 200, 150, 100, { maxWeight: 1000 } );
    const result = getPackagingOptions( { packaging: [ light, medium ], items: [ item( 10, 3, 50, 50, 50, 500 ) ] } );

    assert.equal( result.suggestion, 2 );
    assert.equal( result.options.find( ( option ) => 4 === option.id ).fits, false );
} );

test( 'packaging without max weight has no weight limit', () => {
    const unlimited = box( 4, 200, 150, 100, { maxWeight: 0 } );
    const result = getPackagingOptions( { packaging: [ unlimited ], items: [ item( 10, 1, 50, 50, 50, 900000 ) ] } );

    assert.equal( result.suggestion, 4 );
    assert.equal( result.options[ 0 ].fillWeight, null );
} );

test( 'missing dimensions: checked by weight only', () => {
    const result = getPackagingOptions( { packaging, items: [ item( 10, 1, 0, 0, 0, 500 ), item( 11, 1, 100, 100, 50 ) ] } );

    assert.equal( result.suggestion, 1 );
    assert.deepEqual( result.missingDimensions, [ 10 ] );
} );

test( 'default packaging is preferred in case it fits', () => {
    assert.equal( getPackagingOptions( { packaging, items: [ item( 10, 1, 100, 100, 50 ) ], defaultPackagingId: 2 } ).suggestion, 2 );
    // Not fitting: the best fitting packaging instead.
    assert.equal( getPackagingOptions( { packaging, items: [ item( 10, 1, 350, 100, 50 ) ], defaultPackagingId: 2 } ).suggestion, 3 );
} );

test( 'ranking: restricted packaging first, then volume, empty weight, max weight, costs last', () => {
    const restricted = box( 5, 600, 400, 400, { shippingClasses: [ 7 ] } );
    const cheap = box( 6, 200, 150, 100, { costs: 1 } );
    const expensive = box( 7, 200, 150, 100, { costs: 5 } );
    const heavy = box( 8, 200, 150, 100, { emptyWeight: 300 } );
    const sorted = [ heavy, expensive, small, restricted, cheap ].sort( compare ).map( ( candidate ) => candidate.id );

    assert.deepEqual( sorted, [ 5, 1, 6, 7, 8 ] );
} );

test( 'restrictions: shipping classes and providers', () => {
    const restricted = box( 5, 200, 150, 100, { shippingClasses: [ 7 ] } );
    const dhlOnly = box( 6, 200, 150, 100, { providers: [ 'dhl' ] } );

    // Restricted packaging only holds items of its shipping classes (and is ranked first).
    assert.equal( getPackagingOptions( { packaging: [ restricted, medium ], items: [ item( 10, 1, 100, 100, 50, 500, 7 ) ] } ).suggestion, 5 );
    assert.equal( getPackagingOptions( { packaging: [ restricted, medium ], items: [ item( 10, 1, 100, 100, 50, 500, 8 ) ] } ).suggestion, 2 );
    assert.equal( getPackagingOptions( { packaging: [ restricted, medium ], items: [ item( 10, 1, 100, 100, 50 ) ] } ).suggestion, 2 );
    // Provider restrictions
    assert.equal( getPackagingOptions( { packaging: [ dhlOnly, medium ], items: [ item( 10, 1, 100, 100, 50 ) ], provider: 'dhl' } ).suggestion, 6 );
    assert.equal( getPackagingOptions( { packaging: [ dhlOnly, medium ], items: [ item( 10, 1, 100, 100, 50 ) ], provider: 'ups' } ).suggestion, 2 );
} );

test( 'options: fitting first, others after', () => {
    const result = getPackagingOptions( { packaging, items: [ item( 10, 1, 280, 100, 50 ) ] } );

    assert.deepEqual( result.options.map( ( option ) => [ option.id, option.fits ] ), [ [ 2, true ], [ 3, true ], [ 1, false ] ] );
} );

test( 'split: one shipment in case everything fits one packaging', () => {
    const result = proposeShipments( { packaging, items: [ item( 10, 2, 100, 100, 50 ) ] } );

    assert.equal( result.shipments.length, 1 );
    assert.deepEqual( result.shipments[ 0 ], { items: { 10: 2 }, packaging_id: 1 } );
} );

test( 'split: as few shipments as possible, best packaging per shipment', () => {
    // Each item fills the large box (600 x 400 x 400) by half: 3 items -> 2 shipments.
    const result = proposeShipments( { packaging, items: [ item( 10, 3, 600, 400, 200, 1000 ) ] } );
    const quantities = result.shipments.map( ( shipment ) => shipment.items[ 10 ] ).sort();

    assert.equal( result.shipments.length, 2 );
    assert.deepEqual( quantities, [ 1, 2 ] );
    assert.ok( result.shipments.every( ( shipment ) => 3 === shipment.packaging_id ) );
} );

test( 'split: by weight', () => {
    // 4 x 9 kg, the large box holds 30 kg: 2 shipments.
    const result = proposeShipments( { packaging, items: [ item( 10, 4, 100, 100, 100, 9000 ) ] } );

    assert.equal( result.shipments.length, 2 );
    assert.equal( result.shipments.reduce( ( sum, shipment ) => sum + shipment.items[ 10 ], 0 ), 4 );
} );

test( 'split: items too large are proposed without packaging', () => {
    const result = proposeShipments( { packaging, items: [ item( 10, 1, 100, 100, 50 ), item( 11, 1, 900, 100, 100 ) ] } );

    assert.deepEqual( result.tooLarge, [ 11 ] );
    assert.deepEqual( result.shipments, [ { items: { 10: 1 }, packaging_id: 1 }, { items: { 11: 1 }, packaging_id: 0 } ] );
} );

test( 'many units: weight and volume only', () => {
    const result = getPackagingOptions( { packaging, items: [ item( 10, 150, 10, 10, 10, 10 ) ], maxItems: 100 } );

    assert.equal( result.suggestion, 1 );
} );

test( 'memoization: same composition returns the cached result', () => {
    const args = { packaging, items: [ item( 10, 1, 100, 100, 50 ) ] };

    assert.equal( getPackagingOptions( args ), getPackagingOptions( { ...args, items: [ item( 10, 1, 100, 100, 50 ) ] } ) );
    assert.notEqual( getPackagingOptions( args ), getPackagingOptions( { ...args, items: [ item( 10, 2, 100, 100, 50 ) ] } ) );
} );

test( 'a fitting manual choice is kept, otherwise the suggestion is applied', () => {
    const fitsAll = getPackagingOptions( { packaging, items: [ item( 10, 1, 100, 100, 50 ) ] } );
    const fitsLarge = getPackagingOptions( { packaging, items: [ item( 10, 1, 500, 100, 50 ) ] } );

    // Manual choice (medium) still fits: kept.
    assert.deepEqual( choosePackaging( { current: 2, manual: true, result: fitsAll } ), { id: 2, suggested: false, replaced: 0 } );
    // Manual choice (medium) no longer fits: replaced by the suggestion.
    assert.deepEqual( choosePackaging( { current: 2, manual: true, result: fitsLarge } ), { id: 3, suggested: true, replaced: 2 } );
    // Not chosen manually: always the suggestion.
    assert.deepEqual( choosePackaging( { current: 3, manual: false, result: fitsAll } ), { id: 1, suggested: true, replaced: 0 } );
    // Nothing fits: no packaging.
    assert.deepEqual( choosePackaging( { current: 0, manual: false, result: getPackagingOptions( { packaging, items: [ item( 10, 1, 900, 100, 50 ) ] } ) } ), { id: 0, suggested: false, replaced: 0 } );
} );

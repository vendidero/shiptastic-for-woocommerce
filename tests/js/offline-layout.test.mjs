/**
 * Unit tests for offline shipment editing (assets/js/fulfillment/offline-layout.js).
 *
 * Run: npm run test:js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { applyLayout, mergeShipments, planSteps, mapShipmentIds, mapStepKey } from '../../assets/js/fulfillment/offline-layout.js';

const shipment = ( id, items, extra = {} ) => ( { id, key: 'shipment-' + id, editable: true, packaging_id: 0, items, ...extra } );

const steps = [
    { key: 'create_shipments', action: 'create_shipments', scope: 'order', mode: 'background', offline: false, resets: false, shipment_id: 0, is_done: true },
    { key: 'pick', action: 'pick', scope: 'order', mode: 'interactive', offline: true, resets: true, shipment_id: 0, is_done: true },
    { key: 'pack:10', action: 'pack', scope: 'shipment', mode: 'interactive', offline: true, resets: false, shipment_id: 10, is_done: true },
    { key: 'pack:11', action: 'pack', scope: 'shipment', mode: 'interactive', offline: true, resets: false, shipment_id: 11, is_done: false },
    { key: 'mark_ready', action: 'mark_ready', scope: 'order', mode: 'background', offline: false, resets: false, shipment_id: 0, is_done: false },
];

const item = {
    id: 1,
    steps,
    views: { 'pack:11': { shipmentId: 11, items: [] } },
    layout: { items: [], shipments: [ shipment( 10, { 1: 2 } ), shipment( 11, { 2: 1 } ) ] },
};

const buildView = ( step ) => ( { built: step.key } );

test( 'moving items between shipments: changed shipments are reopened, totals unchanged', () => {
    const result = applyLayout( item, [ shipment( 10, { 1: 1 } ), shipment( 11, { 1: 1, 2: 1 } ) ], buildView );

    assert.deepEqual( result.changed, [ '10', '11' ] );
    assert.equal( result.totalsChanged, false );
    assert.deepEqual( result.item.steps.map( ( step ) => step.key + ':' + step.is_done ), [ 'create_shipments:true', 'pick:true', 'pack:10:false', 'pack:11:false', 'mark_ready:false' ] );
    // Both pack views are rebuilt.
    assert.deepEqual( result.item.views, { 'pack:10': { built: 'pack:10' }, 'pack:11': { built: 'pack:11' } } );
} );

test( 'new shipment (temporary key) and changed totals reopen pick', () => {
    const result = applyLayout( item, [ shipment( 10, { 1: 2 } ), shipment( 11, { 2: 1 } ), { key: 'new-ab', id: 0, editable: true, items: { 3: 1 } } ], buildView );

    assert.deepEqual( result.changed, [ 'new-ab' ] );
    assert.equal( result.totalsChanged, true );
    assert.deepEqual( result.item.steps.map( ( step ) => step.key + ':' + step.is_done ), [ 'create_shipments:true', 'pick:false', 'pack:10:true', 'pack:11:false', 'pack:new-ab:false', 'mark_ready:false' ] );
    // Unchanged open step keeps its view, others are built.
    assert.deepEqual( Object.keys( result.item.views ).sort(), [ 'pack:11', 'pack:new-ab', 'pick' ] );
    assert.equal( result.item.views[ 'pack:11' ].shipmentId, 11 );
    assert.equal( result.item.steps.find( ( step ) => 'pack:new-ab' === step.key ).shipment_id, 'new-ab' );
} );

test( 'removed shipments and views which cannot be built offline', () => {
    const result = applyLayout( item, [ shipment( 10, { 1: 2, 2: 1 } ), shipment( 11, {} ) ], () => null );

    assert.deepEqual( result.changed, [ '10', '11' ] );
    assert.deepEqual( result.item.layout.shipments.map( ( candidate ) => candidate.id ), [ 10 ] );
    assert.deepEqual( result.item.steps.map( ( step ) => step.key ), [ 'create_shipments', 'pick', 'pack:10', 'mark_ready' ] );
    assert.deepEqual( result.item.views, {} );
} );

test( 'locked shipments keep their items', () => {
    const merged = mergeShipments( [ shipment( 10, { 1: 2 }, { editable: false } ), shipment( 11, { 2: 1 } ) ], [ shipment( 11, { 2: 1 } ) ] );

    assert.deepEqual( merged.changed, [] );
    assert.deepEqual( merged.shipments.map( ( candidate ) => candidate.id ), [ 10, 11 ] );
} );

test( 'shipment scope steps planned without shipments are expanded', () => {
    const planned = planSteps( [ { key: 'pack', action: 'pack', scope: 'shipment', shipment_id: 0, is_done: false } ], [ { id: 0, key: 'new-x', items: { 1: 1 } } ], [ 'new-x' ], true );

    assert.deepEqual( planned.map( ( step ) => step.key ), [ 'pack:new-x' ] );
} );

test( 'temporary keys are replaced with the server ids', () => {
    const local = applyLayout( item, [ shipment( 10, { 1: 2 } ), shipment( 11, { 2: 1 } ), { key: 'new-ab', id: 0, editable: true, items: { 3: 1 } } ], buildView ).item;
    local.current = { status: 'needs_input', step: local.steps[ 4 ], view_data: { shipmentId: 'new-ab' } };
    local.offline_data = { 'pack:new-ab': { packed: { 3: 1 } } };

    const mapped = mapShipmentIds( local, { 'new-ab': 12 } );

    assert.equal( mapped.steps[ 4 ].key, 'pack:12' );
    assert.equal( mapped.steps[ 4 ].shipment_id, 12 );
    assert.equal( mapped.current.step.key, 'pack:12' );
    assert.equal( mapped.current.view_data.shipmentId, 12 );
    assert.ok( mapped.views[ 'pack:12' ] );
    assert.ok( mapped.offline_data[ 'pack:12' ] );
    assert.deepEqual( mapped.layout.shipments[ 2 ], { key: 'shipment-12', id: 12, editable: true, items: { 3: 1 } } );
    assert.equal( mapStepKey( 'pack:new-ab', { 'new-ab': 12 } ), 'pack:12' );
    assert.equal( mapStepKey( 'pick', { 'new-ab': 12 } ), 'pick' );
} );

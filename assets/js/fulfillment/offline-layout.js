/**
 * Offline shipment editing: applies a shipment layout (shipments overlay) to a queue item locally, like the server
 * does (ShipmentLayout::apply() + reopen_steps() + the step plan of Runner::get_steps()):
 *
 * - shipments without items are removed, locked (non-editable) shipments are kept as they are,
 * - shipment scope steps are planned per shipment (new shipments use their temporary key, e.g. pack:new-1a2b),
 *   steps of changed shipments are reopened,
 * - order scope steps of actions depending on the shipment contents (`resets`) are reopened in case the total
 *   quantities changed,
 * - views of open offline capable steps are rebuilt (buildView, e.g. callbacks.offlineView_pick) or dropped in case
 *   they cannot be built offline (the order then waits for the connection at that step).
 *
 * Pure functions (no store access), see tests/js/offline-layout.test.mjs.
 */

/**
 * @param {Object} shipment
 * @return {string} The shipment's id or temporary key (new shipments).
 */
export function shipmentTarget( shipment ) {
    return shipment.id ? String( shipment.id ) : String( shipment.key );
}

/**
 * @param {Object} items line key => quantity
 * @return {Object} Positive quantities, sorted by line key.
 */
function normalizeItems( items ) {
    const normalized = {};

    Object.keys( items || {} ).sort().forEach( ( lineKey ) => {
        const quantity = parseInt( items[ lineKey ], 10 ) || 0;

        if ( quantity > 0 ) {
            normalized[ lineKey ] = quantity;
        }
    } );

    return normalized;
}

const same = ( a, b ) => JSON.stringify( normalizeItems( a ) ) === JSON.stringify( normalizeItems( b ) );

/**
 * @param {Object[]} shipments
 * @return {Object} line key => total quantity
 */
export function totalQuantities( shipments ) {
    const totals = {};

    shipments.forEach( ( shipment ) => {
        Object.entries( normalizeItems( shipment.items ) ).forEach( ( [ lineKey, quantity ] ) => {
            totals[ lineKey ] = ( totals[ lineKey ] || 0 ) + quantity;
        } );
    } );

    return normalizeItems( totals );
}

/**
 * @param {Object[]} existing  Shipments of the current layout.
 * @param {Object[]} submitted Editable shipments of the overlay (id or temporary key, items, props).
 * @return {Object} shipments (new layout), changed (targets with changed items, incl. removed and new ones)
 */
export function mergeShipments( existing, submitted ) {
    const shipments = [];
    const changed = [];
    const kept = [];

    submitted.forEach( ( shipment ) => {
        const items = normalizeItems( shipment.items );
        const target = shipmentTarget( shipment );
        const before = existing.find( ( candidate ) => shipmentTarget( candidate ) === target ) || null;

        // Locked shipments keep their items.
        if ( before && ! before.editable ) {
            return;
        }

        if ( 0 === Object.keys( items ).length ) {
            return;
        }

        if ( ! before || ! same( before.items, items ) ) {
            changed.push( target );
        }

        kept.push( target );
        shipments.push( { ...( before || {} ), ...shipment, items } );
    } );

    existing.forEach( ( shipment ) => {
        const target = shipmentTarget( shipment );

        if ( ! shipment.editable ) {
            // Locked shipments: kept at their position.
            shipments.splice( Math.min( existing.indexOf( shipment ), shipments.length ), 0, shipment );
        } else if ( ! kept.includes( target ) ) {
            changed.push( target );
        }
    } );

    return { shipments, changed };
}

/**
 * Plans the steps for the new shipments: shipment scope steps are expanded per shipment at the position of the
 * action's first step, steps of changed shipments are reopened, order scope steps of actions depending on the
 * shipment contents are reopened in case the totals changed.
 *
 * @param {Object[]} steps
 * @param {Object[]} shipments     The new layout's shipments.
 * @param {string[]} changed       Changed shipment targets.
 * @param {boolean}  totalsChanged
 * @return {Object[]}
 */
export function planSteps( steps, shipments, changed, totalsChanged ) {
    const planned = [];
    const expanded = [];

    steps.forEach( ( step ) => {
        if ( 'shipment' !== step.scope ) {
            planned.push( totalsChanged && step.resets ? { ...step, is_done: false } : step );
            return;
        }

        if ( expanded.includes( step.action ) ) {
            return;
        }

        expanded.push( step.action );

        const existing = steps.filter( ( candidate ) => candidate.action === step.action );
        const targets = shipments.length > 0 ? shipments.map( shipmentTarget ) : [ '0' ];

        targets.forEach( ( target ) => {
            const before = existing.find( ( candidate ) => String( candidate.shipment_id ) === target ) || null;
            const isDone = !! before && before.is_done && ! changed.includes( target );

            planned.push( {
                ...( before || step ),
                key: '0' === target ? step.action : step.action + ':' + target,
                shipment_id: '0' === target ? 0 : ( /^\d+$/.test( target ) ? parseInt( target, 10 ) : target ),
                is_done: isDone,
            } );
        } );
    } );

    return planned;
}

/**
 * Applies a layout to a queue item locally.
 *
 * @param {Object}   item
 * @param {Object[]} submitted Editable shipments of the overlay.
 * @param {Function} buildView ( step, item ) => view data or null in case the view cannot be built offline.
 * @return {Object} item (updated layout, steps, views), changed (shipment targets), totalsChanged
 */
export function applyLayout( item, submitted, buildView ) {
    const layout = item.layout || { items: [], shipments: [] };
    const merged = mergeShipments( layout.shipments || [], submitted );
    const totalsChanged = JSON.stringify( totalQuantities( layout.shipments || [] ) ) !== JSON.stringify( totalQuantities( merged.shipments ) );
    const steps = planSteps( item.steps || [], merged.shipments, merged.changed, totalsChanged );
    const next = { ...item, layout: { ...layout, shipments: merged.shipments }, steps };
    const views = {};

    steps.forEach( ( step ) => {
        if ( step.is_done || ! step.offline ) {
            return;
        }

        const before = ( item.steps || [] ).find( ( candidate ) => candidate.key === step.key );
        const unchanged = before && ! before.is_done && ( 'shipment' === step.scope ? ! merged.changed.includes( String( step.shipment_id ) ) : ! ( totalsChanged && step.resets ) );

        if ( unchanged && item.views && item.views[ step.key ] ) {
            views[ step.key ] = item.views[ step.key ];
            return;
        }

        const view = buildView( step, next );

        if ( view ) {
            views[ step.key ] = view;
        }
    } );

    next.views = views;

    return { item: next, changed: merged.changed, totalsChanged };
}

/**
 * Replaces temporary shipment keys (new shipments created offline) with the ids assigned by the server.
 *
 * @param {Object} item
 * @param {Object} idMap temporary key => shipment id
 * @return {Object}
 */
export function mapShipmentIds( item, idMap ) {
    const mapKey = ( key ) => {
        const [ action, target ] = String( key ).split( ':' );
        return undefined !== target && idMap[ target ] ? action + ':' + idMap[ target ] : key;
    };
    const mapStep = ( step ) => ( step ? { ...step, key: mapKey( step.key ), shipment_id: idMap[ step.shipment_id ] || step.shipment_id } : step );
    const mapKeys = ( object ) => Object.fromEntries( Object.entries( object || {} ).map( ( [ key, value ] ) => [ mapKey( key ), value ] ) );
    const mapView = ( view ) => ( view && idMap[ view.shipmentId ] ? { ...view, shipmentId: idMap[ view.shipmentId ] } : view );

    return {
        ...item,
        steps: ( item.steps || [] ).map( mapStep ),
        views: Object.fromEntries( Object.entries( mapKeys( item.views ) ).map( ( [ key, view ] ) => [ key, mapView( view ) ] ) ),
        offline_data: mapKeys( item.offline_data ),
        current: item.current ? { ...item.current, step: mapStep( item.current.step ), view_data: mapView( item.current.view_data ) } : item.current,
        layout: item.layout ? {
            ...item.layout,
            shipments: ( item.layout.shipments || [] ).map( ( shipment ) => ( ! shipment.id && idMap[ shipment.key ] ? { ...shipment, id: idMap[ shipment.key ], key: 'shipment-' + idMap[ shipment.key ] } : shipment ) ),
        } : item.layout,
    };
}

/**
 * @param {string} key   Step key, e.g. pack:new-1a2b
 * @param {Object} idMap
 * @return {string}
 */
export function mapStepKey( key, idMap ) {
    const [ action, target ] = String( key ).split( ':' );
    return undefined !== target && idMap[ target ] ? action + ':' + idMap[ target ] : key;
}

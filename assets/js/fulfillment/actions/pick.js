/**
 * Pick step: picked quantities per order item, verified via the shared check list (scan, swipe, steppers).
 */
import { store, getConfig } from '@wordpress/interactivity';

const { state, callbacks } = store( 'shiptastic/fulfillment', {
    callbacks: {
        validate_pick() {
            return callbacks.validateChecklist( getConfig( 'shiptastic/fulfillment' ).i18n.notPicked );
        },
        initForm_pick( view ) {
            // Prefilled in case the step was reopened (or failed before).
            return { counted: view.previous && view.previous.picked ? { ...view.previous.picked } : {} };
        },
        collect_pick() {
            return { picked: { ...state.form.counted } };
        },
        /**
         * Pick list rebuilt from the shipments layout, e.g. after the shipments were edited offline: quantities of
         * all package shipments, sorted along the walking path (sort key of the server).
         *
         * @param {Object} item The queue item (layout).
         * @return {Object|null} The view data, null in case it cannot be built offline.
         */
        offlineView_pick( item ) {
            const layout = item.layout || {};
            const totals = {};

            ( layout.shipments || [] ).forEach( ( shipment ) => {
                Object.entries( shipment.items || {} ).forEach( ( [ lineKey, quantity ] ) => {
                    totals[ lineKey ] = ( totals[ lineKey ] || 0 ) + ( parseInt( quantity, 10 ) || 0 );
                } );
            } );

            const lineKeys = Object.keys( totals ).filter( ( lineKey ) => totals[ lineKey ] > 0 );
            const lines = ( layout.items || [] ).filter( ( line ) => lineKeys.includes( String( line.id ) ) );

            if ( lines.length !== lineKeys.length ) {
                return null;
            }

            const items = lines.map( ( { total, backordered, ...line } ) => ( { ...line, quantity: totals[ String( line.id ) ] } ) );

            items.sort( ( a, b ) => String( a.sort || '' ).localeCompare( String( b.sort || '' ) ) );

            return { items };
        },
    },
} );

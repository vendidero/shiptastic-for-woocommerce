/**
 * Item check list shared by steps verifying items (pick, pack): counts per order item in `state.form.counted`,
 * changed by scanning (SKU/GTIN, keyboard wedge scanners), swiping (right: +1, left: -1), steppers or "all".
 * The list items are `state.view.items` (id, name, sku, gtin, quantity, location).
 *
 * Steps using the list validate it via callbacks.validateChecklist( message ), e.g. as callbacks.validate_pick.
 */
import { store, getContext, getConfig, withSyncEvent } from '@wordpress/interactivity';

const NAMESPACE = 'shiptastic/fulfillment';

const { state, actions, callbacks } = store( NAMESPACE, {
    state: {
        checklist: {
            get count() {
                const { item } = getContext();
                return state.form.counted && state.form.counted[ item.id ] ? state.form.counted[ item.id ] : 0;
            },
            get isItemComplete() {
                const { item } = getContext();
                return state.checklist.count >= item.quantity;
            },
            get isComplete() {
                return ( state.view.items || [] ).every( ( item ) => ( state.form.counted[ item.id ] || 0 ) >= item.quantity );
            },
            get isItemMissing() {
                return state.showValidation && ! state.checklist.isItemComplete;
            },
            get checked() {
                return ( state.view.items || [] ).reduce( ( sum, item ) => sum + Math.min( item.quantity, state.form.counted[ item.id ] || 0 ), 0 );
            },
            get total() {
                return ( state.view.items || [] ).reduce( ( sum, item ) => sum + item.quantity, 0 );
            },
            get progressText() {
                return getConfig( NAMESPACE ).i18n.checklistProgress.replace( '%1$d', state.checklist.checked ).replace( '%2$d', state.checklist.total );
            },
            get progressStyle() {
                return 'width: ' + ( state.checklist.total ? Math.round( ( state.checklist.checked / state.checklist.total ) * 100 ) : 0 ) + '%';
            },
        },
    },
    callbacks: {
        /**
         * @param {string} message Message containing %s for the list of missing items.
         * @return {string} The error message, empty in case all items are checked.
         */
        validateChecklist( message ) {
            const missing = ( state.view.items || [] )
                .filter( ( item ) => ( state.form.counted[ item.id ] || 0 ) < item.quantity )
                .map( ( item ) => ( state.form.counted[ item.id ] || 0 ) + '/' + item.quantity + ' × ' + item.name );

            return missing.length > 0 ? message.replace( '%s', missing.join( ', ' ) ) : '';
        },
    },
    actions: {
        checklist: {
            set( item, quantity ) {
                state.form.counted = { ...state.form.counted, [ item.id ]: Math.max( 0, Math.min( item.quantity, quantity ) ) };
            },
            increment() {
                const { item } = getContext();
                actions.checklist.set( item, state.checklist.count + 1 );
            },
            decrement() {
                const { item } = getContext();
                actions.checklist.set( item, state.checklist.count - 1 );
            },
            onSwipe( event ) {
                const { item } = getContext();
                actions.checklist.set( item, state.checklist.count + ( 'right' === event.detail.direction ? 1 : -1 ) );
            },
            all() {
                ( state.view.items || [] ).forEach( ( item ) => actions.checklist.set( item, item.quantity ) );
            },
            onScanKeydown: withSyncEvent( ( event ) => {
                if ( 'Enter' !== event.key ) {
                    return;
                }

                event.preventDefault();

                const code = event.target.value.trim().toLowerCase();
                event.target.value = '';

                if ( ! code ) {
                    return;
                }

                const matches = ( state.view.items || [] ).filter( ( item ) => [ item.sku, item.gtin ].filter( Boolean ).map( ( value ) => String( value ).toLowerCase() ).includes( code ) );
                const item = matches.find( ( candidate ) => ( state.form.counted[ candidate.id ] || 0 ) < candidate.quantity ) || matches[ 0 ];

                if ( ! item ) {
                    actions.notify( getConfig( NAMESPACE ).i18n.unknownCode, 'error', 3000 );
                    return;
                }

                actions.checklist.set( item, ( state.form.counted[ item.id ] || 0 ) + 1 );
            } ),
        },
    },
} );

export { callbacks };

/**
 * Pack step: verify the shipment's items via the shared check list (scan, swipe, steppers). Shipment details
 * (provider, weight, dimensions, items) are edited via the shipments overlay.
 *
 * Packaging: the shipment's packaging is kept as long as the items fit, otherwise the best fitting packaging is
 * applied ("Suggested", see packing.js) and submitted with the step. In case no packaging fits, the packaging needs
 * to be chosen manually.
 */
import { store, getConfig } from '@wordpress/interactivity';
import { choosePackaging } from '../packing.js';
import { getPackagingResult, getPackagingSelectOptions, getPackagingWarnings } from '../shipments.js';

const NAMESPACE = 'shiptastic/fulfillment';
const i18n = () => getConfig( NAMESPACE ).i18n;
const format = ( text, ...values ) => {
    let index = 0;
    return String( text || '' ).replace( /%(\d+\$)?[sd]/g, ( match, position ) => String( position ? values[ parseInt( position, 10 ) - 1 ] : values[ index++ ] ) );
};

function getPackaging( id ) {
    return state.packaging.find( ( packaging ) => packaging.id === id ) || null;
}

function packResult( view ) {
    return getPackagingResult( state.packaging, view.items || [], view.provider || '' );
}

/**
 * @param {Object} view
 * @return {boolean} Whether the packaging needs to be chosen manually (no packaging fits the items).
 */
function needsChoice( view ) {
    return state.packaging.length > 0 && ( view.items || [] ).length > 0 && ! packResult( view ).suggestion;
}

const { state, callbacks } = store( NAMESPACE, {
    state: {
        pack: {
            /**
             * Empty unless a pack step is shown: lists within selects must be empty while the page hydrates (the
             * options are rendered client-side only, server-side rendered HTML would not match).
             */
            get packagingOptions() {
                if ( ! state.view || ! state.view.shipmentId ) {
                    return [];
                }

                return getPackagingSelectOptions( packResult( state.view ), state.form.packaging_id || 0 );
            },
            get isSuggested() {
                return !! state.form.packagingSuggested && state.form.packaging_id > 0;
            },
            get notice() {
                return state.form.packagingNotice || '';
            },
            get warning() {
                return getPackagingWarnings( packResult( state.view ), state.view.items || [] );
            },
            get hasPackaging() {
                return state.packaging.length > 0;
            },
            /**
             * Dimensions of the chosen packaging in case it differs from the shipment's packaging (otherwise the
             * shipment's dimensions).
             */
            get dimensions() {
                if ( state.form.packaging_id === state.view.packagingId ) {
                    return state.view.dimensions || '';
                }

                const packaging = getPackaging( state.form.packaging_id );

                if ( ! packaging ) {
                    return '';
                }

                const locale = document.documentElement.lang || undefined;

                return [ packaging.length, packaging.width, packaging.height ].map( ( value ) => Number( value || 0 ).toLocaleString( locale, { maximumFractionDigits: 2 } ) ).join( ' × ' ) + ' ' + ( getConfig( NAMESPACE ).packing || {} ).dimensionUnit;
            },
        },
    },
    actions: {
        pack: {
            setPackaging( event ) {
                state.form.packaging_id = parseInt( event.target.value, 10 ) || 0;
                state.form.packagingManual = true;
                state.form.packagingSuggested = false;
                state.form.packagingNotice = '';
            },
        },
    },
    callbacks: {
        validate_pack() {
            const error = callbacks.validateChecklist( i18n().notPacked );

            if ( error ) {
                return error;
            }

            return needsChoice( state.view ) && ! state.form.packagingManual ? ( i18n().packaging || {} ).chooseManually : '';
        },
        initForm_pack( view ) {
            const form = {
                // Prefilled in case the step was reopened (or failed before).
                counted: view.previous && view.previous.packed ? { ...view.previous.packed } : {},
                packaging_id: view.packagingId || 0,
                packagingManual: false,
                packagingSuggested: false,
                packagingNotice: '',
            };

            if ( view.previous && view.previous.packaging_id ) {
                form.packaging_id = parseInt( view.previous.packaging_id, 10 ) || 0;
                form.packagingManual = true;
            } else if ( state.packaging.length > 0 && ! needsChoice( view ) ) {
                // The shipment's packaging is kept as long as the items fit.
                const choice = choosePackaging( { current: form.packaging_id, manual: form.packaging_id > 0, result: packResult( view ) } );
                const replaced = getPackaging( choice.replaced );
                const chosen = getPackaging( choice.id );

                form.packaging_id = choice.id;
                form.packagingSuggested = choice.suggested;
                form.packagingNotice = replaced && chosen ? format( ( i18n().packaging || {} ).changed, replaced.title, chosen.title ) : '';
            }

            return form;
        },
        /**
         * Pack view rebuilt from the shipments layout, e.g. after the shipments were edited offline (see
         * Pack::get_view_data()).
         *
         * @param {Object} item The queue item (layout).
         * @param {Object} step The step (shipment_id: id or temporary key of a new shipment).
         * @return {Object|null} The view data, null in case it cannot be built offline.
         */
        offlineView_pack( item, step ) {
            const layout = item.layout || {};
            const target = String( step.shipment_id );
            const shipment = ( layout.shipments || [] ).find( ( candidate ) => String( candidate.id || candidate.key ) === target );

            if ( ! shipment ) {
                return null;
            }

            const items = [];

            for ( const [ lineKey, quantity ] of Object.entries( shipment.items || {} ) ) {
                const line = ( layout.items || [] ).find( ( candidate ) => String( candidate.id ) === lineKey );

                if ( ! line ) {
                    return null;
                }

                const { total, backordered, sort, product_id: productId, warehouse, ...data } = line;
                items.push( { ...data, quantity: parseInt( quantity, 10 ) || 0, location: '' } );
            }

            const packaging = getPackaging( shipment.packaging_id || 0 );
            const unit = packaging ? ( getConfig( NAMESPACE ).packing || {} ).dimensionUnit : getConfig( NAMESPACE ).dimensionUnit;
            const dimensions = ( packaging ? [ packaging.length, packaging.width, packaging.height ] : [ shipment.length, shipment.width, shipment.height ] ).map( ( value ) => Number( value || 0 ) );
            const locale = document.documentElement.lang || undefined;

            return {
                shipmentId: shipment.id || shipment.key,
                items,
                packaging: packaging ? packaging.title : '',
                packagingId: shipment.packaging_id || 0,
                provider: shipment.provider || '',
                dimensions: dimensions.some( ( value ) => value > 0 ) ? dimensions.map( ( value ) => value.toLocaleString( locale, { maximumFractionDigits: 2 } ) ).join( ' × ' ) + ' ' + unit : '',
            };
        },
        collect_pack() {
            const data = { packed: { ...state.form.counted } };

            // Only submitted in case the packaging changed (the suggestion or a manual choice).
            if ( ( state.form.packaging_id || 0 ) !== ( state.view.packagingId || 0 ) ) {
                data.packaging_id = state.form.packaging_id || 0;
            }

            return data;
        },
    },
} );

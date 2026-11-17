/**
 * Shipments overlay (available during the whole run) and the shipment fields shared with steps editing a shipment
 * (e.g. pack).
 *
 * The overlay edits a draft copy (`state.overlay`) of the current item's layout (`currentItem.layout`): items not yet
 * part of a shipment (pool, incl. backordered units) and the package shipments. Items are added, removed or moved by
 * drag & drop (handle), swiping (right: +1, left: -1) or steppers. Saving posts the layout to
 * `queue/{id}/shipments`, the response continues the run (e.g. steps of changed shipments are reopened).
 *
 * Shipment fields operate on `context.shipment` (packaging_id, provider, weight, length, width, height, items).
 * Dimensions are locked while a packaging is chosen (the packaging defines them).
 *
 * Packaging suggestions (packing.js): whenever the items (or the provider) of a shipment change, the best fitting
 * packaging is applied ("Suggested"). A manual choice (or the stored packaging) is kept as long as it still fits.
 * Packages without shipments: the shipments are proposed (split into as few packages as possible) when the overlay
 * is opened, no automatic splitting afterwards.
 */
import { store, getContext, getConfig } from '@wordpress/interactivity';
import * as api from './api.js';
import * as hooks from './hooks.js';
import { getPackagingOptions, proposeShipments, choosePackaging } from './packing.js';

const NAMESPACE = 'shiptastic/fulfillment';
const i18n = () => getConfig( NAMESPACE ).i18n;
const packingConfig = () => getConfig( NAMESPACE ).packing || {};
const format = ( text, ...values ) => {
    let index = 0;
    return text.replace( /%(\d+\$)?[sd]/g, ( match, position ) => String( position ? values[ parseInt( position, 10 ) - 1 ] : values[ index++ ] ) );
};
const round = ( value, decimals = 3 ) => parseFloat( Number( value || 0 ).toFixed( decimals ) );
const copy = ( value ) => JSON.parse( JSON.stringify( value ) );

let counter = 0;

const DIMENSIONS = [ 'length', 'width', 'height' ];

/**
 * Item (line) ids are order item ids or, for inner boxes, strings like "{order_item_id}:{inner_box}".
 * Object keys are strings - restore numeric ids to match the items list.
 *
 * @param {string|number} id
 * @return {string|number} The line id.
 */
function toLineId( id ) {
    return /^\d+$/.test( String( id ) ) ? parseInt( id, 10 ) : String( id );
}

function getItem( id ) {
    const layout = state.currentItem && state.currentItem.layout ? state.currentItem.layout : { items: [] };
    return layout.items.find( ( item ) => item.id === id ) || null;
}

function getPackaging( id ) {
    return state.packaging.find( ( packaging ) => packaging.id === id ) || null;
}

function packagingTitle( id ) {
    const packaging = getPackaging( id );
    return packaging ? packaging.title : '';
}

/**
 * @param {Object[]} packaging Packaging list.
 * @param {Object[]} items     Items incl. quantity and packing data.
 * @param {string}   provider
 * @return {Object} See packing.js getPackagingOptions().
 */
export function getPackagingResult( packaging, items, provider ) {
    return getPackagingOptions( {
        packaging,
        items,
        provider,
        defaultPackagingId: packingConfig().defaultPackaging || 0,
        maxItems: packingConfig().maxItems || undefined,
    } );
}

/**
 * Select options of the packaging list: fitting packaging first (incl. fill rate), others marked "does not fit".
 *
 * @param {Object} result   See getPackagingResult().
 * @param {number} selected Selected packaging id.
 * @return {Object[]} { id, label, fits, selected }
 */
export function getPackagingSelectOptions( result, selected ) {
    const texts = i18n().packaging || {};

    return result.options.map( ( option ) => {
        const fill = Math.max( option.fillVolume || 0, option.fillWeight || 0 );

        return {
            id: option.id,
            fits: option.fits,
            selected: option.id === selected,
            label: option.fits ? format( texts.option, option.title, Math.round( fill * 100 ) + ' %' ) : format( texts.doesNotFit, option.title ),
        };
    } );
}

/**
 * @param {Object} result See getPackagingResult().
 * @param {Object[]} items
 * @return {string} Warnings about items fitting no packaging or without dimensions.
 */
export function getPackagingWarnings( result, items ) {
    const texts = i18n().packaging || {};
    const names = ( ids ) => ids.map( ( id ) => {
        const item = items.find( ( candidate ) => candidate.id === id );
        return item ? item.name : '#' + id;
    } ).join( ', ' );
    const warnings = [];

    if ( result.tooLarge.length > 0 ) {
        warnings.push( format( texts.tooLarge, names( result.tooLarge ) ) );
    } else if ( ! result.suggestion && items.length > 0 && state.packaging.length > 0 ) {
        warnings.push( texts.noneFits );
    }

    if ( result.missingDimensions.length > 0 ) {
        warnings.push( format( texts.missingDimensions, names( result.missingDimensions ) ) );
    }

    return warnings.join( ' ' );
}

function shipmentPackingItems( shipment ) {
    return Object.keys( shipment.items )
        .map( ( id ) => toLineId( id ) )
        .filter( ( id ) => shipment.items[ id ] > 0 )
        .map( ( id ) => {
            const item = getItem( id ) || { id, name: '#' + id, packing: {} };
            return { ...item, quantity: shipment.items[ id ] };
        } );
}

function shipmentPackagingResult( shipment ) {
    return getPackagingResult( state.packaging, shipmentPackingItems( shipment ), shipment.provider );
}

/**
 * Applies the best fitting packaging after the items or the provider changed (keeps a fitting manual choice).
 *
 * @param {Object} shipment
 */
function applySuggestion( shipment ) {
    if ( ! shipment || ! shipment.editable || 0 === state.packaging.length ) {
        return;
    }

    const choice = choosePackaging( { current: shipment.packaging_id, manual: !! shipment.packagingManual, result: shipmentPackagingResult( shipment ) } );

    shipment.packagingNotice = choice.replaced ? format( ( i18n().packaging || {} ).changed, packagingTitle( choice.replaced ), choice.id ? packagingTitle( choice.id ) : ( i18n().packaging || {} ).none ) : '';
    shipment.packagingManual = ! choice.suggested && choice.id > 0 && choice.id === shipment.packaging_id && !! shipment.packagingManual;
    shipment.packagingSuggested = choice.suggested;
    shipment.packaging_id = choice.id;
}

function assigned( id ) {
    return state.overlay.shipments.reduce( ( sum, shipment ) => sum + ( shipment.items[ id ] || 0 ), 0 );
}

function unassigned( id ) {
    const item = state.overlay.items.find( ( candidate ) => candidate.id === id );
    return item ? Math.max( 0, item.total + item.backordered - assigned( id ) ) : 0;
}

function findShipment( key ) {
    return state.overlay.shipments.find( ( shipment ) => shipment.key === key ) || null;
}

function setQuantity( shipment, id, quantity ) {
    if ( ! shipment || ! shipment.editable ) {
        return;
    }

    const items = { ...shipment.items };
    const max = ( items[ id ] || 0 ) + unassigned( id );

    quantity = Math.max( 0, Math.min( max, quantity ) );

    if ( quantity > 0 ) {
        items[ id ] = quantity;
    } else {
        delete items[ id ];
    }

    shipment.items = items;
    state.overlay.dirty = true;

    applySuggestion( shipment );
}

function newShipment() {
    return {
        key: 'new-' + ( ++counter ),
        id: 0,
        status: '',
        editable: true,
        packaging_id: 0,
        provider: state.currentItem ? state.currentItem.shipping_provider || '' : '',
        weight: '',
        length: '',
        width: '',
        height: '',
        contentLength: 0,
        contentWidth: 0,
        contentHeight: 0,
        items: {},
        packagingSuggested: false,
        packagingManual: false,
        packagingNotice: '',
    };
}

function getTargetShipment() {
    let shipment = findShipment( state.overlay.activeKey );

    if ( ! shipment || ! shipment.editable ) {
        shipment = state.overlay.shipments.find( ( candidate ) => candidate.editable ) || null;
    }

    if ( ! shipment ) {
        shipment = newShipment();
        state.overlay.shipments = [ ...state.overlay.shipments, shipment ];
        shipment = state.overlay.shipments[ state.overlay.shipments.length - 1 ];
    }

    state.overlay.activeKey = shipment.key;

    return shipment;
}

function markDirty() {
    if ( state.overlay.open ) {
        state.overlay.dirty = true;
    }
}

const { state, actions } = store( NAMESPACE, {
    state: {
        get hasLayout() {
            return !! state.currentItem && !! state.currentItem.layout;
        },
        get shipmentsButtonText() {
            const count = state.hasLayout ? state.currentItem.layout.shipments.length : 0;
            return format( i18n().shipmentsCount, count );
        },
        shipments: {
            get poolItems() {
                return state.overlay.items
                    .map( ( item ) => {
                        const quantity = unassigned( item.id );
                        const available = Math.max( 0, item.total - assigned( item.id ) );

                        return { id: item.id, name: item.name, sku: item.sku, quantity, backordered: Math.max( 0, quantity - available ) };
                    } )
                    .filter( ( item ) => item.quantity > 0 );
            },
            get hasPoolItems() {
                return state.shipments.poolItems.length > 0;
            },
            get poolItemMeta() {
                const { pool_item: item } = getContext();
                const parts = [ item.sku ];

                if ( item.backordered > 0 ) {
                    parts.push( format( i18n().backordered, item.backordered ) );
                }

                return parts.filter( Boolean ).join( ' · ' );
            },
            get poolItemHasBackorder() {
                const { pool_item: item } = getContext();
                return item.backordered > 0;
            },
            get lines() {
                const { shipment } = getContext();

                return Object.keys( shipment.items )
                    .map( ( id ) => toLineId( id ) )
                    .filter( ( id ) => shipment.items[ id ] > 0 )
                    .map( ( id ) => {
                        const item = getItem( id ) || { name: '#' + id, sku: '' };
                        return { id, name: item.name, sku: item.sku, quantity: shipment.items[ id ] };
                    } );
            },
            get isEmptyShipment() {
                return 0 === state.shipments.lines.length;
            },
            get title() {
                const { shipment } = getContext();
                const index = state.overlay.shipments.findIndex( ( candidate ) => candidate.key === shipment.key ) + 1;

                return format( i18n().shipmentTitle, index ) + ( shipment.id ? ' (#' + shipment.id + ')' : '' );
            },
            get isActive() {
                const { shipment } = getContext();
                return shipment.key === state.overlay.activeKey;
            },
            get canAddLine() {
                const { line } = getContext();
                return unassigned( line.id ) > 0;
            },
            /**
             * A new shipment is only needed for items not part of a shipment yet (an empty one may exist only once).
             */
            get canAddShipment() {
                return state.shipments.hasPoolItems && ! state.overlay.shipments.some( ( shipment ) => shipment.editable && 0 === Object.values( shipment.items ).filter( ( quantity ) => quantity > 0 ).length );
            },
            get canSave() {
                return state.overlay.dirty && ! state.overlay.saving;
            },
        },
        shipmentFields: {
            get isLocked() {
                const { shipment } = getContext();
                return ! shipment.editable;
            },
            get packagingOptions() {
                const { shipment } = getContext();
                return getPackagingSelectOptions( shipmentPackagingResult( shipment ), shipment.packaging_id );
            },
            get isPackagingSuggested() {
                const { shipment } = getContext();
                return !! shipment.packagingSuggested && shipment.packaging_id > 0;
            },
            get packagingNotice() {
                const { shipment } = getContext();
                return shipment.packagingNotice || '';
            },
            get packagingWarning() {
                const { shipment } = getContext();
                return getPackagingWarnings( shipmentPackagingResult( shipment ), shipmentPackingItems( shipment ) );
            },
            get isSelectedProvider() {
                const { shipment, provider } = getContext();
                return provider.id === shipment.provider;
            },
            get isDimensionLocked() {
                const { shipment } = getContext();
                return ! shipment.editable || shipment.packaging_id > 0;
            },
            get dimensionValue() {
                const { shipment, dimension } = getContext();
                const packaging = getPackaging( shipment.packaging_id );

                if ( packaging ) {
                    return String( round( packaging[ dimension ], 2 ) );
                }

                return undefined === shipment[ dimension ] ? '' : String( shipment[ dimension ] );
            },
            get dimensionPlaceholder() {
                const { shipment, dimension } = getContext();
                const value = shipment[ 'content' + dimension.charAt( 0 ).toUpperCase() + dimension.slice( 1 ) ];

                return value ? String( round( value, 2 ) ) : '';
            },
            get contentWeight() {
                const { shipment } = getContext();

                return round( Object.keys( shipment.items ).reduce( ( sum, id ) => {
                    const item = getItem( toLineId( id ) );
                    return sum + ( item ? item.weight * shipment.items[ id ] : 0 );
                }, 0 ) );
            },
            get weightPlaceholder() {
                return String( state.shipmentFields.contentWeight );
            },
            get totalWeightText() {
                const { shipment } = getContext();
                const packaging = getPackaging( shipment.packaging_id );
                const content = '' !== shipment.weight && undefined !== shipment.weight ? parseFloat( shipment.weight ) || 0 : state.shipmentFields.contentWeight;
                const total = round( content + ( packaging ? packaging.weight : 0 ) );

                return format( i18n().totalWeight, total + ' ' + getConfig( NAMESPACE ).weightUnit );
            },
            get exceedsMaxWeight() {
                const { shipment } = getContext();
                const packaging = getPackaging( shipment.packaging_id );

                return !! packaging && packaging.maxWeight > 0 && state.shipmentFields.contentWeight > packaging.maxWeight;
            },
        },
    },
    actions: {
        shipmentFields: {
            setPackaging( event ) {
                const { shipment } = getContext();
                shipment.packaging_id = parseInt( event.target.value, 10 ) || 0;
                // A manual choice is kept as long as it fits.
                shipment.packagingManual = shipment.packaging_id > 0;
                shipment.packagingSuggested = false;
                shipment.packagingNotice = '';
                markDirty();
            },
            setProvider( event ) {
                const { shipment } = getContext();
                shipment.provider = event.target.value;
                // Packaging may be restricted to shipping providers.
                applySuggestion( shipment );
                markDirty();
            },
            setWeight( event ) {
                const { shipment } = getContext();
                shipment.weight = event.target.value;
                markDirty();
            },
            setDimension( event ) {
                const { shipment, dimension } = getContext();
                shipment[ dimension ] = event.target.value;
                markDirty();
            },
        },
        shipments: {
            open() {
                if ( ! state.hasLayout ) {
                    return;
                }

                const layout = copy( state.currentItem.layout );

                state.overlay = {
                    open: true,
                    saving: false,
                    dirty: false,
                    proposed: false,
                    activeKey: '',
                    items: layout.items,
                    shipments: layout.shipments.map( ( shipment ) => ( {
                        ...shipment,
                        items: Array.isArray( shipment.items ) ? {} : shipment.items,
                        // The stored packaging is kept as long as it fits.
                        packagingManual: shipment.packaging_id > 0,
                        packagingSuggested: false,
                        packagingNotice: '',
                    } ) ),
                };

                // No shipments yet: propose them for the available items (split into as few packages as possible).
                if ( 0 === state.overlay.shipments.length ) {
                    const items = state.overlay.items.filter( ( item ) => item.total > 0 ).map( ( item ) => ( { ...item, quantity: item.total } ) );
                    const { shipments } = proposeShipments( {
                        packaging: state.packaging,
                        items,
                        provider: state.currentItem.shipping_provider || '',
                        defaultPackagingId: packingConfig().defaultPackaging || 0,
                        maxItems: packingConfig().maxItems || undefined,
                    } );

                    if ( shipments.length > 0 ) {
                        state.overlay.shipments = shipments.map( ( proposal ) => ( {
                            ...newShipment(),
                            items: { ...proposal.items },
                            packaging_id: proposal.packaging_id,
                            packagingSuggested: proposal.packaging_id > 0,
                        } ) );
                        state.overlay.proposed = true;
                        state.overlay.dirty = true;
                    }
                }

                const first = state.overlay.shipments.find( ( shipment ) => shipment.editable );
                state.overlay.activeKey = first ? first.key : '';

                const body = document.querySelector( '.stc-sheet-body' );

                if ( body ) {
                    body.scrollTop = 0;
                }
            },
            close() {
                if ( state.overlay.dirty && ! window.confirm( i18n().discardChanges ) ) {
                    return;
                }

                state.overlay.open = false;
            },
            select() {
                const { shipment } = getContext();

                if ( shipment.editable ) {
                    state.overlay.activeKey = shipment.key;
                }
            },
            addShipment() {
                const shipment = newShipment();

                state.overlay.shipments = [ ...state.overlay.shipments, shipment ];
                state.overlay.activeKey = shipment.key;
                state.overlay.dirty = true;
            },
            removeShipment() {
                const { shipment } = getContext();

                if ( ! shipment.editable ) {
                    return;
                }

                state.overlay.shipments = state.overlay.shipments.filter( ( candidate ) => candidate.key !== shipment.key );

                if ( state.overlay.activeKey === shipment.key ) {
                    const first = state.overlay.shipments.find( ( candidate ) => candidate.editable );
                    state.overlay.activeKey = first ? first.key : '';
                }

                state.overlay.dirty = true;
            },
            // Pool (items not in a shipment yet)
            poolAdd() {
                const { pool_item: item } = getContext();
                const shipment = getTargetShipment();

                setQuantity( shipment, item.id, ( shipment.items[ item.id ] || 0 ) + 1 );
            },
            poolSwipe( event ) {
                if ( 'right' === event.detail.direction ) {
                    actions.shipments.poolAdd();
                }
            },
            poolDrop( event ) {
                const { pool_item: item } = getContext();
                const shipment = findShipment( event.detail.target );

                if ( shipment ) {
                    state.overlay.activeKey = shipment.key;
                    setQuantity( shipment, item.id, ( shipment.items[ item.id ] || 0 ) + item.quantity );
                }
            },
            // Lines of a shipment
            lineIncrement() {
                const { shipment, line } = getContext();
                setQuantity( shipment, line.id, line.quantity + 1 );
            },
            lineDecrement() {
                const { shipment, line } = getContext();
                setQuantity( shipment, line.id, line.quantity - 1 );
            },
            lineSwipe( event ) {
                if ( 'right' === event.detail.direction ) {
                    actions.shipments.lineIncrement();
                } else {
                    actions.shipments.lineDecrement();
                }
            },
            lineDrop( event ) {
                const { shipment, line } = getContext();

                if ( ! shipment.editable ) {
                    return;
                }

                const quantity = line.quantity;

                // Back to the pool.
                setQuantity( shipment, line.id, 0 );

                if ( 'pool' !== event.detail.target ) {
                    const target = findShipment( event.detail.target );

                    if ( target && target.editable ) {
                        state.overlay.activeKey = target.key;
                        setQuantity( target, line.id, ( target.items[ line.id ] || 0 ) + quantity );
                    } else {
                        setQuantity( shipment, line.id, quantity );
                    }
                }
            },
            *save() {
                const item = state.currentItem;

                if ( ! item || state.overlay.saving ) {
                    return;
                }

                state.overlay.saving = true;
                actions.dismissNotice();

                // E.g. fixing an order a run of an extension failed on: the run continues the order.
                const continues = hooks.applyFilters( 'shipmentsContinue', true );

                try {
                    const data = yield api.request( `queue/${ item.id }/shipments`, {
                        method: 'POST',
                        body: {
                            token: item.lock_token,
                            idempotency_key: api.uuid(),
                            continue: continues,
                            shipments: state.overlay.shipments
                                .filter( ( shipment ) => shipment.editable )
                                .map( ( shipment ) => ( {
                                    id: shipment.id || shipment.key,
                                    items: shipment.items,
                                    props: {
                                        packaging_id: shipment.packaging_id,
                                        provider: shipment.provider,
                                        weight: shipment.weight,
                                        ...( shipment.packaging_id ? {} : DIMENSIONS.reduce( ( props, dimension ) => ( { ...props, [ dimension ]: shipment[ dimension ] } ), {} ) ),
                                    },
                                } ) ),
                        },
                    } );

                    state.overlay.open = false;
                    state.overlay.dirty = false;

                    if ( ! continues ) {
                        // Not continued: keep showing the failed step, update the shipments (layout), steps and version.
                        const current = state.currentItem;
                        state.run.items = [ { ...current, steps: data.steps, layout: data.layout, version: data.version, lock_token: data.lock_token || current.lock_token } ];
                        state.overlay.saving = false;

                        // E.g. the fix is saved: an extension retries right away.
                        yield hooks.applyFiltersAsync( 'shipmentsSaved', false, data );
                    } else {
                        yield actions.applyResponse( data, true );
                    }
                } catch ( error ) {
                    if ( error && [ 403, 404, 409 ].includes( error.status ) ) {
                        state.overlay.open = false;
                        yield actions.handleItemError( error );
                    } else {
                        actions.handleError( error );
                    }
                } finally {
                    state.overlay.saving = false;
                }
            },
        },
    },
} );

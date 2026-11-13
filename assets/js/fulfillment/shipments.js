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
 */
import { store, getContext, getConfig } from '@wordpress/interactivity';
import * as api from './api.js';

const NAMESPACE = 'shiptastic/fulfillment';
const i18n = () => getConfig( NAMESPACE ).i18n;
const format = ( text, ...values ) => {
    let index = 0;
    return text.replace( /%(\d+\$)?[sd]/g, ( match, position ) => String( position ? values[ parseInt( position, 10 ) - 1 ] : values[ index++ ] ) );
};
const round = ( value, decimals = 3 ) => parseFloat( Number( value || 0 ).toFixed( decimals ) );
const copy = ( value ) => JSON.parse( JSON.stringify( value ) );

let counter = 0;

const DIMENSIONS = [ 'length', 'width', 'height' ];

function getItem( id ) {
    const layout = state.currentItem && state.currentItem.layout ? state.currentItem.layout : { items: [] };
    return layout.items.find( ( item ) => item.id === id ) || null;
}

function getPackaging( id ) {
    return state.packaging.find( ( packaging ) => packaging.id === id ) || null;
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
                    .map( ( id ) => parseInt( id, 10 ) )
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
            get isSelectedPackaging() {
                const { shipment, packaging } = getContext();
                return packaging.id === shipment.packaging_id;
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
                    const item = getItem( parseInt( id, 10 ) );
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
                markDirty();
            },
            setProvider( event ) {
                const { shipment } = getContext();
                shipment.provider = event.target.value;
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
                    activeKey: '',
                    items: layout.items,
                    shipments: layout.shipments.map( ( shipment ) => ( { ...shipment, items: Array.isArray( shipment.items ) ? {} : shipment.items } ) ),
                };

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

                try {
                    const data = yield api.request( `queue/${ item.id }/shipments`, {
                        method: 'POST',
                        body: {
                            token: item.lock_token,
                            idempotency_key: api.uuid(),
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

                    yield actions.applyResponse( data, true );
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

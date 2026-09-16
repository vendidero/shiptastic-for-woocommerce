// view.js
import { store, getContext, getElement, withSyncEvent, getServerContext, getServerState } from '@wordpress/interactivity';

const { state, actions } = store( 'shiptastic/fulfillments/shipments', {
    state: {
        selectedItems: [],

        get hasSelectedItems() {
            return state.selectedItems.length;
        },

        get isItemSelected() {
            const context = getContext();

            return state.selectedItems.filter( ( item ) => item.id === context.item.id ).length;
        },
    },
    actions: {
        createShipment() {
            const context = getContext();

            const shipment = {
                'items': [],
                'id': 'new_' + Date.now(),
                'packagingId': 0,
                'shippingProvider': '',
                'status': '',
                'weight': 0.0,
                'length': 0.0,
                'width': 0.0,
                'height': 0.0,
            };

            state.selectedItems.map( ( item ) => {
                const shipmentItem = { ...item, ...{
                    'itemId': 0
                } };

                console.log(item);

                state.items = state.items.map( ( contextItem ) => {
                    if ( contextItem.id === item.id ) {
                        contextItem.maxQuantity -= item.quantity;
                    }

                    return contextItem;
                } );

                shipment.items.push( shipmentItem );

                shipment.weight += ( item.weight * item.quantity );
                shipment.length = Math.max( shipment.length, item.length );
                shipment.width = Math.max( shipment.width, item.width );
                shipment.height = Math.max( shipment.height, item.height );
            } );

            state.selectedItems = [];

            state.items = state.items.filter( ( contextItem ) => {
                if ( contextItem.maxQuantity <= 0 ) {
                    return false;
                }

                return true;
            } );

            state.shipments.push( shipment );
        },

        unselectItem() {
            const context = getContext();

            state.selectedItems = state.selectedItems.filter( ( item ) => item.id !== context.item.id );
        },

        selectItem() {
            const context = getContext();

            state.selectedItems.push( context.item );
        },

        toggleSelectItem() {
            if ( state.isItemSelected ) {
                actions.unselectItem();
            } else {
                actions.selectItem();
            }
        },

        setItemQuantity( event ) {
            const { item } = getContext();

            item.quantity = event.target.value || null;
        },

        deleteShipmentItem( itemId ) {
            const context = getContext();

            context.shipment.items = context.shipment.items.filter( ( shipmentItem ) => {
                if ( shipmentItem.id === itemId ) {
                    return false;
                }

                return true;
            } );

            if ( context.shipment.items.length <= 0 ) {
                actions.deleteShipment( context.shipment.id );
            }
        },

        deleteShipment( shipmentId ) {
            state.shipments = state.shipments.filter( ( shipment ) => {
                if ( shipment.id === shipmentId ) {
                    return false;
                }

                return true;
            } );
        },

        setShipmentItemQuantity( event ) {
            const context = getContext();

            context.shipment_item.quantity = event.target.value || null;

            if ( context.shipment_item.quantity <= 0 ) {
                actions.deleteShipmentItem( context.shipment_item.id );
            }

            const quantityLeft = context.shipment_item.maxQuantity - context.shipment_item.quantity;

            console.log(quantityLeft);

            if ( quantityLeft > 0 ) {
                let exists = false;

                state.items = state.items.map( ( contextItem ) => {
                    if ( contextItem.id === context.shipment_item.id ) {
                        contextItem.maxQuantity = quantityLeft;
                        contextItem.quantity = contextItem.maxQuantity;
                        exists = true;
                    }

                    return contextItem;
                } );

                if ( ! exists ) {
                    state.items.push( {...context.shipment_item, ...{'itemId': 0, 'maxQuantity': quantityLeft, 'quantity': quantityLeft}} )
                }
            } else {
                state.items = state.items.filter( ( contextItem ) => {
                    if ( contextItem.id === context.shipment_item.id ) {
                        return false;
                    }

                    return true;
                } );
            }
        },

        stopPropagation: withSyncEvent( ( event ) => {
            event.stopPropagation();
        } ),
    },
    callbacks: {
        updateContext() {
            const context = getContext();
            const serverContext = getServerContext();
            const serverState   = getServerState();

            state.shipments = serverState.shipments;
            state.items = serverState.items;
            state.selectedItems = [];
        },
    }
} );
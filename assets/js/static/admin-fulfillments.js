// view.js
import { store, withSyncEvent, getContext, getServerContext, getServerState, getConfig, watch } from '@wordpress/interactivity';

const isGetter = (obj, prop) => {
    return !!Object.getOwnPropertyDescriptor(obj, prop)['get'];
}

const { state, actions, callbacks } = store( 'shiptastic/fulfillments', {
    state: {
        currentUrl: '',

        get shipmentsItemCount() {
            return state.shipments.reduce( ( count, shipment ) => {
                return count + shipment.items.reduce( ( innerCount, item ) => {
                    return innerCount + item.quantity;
                }, 0 );
            }, 0 );
        },

        get shipmentsUniqueItemCount() {
            return state.shipments.reduce( ( count, shipment ) => {
                return count + shipment.items.length;
            }, 0 );
        },

        get shipmentCount() {
            return state.shipments.length;
        },

        get allShipmentItems() {
            const shipmentItems = {};

            for ( const shipment of state.shipments ) {
                for ( const item of shipment.items ) {
                    const shipmentItem = { ...item };

                    if ( shipmentItems.hasOwnProperty( shipmentItem.id ) ) {
                        shipmentItems[ shipmentItem.id ].quantity += shipmentItem.quantity;
                    } else {
                        shipmentItems[ shipmentItem.id ] = shipmentItem;
                    }
                }
            }

            return Object.values( shipmentItems );
        }
    },

    actions: {
        prefetch: function* ( event ) {
            const link = event.target.closest( 'a' );

            const { actions } = yield import(
                '@wordpress/interactivity-router'
                );
            yield actions.prefetch( link.href );
        },

        prevOrder: withSyncEvent( function* ( event ) {
            event.preventDefault();
            const link = event.target.closest( 'a' );

            const { actions } = yield import(
                '@wordpress/interactivity-router'
                );

            yield actions.navigate( link.href, { force: true } );
        } ),

        nextOrder: withSyncEvent( function* ( event ) {
            event.preventDefault();
            const link = event.target.closest( 'a' );

            const { actions } = yield import(
                '@wordpress/interactivity-router'
                );
            yield actions.navigate( link.href, { force: true } );
        } ),

        goToAction: withSyncEvent( function* ( event ) {
            event.preventDefault();
            const link = event.target.closest( 'a' );

            const { actions } = yield import(
                '@wordpress/interactivity-router'
                );
            yield actions.navigate( link.href );
        } ),

        deleteShipment( shipment ) {
            state.shipments = state.shipments.filter( ( theShipment ) => {
                if ( theShipment.id === shipment.id ) {
                    return false;
                }

                return true;
            } );
        },

        deleteShipmentItem( shipment, shipmentItem ) {
            shipment.items = shipment.items.filter( ( theShipmentItem ) => {
                if ( theShipmentItem.itemId === shipmentItem.itemId ) {
                    return false;
                }

                return true;
            } );

            if ( shipment.items.length <= 0 ) {
                actions.deleteShipment( shipment );
            }
        },

        getShipmentByItem( shipmentItem ) {
            const items = state.shipments.filter( ( theShipment ) => {
                return theShipment.items.filter( ( theShipmentItem ) => {
                    if ( theShipmentItem.itemId === shipmentItem.itemId ) {
                        return true;
                    }

                    return false;
                } ).length > 0;
            } );

            if ( items.length > 0 ) {
                return items[0];
            }

            return null;
        },

        getShipmentItemById( shipment, id ) {
            const item = shipment.items.filter( ( theShipmentItem ) => {
                if ( theShipmentItem.id === id ) {
                    return true;
                }

                return false;
            } );

            if ( item.length > 0 ) {
                return item[0];
            }

            return null;
        },

        addShipmentItem( shipment, shipmentItem ) {
            const originalShipment = actions.getShipmentByItem( shipmentItem );

            if ( originalShipment && originalShipment.id === shipment.id ) {
                return;
            }

            const item = actions.getShipmentItemById( shipment, shipmentItem.id );

            if ( originalShipment ) {
                actions.deleteShipmentItem( originalShipment, shipmentItem );
            }

            if ( item ) {
                const newQuantity = item.quantity + shipmentItem.quantity;

                actions.setShipmentItemQuantity( shipment, item, newQuantity );
            } else {
                const newShipmentItem = { ...shipmentItem, ...{
                    'itemId': 'new_item_' + shipmentItem.id + '_' + Date.now(),
                } };

                shipment.items.push( newShipmentItem );

                actions.setShipmentItemQuantity( shipment, newShipmentItem, newShipmentItem.quantity );

                shipment.weight += ( newShipmentItem.weight * newShipmentItem.quantity );
                shipment.length = Math.max( shipment.length, newShipmentItem.length );
                shipment.width = Math.max( shipment.width, newShipmentItem.width );
                shipment.height = Math.max( shipment.height, newShipmentItem.height );
            }
        },

        createShipment( items ) {
            const shipment = {
                'items': [],
                'id': 'new_shipment_' + Date.now(),
                'packagingId': 0,
                'shippingProvider': '',
                'currentShipmentNumber': state.shipments.length + 1,
                'status': '',
                'weight': 0.0,
                'length': 0.0,
                'width': 0.0,
                'height': 0.0,
            };

            items.map( ( item ) => {
                actions.addShipmentItem( shipment, item );
            } );

            state.itemsAvailableToShip = state.itemsAvailableToShip.filter( ( contextItem ) => {
                if ( contextItem.maxQuantity <= 0 ) {
                    return false;
                }

                return true;
            } );

            state.shipments.push( shipment );
        },

        setShipmentItemQuantity( shipment, shipmentItem, quantity ) {
            shipmentItem.quantity = quantity;

            const quantityLeft = shipmentItem.maxQuantity - shipmentItem.quantity;

            if ( shipmentItem.quantity <= 0 ) {
                actions.deleteShipmentItem( shipment, shipmentItem );
            }

            if ( quantityLeft > 0 ) {
                let exists = false;

                state.itemsAvailableToShip = state.itemsAvailableToShip.map( ( contextItem ) => {
                    if ( contextItem.id === shipmentItem.id ) {
                        contextItem.maxQuantity = quantityLeft;
                        contextItem.quantity = contextItem.maxQuantity;
                        exists = true;
                    }

                    return contextItem;
                } );

                if ( ! exists ) {
                    state.itemsAvailableToShip.push( {...shipmentItem, ...{ 'itemId': 0, 'maxQuantity': quantityLeft, 'quantity': quantityLeft}} )
                }
            } else {
                state.itemsAvailableToShip = state.itemsAvailableToShip.filter( ( contextItem ) => {
                    if ( contextItem.id === shipmentItem.id ) {
                        return false;
                    }

                    return true;
                } );
            }
        },

        getCurrentActionData() {
            return {};
        },
        save() {
            console.log(actions.getCurrentActionData());
        }
    },
    callbacks: {
        shipmentHasItem( shipment, id ) {
            return actions.getShipmentItemById( shipment, id );
        },

        updateShipmentItems() {
            const { shipments } = getServerState();
            const shipmentItems = {};

            console.log(shipments);

            for ( const shipment of shipments.shipments ) {
                for ( const item of shipment.items ) {
                    if ( shipmentItems.hasOwnProperty( item.id ) ) {
                        shipmentItems[ item.id ].quantity += item.quantity;
                    } else {
                        shipmentItems[ item.id ] = item;
                    }
                }
            }

            state.shipmentItems = Object.values( shipmentItems );

            console.log(state.shipmentItems);
        },

        getNextOrderUrl() {
            if ( ! state.nextOrderId ) {
                return '#';
            }

            const { baseUrl } = getConfig();
            let url = new URL( baseUrl );

            url.searchParams.set( 'order_id', state.nextOrderId );

            return url;
        },

        getPrevOrderUrl() {
            if ( ! state.prevOrderId ) {
                return '#';
            }

            const { baseUrl } = getConfig();
            let url = new URL( baseUrl );

            url.searchParams.set( 'order_id', state.prevOrderId );

            return url;
        },

        onUpdateState() {
            const serverState = getServerState();

            /**
             * Override all state.
             */
            if ( state.orderId !== serverState.orderId ) {
                for ( let prop in serverState ) {
                    if ( serverState.hasOwnProperty( prop ) && state.hasOwnProperty( prop ) && ! isGetter( state, prop ) ) {
                        state[ prop ] = serverState[ prop ];
                    }
                }
            }

            if ( state.currentAction !== serverState.currentAction ) {
                state.currentAction = serverState.currentAction;
            }

            if ( parseInt( state.shipmentId ) !== parseInt( serverState.shipmentId ) ) {
                state.shipmentId = parseInt( serverState.shipmentId );
            }
        },
    }
} );

/*
watch( () => {
    const { state } = store( 'core/router' );
    const { fulfillmentsState, callbacks } = store( 'shiptastic/fulfillments' );

    if ( fulfillmentsState.currentUrl !== state.url && undefined !== fulfillmentsState.currentUrl ) {
        const prevUrl = new URL( fulfillmentsState.currentUrl );
        const newUrl = new URL( state.url );

        const prevCompare = {
            'orderId': prevUrl.searchParams.get( 'order_id' ),
            'shipmentId': prevUrl.searchParams.get( 'shipment_id' ),
            'action': prevUrl.searchParams.get( 'action' ),
        };

        const newCompare = {
            'orderId': newUrl.searchParams.get( 'order_id' ),
            'shipmentId': newUrl.searchParams.get( 'shipment_id' ),
            'action': newUrl.searchParams.get( 'action' ),
        };

        if ( JSON.stringify( prevCompare ) !== JSON.stringify( newCompare ) ) {
            if ( prevCompare['orderId'] !== newCompare['orderId'] ) {
                callbacks.updateState();
            } else {
                callbacks.updateActionState();
            }
        }
    }

    fulfillmentsState.currentUrl = state.url
} );
*/

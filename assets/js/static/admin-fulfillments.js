// view.js
import { store, withSyncEvent, getContext, getServerContext, getServerState, getConfig, watch, useEffect } from '@wordpress/interactivity';
import { pack3D } from 'binpackingjs';

const isGetter = (obj, prop) => {
    return !!Object.getOwnPropertyDescriptor(obj, prop)['get'];
}

const { state, actions, callbacks } = store( 'shiptastic/fulfillments', {
    state: {
        currentUrl: '',
        syncWithServer: false,
        hasSaved: false,
        didInit: false,

        get itemsAvailableToShip() {
            const items = state.allShipmentItemsMap;
            const { allItemsAvailableToShip } = getServerState();

            return allItemsAvailableToShip.filter( ( theItem ) => {
                if ( items.hasOwnProperty( theItem.id ) ) {
                    theItem.maxQuantity -= items[ theItem.id ].quantity;
                    theItem.quantity -= items[ theItem.id ].quantity;
                }

                if ( theItem.maxQuantity <= 0 ) {
                    return false;
                }

                return true;
            } );
        },

        get currentShipment() {
            if ( state.currentShipmentId <= 0 ) {
                return null;
            }

            const shipments = state.shipments.filter( ( theShipment ) => {
                if ( theShipment.id === state.currentShipmentId ) {
                    return true;
                }

                return false;
            } );

            return shipments.length > 0 ? shipments[0] : null;
        },

        get currentShipmentNumber() {
            const currentShipment = state.currentShipment;

            return currentShipment ? callbacks.getShipmentNumber( currentShipment ) : 0;
        },

        get currentShipmentItemCount() {
            return state.currentShipment ? state.currentShipment.items.reduce( ( innerCount, item ) => {
                return innerCount + item.quantity;
            }, 0 ) : 0;
        },

        get currentShipmentUniqueItemCount() {
            return state.currentShipment ? state.currentShipment.items.length : 0;
        },

        get shipmentsItemCount() {
            return state.shipments.reduce( ( count, shipment ) => {
                return count + shipment.items.reduce( ( innerCount, item ) => {
                    return innerCount + item.quantity;
                }, 0 );
            }, 0 );
        },

        get shipmentsUniqueItemCount() {
            return Object.keys( state.allShipmentItemsMap ).length;
        },

        get shipmentCount() {
            return state.shipments.length;
        },

        get allShipmentItemsMap() {
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

            return shipmentItems;
        },

        get allShipmentItems() {
            return Object.values( state.allShipmentItemsMap );
        },
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

        done: withSyncEvent( function* ( event, formData ) {
            try {
                const { ajaxUrl, saveNonce } = getConfig();

                formData.append( 'action', 'woocommerce_stc_save_bulk_fulfillment' );
                formData.append( 'order_id', state.orderId );
                formData.append( 'fulfillment_id', state.fulfillmentId );
                formData.append( 'current_action', state.currentActionId );
                formData.append( 'security', saveNonce );

                const data = yield fetch( ajaxUrl, {
                    method: 'POST',
                    body: formData,
                } ).then( ( response ) => response.json() );

                // Navigate using the fetched HTML.
                const { actions } = yield import(
                    '@wordpress/interactivity-router'
                );

                const html = data['html'];

                yield actions.navigate( data['url'], { html, force: true } ).then( () => {
                    state.syncWithServer = true;
                    state.hasSaved = true;
                } );
            } catch ( e ) {
                // Something went wrong!
                console.log(e);
            }
        } ),

        setLocalState( key, value ) {
            const newPayload = {
                [key]: {
                    'dateModified': Math.round( Date.now() / 1000 ),
                    'value': value
                }
            };

            const payload = { ...actions.getCurrentLocalState(), ...newPayload };

            localStorage.setItem( actions.getLocalStateKey(), JSON.stringify( payload ) );
        },

        getCurrentLocalState() {
            const payloadRaw = localStorage.getItem( actions.getLocalStateKey() );

            if ( payloadRaw ) {
                return JSON.parse( payloadRaw );
            }

            return {};
        },

        getLocalStateKey() {
            return 'wc_stc_bulk_fulfillments_' + state.fulfillmentId + '_order_' + state.orderId;
        },

        getLocalState( key ) {
            const payloadRaw = actions.getCurrentLocalState();

            if ( payloadRaw.hasOwnProperty( key ) ) {
                return payloadRaw[ key ];
            }

            return null;
        },

        deleteLocalState( key ) {
            let payload = actions.getCurrentLocalState();

            if ( payload.hasOwnProperty( key ) ) {
                delete payload[ key ];
                localStorage.setItem( actions.getLocalStateKey(), JSON.stringify( payload ) );
            }
        },

        getLocalStateLastUpdated( key ) {
            return actions.getLocalState( key, 'dateModified' );
        },

        deleteShipment( shipment ) {
            state.shipments = state.shipments.filter( ( theShipment ) => {
                if ( theShipment.id === shipment.id ) {
                    shipment.items = shipment.items.filter( ( theShipmentItem ) => {
                        actions.setShipmentItemQuantity( theShipment, theShipmentItem, 0 );

                        return true;
                    } );

                    return false;
                }

                return true;
            } );
        },

        deleteShipmentItem( shipment, shipmentItem ) {
            shipment.items = shipment.items.filter( ( theShipmentItem ) => {
                if ( theShipmentItem.itemId === shipmentItem.itemId ) {
                    if ( theShipmentItem.quantity > 0 ) {
                        actions.setShipmentItemQuantity( shipment, theShipmentItem, 0 );
                    }

                    return false;
                }

                return true;
            } );

            if ( shipment.items.length <= 0 ) {
                actions.deleteShipment( shipment );
            }
        },

        getBestPackagingForShipment( shipment ) {
            const { packagingOptions } = getConfig();

            const bins = packagingOptions.map( ( packaging ) => {
                return {
                    'name': packaging.id,
                    'width': packaging.length,
                    'height': packaging.width,
                    'depth' : packaging.height,
                    'maxWeight': packaging.maxWeight,
                };
            } );

            const allItems = shipment.items.flatMap( ( item ) => {
                return Array( item.quantity ).fill(
                    {
                        'name': item.id,
                        'width': item.length,
                        'height': item.width,
                        'depth' : item.height,
                        'weight': item.weight,
                    }
                );
            } );

            const result = pack3D({
                bins: bins,
                items: allItems,
            });

            const bestPackagings = result.packedBins.filter( ( bin ) => {
                if ( bin.items.length === allItems.length ) {
                    return true;
                }

                return false;
            } );

            return bestPackagings.length > 0 ? callbacks.getPackagingById( bestPackagings[0].name ) : null;
        },

        packagingFitsShipment( packaging, shipment ) {
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

        getShipmentContentWeight( shipment ) {
            return shipment.items.reduce( ( count, item ) => {
                return count + ( item.weight * item.quantity );
            }, 0.0 );
        },

        getShipmentContentLength( shipment ) {
            let maxLength = 0.0;

            shipment.items.map( ( item ) => {
                if ( item.length > maxLength ) {
                    maxLength = item.length;
                }
            } );

            return maxLength;
        },

        getShipmentContentWidth( shipment ) {
            let maxWidth = 0.0;

            shipment.items.map( ( item ) => {
                if ( item.width > maxWidth ) {
                    maxWidth = item.width;
                }
            } );

            return maxWidth;
        },

        getShipmentContentHeight( shipment ) {
            let maxHeight = 0.0;

            shipment.items.map( ( item ) => {
                const itemHeight = item.height * item.quantity;

                if ( itemHeight > maxHeight ) {
                    maxHeight = itemHeight;
                }
            } );

            return maxHeight;
        },

        addShipmentItem( shipment, shipmentItem ) {
            const originalShipment = actions.getShipmentByItem( shipmentItem );

            if ( originalShipment && originalShipment.id === shipment.id ) {
                return;
            }

            const item = actions.getShipmentItemById( shipment, shipmentItem.id );
            const originalShipmentItem = { ...shipmentItem }; // Clone before potential deletion

            if ( originalShipment ) {
                actions.deleteShipmentItem( originalShipment, shipmentItem );
            }

            if ( item ) {
                const newQuantity = item.quantity + originalShipmentItem.quantity;

                actions.setShipmentItemQuantity( shipment, item, newQuantity );
            } else {
                const newShipmentItem = { ...originalShipmentItem, ...{
                    'itemId': 'new_item_' + originalShipmentItem.id + '_' + Date.now(),
                } };

                shipment.items = [
                    ...shipment.items,
                    newShipmentItem
                ]

                actions.setShipmentItemQuantity( shipment, newShipmentItem, newShipmentItem.quantity );
            }
        },

        createShipment( items ) {
            const { weightUnit, dimensionUnit } = getConfig();

            const shipment = {
                'items': [],
                'id': 'new_shipment_' + Date.now(),
                'packagingId': 0,
                'shippingProvider': '',
                'currentShipmentNumber': state.shipments.length + 1,
                'status': '',
                'editable': true,
                'weightUnit': weightUnit,
                'dimensionUnit': dimensionUnit,
                'weight': '',
                'length': '',
                'width': '',
                'height': '',
            };

            items.map( ( item ) => {
                actions.addShipmentItem( shipment, item );
            } );

            state.shipments = [
                ...state.shipments,
                shipment
            ];
        },

        setShipmentItemQuantity( shipment, shipmentItem, quantity ) {
            shipmentItem.quantity = quantity;

            const quantityLeft = shipmentItem.maxQuantity - shipmentItem.quantity;

            if ( shipmentItem.quantity <= 0 ) {
                actions.deleteShipmentItem( shipment, shipmentItem );
            }
        },
    },
    callbacks: {
        formatDecimal( value ) {
            const { decimalPoint, numberOfDecimals } = getConfig();

            if ( typeof value === 'number' || value instanceof Number ) {
                value = +value.toFixed( numberOfDecimals );
                value = value.toString();
                value = value.replace( '.', decimalPoint );
            }

            const regex = new RegExp(
                '[^-0-9\\' + decimalPoint + ']+',
                'gi'
            );

            const decimalRegex = new RegExp(
                '\\' + decimalPoint + '+',
                'gi'
            );

            const newValue = value.replace( regex, '' ).replace( decimalRegex, decimalPoint );

            if ( value !== newValue ) {
                value = newValue;
            }

            return value;
        },

        toDecimal( value ) {
            const { decimalPoint, numberOfDecimals } = getConfig();

            if ( typeof value === 'number' || value instanceof Number ) {
                return value;
            } else {
                let newValue = parseFloat( callbacks.formatDecimal( value ).replace( decimalPoint, '.' ) );

                if ( isNaN( newValue ) ) {
                    return '';
                } else {
                    newValue = +newValue.toFixed( numberOfDecimals );

                    return newValue;
                }
            }
        },

        getPackagingById( packagingId ) {
            const { packagingOptions } = getConfig();

            const packagingList = packagingOptions.filter( ( thePackaging ) => {
                if ( thePackaging.id === packagingId ) {
                    return true;
                }

                return false;
            } );

            return packagingList.length > 0 ? packagingList[0] : null;
        },

        getShipmentNumber(shipment ) {
            let count = 0;

            for ( const theShipment of state.shipments ) {
                count++;

                if ( shipment.id === theShipment.id ) {
                    break;
                }
            }

            return count;
        },

        shipmentHasItem( shipment, id ) {
            return actions.getShipmentItemById( shipment, id );
        },

        updateShipmentItems() {
            const { shipments } = getServerState();
            const shipmentItems = {};

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

        onUpdate() {

        },

        onInit() {
            const serverState = getServerState();
            const oldAction = state.currentActionId;

            const oldActionStore = store( 'shiptastic/fulfillments/' + state.currentAction );

            /**
             * Override all state.
             */
            if ( state.orderId !== serverState.orderId ) {
                state.syncWithServer = true;
            }

            if ( state.syncWithServer ) {
                for ( let prop in serverState ) {
                    if ( serverState.hasOwnProperty( prop ) && state.hasOwnProperty( prop ) && ! isGetter( state, prop ) ) {
                        state[ prop ] = serverState[ prop ];
                    }
                }
            }

            console.log('on init fulfillments');
            console.log(serverState);
            console.log(state.syncWithServer);
            console.log(state.hasSaved);

            if ( oldAction !== serverState.currentActionId ) {
                state.currentActionId = serverState.currentActionId;
                state.currentAction   = serverState.currentAction;
            }

            const currentActionStore = store( 'shiptastic/fulfillments/' + state.currentAction );

            if ( state.hasSaved ) {
                actions.deleteLocalState( oldAction );

                if ( oldActionStore && typeof oldActionStore.callbacks.onSave !== 'undefined' ) {
                    oldActionStore.callbacks.onSave();
                }

                state.hasSaved = false;
            } else {
                if ( currentActionStore && typeof currentActionStore.callbacks.onInit !== 'undefined' ) {
                    currentActionStore.callbacks.onInit();
                }
            }

            state.syncWithServer = false;
            state.didInit = true;
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

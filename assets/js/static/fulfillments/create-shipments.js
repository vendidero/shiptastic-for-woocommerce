// view.js
import { store, getContext, getElement, withSyncEvent, getServerContext, getServerState, getConfig } from '@wordpress/interactivity';

const mainStore = store( 'shiptastic/fulfillments' );

const { state, actions } = store( 'shiptastic/fulfillments/create_shipments', {
    state: {
        selectedItems: [],
        currentShipmentItemDragged: null,

        get hasSelectedItems() {
            return state.selectedItems.length;
        },

        get shipmentItemMaxQuantity() {
            const context = getContext();
            const shipment = context.shipment;
            const shipmentItem = context.shipment_item;
            const shipmentItemsMap = mainStore.state.allShipmentItemsMap;
            const { allItemsAvailableToShip } = getServerState( 'shiptastic/fulfillments' );

            let maxQuantityAvailable = allItemsAvailableToShip.filter( ( theItem ) => {
                if ( theItem.id === shipmentItem.id ) {
                    return true;
                }

                return false;
            } )[0].maxQuantity;

            let curMapItemQuantity = shipmentItemsMap[ shipmentItem.id ].quantity - shipmentItem.quantity;

            return Math.max( 0, maxQuantityAvailable - curMapItemQuantity );
        },

        get shipmentItemQuantity() {
            const context = getContext();
            const shipment = context.shipment;
            const shipmentItem = context.shipment_item;

            return shipmentItem.quantity;
        },

        get formattedShipmentWeight() {
            const context = getContext();

            if ( '' === context.shipment.weight ) {
                return '';
            } else {
                return mainStore.callbacks.formatDecimal( context.shipment.weight );
            }
        },

        get shipmentContentWeight() {
            const context = getContext();

            return mainStore.callbacks.formatDecimal( mainStore.actions.getShipmentContentWeight( context.shipment ) );
        },

        get shipmentContentLength() {
            const context = getContext();

            return mainStore.callbacks.formatDecimal( mainStore.actions.getShipmentContentLength( context.shipment ) );
        },

        get shipmentContentWidth() {
            const context = getContext();

            return mainStore.callbacks.formatDecimal( mainStore.actions.getShipmentContentWidth( context.shipment ) );
        },

        get shipmentContentHeight() {
            const context = getContext();

            return mainStore.callbacks.formatDecimal( mainStore.actions.getShipmentContentHeight( context.shipment ) );
        },

        get packagingOptions() {
            const context = getContext();
            const shipment = context.shipment;
            const { packagingOptions } = getConfig( 'shiptastic/fulfillments' );

            let shipmentPackagingOptions = packagingOptions.map( ( packaging ) => {
                let newPackaging = { ...packaging };

                if ( shipment.bestPackagingId && shipment.bestPackagingId === newPackaging.id ) {
                    newPackaging.title = newPackaging.title + ' (best fit)';
                }

                return newPackaging;
            } );

            console.log(shipmentPackagingOptions);

            return shipmentPackagingOptions;
        },

        get shipmentPackagingId() {
            const context = getContext();
            const shipment = context.shipment;

            if ( shipment.hasNewBestPackaging ) {
                shipment.hasNewBestPackaging = false;
                shipment.packagingId = shipment.bestPackagingId;
            }

            if ( shipment.packagingId > 0 ) {
                return shipment.packagingId;
            } else {
                return 0;
            }
        },

        get formattedShipmentWidth() {
            const context = getContext();

            if ( '' === context.shipment.width ) {
                return '';
            } else {
                return mainStore.callbacks.formatDecimal( context.shipment.width );
            }
        },

        get formattedShipmentLength() {
            const context = getContext();

            if ( '' === context.shipment.length ) {
                return '';
            } else {
                return mainStore.callbacks.formatDecimal( context.shipment.length );
            }
        },

        get formattedShipmentHeight() {
            const context = getContext();

            if ( '' === context.shipment.height ) {
                return '';
            } else {
                return mainStore.callbacks.formatDecimal( context.shipment.height );
            }
        },

        get isItemSelected() {
            const context = getContext();

            return state.selectedItems.filter( ( item ) => item.id === context.item.id ).length;
        },

        get shipments() {
            return mainStore.state.shipments;
        },

        get itemsAvailableToShip() {
            return mainStore.state.itemsAvailableToShip;
        },

        get shipmentCount() {
            return mainStore.state.shipmentCount;
        },

        get currentShipmentNumber() {
            const context = getContext();

            return mainStore.callbacks.getShipmentNumber( context.shipment );
        },
    },
    actions: {
        createShipment() {
            mainStore.actions.createShipment( state.selectedItems );
            state.selectedItems = [];
        },

        setShipmentWeight( event ) {
            const context = getContext();

            if ( '' !== event.target.value ) {
                context.shipment.weight = mainStore.callbacks.toDecimal( event.target.value );
            } else {
                context.shipment.weight = '';
            }
        },

        setShipmentWidth( event ) {
            const context = getContext();

            if ( '' !== event.target.value ) {
                context.shipment.width = mainStore.callbacks.toDecimal( event.target.value );
            } else {
                context.shipment.width = '';
            }
        },

        setShipmentLength( event ) {
            const context = getContext();

            if ( '' !== event.target.value ) {
                context.shipment.length = mainStore.callbacks.toDecimal( event.target.value );
            } else {
                context.shipment.length = '';
            }
        },

        setShipmentHeight( event ) {
            const context = getContext();

            if ( '' !== event.target.value ) {
                context.shipment.height = mainStore.callbacks.toDecimal( event.target.value );
            } else {
                context.shipment.height = '';
            }
        },

        setShipmentPackagingId( event ) {
            const context = getContext();

            context.shipment.packagingId = parseInt( event.target.value ) || 0;

            if ( 0 === context.shipment.packagingId ) {
                context.shipment.length = '';
                context.shipment.width = '';
                context.shipment.height = '';
            }
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

            item.quantity = parseInt( event.target.value ) || 0;
        },

        setShipmentItemQuantity( event ) {
            const context = getContext();
            let quantity = ( parseInt( event.target.value ) || 0 );
            const maxQuantity = state.shipmentItemMaxQuantity;

            if ( quantity > maxQuantity ) {
                quantity = maxQuantity;
            }

            mainStore.actions.setShipmentItemQuantity( context.shipment, context.shipment_item, quantity );
        },

        deleteShipment() {
            const context = getContext();

            mainStore.actions.deleteShipment( context.shipment );
        },

        deleteShipmentItem() {
            const context = getContext();

            mainStore.actions.deleteShipmentItem( context.shipment, context.shipment_item );
        },

        onShipmentItemDrag( event ) {
            const context = getContext();

            state.currentShipmentItemDragged = context.shipment_item ? context.shipment_item : context.item;
        },

        onShipmentItemDragOver: withSyncEvent( ( event ) => {
            event.preventDefault();
        } ),

        onShipmentItemDrop: withSyncEvent( ( event ) => {
            const context = getContext();

            event.preventDefault();

            mainStore.actions.addShipmentItem( context.shipment, state.currentShipmentItemDragged );

            state.currentShipmentItemDragged = null;
        } ),

        stopPropagation: withSyncEvent( ( event ) => {
            event.stopPropagation();
        } ),

        done: withSyncEvent( function* ( event ) {
            const formData = new FormData();
            formData.append( 'create_shipments', JSON.stringify( state.shipments ) );

            yield mainStore.actions.done( event, formData );
        } ),
    },
    callbacks: {
        setupShipment() {
            const context = getContext();

            if ( undefined === context.shipment.weightUnit ) {
                const { weightUnit } = getConfig( 'shiptastic/fulfillments' );

                context.shipment.weightUnit = weightUnit;
            }

            if ( undefined === context.shipment.dimensionUnit ) {
                const { dimensionUnit } = getConfig( 'shiptastic/fulfillments' );

                context.shipment.dimensionUnit = dimensionUnit;
            }

            if ( undefined === context.shipment.editable ) {
                context.shipment.editable = true;
            }
        },

        onInit() {
            console.log('on init create_shipments');

            state.selectedItems = [];
            state.currentShipmentItemDragged = null;

            const curLocalShipments = mainStore.actions.getLocalState( 'shipments' );

            if ( curLocalShipments && curLocalShipments.dateModified > mainStore.state.shipmentsSavedAt ) {
                const { allItemsAvailableToShip } = getServerState( 'shiptastic/fulfillments' );
                let allItemsMap = allItemsAvailableToShip.reduce( ( ac, item ) => ({...ac, [item.id]: item }), {} );

                /**
                 * Validate shipment item quantities
                 */
                const validShipments = curLocalShipments.value.filter( ( shipment ) => {
                    const validItems = shipment.items.filter( ( item ) => {
                        if ( allItemsMap.hasOwnProperty( item.id ) ) {
                            allItemsMap[ item.id ].maxQuantity -= item.quantity;

                            if ( allItemsMap[ item.id ].maxQuantity < 0 ) {
                                return false;
                            }

                            return true;
                        } else {
                            return false;
                        }
                    } );

                    if ( validItems.length !== shipment.items.length ) {
                        return false;
                    }

                    return true;
                } );

                if ( validShipments.length > 0 ) {
                    console.log( 'loading shipment local state' );
                    mainStore.state.shipments = validShipments;
                }
            }
        },

        onUpdate() {
            const serverState = getServerState( 'shiptastic/fulfillments' );
            const shipments = state.shipments;

            shipments.map( ( shipment ) => {
                const newWeight = shipment.weight;
                const newLength = shipment.length;
                const newWidth = shipment.width;
                const newHeight = shipment.height;
                const packagingId = shipment.packagingId;

                shipment.items.map( ( item ) => {
                    const quantity = item.quantity;
                } );
            } );

            if ( undefined !== serverState.shipments ) {
                console.log('persisting shipment local state');
                console.log(shipments);

                mainStore.actions.setLocalState( 'shipments', shipments );
            }
        },

        onSave() {
            console.log('on save create_shipments');

            mainStore.actions.deleteLocalState( 'shipments' );
        },

        renderItemAttributeLabel() {
            const context = getContext();
            const element = getElement();

            element.ref.innerHTML = context.attribute.label;
        },

        renderItemAttributeValue() {
            const context = getContext();
            const element = getElement();

            element.ref.innerHTML = context.attribute.value;
        },

        renderPackagingTitle() {
            const context = getContext();
            const element = getElement();

            element.ref.innerHTML = context.packaging.title;
        },

        findBestPackaging() {
            const context = getContext();
            const shipment = context.shipment;

            shipment.items.map( ( item ) => {
                const itemQuantity = item.quantity;
            } );

            const bestPackaging = mainStore.actions.getBestPackagingForShipment( shipment );

            if ( bestPackaging && bestPackaging.id !== shipment.bestPackagingId ) {
                shipment.bestPackagingId = bestPackaging.id;
                shipment.hasNewBestPackaging = true;
            } else if ( ! bestPackaging ) {
                shipment.bestPackagingId = 0;
            }

            console.log('best packaging id');
            console.log(shipment.bestPackagingId);
        },

        updatePackaging() {
            const context = getContext();

            const packaging = mainStore.callbacks.getPackagingById( context.shipment.packagingId );

            if ( packaging ) {
                context.shipment.length = packaging.length;
                context.shipment.width = packaging.width;
                context.shipment.height = packaging.height;
            } else {
                context.shipment.packagingId = 0;
            }
        },
    }
} );
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
            const { packagingOptions } = getConfig( 'shiptastic/fulfillments' );

            return packagingOptions;
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

            return mainStore.callbacks.getCurrentShipmentNumber( context.shipment );
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

        setShipmentPackaging( event ) {
            const context = getContext();

            context.shipment.packagingId = parseInt( event.target.value ) || 0;

            const packaging = mainStore.callbacks.getPackagingById( context.shipment.packagingId );

            if ( packaging ) {
                context.shipment.length = packaging.length;
                context.shipment.width = packaging.width;
                context.shipment.height = packaging.height;
            } else {
                context.shipment.packagingId = 0;
            }

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
            const quantity = ( parseInt( event.target.value ) || 0 );

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
            formData.append( 'shipments', JSON.stringify( state.shipments ) );

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

        onUpdateState() {
            console.log('update create_shipments action state');
        },

        loadLocalState() {
            console.log('load local create_shipments state');
        },

        syncWithServer() {
            console.log('sync create_shipments with server');
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

            mainStore.actions.getBestPackagingForShipment( shipment );

            console.log('quantity changed?');
            console.log();
        },
    }
} );
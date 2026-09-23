// view.js
import { store, getContext, getElement, withSyncEvent, getServerContext, getServerState } from '@wordpress/interactivity';

const mainStore = store( 'shiptastic/fulfillments' );

const { state, actions } = store( 'shiptastic/fulfillments/create_shipments', {
    state: {
        selectedItems: [],
        currentShipmentItemDragged: null,

        get hasSelectedItems() {
            return state.selectedItems.length;
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
    },
    actions: {
        createShipment() {
            mainStore.actions.createShipment( state.selectedItems );
            state.selectedItems = [];
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

            console.log(event);
            console.log(quantity);

            mainStore.actions.setShipmentItemQuantity( context.shipment, context.shipment_item, quantity );
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
    },
    callbacks: {
        onUpdateState() {
            console.log('update create_shipments action state');
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
    }
} );
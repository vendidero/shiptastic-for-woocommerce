// view.js
import { store, getContext, getElement, withSyncEvent, getServerContext, getServerState } from '@wordpress/interactivity';

const mainStore = store( 'shiptastic/fulfillments' );

const { state, actions } = store( 'shiptastic/fulfillments/create_shipments', {
    state: {
        selectedItems: [],

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
        }
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
            context.shipment_item.quantity = parseInt( event.target.value ) || 0;

            mainStore.actions.onUpdateShipmentItemQuantity( context.shipment, context.shipment_item );
        },

        stopPropagation: withSyncEvent( ( event ) => {
            event.stopPropagation();
        } ),
    },
    callbacks: {
        onUpdateState() {
            console.log('update create_shipments action state');
        },
    }
} );
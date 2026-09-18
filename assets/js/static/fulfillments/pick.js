// view.js
import { store, getContext, getElement, withSyncEvent, getServerContext, getServerState } from '@wordpress/interactivity';

const mainStore = store( 'shiptastic/fulfillments' );

const { state, actions } = store( 'shiptastic/fulfillments/pick', {
    state: {
        pickedItems: [],

        get allShipmentItems() {
            return mainStore.state.allShipmentItems;
        }
    },
    actions: {

    },
    callbacks: {
        onUpdateState() {

        }
    }
} );
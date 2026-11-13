/**
 * Pack step: verify the shipment's items via the shared check list (scan, swipe, steppers). Shipment details
 * (packaging, provider, weight, dimensions) are edited via the shipments overlay.
 */
import { store, getConfig } from '@wordpress/interactivity';

const { state, callbacks } = store( 'shiptastic/fulfillment', {
    callbacks: {
        validate_pack() {
            return callbacks.validateChecklist( getConfig( 'shiptastic/fulfillment' ).i18n.notPacked );
        },
        initForm_pack( view ) {
            // Prefilled in case the step was reopened (or failed before).
            return { counted: view.previous && view.previous.packed ? { ...view.previous.packed } : {} };
        },
        collect_pack() {
            return { packed: { ...state.form.counted } };
        },
    },
} );

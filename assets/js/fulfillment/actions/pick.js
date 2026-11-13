/**
 * Pick step: picked quantities per order item, verified via the shared check list (scan, swipe, steppers).
 */
import { store, getConfig } from '@wordpress/interactivity';

const { state, callbacks } = store( 'shiptastic/fulfillment', {
    callbacks: {
        validate_pick() {
            return callbacks.validateChecklist( getConfig( 'shiptastic/fulfillment' ).i18n.notPicked );
        },
        initForm_pick( view ) {
            // Prefilled in case the step was reopened (or failed before).
            return { counted: view.previous && view.previous.picked ? { ...view.previous.picked } : {} };
        },
        collect_pick() {
            return { picked: { ...state.form.counted } };
        },
    },
} );

/**
 * Shipping documents step: uploads and document settings, see documents.js (Shiptastic Pro replaces the step with
 * the document creation).
 */
import { store } from '@wordpress/interactivity';
import { validateDocuments } from '../documents.js';

store( 'shiptastic/fulfillment', {
    callbacks: {
        initForm_create_shipping_documents() {
            return { uploading: '', regenerating: '' };
        },
        validate_create_shipping_documents() {
            return validateDocuments();
        },
    },
} );

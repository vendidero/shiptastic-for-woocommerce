/**
 * Document steps (e.g. shipping documents): the documents of `state.view.documents` (type, title, required, hint,
 * shipmentId, editable, regenerable, file) are uploaded right away when a file is chosen (POST queue/{id}/documents,
 * multipart). Documents created by extensions may be recreated (↻) or edited within a sheet (settings form, saving
 * (re-)creates the document), see POST queue/{id}/shipments/{shipment_id}/document-action. Documents are optional,
 * steps check the ones marked as required (e.g. by Shiptastic Pro) when continuing.
 */
import { store, getContext, getConfig, getElement } from '@wordpress/interactivity';
import * as api from './api.js';

const NAMESPACE = 'shiptastic/fulfillment';
const i18n = () => getConfig( NAMESPACE ).i18n;

// The sheet's form container.
let formElement = null;
let formRequest = 0;

const isVisible = ( element ) => !! ( element.offsetWidth || element.offsetHeight || element.getClientRects().length );

/**
 * Values of a form's visible fields (and hidden inputs) as nested object, field names parsed like PHP does, e.g.
 * commercial_invoice[items][12][hs_code] - names ending with [] are lists.
 *
 * @param {HTMLElement} form
 * @return {Object}
 */
function serializeNested( form ) {
    const data = {};

    form.querySelectorAll( 'input, select, textarea' ).forEach( ( field ) => {
        if ( ! field.name || field.disabled || ( 'hidden' !== field.type && ! isVisible( field ) ) ) {
            return;
        }

        if ( [ 'checkbox', 'radio' ].includes( field.type ) && ! field.checked ) {
            return;
        }

        const keys = field.name.replace( /\]/g, '' ).split( '[' );
        const values = 'SELECT' === field.tagName && field.multiple ? Array.from( field.selectedOptions ).map( ( option ) => option.value ) : [ field.value ];
        let target = data;

        keys.forEach( ( key, index ) => {
            const isLast = index === keys.length - 1;
            const nextIsList = ! isLast && '' === keys[ index + 1 ] && index + 1 === keys.length - 1;

            if ( isLast ) {
                if ( '' === key ) {
                    return;
                }

                target[ key ] = values.length > 1 ? values : values[ 0 ];
            } else if ( nextIsList ) {
                target[ key ] = [ ...( Array.isArray( target[ key ] ) ? target[ key ] : [] ), ...values ];
            } else {
                target[ key ] = target[ key ] && 'object' === typeof target[ key ] ? target[ key ] : {};
                target = target[ key ];
            }
        } );
    } );

    return data;
}

const { state, actions } = store( NAMESPACE, {
    state: {
        documents: {
            // Settings sheet of a document (e.g. commercial invoice): type, shipmentId, title, html.
            sheet: { open: false, loading: false, saving: false, type: '', shipmentId: 0, title: '', html: '' },
            get hasFile() {
                const { document } = getContext();
                return !! document.file;
            },
            get fileUrl() {
                const { document } = getContext();
                return document.file ? document.file.url : '';
            },
            get fileName() {
                const { document } = getContext();
                return document.file ? document.file.name : '';
            },
            get isUploading() {
                const { document } = getContext();
                return state.form.uploading === document.type;
            },
            // Highlighted once continuing failed validation.
            get isMissing() {
                const { document } = getContext();
                return state.showValidation && document.required && ! document.file;
            },
            get isRegenerating() {
                const { document } = getContext();
                return state.form.regenerating === document.type;
            },
            get isSheetBusy() {
                return state.documents.sheet.loading || state.documents.sheet.saving;
            },
            get saveLabel() {
                return state.documents.sheet.saving ? i18n().documentSaving : i18n().documentSave;
            },
            get uploadLabel() {
                const { document } = getContext();

                if ( state.form.uploading === document.type ) {
                    return i18n().documentUploading;
                }

                return document.file ? i18n().documentReplace : i18n().documentUpload;
            },
        },
    },
    actions: {
        documents: {
            // Opens the settings sheet of a document and loads its form.
            *openForm() {
                const { document } = getContext();
                const item = state.currentItem;
                const request = ++formRequest;

                if ( ! item ) {
                    return;
                }

                state.documents.sheet = { open: true, loading: true, saving: false, type: document.type, shipmentId: document.shipmentId, title: document.title + ( state.currentShipmentNumber ? ' · ' + state.currentShipmentNumber : '' ), html: '' };

                try {
                    const response = yield api.request( `queue/${ item.id }/shipments/${ document.shipmentId }/document-form`, { query: { token: item.lock_token, type: document.type } } );

                    if ( request === formRequest ) {
                        state.documents.sheet = { ...state.documents.sheet, loading: false, html: response.html || '' };
                    }
                } catch ( error ) {
                    actions.documents.closeForm();
                    actions.handleError( error );
                }
            },
            closeForm() {
                ++formRequest;
                state.documents.sheet = { ...state.documents.sheet, open: false, loading: false, saving: false, html: '' };
            },
            // Saves the form: the document is (re-)created with its values.
            *saveForm() {
                const sheet = state.documents.sheet;
                const item = state.currentItem;

                if ( ! item || ! formElement || state.documents.isSheetBusy ) {
                    return;
                }

                state.documents.sheet = { ...sheet, saving: true };

                try {
                    const response = yield runAction( item, sheet.shipmentId, sheet.type, 'save', serializeNested( formElement ) );

                    applyFile( response );
                    actions.documents.closeForm();
                    actions.notify( i18n().documentSaved.replace( '%s', sheet.title ), 'success', 3000 );
                } catch ( error ) {
                    state.documents.sheet = { ...state.documents.sheet, saving: false };
                    actions.handleError( error );
                }
            },
            // Recreates a document (e.g. a packing slip after changing the shipment).
            *regenerate() {
                const { document } = getContext();
                const item = state.currentItem;

                if ( ! item || state.form.regenerating ) {
                    return;
                }

                state.form.regenerating = document.type;

                try {
                    applyFile( yield runAction( item, document.shipmentId, document.type, 'regenerate' ) );
                } catch ( error ) {
                    actions.handleError( error );
                } finally {
                    state.form.regenerating = '';
                }
            },
            *upload( event ) {
                const { document } = getContext();
                const input = event.target;
                const file = input.files && input.files[ 0 ];
                const item = state.currentItem;

                if ( ! file || ! item ) {
                    return;
                }

                const body = new FormData();

                body.append( 'token', item.lock_token );
                body.append( 'type', document.type );
                body.append( 'shipment_id', String( document.shipmentId || 0 ) );
                body.append( 'file', file );

                state.form.uploading = document.type;

                try {
                    applyFile( yield api.request( `queue/${ item.id }/documents`, { method: 'POST', body } ) );
                } catch ( error ) {
                    actions.handleError( error );
                } finally {
                    state.form.uploading = '';
                    input.value = '';
                }
            },
        },
    },
} );

store( NAMESPACE, {
    callbacks: {
        // Renders the loaded form into the sheet.
        renderDocumentForm() {
            const { ref } = getElement();
            const html = state.documents.sheet.html || '';

            if ( ! ref ) {
                return;
            }

            formElement = ref;

            if ( ref.stcRenderedHtml !== html ) {
                ref.innerHTML = html;
                ref.stcRenderedHtml = html;
            }
        },
    },
} );

/**
 * @param {Object} item       The queue item.
 * @param {number} shipmentId
 * @param {string} type       Document type.
 * @param {string} action     regenerate|save
 * @param {Object} form       Form values (save).
 * @return {Promise<Object>} type, shipment_id, file
 */
function runAction( item, shipmentId, type, action, form = {} ) {
    return api.request( `queue/${ item.id }/shipments/${ shipmentId }/document-action`, { method: 'POST', body: { token: item.lock_token, type, action, form } } );
}

/**
 * Updates the document row of an action/upload response.
 *
 * @param {Object} response type, shipment_id, file
 */
function applyFile( response ) {
    const row = ( state.view.documents || [] ).find( ( candidate ) => candidate.type === response.type && ( candidate.shipmentId || 0 ) === ( response.shipment_id || 0 ) );

    if ( row ) {
        row.file = response.file;
    }
}

/**
 * @return {string} Validation message in case required documents are missing (empty otherwise).
 */
export function validateDocuments() {
    const missing = ( state.view.documents || [] ).filter( ( row ) => row.required && ! row.file ).map( ( row ) => row.title );

    return missing.length > 0 ? i18n().documentsMissing.replace( '%s', missing.join( ', ' ) ) : '';
}

/**
 * Order details overlay (opened via the order number in the header): fulfillment relevant order data and the
 * editable shipping address.
 *
 * - Uses the WooCommerce core REST API (wc/v3/orders, permissions checked by WooCommerce).
 * - The address form renders the fields of the edit order page (incl. custom fields added via
 *   `woocommerce_admin_shipping_fields`): requested via `stc_address_fields=1`, saved via `stc_shipping`.
 * - Countries/states: preloaded and cached by countries.js (wc/v3/data/countries).
 * - Saving notifies extensions (hook orderDetailsSaved), e.g. to retry an order a run failed on.
 */
import { store, getContext, getConfig } from '@wordpress/interactivity';
import * as api from './api.js';
import * as hooks from './hooks.js';
import { loadCountries } from './countries.js';

const NAMESPACE = 'shiptastic/fulfillment';
const i18n = () => getConfig( NAMESPACE ).i18n.orderDetails;

/**
 * @param {Object} order Order (wc/v3/orders) response incl. stc_shipping_fields.
 * @return {Object} Data shown in the overlay.
 */
function toDetails( order ) {
    return {
        id: order.id,
        status: i18n().statuses[ order.status ] || order.status,
        date_created: order.date_created ? new Date( order.date_created ).toLocaleString() : '',
        shipping_method: ( order.shipping_lines || [] ).map( ( line ) => line.method_title ).join( ', ' ),
        payment_method: order.payment_method_title || '',
        customer_note: order.customer_note || '',
        email: ( order.billing && order.billing.email ) || '',
        fields: order.stc_shipping_fields || [],
    };
}

function toForm( details ) {
    const form = { email: details.email };

    details.fields.forEach( ( field ) => {
        form[ field.key ] = field.value;
    } );

    return form;
}

const { state, actions } = store( NAMESPACE, {
    state: {
        orderDetailsData: {
            get fields() {
                return state.orderDetails.data ? state.orderDetails.data.fields : [];
            },
            get fieldValue() {
                const { field } = getContext();
                const value = state.orderDetails.form[ field.key ];

                return undefined === value || null === value ? '' : value;
            },
            get emailValue() {
                return state.orderDetails.form.email || '';
            },
            get fieldId() {
                const { field } = getContext();
                return 'stc-order-field-' + field.key;
            },
            get fieldClass() {
                const { field } = getContext();
                return 'stc-field' + ( field.wide ? ' is-full' : ' is-half' );
            },
            get isInput() {
                const { field } = getContext();
                return [ 'text', 'tel', 'email' ].includes( field.type );
            },
            get inputType() {
                const { field } = getContext();
                return field.type;
            },
            /**
             * The state field is only shown in case the chosen country has states (its value is kept as is).
             */
            get isFieldHidden() {
                const { field } = getContext();
                return 'state' === field.type && ! state.orderDetailsData.hasStates;
            },
            get isTextarea() {
                const { field } = getContext();
                return 'textarea' === field.type;
            },
            get isSelect() {
                const { field } = getContext();
                return 'select' === field.type;
            },
            get isCountry() {
                const { field } = getContext();
                return 'country' === field.type;
            },
            get isStateSelect() {
                const { field } = getContext();
                return 'state' === field.type && state.orderDetailsData.hasStates;
            },
            get states() {
                const country = state.countries.find( ( candidate ) => candidate.code === state.orderDetails.form.country );
                return country ? country.states : [];
            },
            get hasStates() {
                return state.orderDetailsData.states.length > 0;
            },
            get isSelectedOption() {
                const { field, option } = getContext();
                return option.value === state.orderDetails.form[ field.key ];
            },
            get isSelectedCountry() {
                const { country } = getContext();
                return country.code === state.orderDetails.form.country;
            },
            get isSelectedState() {
                const { region } = getContext();
                return region.code === state.orderDetails.form.state;
            },
            get hasNote() {
                return !! state.orderDetails.data && !! state.orderDetails.data.customer_note;
            },
            get editUrl() {
                return state.currentItem ? state.currentItem.order_url : '';
            },
            get canSave() {
                return state.orderDetails.dirty && ! state.orderDetails.saving;
            },
        },
    },
    actions: {
        orderDetails: {
            *open() {
                const item = state.currentItem;

                if ( ! item ) {
                    return;
                }

                state.orderDetails = { open: true, loading: true, saving: false, dirty: false, error: '', data: null, form: {} };

                try {
                    const [ order ] = yield Promise.all( [
                        api.request( `wc/v3/orders/${ item.order_id }`, { root: true, query: { stc_address_fields: 1 } } ),
                        loadCountries(),
                    ] );
                    const details = toDetails( order );

                    state.orderDetails.data = details;
                    state.orderDetails.form = toForm( details );
                } catch ( error ) {
                    state.orderDetails.open = false;
                    actions.handleError( error );
                } finally {
                    state.orderDetails.loading = false;
                }

                const body = document.querySelector( '.stc-order-sheet .stc-sheet-body' );

                if ( body ) {
                    body.scrollTop = 0;
                }
            },
            close() {
                if ( state.orderDetails.dirty && ! window.confirm( i18n().discardChanges ) ) {
                    return;
                }

                state.orderDetails.open = false;
            },
            setField( event ) {
                const { field } = getContext();
                const form = { ...state.orderDetails.form, [ field.key ]: event.target.value };

                // Reset a state which does not belong to the new country.
                if ( 'country' === field.type ) {
                    const country = state.countries.find( ( candidate ) => candidate.code === event.target.value );

                    if ( country && country.states.length > 0 && ! country.states.some( ( region ) => region.code === form.state ) ) {
                        form.state = '';
                    }
                }

                state.orderDetails.form = form;
                state.orderDetails.dirty = true;
                state.orderDetails.error = '';
            },
            setEmail( event ) {
                state.orderDetails.form = { ...state.orderDetails.form, email: event.target.value };
                state.orderDetails.dirty = true;
                state.orderDetails.error = '';
            },
            *save() {
                const item = state.currentItem;
                const form = state.orderDetails.form;
                const fields = state.orderDetailsData.fields;

                if ( ! item || state.orderDetails.saving ) {
                    return;
                }

                // Fields declared as required by their definition (the edit order page does not require any).
                const missing = fields.filter( ( field ) => field.required && ! String( form[ field.key ] || '' ).trim() );

                if ( missing.length > 0 ) {
                    state.orderDetails.error = i18n().required.replace( '%s', missing.map( ( field ) => field.label ).join( ', ' ) );
                    return;
                }

                const values = {};

                fields.forEach( ( field ) => {
                    values[ field.key ] = undefined === form[ field.key ] || null === form[ field.key ] ? '' : form[ field.key ];
                } );

                state.orderDetails.saving = true;
                state.orderDetails.error = '';

                try {
                    const details = toDetails(
                        yield api.request( `wc/v3/orders/${ item.order_id }`, {
                            root: true,
                            method: 'PUT',
                            body: { stc_address_fields: 1, stc_shipping: values, billing: { email: form.email || '' } },
                        } )
                    );

                    state.orderDetails.data = details;
                    state.orderDetails.form = toForm( details );
                    state.orderDetails.dirty = false;
                    state.orderDetails.open = false;
                    state.orderDetails.saving = false;

                    actions.notify( i18n().saved, 'info', 3000 );

                    // E.g. fixing an order a run of an extension failed on: the extension retries right away.
                    yield hooks.applyFiltersAsync( 'orderDetailsSaved', false );
                } catch ( error ) {
                    // E.g. an invalid email address (WooCommerce validation) or missing permissions.
                    state.orderDetails.error = error && error.message ? error.message : getConfig( NAMESPACE ).i18n.error;
                } finally {
                    state.orderDetails.saving = false;
                }
            },
        },
    },
} );


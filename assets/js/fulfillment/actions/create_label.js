/**
 * Create label step: review of the provider's default label settings + create (submit). On request, the whole label
 * form of the provider (same markup as the label modal of the shipment admin) is loaded into the step
 * (GET queue/{id}/shipments/{shipment_id}/label-form) and its values are submitted as label props.
 *
 * Form behavior mirrors the admin label modal (admin-shipment-label.js, admin-shipment-modal.js): "More services"
 * toggles, fields depending on the chosen product (data-products-supported) and mutually exclusive services
 * (data-exclude-if-checked). Only visible fields are submitted.
 */
import { store, getElement, getConfig } from '@wordpress/interactivity';
import * as api from '../api.js';

const NAMESPACE = 'shiptastic/fulfillment';
const i18n = () => getConfig( NAMESPACE ).i18n;

// The form container (rendered once, reused for every shipment).
let formElement = null;
let formRequest = 0;

const isVisible = ( element ) => !! ( element.offsetWidth || element.offsetHeight || element.getClientRects().length );
const fieldWrapper = ( element ) => element.closest( '.form-field' );

/**
 * Fields are hidden for several reasons (product, excluded service, show-if dependency), tracked separately so that
 * one rule does not show a field hidden by another one.
 *
 * @param {HTMLElement} element
 * @param {boolean}     visible
 * @param {string}      reason
 */
function setFieldVisible( element, visible, reason ) {
    const wrapper = fieldWrapper( element );

    if ( ! wrapper ) {
        return;
    }

    const reasons = new Set( ( wrapper.dataset.stcHidden || '' ).split( ' ' ).filter( Boolean ) );

    if ( visible ) {
        reasons.delete( reason );
    } else {
        reasons.add( reason );
    }

    wrapper.dataset.stcHidden = Array.from( reasons ).join( ' ' );
    wrapper.hidden = reasons.size > 0;
}

const isShown = ( element ) => {
    const wrapper = fieldWrapper( element );
    return ! wrapper || ! wrapper.hidden;
};

/**
 * Fields depending on other fields (`data-show-if-{field id}`): shown in case the field is shown and checked
 * (checkboxes), its value is contained in the attribute value or, for an empty attribute value, it is not empty.
 * Checkboxes with the class `show-if-trigger` toggle the elements matching their `data-show-if` selector. Applied
 * repeatedly to resolve chained dependencies.
 *
 * @param {HTMLElement} form
 */
function applyShowIf( form ) {
    const fields = Array.from( form.querySelectorAll( 'input, select, textarea' ) );
    const dependents = fields.filter( ( field ) => Array.from( field.attributes ).some( ( attr ) => attr.name.startsWith( 'data-show-if-' ) ) );
    // Attribute names are lower case (HTML), field ids are not.
    const controllers = new Map( fields.filter( ( field ) => field.id ).map( ( field ) => [ field.id.toLowerCase(), field ] ) );

    for ( let pass = 0; pass < 5; pass++ ) {
        let changed = false;

        dependents.forEach( ( field ) => {
            const before = isShown( field );
            const visible = Array.from( field.attributes )
                .filter( ( attr ) => attr.name.startsWith( 'data-show-if-' ) )
                .every( ( attr ) => {
                    const controller = controllers.get( attr.name.replace( 'data-show-if-', '' ) );

                    if ( ! controller || ! isShown( controller ) ) {
                        return ! controller;
                    }

                    if ( 'checkbox' === controller.type ) {
                        return controller.checked;
                    }

                    const value = controller.value || '';

                    return '' === attr.value ? '' !== value && '0' !== value : '' !== value && attr.value.includes( value );
                } );

            setFieldVisible( field, visible, 'show-if' );
            changed = changed || before !== isShown( field );
        } );

        if ( ! changed ) {
            break;
        }
    }

    form.querySelectorAll( '.show-if-trigger[data-show-if]' ).forEach( ( trigger ) => {
        form.querySelectorAll( trigger.dataset.showIf ).forEach( ( element ) => {
            element.hidden = ! trigger.checked;
        } );
    } );
}

/**
 * Fields (or select options) only supported by some products: `data-products-supported` contains product ids
 * ("a,b") or, for selects, per option product ids ("&option=a,b&option2=c").
 *
 * @param {HTMLElement} form
 */
function applyProduct( form ) {
    const product = form.querySelector( '#product_id' );
    const productId = product ? product.value : '';

    form.querySelectorAll( '[data-products-supported]' ).forEach( ( field ) => {
        const supported = field.dataset.productsSupported || '';

        if ( ! supported ) {
            return;
        }

        if ( supported.includes( '&' ) && 'SELECT' === field.tagName ) {
            let resetNeeded = false;

            supported.split( '&' ).filter( Boolean ).forEach( ( entry ) => {
                const [ optionKey, products = '' ] = entry.split( '=' );
                const option = Array.from( field.options ).find( ( candidate ) => candidate.value === optionKey );

                if ( option ) {
                    const hidden = ! products.split( ',' ).includes( productId );

                    option.hidden = hidden;
                    option.disabled = hidden;
                    resetNeeded = resetNeeded || ( hidden && option.selected );
                }
            } );

            const firstVisible = Array.from( field.options ).find( ( option ) => ! option.hidden );

            if ( resetNeeded && firstVisible ) {
                firstVisible.selected = true;
            }

            setFieldVisible( field, !! firstVisible, 'product' );
        } else {
            setFieldVisible( field, supported.split( ',' ).filter( Boolean ).includes( productId ), 'product' );
        }
    } );

    applyServices( form );
}

/**
 * Services excluding each other (`data-exclude-if-checked`: service names).
 *
 * @param {HTMLElement} form
 * @param {HTMLElement|null} changed The service checkbox just changed.
 */
function applyServices( form, changed = null ) {
    const selected = Array.from( form.querySelectorAll( '.label-service-list input[type=checkbox]:checked' ) )
        .map( ( input ) => input.name || '' )
        .filter( ( name ) => name.includes( 'service_' ) )
        .map( ( name ) => name.replace( 'service_', '' ) );

    form.querySelectorAll( '.form-field [data-exclude-if-checked]' ).forEach( ( field ) => {
        if ( field === changed ) {
            return;
        }

        const excluded = String( field.dataset.excludeIfChecked || '' ).split( ',' ).filter( Boolean );

        if ( excluded.length > 0 ) {
            const conflict = excluded.some( ( service ) => selected.includes( service ) );

            if ( conflict ) {
                field.checked = false;
            }

            setFieldVisible( field, ! conflict, 'service' );
        }
    } );

    applyShowIf( form );
}

/**
 * "More services" / "Fewer services" toggles.
 *
 * @param {HTMLElement} form
 * @param {boolean}     expand
 */
function toggleMore( form, expand ) {
    form.querySelectorAll( '.show-more-wrapper' ).forEach( ( wrapper ) => wrapper.classList.toggle( 'hide-default', ! expand ) );
    form.querySelectorAll( '.show-more-trigger .show-more' ).forEach( ( link ) => link.classList.toggle( 'hide-default', expand ) );
    form.querySelectorAll( '.show-more-trigger .show-fewer' ).forEach( ( link ) => link.classList.toggle( 'hide-default', ! expand ) );
}

/**
 * Groups each service checkbox with its dependent fields (e.g. "Transportversicherung" + "Warenwert"), which follow it
 * as siblings (or within a `.columns` wrapper), into one cell of the services grid: the details stay below their
 * service instead of taking the next column.
 *
 * @param {HTMLElement} form
 */
function groupServices( form ) {
    const dependsOn = ( element, id ) => {
        const fields = element.matches( 'input, select, textarea' ) ? [ element ] : Array.from( element.querySelectorAll( 'input, select, textarea' ) );

        return fields.length > 0 && fields.every( ( field ) => field.hasAttribute( 'data-show-if-' + id ) );
    };

    form.querySelectorAll( '.label-service-list > p.form-field-checkbox' ).forEach( ( service ) => {
        const checkbox = service.querySelector( 'input[type=checkbox]' );

        if ( ! checkbox || ! checkbox.id ) {
            return;
        }

        const id = checkbox.id.toLowerCase();
        const details = [];
        let next = service.nextElementSibling;

        while ( next && ( next.matches( 'p.form-field' ) || next.matches( '.columns' ) ) && dependsOn( next, id ) ) {
            details.push( next );
            next = next.nextElementSibling;
        }

        if ( details.length > 0 ) {
            const group = document.createElement( 'div' );

            group.className = 'stc-label-service';
            service.before( group );
            group.append( service, ...details );
        }
    } );
}

function initForm( form ) {
    form.addEventListener( 'click', ( event ) => {
        const more = event.target.closest( '.show-more' );
        const fewer = event.target.closest( '.show-fewer' );

        if ( more || fewer ) {
            event.preventDefault();
            toggleMore( form, !! more );
        }
    } );

    form.addEventListener( 'change', ( event ) => {
        if ( 'product_id' === event.target.id ) {
            applyProduct( form );
        } else if ( event.target.closest( '.label-service-list' ) && 'checkbox' === event.target.type ) {
            applyServices( form, event.target );
        } else {
            applyShowIf( form );
        }
    } );
}

/**
 * Values of the visible fields (and hidden inputs), like the admin label modal: names with [] are lists, later
 * values override earlier ones (e.g. the "no" placeholder of checked checkboxes).
 *
 * @param {HTMLElement} form
 * @return {Object}
 */
function serializeForm( form ) {
    // Collapsed services are submitted too.
    const collapsed = Array.from( form.querySelectorAll( '.show-more-wrapper.hide-default' ) );

    collapsed.forEach( ( wrapper ) => wrapper.classList.remove( 'hide-default' ) );

    const props = {};

    form.querySelectorAll( 'input, select, textarea' ).forEach( ( field ) => {
        if ( ! field.name || field.disabled || ( 'hidden' !== field.type && ! isVisible( field ) ) ) {
            return;
        }

        if ( [ 'checkbox', 'radio' ].includes( field.type ) && ! field.checked ) {
            return;
        }

        const values = 'SELECT' === field.tagName && field.multiple ? Array.from( field.selectedOptions ).map( ( option ) => option.value ) : [ field.value ];

        if ( field.name.includes( '[]' ) ) {
            const name = field.name.replace( '[]', '' );

            props[ name ] = [ ...( Array.isArray( props[ name ] ) ? props[ name ] : [] ), ...values ];
        } else {
            props[ field.name ] = values[ 0 ];
        }
    } );

    collapsed.forEach( ( wrapper ) => wrapper.classList.add( 'hide-default' ) );

    return props;
}

const { state, actions } = store( NAMESPACE, {
    state: {
        createLabel: {
            get showOverview() {
                return ! state.view.hasLabel && ! state.form.editing && ( !! state.view.product || ( state.view.package || [] ).length > 0 );
            },
            // Further options of the label, e.g. "Print format: Standard".
            get optionsText() {
                return ( state.view.options || [] ).map( ( option ) => option.label + ': ' + option.value ).join( ' · ' );
            },
            get showForm() {
                return !! state.form.editing && !! state.form.html;
            },
        },
    },
    actions: {
        createLabel: {
            *openForm() {
                const item = state.currentItem;
                const shipmentId = state.view.shipmentId;

                if ( ! item || ! shipmentId ) {
                    return;
                }

                state.form.editing = true;

                if ( state.form.html ) {
                    return;
                }

                const request = ++formRequest;

                state.form.loading = true;

                try {
                    const response = yield api.request( `queue/${ item.id }/shipments/${ shipmentId }/label-form`, { query: { token: item.lock_token } } );

                    // Still the same shipment.
                    if ( request === formRequest && state.view.shipmentId === shipmentId ) {
                        state.form.html = response.html || '';
                    }
                } catch ( error ) {
                    state.form.editing = false;
                    actions.handleError( error );
                } finally {
                    if ( request === formRequest ) {
                        state.form.loading = false;
                    }
                }
            },
            closeForm() {
                state.form.editing = false;
            },
        },
    },
    callbacks: {
        initForm_create_label() {
            ++formRequest;

            return { editing: false, loading: false, html: '' };
        },
        submitLabel_create_label() {
            return state.view.hasLabel ? '' : i18n().createLabel;
        },
        collect_create_label() {
            if ( state.form.editing && state.form.html && formElement ) {
                return { props: serializeForm( formElement ) };
            }

            return {};
        },
        // Renders the loaded label form (once per shipment) and applies the dependencies of its fields.
        renderLabelForm() {
            const { ref } = getElement();
            const html = state.form.html || '';

            if ( ! ref ) {
                return;
            }

            if ( ! formElement ) {
                formElement = ref;
                initForm( ref );
            }

            if ( ref.stcRenderedHtml !== html ) {
                ref.innerHTML = html;
                ref.stcRenderedHtml = html;

                if ( html ) {
                    groupServices( ref );
                    applyProduct( ref );
                }
            }
        },
    },
} );

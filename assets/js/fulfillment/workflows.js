/**
 * Workflow editor (managers): create, edit and delete workflows in a full overlay.
 *
 * - General: title, active, mode (in case there are several, e.g. added by extensions), priority.
 * - Steps: actions in order incl. their settings (mode/scope in case an action supports several), reorder, remove, add.
 * - Orders: filters (locations, shipping providers, countries, items, weight, backorders).
 * Extensions add settings via hooks (see hooks.js: workflowForm, workflowSaveBody, workflowStepVisible, ...) and
 * template actions.
 *
 * Choices are loaded on demand when the editor is opened the first time: Shiptastic specific ones from
 * `workflows/editor`, countries (wc/v3/data/countries) from WooCommerce core.
 */
import { store, getContext, getConfig } from '@wordpress/interactivity';
import * as api from './api.js';
import * as hooks from './hooks.js';
import { loadCountries } from './order.js';

const NAMESPACE = 'shiptastic/fulfillment';
const i18n = () => getConfig( NAMESPACE ).i18n.workflowEditor;

let choicesRequest = null;
let stepCounter = 0;

/**
 * Loads the editor choices once.
 *
 * @return {Promise}
 */
function loadChoices() {
    if ( ! choicesRequest ) {
        choicesRequest = Promise.all( [
            api.request( 'workflows/editor' ),
            loadCountries(),
        ] )
            .then( ( [ editor ] ) => {
                state.workflowChoices = { ...editor, loaded: true };
            } )
            .catch( ( error ) => {
                choicesRequest = null;
                throw error;
            } );
    }

    return choicesRequest;
}

function defaultForm() {
    return {
        id: 0,
        title: '',
        status: 'active',
        priority: 0,
        mode: 'interactive',
        steps: [],
        filters: { locations: [], shipping_providers: [], countries: [], min_items: '', max_items: '', min_weight: '', max_weight: '', backorders: 'any' },
    };
}

function emptyForm() {
    return hooks.applyFilters( 'workflowForm', defaultForm(), null );
}

function toForm( workflow ) {
    const form = defaultForm();

    return hooks.applyFilters(
        'workflowForm',
        {
            ...form,
            id: workflow.id,
            title: workflow.title,
            status: workflow.status,
            priority: workflow.priority,
            mode: workflow.mode,
            steps: workflow.steps.map( ( step ) => ( { key: 'step-' + ( ++stepCounter ), action: step.action, settings: { ...( Array.isArray( step.settings ) ? {} : step.settings ) } } ) ),
            filters: { ...form.filters, ...workflow.filters },
        },
        workflow
    );
}

function getAction( name ) {
    return state.workflowChoices.actions ? state.workflowChoices.actions.find( ( action ) => action.name === name ) : null;
}

function scrollToTop() {
    const body = document.querySelector( '.stc-workflow-sheet .stc-sheet-body' );

    if ( body ) {
        body.scrollTop = 0;
    }
}

/**
 * Extensions may hide steps depending on the form (e.g. the workflow mode): hidden steps are not saved, they are
 * shown again once visible.
 *
 * @param {Object} step
 * @return {boolean}
 */
function isStepVisible( step ) {
    return !! hooks.applyFilters( 'workflowStepVisible', true, step, state.workflowEditor.form );
}

/**
 * @param {Object} action
 * @return {string[]} The modes a step of the action may run in within the workflow (the first is the default).
 */
function getStepModes( action ) {
    return hooks.applyFilters( 'workflowStepModes', action.modes, action, state.workflowEditor.form );
}

function getVisibleSteps() {
    return state.workflowEditor.form.steps.filter( isStepVisible );
}

/**
 * Actions (transitively) required by an action, e.g. create_shipments for pack.
 *
 * @param {string} name
 * @return {string[]}
 */
function getRequiredActions( name ) {
    const required = [];
    const pending = [ name ];

    while ( pending.length > 0 ) {
        const action = getAction( pending.shift() );

        ( action ? action.requires : [] ).forEach( ( candidate ) => {
            if ( candidate !== name && ! required.includes( candidate ) ) {
                required.push( candidate );
                pending.push( candidate );
            }
        } );
    }

    return required;
}

/**
 * Whether two neighboring steps may swap: a step never moves above a step it requires (dependency order).
 *
 * @param {Object} upper
 * @param {Object} lower
 * @return {boolean}
 */
function canSwap( upper, lower ) {
    return !! upper && !! lower && ! getRequiredActions( lower.action ).includes( upper.action );
}

function getNeighbor( step, offset ) {
    const visible = getVisibleSteps();
    const position = visible.findIndex( ( candidate ) => candidate.key === step.key );

    return position < 0 ? null : visible[ position + offset ] || null;
}

function getRangeValue( key ) {
    const value = state.workflowEditor.form.filters[ key ];
    return undefined === value || null === value ? '' : String( value );
}

function isRangeInvalid( range ) {
    const min = getRangeValue( 'min_' + range );
    const max = getRangeValue( 'max_' + range );

    return '' !== min && '' !== max && parseFloat( min ) > parseFloat( max );
}

function markDirty() {
    state.workflowEditor.dirty = true;
    state.workflowEditor.error = '';
}

/**
 * Toggles a value of a list filter.
 *
 * @param {string} filter
 * @param {string} value
 * @param {boolean} checked
 */
function toggleFilter( filter, value, checked ) {
    const values = state.workflowEditor.form.filters[ filter ].filter( ( candidate ) => candidate !== value );

    if ( checked ) {
        values.push( value );
    }

    state.workflowEditor.form.filters = { ...state.workflowEditor.form.filters, [ filter ]: values };
    markDirty();
}

const { state, actions } = store( NAMESPACE, {
    state: {
        workflowEditorData: {
            get isEditing() {
                return state.workflowEditor.form.id > 0;
            },
            get title() {
                return state.workflowEditorData.isEditing ? i18n().editTitle : i18n().newTitle;
            },
            get isActive() {
                return 'active' === state.workflowEditor.form.status;
            },
            get isWorkflowMode() {
                const { value } = getContext();
                return value === state.workflowEditor.form.mode;
            },
            get visibleSteps() {
                return getVisibleSteps();
            },
            get hasSteps() {
                return state.workflowEditorData.visibleSteps.length > 0;
            },
            // Step list
            get stepNumber() {
                const { step } = getContext();
                return state.workflowEditorData.visibleSteps.findIndex( ( candidate ) => candidate.key === step.key ) + 1;
            },
            get stepTitle() {
                const { step } = getContext();
                const action = getAction( step.action );
                return action ? action.title : step.action;
            },
            get stepDescription() {
                const { step } = getContext();
                const action = getAction( step.action );
                return action ? action.description : '';
            },
            get stepRequires() {
                const { step } = getContext();
                const action = getAction( step.action );
                const listed = state.workflowEditor.form.steps.map( ( candidate ) => candidate.action );
                const missing = action ? action.requires.filter( ( required ) => ! listed.includes( required ) ).map( ( required ) => ( getAction( required ) || { title: required } ).title ) : [];

                return missing.length > 0 ? i18n().requires.replace( '%s', missing.join( ', ' ) ) : '';
            },
            get canMoveUp() {
                const { step } = getContext();
                return canSwap( getNeighbor( step, -1 ), step );
            },
            get canMoveDown() {
                const { step } = getContext();
                return canSwap( step, getNeighbor( step, 1 ) );
            },
            get stepSettings() {
                const { step } = getContext();
                const action = getAction( step.action );

                if ( ! action ) {
                    return [];
                }

                return action.settings;
            },
            /*
             * Mode (automatically or interactively within the app) and scope (per order or per shipment): a choice in
             * case several are available, a fixed hint otherwise.
             */
            get stepModeChoices() {
                const { step } = getContext();
                const action = getAction( step.action );
                const modes = action ? getStepModes( action ) : [];
                // Modes requiring Shiptastic Pro: presented (disabled) with a pro marker.
                const proModes = action && ! getConfig( NAMESPACE ).pro?.automation ? ( action.pro_modes || [] ).filter( ( mode ) => ! modes.includes( mode ) ) : [];

                if ( modes.length + proModes.length < 2 ) {
                    return [];
                }

                const current = step.settings.mode && modes.includes( step.settings.mode ) ? step.settings.mode : modes[ 0 ];
                const choice = ( mode, isPro ) => ( {
                    value: mode,
                    label: i18n().modes[ mode ] || mode,
                    checked: ! isPro && mode === current,
                    name: step.key + '-mode',
                    isPro,
                    title: isPro ? i18n().proMode : '',
                } );

                return [ ...modes.map( ( mode ) => choice( mode, false ) ), ...proModes.map( ( mode ) => choice( mode, true ) ) ];
            },
            get hasStepModeChoice() {
                return state.workflowEditorData.stepModeChoices.length > 0;
            },
            get stepModeText() {
                const { step } = getContext();
                const action = getAction( step.action );

                if ( ! action || state.workflowEditorData.hasStepModeChoice ) {
                    return '';
                }

                const modes = getStepModes( action );

                return i18n().modeHints[ modes.length > 0 ? modes[ 0 ] : action.default_mode ] || '';
            },
            get stepScopeChoices() {
                const { step } = getContext();
                const action = getAction( step.action );

                if ( ! action || action.scopes.length < 2 ) {
                    return [];
                }

                const current = step.settings.scope && action.scopes.includes( step.settings.scope ) ? step.settings.scope : action.default_scope;

                return action.scopes.map( ( scope ) => ( { value: scope, label: i18n().scopes[ scope ] || scope, checked: scope === current, name: step.key + '-scope' } ) );
            },
            get hasStepScopeChoice() {
                return state.workflowEditorData.stepScopeChoices.length > 0;
            },
            get stepScopeText() {
                const { step } = getContext();
                const action = getAction( step.action );

                return action && ! state.workflowEditorData.hasStepScopeChoice ? i18n().scopes[ action.default_scope ] || '' : '';
            },
            get settingValue() {
                const { step, setting } = getContext();
                const value = step.settings[ setting.key ];

                return undefined === value ? setting.default : value;
            },
            get settingChecked() {
                const value = state.workflowEditorData.settingValue;
                return true === value || 'yes' === value || '1' === value || 1 === value;
            },
            get isSettingSelect() {
                const { setting } = getContext();
                return 'select' === setting.type;
            },
            get isSettingCheckbox() {
                const { setting } = getContext();
                return 'checkbox' === setting.type;
            },
            get isSettingInput() {
                const { setting } = getContext();
                return 'number' === setting.type || 'text' === setting.type;
            },
            get isSelectedSettingOption() {
                const { option } = getContext();
                return String( option.value ) === String( state.workflowEditorData.settingValue );
            },
            get availableActions() {
                const listed = state.workflowEditor.form.steps.map( ( step ) => step.action );
                return ( state.workflowChoices.actions || [] ).filter( ( action ) => ! listed.includes( action.name ) && hooks.applyFilters( 'workflowActionAvailable', true, action, state.workflowEditor.form ) );
            },
            get canAddStep() {
                return state.workflowEditorData.availableActions.length > 0;
            },
            // Filters
            get listChoices() {
                const { list } = getContext();

                if ( 'countries' === list ) {
                    return state.countries.map( ( country ) => ( { value: country.code, label: country.name } ) );
                }

                return ( 'locations' === list ? state.workflowChoices.locations : state.workflowChoices.providers ) || [];
            },
            get listSelected() {
                const { list } = getContext();
                const choices = state.workflowEditorData.listChoices;

                return state.workflowEditor.form.filters[ list ].map( ( value ) => choices.find( ( choice ) => choice.value === value ) || { value, label: value } );
            },
            get listAvailable() {
                const { list } = getContext();
                const selected = state.workflowEditor.form.filters[ list ];

                return state.workflowEditorData.listChoices.filter( ( choice ) => ! selected.includes( choice.value ) );
            },
            /**
             * All options are included by default, restricting to selected options happens on request (or in case
             * options are selected already).
             */
            get isListLimited() {
                const { list } = getContext();
                return state.workflowEditor.form.filters[ list ].length > 0 || !! state.workflowEditor.limited[ list ];
            },
            get isBackorders() {
                const { value } = getContext();
                return value === state.workflowEditor.form.filters.backorders;
            },
            // Range filters (context.range: items|weight), empty bounds = no limit.
            get rangeMin() {
                const { range } = getContext();
                return getRangeValue( 'min_' + range );
            },
            get rangeMax() {
                const { range } = getContext();
                return getRangeValue( 'max_' + range );
            },
            get isRangeInvalid() {
                const { range } = getContext();
                return isRangeInvalid( range );
            },
            get rangeSummary() {
                const { unit } = getContext();
                const format = ( value ) => ( '' === value ? '' : parseFloat( value ).toLocaleString( document.documentElement.lang || undefined ) );
                const min = format( state.workflowEditorData.rangeMin );
                const max = format( state.workflowEditorData.rangeMax );

                if ( '' === min && '' === max ) {
                    return i18n().rangeAny;
                }

                if ( '' === max ) {
                    return i18n().rangeMin.replace( '%s', min + ' ' + unit );
                }

                if ( '' === min ) {
                    return i18n().rangeMax.replace( '%s', max + ' ' + unit );
                }

                return i18n().rangeBetween.replace( '%1$s', min ).replace( '%2$s', max + ' ' + unit );
            },
            get canSave() {
                const valid = state.workflowEditor.dirty && ! state.workflowEditor.saving && !! state.workflowEditor.form.title.trim() && ! isRangeInvalid( 'items' ) && ! isRangeInvalid( 'weight' );

                return !! hooks.applyFilters( 'workflowCanSave', valid, state.workflowEditor.form );
            },
            // Overview
            get workflowStatusText() {
                const { workflow } = getContext();
                return 'active' === workflow.status ? '' : i18n().inactive;
            },
        },
    },
    actions: {
        workflowEditor: {
            *open() {
                const context = getContext();

                yield actions.workflowEditor.show( context && context.workflow ? context.workflow : null );
            },
            /**
             * Opens the editor for a workflow (null: a new workflow), e.g. via the URL (&workflow={id|new}).
             *
             * @param {Object|null} workflow
             */
            *show( workflow ) {
                state.workflowEditor = { open: true, loading: true, saving: false, dirty: false, error: '', limited: {}, form: workflow ? toForm( workflow ) : emptyForm() };

                try {
                    yield loadChoices();
                } catch ( error ) {
                    state.workflowEditor.open = false;
                    actions.handleError( error );
                } finally {
                    state.workflowEditor.loading = false;
                }

                scrollToTop();
            },
            /**
             * @return {boolean} Whether the editor closed (unsaved changes need a confirmation).
             */
            close() {
                if ( state.workflowEditor.dirty && ! window.confirm( i18n().discardChanges ) ) {
                    return false;
                }

                state.workflowEditor.open = false;

                return true;
            },
            setTitle( event ) {
                state.workflowEditor.form.title = event.target.value;
                markDirty();
            },
            setActive( event ) {
                state.workflowEditor.form.status = event.target.checked ? 'active' : 'inactive';
                markDirty();
            },
            setMode( event ) {
                state.workflowEditor.form.mode = event.target.value;
                hooks.applyFilters( 'workflowModeChanged', undefined, event.target.value, state.workflowEditor.form );
                markDirty();
            },
            setPriority( event ) {
                state.workflowEditor.form.priority = parseInt( event.target.value, 10 ) || 0;
                markDirty();
            },
            // Steps
            addStep( event ) {
                const name = event.target.value;

                if ( ! name ) {
                    return;
                }

                event.target.value = '';

                const action = getAction( name );
                const settings = {};

                // Store the choice explicitly for actions supporting several modes/scopes (shown right away).
                if ( action && getStepModes( action ).length > 1 ) {
                    settings.mode = getStepModes( action )[ 0 ];
                }

                if ( action && action.scopes.length > 1 ) {
                    settings.scope = action.default_scope;
                }

                const steps = [ ...state.workflowEditor.form.steps ];
                const step = { key: 'step-' + ( ++stepCounter ), action: name, settings };

                // Dependency order: insert before the first step requiring the action (e.g. create shipments first).
                const index = steps.findIndex( ( candidate ) => getRequiredActions( candidate.action ).includes( name ) );

                if ( index < 0 ) {
                    steps.push( step );
                } else {
                    steps.splice( index, 0, step );
                }

                state.workflowEditor.form.steps = steps;
                markDirty();
            },
            setStepMode() {
                const { step, choice } = getContext();
                step.settings = { ...step.settings, mode: choice.value };
                markDirty();
            },
            setStepScope() {
                const { step, choice } = getContext();
                step.settings = { ...step.settings, scope: choice.value };
                markDirty();
            },
            removeStep() {
                const { step } = getContext();
                state.workflowEditor.form.steps = state.workflowEditor.form.steps.filter( ( candidate ) => candidate.key !== step.key );
                markDirty();
            },
            moveStepUp() {
                actions.workflowEditor.moveStep( -1 );
            },
            moveStepDown() {
                actions.workflowEditor.moveStep( 1 );
            },
            moveStep( offset ) {
                const { step } = getContext();
                const steps = [ ...state.workflowEditor.form.steps ];
                const neighbor = getNeighbor( step, offset );

                // Swap with the neighboring visible step (hidden steps keep their position), respecting dependencies.
                if ( ! ( offset < 0 ? canSwap( neighbor, step ) : canSwap( step, neighbor ) ) ) {
                    return;
                }

                const index = steps.findIndex( ( candidate ) => candidate.key === step.key );
                const target = steps.findIndex( ( candidate ) => candidate.key === neighbor.key );

                [ steps[ index ], steps[ target ] ] = [ steps[ target ], steps[ index ] ];
                state.workflowEditor.form.steps = steps;
                markDirty();
            },
            setSetting( event ) {
                const { step, setting } = getContext();
                const value = 'checkbox' === setting.type ? event.target.checked : event.target.value;

                step.settings = { ...step.settings, [ setting.key ]: value };
                markDirty();
            },
            // Filters
            includeAll() {
                const { list } = getContext();

                state.workflowEditor.limited = { ...state.workflowEditor.limited, [ list ]: false };

                if ( state.workflowEditor.form.filters[ list ].length > 0 ) {
                    state.workflowEditor.form.filters = { ...state.workflowEditor.form.filters, [ list ]: [] };
                    markDirty();
                }
            },
            limitList() {
                const { list } = getContext();
                state.workflowEditor.limited = { ...state.workflowEditor.limited, [ list ]: true };
            },
            addListValue( event ) {
                const { list } = getContext();
                const value = event.target.value;

                event.target.value = '';

                if ( value ) {
                    toggleFilter( list, value, true );
                }
            },
            removeListValue() {
                const { list, choice } = getContext();
                toggleFilter( list, choice.value, false );
            },
            setRangeMin( event ) {
                const { range } = getContext();
                state.workflowEditor.form.filters = { ...state.workflowEditor.form.filters, [ 'min_' + range ]: event.target.value };
                markDirty();
            },
            setRangeMax( event ) {
                const { range } = getContext();
                state.workflowEditor.form.filters = { ...state.workflowEditor.form.filters, [ 'max_' + range ]: event.target.value };
                markDirty();
            },
            setBackorders( event ) {
                state.workflowEditor.form.filters = { ...state.workflowEditor.form.filters, backorders: event.target.value };
                markDirty();
            },
            *save() {
                const form = state.workflowEditor.form;

                if ( state.workflowEditor.saving ) {
                    return;
                }

                state.workflowEditor.saving = true;
                state.workflowEditor.error = '';

                const body = hooks.applyFilters(
                    'workflowSaveBody',
                    {
                        title: form.title.trim(),
                        status: form.status,
                        priority: form.priority,
                        mode: form.mode,
                        steps: getVisibleSteps().map( ( step ) => ( { action: step.action, settings: step.settings } ) ),
                        filters: form.filters,
                    },
                    form
                );

                try {
                    const workflow = yield api.request( form.id ? `workflows/${ form.id }` : 'workflows', { method: form.id ? 'PUT' : 'POST', body } );
                    const exists = state.workflows.some( ( candidate ) => candidate.id === workflow.id );

                    state.workflows = exists ? state.workflows.map( ( candidate ) => ( candidate.id === workflow.id ? workflow : candidate ) ) : [ ...state.workflows, workflow ];
                    state.workflowEditor.dirty = false;
                    state.workflowEditor.open = false;

                    actions.notify( i18n().saved, 'info', 3000 );
                    yield actions.loadQueue();
                } catch ( error ) {
                    // E.g. validation errors (no steps, unknown actions, settings of extensions).
                    state.workflowEditor.error = error && error.message ? error.message : getConfig( NAMESPACE ).i18n.error;
                    scrollToTop();
                } finally {
                    state.workflowEditor.saving = false;
                }
            },
            *remove() {
                const form = state.workflowEditor.form;

                if ( ! form.id || ! window.confirm( i18n().confirmDelete ) ) {
                    return;
                }

                state.workflowEditor.saving = true;

                try {
                    yield api.request( `workflows/${ form.id }`, { method: 'DELETE' } );

                    state.workflows = state.workflows.filter( ( candidate ) => candidate.id !== form.id );
                    state.workflowEditor.dirty = false;
                    state.workflowEditor.open = false;

                    actions.notify( i18n().deleted, 'info', 3000 );
                    yield actions.loadQueue();
                } catch ( error ) {
                    state.workflowEditor.error = error && error.message ? error.message : getConfig( NAMESPACE ).i18n.error;
                } finally {
                    state.workflowEditor.saving = false;
                }
            },
        },
    },
} );

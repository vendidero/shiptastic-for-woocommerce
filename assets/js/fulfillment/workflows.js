/**
 * Workflow editor (managers): create, edit and delete workflows in a full overlay.
 *
 * - General: title, mode (in case there are several, e.g. added by extensions).
 * - Steps: actions in order incl. their settings (mode/scope in case an action supports several), reorder, remove, add.
 * - Orders: filters (locations, shipping providers, countries, items, weight, backorders).
 * Extensions add settings via hooks (see hooks.js: workflowForm, workflowSaveBody, workflowStepVisible, ...) and
 * template actions.
 *
 * Choices are loaded on demand when the editor is opened the first time: Shiptastic specific ones from
 * `workflows/editor` (preloaded for managers), countries (wc/v3/data/countries, preloaded and cached, countries.js).
 */
import { store, getContext, getConfig, getElement, withSyncEvent } from '@wordpress/interactivity';
import * as api from './api.js';
import * as hooks from './hooks.js';
import { loadCountries } from './countries.js';

const NAMESPACE = 'shiptastic/fulfillment';
const i18n = () => getConfig( NAMESPACE ).i18n.workflowEditor;

let choicesRequest = null;

// Autocomplete of the including/excluding filters (queue tags): debounce timers and request ids per list.
const tagSearchTimers = {};
const tagSearchRequests = {};

// The complete list of tags (in case the server returned it, see workflows/tags): searched locally afterwards.
let allTags = null;
// The complete list was requested but is too large: searched on the server.
let tagsTooMany = false;

/**
 * @param {Object[]} tags  value, label
 * @param {string}   query
 * @return {Object[]} Tags matching the query (label or value), max. 20.
 */
function filterTags( tags, query ) {
    const needle = String( query || '' ).trim().toLowerCase();

    return tags.filter( ( tag ) => ! needle || ( tag.label + ' ' + tag.value ).toLowerCase().includes( needle ) ).slice( 0, 20 );
}
let stepCounter = 0;

/**
 * Loads the editor choices once (preloaded in the background for managers, see preloadChoices()). The countries
 * (country filter) are loaded separately (countries.js) and do not block the editor.
 *
 * @return {Promise}
 */
function loadChoices() {
    if ( ! choicesRequest ) {
        // Usually preloaded already, retried here in case the preload failed.
        loadCountries().catch( () => {} );

        choicesRequest = api.request( 'workflows/editor' )
            .then( ( editor ) => {
                state.workflowChoices = { ...editor, loaded: true };
            } )
            .catch( ( error ) => {
                choicesRequest = null;
                throw error;
            } );
    }

    return choicesRequest;
}

const isTagList = ( list ) => 'tags' === list || 'exclude_tags' === list;

/**
 * Remembers labels of tags (results, selected tags) - the chips show them.
 *
 * @param {Object[]} choices value, label
 */
function rememberTagLabels( choices ) {
    const labels = { ...( state.workflowTagLabels || {} ) };

    ( choices || [] ).forEach( ( choice ) => {
        labels[ choice.value ] = choice.label;
    } );

    state.workflowTagLabels = labels;
}

/**
 * Loads the labels of the selected tags of the edited workflow (unknown ones only).
 */
function loadSelectedTagLabels() {
    const filters = state.workflowEditor.form.filters || {};
    const known = state.workflowTagLabels || {};
    const include = [ ...( filters.tags || [] ), ...( filters.exclude_tags || [] ) ].filter( ( value ) => ! known[ value ] );

    if ( 0 === include.length || ! getConfig( NAMESPACE ).pro?.tagFilters ) {
        return;
    }

    // Comma separated (REST array param).
    api.request( 'workflows/tags', { query: { include: include.join( ',' ) } } ).then( ( response ) => rememberTagLabels( response.items ) ).catch( () => {} );
}

/**
 * Loads the editor choices in the background once the browser is idle (app start, managers only), so that opening
 * the workflow editor does not wait for them.
 */
export function preloadChoices() {
    const load = () => loadChoices().catch( () => {} );

    if ( 'requestIdleCallback' in window ) {
        window.requestIdleCallback( load, { timeout: 3000 } );
    } else {
        window.setTimeout( load, 1000 );
    }
}

function defaultForm() {
    return {
        id: 0,
        title: '',
        mode: 'interactive',
        steps: [],
        filters: { locations: [], shipping_providers: [], countries: [], tags: [], exclude_tags: [], min_items: '', max_items: '', min_weight: '', max_weight: '', backorders: 'any' },
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
            mode: workflow.mode,
            // The instance tells several steps of an action apart (assigned by the server, kept as is).
            steps: workflow.steps.map( ( step ) => ( { key: 'step-' + ( ++stepCounter ), action: step.action, instance: step.instance || '', settings: { ...( Array.isArray( step.settings ) ? {} : step.settings ) } } ) ),
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

const PHASES = [ 'before_queue', 'item', 'after_shipment', 'after_item', 'after_queue' ];

const FALLBACK_WHEN = { value: 'item:order', phase: 'item', scope: 'order', label: '', description: '' };

/**
 * When a step runs (phase + scope combined, see RunPhases::get_choices()): start of batch, per order, per shipment,
 * after each order, end of run. Steps saved with the after_shipment phase run per shipment.
 *
 * @param {Object} step
 * @return {Object} The action's choice: value, phase, scope, label, description, run_level.
 */
function getStepWhen( step ) {
    const action = getAction( step.action );
    const choices = action && action.when ? action.when : [];
    const fallback = choices.find( ( choice ) => choice.value === ( action && action.default_when ) ) || choices[ 0 ] || FALLBACK_WHEN;
    const settings = step.settings || {};
    let phase = settings.phase;
    let scope = settings.scope;

    if ( 'after_shipment' === phase && choices.some( ( choice ) => 'item:shipment' === choice.value ) ) {
        phase = 'item';
        scope = 'shipment';
    }

    if ( ! choices.some( ( choice ) => choice.phase === phase ) ) {
        phase = fallback.phase;
    }

    if ( 'item' === phase && ( ! scope || ! choices.some( ( choice ) => choice.value === 'item:' + scope ) ) ) {
        scope = 'item' === fallback.phase ? fallback.scope : ( action && action.default_scope ) || 'order';
    }

    const value = 'item' === phase ? 'item:' + scope : phase;

    return choices.find( ( choice ) => choice.value === value ) || fallback;
}

/**
 * @param {Object} step
 * @return {string} The phase the step runs in (see RunPhases).
 */
function getStepPhase( step ) {
    return getStepWhen( step ).phase;
}

/**
 * Steps are ordered by phase: start of batch, per order, after each shipment, after each order, end of run (stable).
 *
 * @param {Object[]} steps
 * @return {Object[]}
 */
function sortByPhase( steps ) {
    return steps
        .map( ( step, index ) => ( { step, index, rank: PHASES.indexOf( getStepPhase( step ) ) } ) )
        .sort( ( a, b ) => a.rank - b.rank || a.index - b.index )
        .map( ( entry ) => entry.step );
}

/**
 * Whether two neighboring steps may swap: a step never moves above a step it requires (dependency order) and steps
 * stay within their phase.
 *
 * @param {Object} upper
 * @param {Object} lower
 * @return {boolean}
 */
function canSwap( upper, lower ) {
    return !! upper && !! lower && getStepPhase( upper ) === getStepPhase( lower ) && ! getRequiredActions( lower.action ).includes( upper.action );
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

/**
 * @param {*} value Multiselect setting value: list of values (legacy single values are converted, empty = all).
 * @return {string[]}
 */
function getSettingValues( value ) {
    if ( Array.isArray( value ) ) {
        return value.map( String );
    }

    return undefined === value || null === value || '' === value ? [] : [ String( value ) ];
}

function getSettingLimitKey( step, setting ) {
    return 'setting-' + step.key + '-' + setting.key;
}

function toggleSettingValue( value, checked ) {
    const { step, setting } = getContext();
    const values = state.workflowEditorData.settingValues.filter( ( candidate ) => candidate !== value );

    if ( checked ) {
        values.push( value );
    }

    step.settings = { ...step.settings, [ setting.key ]: values };
    markDirty();
}

const { state, actions } = store( NAMESPACE, {
    state: {
        workflowEditorData: {
            get isEditing() {
                return state.workflowEditor.form.id > 0;
            },
            // The header shows the workflow's title (updated while typing), a generic title as long as it is empty.
            get title() {
                const title = ( state.workflowEditor.form.title || '' ).trim();

                if ( title ) {
                    return title;
                }

                return state.workflowEditorData.isEditing ? i18n().editTitle : i18n().newTitle;
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

                // Steps at the start of a batch/end of the run always run automatically.
                if ( [ 'before_queue', 'after_queue' ].includes( getStepPhase( step ) ) ) {
                    return [];
                }
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

                if ( [ 'before_queue', 'after_queue' ].includes( getStepPhase( step ) ) ) {
                    return i18n().modeHints.background || '';
                }

                const modes = getStepModes( action );

                return i18n().modeHints[ modes.length > 0 ? modes[ 0 ] : action.default_mode ] || '';
            },
            /*
             * When the step runs (start of batch, per order, per shipment, after each order, end of run): a choice in
             * case the action supports several, a badge otherwise.
             */
            get stepPhaseChoices() {
                const { step } = getContext();
                const action = getAction( step.action );
                const choices = action && action.when ? action.when : [];

                if ( choices.length < 2 ) {
                    return [];
                }

                const current = getStepWhen( step ).value;

                return choices.map( ( choice ) => ( { ...choice, checked: choice.value === current, name: step.key + '-when' } ) );
            },
            get hasStepPhaseChoice() {
                return state.workflowEditorData.stepPhaseChoices.length > 0;
            },
            get stepPhaseText() {
                return state.workflowEditorData.hasStepPhaseChoice ? '' : getStepWhen( getContext().step ).label || '';
            },
            // Tooltip of the badge.
            get stepPhaseDescription() {
                return getStepWhen( getContext().step ).description || '';
            },
            // Explanation below the choice.
            get stepPhaseChoiceDescription() {
                return state.workflowEditorData.hasStepPhaseChoice ? getStepWhen( getContext().step ).description || '' : '';
            },
            // Tooltip of the mode badge.
            get stepModeDescription() {
                const { step } = getContext();
                const action = getAction( step.action );

                if ( ! action ) {
                    return '';
                }

                const modes = getStepModes( action );
                const mode = [ 'before_queue', 'after_queue' ].includes( getStepPhase( step ) ) ? 'background' : ( modes[ 0 ] || action.default_mode );

                return ( i18n().modeDescriptions || {} )[ mode ] || '';
            },
            get hasStepChoices() {
                const data = state.workflowEditorData;
                return data.hasStepPhaseChoice || data.hasStepModeChoice;
            },
            // Configuration section: choices or settings of the action.
            get hasStepConfig() {
                return state.workflowEditorData.hasStepChoices || state.workflowEditorData.stepSettings.length > 0;
            },
            get isRunLevelStep() {
                const { step } = getContext();
                return [ 'before_queue', 'after_queue' ].includes( getStepPhase( step ) );
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
            get isSettingMultiselect() {
                const { setting } = getContext();
                return 'multiselect' === setting.type;
            },
            // Settings presented as a labeled select/input field.
            get isSettingField() {
                return state.workflowEditorData.isSettingSelect || state.workflowEditorData.isSettingInput;
            },
            // Multiselects: same behavior as the list filters (all options by default, restricted on request).
            get settingValues() {
                return getSettingValues( state.workflowEditorData.settingValue );
            },
            get settingSelected() {
                const { setting } = getContext();
                const options = setting.options || [];

                return state.workflowEditorData.settingValues.map( ( value ) => options.find( ( option ) => option.value === value ) || { value, label: value } );
            },
            get settingAvailable() {
                const { setting } = getContext();
                const selected = state.workflowEditorData.settingValues;

                return ( setting.options || [] ).filter( ( option ) => ! selected.includes( option.value ) );
            },
            get isSettingLimited() {
                const { step, setting } = getContext();
                return state.workflowEditorData.settingValues.length > 0 || !! state.workflowEditor.limited[ getSettingLimitKey( step, setting ) ];
            },
            get settingRadioName() {
                const { step, setting } = getContext();
                return 'stc-setting-' + getSettingLimitKey( step, setting );
            },
            get isSelectedSettingOption() {
                const { option } = getContext();
                return String( option.value ) === String( state.workflowEditorData.settingValue );
            },
            get availableActions() {
                const listed = state.workflowEditor.form.steps.map( ( step ) => step.action );
                // Actions allowing several steps (e.g. printing at different times) can be added again.
                return ( state.workflowChoices.actions || [] ).filter( ( action ) => ( action.multiple || ! listed.includes( action.name ) ) && hooks.applyFilters( 'workflowActionAvailable', true, action, state.workflowEditor.form ) );
            },
            get canAddStep() {
                return state.workflowEditorData.availableActions.length > 0 || state.workflowEditorData.proActions.length > 0;
            },
            // Steps available with Shiptastic Pro: shown with a pro marker, not selectable.
            get proActions() {
                return state.workflowChoices.pro_actions || [];
            },
            // Filters
            get listChoices() {
                const { list } = getContext();

                if ( 'countries' === list ) {
                    return state.countries.map( ( country ) => ( { value: country.code, label: country.name } ) );
                }

                // Including/excluding: queue item tags (categories, shipping classes, payment methods).
                if ( isTagList( list ) ) {
                    return Object.entries( state.workflowTagLabels || {} ).map( ( [ value, label ] ) => ( { value, label } ) );
                }

                return ( 'locations' === list ? state.workflowChoices.locations : state.workflowChoices.providers ) || [];
            },
            // Autocomplete of the including/excluding filters (per list: query, results, loading, open).
            get tagSearch() {
                const { list } = getContext();
                return ( state.workflowEditor.tagSearch || {} )[ list ] || { query: '', results: [], loading: false, open: false, searched: false };
            },
            get tagQuery() {
                return state.workflowEditorData.tagSearch.query;
            },
            // Results without the selected tags.
            get tagResults() {
                const { list } = getContext();
                const selected = state.workflowEditor.form.filters[ list ] || [];

                return state.workflowEditorData.tagSearch.results.filter( ( result ) => ! selected.includes( result.value ) );
            },
            get isTagSearching() {
                return state.workflowEditorData.tagSearch.loading;
            },
            get hasNoTagResults() {
                const search = state.workflowEditorData.tagSearch;
                return search.searched && ! search.loading && 0 === state.workflowEditorData.tagResults.length;
            },
            get showTagResults() {
                const search = state.workflowEditorData.tagSearch;
                return search.open && ( search.loading || search.searched );
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
            // Explains the backorders choice (shown below the segmented control, like the list filters' hints).
            get backordersHint() {
                return ( i18n().backordersHints || {} )[ state.workflowEditor.form.filters.backorders || 'any' ] || '';
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
                state.workflowEditor = { open: true, loading: true, saving: false, dirty: false, error: '', limited: {}, pickerOpen: false, tagSearch: {}, form: workflow ? toForm( workflow ) : emptyForm() };

                // Labels of the selected tags (including/excluding filters), in the background.
                loadSelectedTagLabels();

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
            setMode( event ) {
                state.workflowEditor.form.mode = event.target.value;
                hooks.applyFilters( 'workflowModeChanged', undefined, event.target.value, state.workflowEditor.form );
                markDirty();
            },
            // Steps
            // Step picker (available steps + steps of Shiptastic Pro).
            togglePicker() {
                state.workflowEditor.pickerOpen = ! state.workflowEditor.pickerOpen;
            },
            // Escape closes the picker only (not the editor).
            onPickerKeydown: withSyncEvent( ( event ) => {
                if ( 'Escape' === event.key && state.workflowEditor.pickerOpen ) {
                    event.preventDefault();
                    state.workflowEditor.pickerOpen = false;
                }
            } ),
            // Closes the picker once the focus left it (e.g. tabbing/clicking elsewhere).
            onPickerFocusout( event ) {
                const { ref } = getElement();

                if ( ref && event.relatedTarget && ! ref.contains( event.relatedTarget ) ) {
                    state.workflowEditor.pickerOpen = false;
                }
            },
            pickStep() {
                const { action } = getContext();

                state.workflowEditor.pickerOpen = false;
                actions.workflowEditor.addStep( action.name );
            },
            addStep( name ) {
                if ( ! name ) {
                    return;
                }

                const action = getAction( name );
                const settings = {};

                // Store the choice explicitly for actions supporting several modes/scopes (shown right away).
                if ( action && getStepModes( action ).length > 1 ) {
                    settings.mode = getStepModes( action )[ 0 ];
                }

                // When the step runs (phase + scope).
                if ( action && action.when && action.when.length > 1 ) {
                    const when = action.when.find( ( choice ) => choice.value === action.default_when ) || action.when[ 0 ];

                    settings.phase = when.phase;

                    if ( 'item' === when.phase ) {
                        settings.scope = when.scope;
                    }
                }

                const steps = [ ...state.workflowEditor.form.steps ];
                const step = { key: 'step-' + ( ++stepCounter ), action: name, settings };
                const phase = getStepPhase( step );

                // Dependency order: insert before the first step (of the same phase) requiring the action (e.g. create
                // shipments first), otherwise at the end of its phase.
                const index = steps.findIndex( ( candidate ) => getStepPhase( candidate ) === phase && getRequiredActions( candidate.action ).includes( name ) );

                if ( index < 0 ) {
                    steps.push( step );
                } else {
                    steps.splice( index, 0, step );
                }

                state.workflowEditor.form.steps = sortByPhase( steps );
                markDirty();
            },
            setStepPhase() {
                const { step, choice } = getContext();
                const { scope, ...settings } = step.settings || {};

                // The scope is part of the choice for per order/per shipment steps, fixed otherwise.
                step.settings = 'item' === choice.phase ? { ...settings, phase: choice.phase, scope: choice.scope } : { ...settings, phase: choice.phase };
                state.workflowEditor.form.steps = sortByPhase( [ ...state.workflowEditor.form.steps ] );
                markDirty();
            },
            setStepMode() {
                const { step, choice } = getContext();
                step.settings = { ...step.settings, mode: choice.value };
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
            // Multiselect settings
            includeAllSettingValues() {
                const { step, setting } = getContext();

                state.workflowEditor.limited = { ...state.workflowEditor.limited, [ getSettingLimitKey( step, setting ) ]: false };

                if ( state.workflowEditorData.settingValues.length > 0 ) {
                    step.settings = { ...step.settings, [ setting.key ]: [] };
                    markDirty();
                }
            },
            limitSettingValues() {
                const { step, setting } = getContext();
                state.workflowEditor.limited = { ...state.workflowEditor.limited, [ getSettingLimitKey( step, setting ) ]: true };
            },
            addSettingValue( event ) {
                const value = event.target.value;

                event.target.value = '';

                if ( value ) {
                    toggleSettingValue( value, true );
                }
            },
            removeSettingValue() {
                const { option } = getContext();
                toggleSettingValue( option.value, false );
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
            // Autocomplete: searches (debounced) when typing or focusing the field.
            searchTags( event ) {
                const { list } = getContext();
                const query = event.target.value;
                const update = ( props ) => {
                    const all = state.workflowEditor.tagSearch || {};
                    const current = all[ list ] || { query: '', results: [], loading: false, open: false, searched: false };

                    state.workflowEditor.tagSearch = { ...all, [ list ]: { ...current, ...props } };
                };

                window.clearTimeout( tagSearchTimers[ list ] );

                // The complete list is known: searched locally, right away.
                if ( allTags ) {
                    update( { query, open: true, loading: false, searched: true, results: filterTags( allTags, query ) } );
                    return;
                }

                update( { query, open: true, loading: true } );

                tagSearchTimers[ list ] = window.setTimeout( () => {
                    const request = ( tagSearchRequests[ list ] || 0 ) + 1;

                    tagSearchRequests[ list ] = request;

                    // First the complete list (in case it is small enough, see workflows/tags), the search otherwise.
                    api.request( 'workflows/tags', { query: { search: tagsTooMany ? query : '' } } )
                        .then( ( response ) => {
                            const items = response.items || [];

                            rememberTagLabels( items );

                            if ( response.complete ) {
                                allTags = items;
                            } else if ( ! tagsTooMany ) {
                                tagsTooMany = true;

                                // Too many for a local search: search the current query on the server.
                                if ( request === tagSearchRequests[ list ] && state.workflowEditor.tagSearch[ list ].query ) {
                                    return api.request( 'workflows/tags', { query: { search: state.workflowEditor.tagSearch[ list ].query } } ).then( ( searched ) => {
                                        rememberTagLabels( searched.items );
                                        return searched.items || [];
                                    } );
                                }
                            }

                            return items;
                        } )
                        .then( ( items ) => {
                            if ( request === tagSearchRequests[ list ] ) {
                                const current = state.workflowEditor.tagSearch[ list ].query;
                                update( { results: allTags ? filterTags( allTags, current ) : items, loading: false, searched: true } );
                            }
                        } )
                        .catch( () => update( { results: [], loading: false, searched: true } ) );
                }, allTags ? 0 : 250 );
            },
            addTag() {
                const { list, result } = getContext();
                const all = state.workflowEditor.tagSearch || {};

                toggleFilter( list, result.value, true );
                state.workflowEditor.tagSearch = { ...all, [ list ]: { ...( all[ list ] || {} ), query: '', open: false } };
            },
            /*
             * Keyboard: Escape closes the results only (not the editor), arrow down moves to the results, Enter adds the
             * first result.
             */
            onTagKeydown: withSyncEvent( ( event ) => {
                const { list } = getContext();
                const all = state.workflowEditor.tagSearch || {};
                const search = all[ list ] || {};

                if ( 'Escape' === event.key && search.open ) {
                    event.preventDefault();
                    state.workflowEditor.tagSearch = { ...all, [ list ]: { ...search, open: false } };
                } else if ( 'ArrowDown' === event.key ) {
                    const first = event.target.parentElement.querySelector( '.stc-autocomplete-list button' );

                    if ( first ) {
                        event.preventDefault();
                        first.focus();
                    }
                } else if ( 'Enter' === event.key ) {
                    const first = event.target.parentElement.querySelector( '.stc-autocomplete-list button' );

                    event.preventDefault();

                    if ( first && search.open ) {
                        first.click();
                    }
                }
            } ),
            // Keyboard within the results: arrow up/down moves between them (up from the first one: back to the field).
            onTagResultKeydown: withSyncEvent( ( event ) => {
                if ( ! [ 'ArrowDown', 'ArrowUp' ].includes( event.key ) ) {
                    return;
                }

                const buttons = [ ...event.target.closest( '.stc-autocomplete-list' ).querySelectorAll( 'button' ) ];
                const index = buttons.indexOf( event.target );
                const next = 'ArrowDown' === event.key ? buttons[ index + 1 ] : buttons[ index - 1 ] || event.target.closest( '.stc-autocomplete' ).querySelector( 'input' );

                event.preventDefault();

                if ( next ) {
                    next.focus();
                }
            } ),
            // Closes the results once the focus left the field/results.
            onTagFocusout( event ) {
                const { list } = getContext();
                const { ref } = getElement();
                const all = state.workflowEditor.tagSearch || {};

                if ( ref && ! ( event.relatedTarget && ref.contains( event.relatedTarget ) ) ) {
                    state.workflowEditor.tagSearch = { ...all, [ list ]: { ...( all[ list ] || {} ), open: false } };
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
                        mode: form.mode,
                        steps: getVisibleSteps().map( ( step ) => ( step.instance ? { action: step.action, instance: step.instance, settings: step.settings } : { action: step.action, settings: step.settings } ) ),
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

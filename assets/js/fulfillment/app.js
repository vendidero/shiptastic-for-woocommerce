/**
 * Fulfillment app store (shiptastic/fulfillment).
 *
 * Screens: overview (workflows + queue) and run (claimed orders of a workflow, one step at a time).
 * Data comes from the REST API, URLs/history see router.js. Working through a workflow is a server-side interactive
 * run (shared lock token, files of its steps are exported, see RunExport), its local state is persisted in IndexedDB
 * to resume it after a reload; without it, the run's orders are loaded from the server.
 *
 * Action modules extend this store:
 * - callbacks.initForm_{action}( viewData ): initial local form state for a step
 * - callbacks.validate_{action}(): client side validation, returns an error message (empty if valid). Once a submit
 *   failed validation, the message is shown (and updated live) until the step is valid.
 * - callbacks.collect_{action}(): data submitted for the step
 *
 * Shared parts: checklist.js (item verification for pick/pack), shipments.js (shipments overlay + shipment fields),
 * gestures.js (swipe, drag & drop).
 */
import { store, getContext, getConfig, withSyncEvent } from '@wordpress/interactivity';
import * as api from './api.js';
import * as db from './db.js';
import * as gestures from './gestures.js';
import * as hooks from './hooks.js';
import './checklist.js';
import './shipments.js';
import './report.js';
import './order.js';
import './workflows.js';
import { getRoute } from './router.js';

const NAMESPACE = 'shiptastic/fulfillment';
const config = getConfig( NAMESPACE );

api.setup( config );

const sprintf = ( format, ...args ) => {
    let index = 0;

    return format.replace( /%(\d+\$)?[sd]/g, ( match, position ) => {
        const value = position ? args[ parseInt( position, 10 ) - 1 ] : args[ index++ ];
        return undefined === value ? '' : String( value );
    } );
};

const runKey = () => 'run-' + config.userId;

/**
 * Numbers shipment-scope steps in case an action runs for multiple shipments, e.g. Pack 1, Pack 2.
 *
 * @param {Object} step
 * @return {string}
 */
function stepLabel( step ) {
    const same = state.visibleSteps.filter( ( candidate ) => candidate.action === step.action );

    return same.length > 1 ? step.title + ' ' + ( same.findIndex( ( candidate ) => candidate.key === step.key ) + 1 ) : step.title;
}

/**
 * Automatic (not yet done) steps starting at an index of the visible steps, up to the next interactive step.
 *
 * @param {number} index
 * @return {Object[]}
 */
function autoStepsFrom( index ) {
    const steps = state.visibleSteps;
    const auto = [];

    for ( let i = Math.max( 0, index ); i < steps.length && 'background' === steps[ i ].mode; i++ ) {
        if ( ! steps[ i ].is_done ) {
            auto.push( steps[ i ] );
        }
    }

    return auto;
}

let autoSlowTimer = null;

const AUTO_IDLE = { active: false, paused: false, slow: false, steps: [] };

/**
 * Opens the progress panel of the automatic steps (the app runs them one by one, see actions.runAutomaticSteps()).
 * Keeps the panel's steps in case it is open already (e.g. retrying a failed step).
 *
 * @param {Object[]} steps
 */
function openAutoSteps( steps ) {
    if ( state.autoSteps.active ) {
        return;
    }

    state.autoSteps = { ...AUTO_IDLE, active: steps.length > 0, steps: steps.map( ( step ) => ( { ...step, status: 'pending', message: '' } ) ) };
}

/**
 * @param {Object} step
 * @param {string} status pending|running|done|error
 * @param {string} message
 */
function setAutoStepStatus( step, status, message = '' ) {
    const steps = state.autoSteps.steps.some( ( candidate ) => candidate.key === step.key ) ? state.autoSteps.steps : [ ...state.autoSteps.steps, { ...step } ];

    state.autoSteps.steps = steps.map( ( candidate ) => ( candidate.key === step.key ? { ...candidate, status, message } : candidate ) );

    window.clearTimeout( autoSlowTimer );
    state.autoSteps.slow = false;

    // A step taking longer, e.g. a shipping provider creating a label.
    if ( 'running' === status ) {
        autoSlowTimer = window.setTimeout( () => {
            state.autoSteps.slow = true;
        }, 5000 );
    }
}

/**
 * Closes the progress panel.
 *
 * @param {boolean} showDone Show the done steps for a moment before.
 * @return {Promise} Resolves once the panel is closed.
 */
function closeAutoSteps( showDone = false ) {
    const close = () => {
        state.autoSteps = { ...AUTO_IDLE };
    };

    window.clearTimeout( autoSlowTimer );

    if ( ! state.autoSteps.active || ! showDone || ! state.autoSteps.steps.some( ( step ) => 'done' === step.status ) ) {
        close();
        return Promise.resolve();
    }

    state.autoSteps.slow = false;

    return new Promise( ( resolve ) => {
        window.setTimeout( () => {
            close();
            resolve();
        }, 700 );
    } );
}

/**
 * @param {Object[]} steps
 * @return {string} Titles of the steps, e.g. "Create label, Mark ready".
 */
function stepTitles( steps ) {
    return steps.map( ( step ) => stepLabel( step ) ).join( ', ' );
}

/**
 * Applies the export state of the interactive run returned with step responses (files created so far).
 *
 * @param {Object|null} run
 */
function applyRunExport( run ) {
    if ( run && state.run.id === run.id ) {
        state.run.exportFiles = run.export_files || 0;
        state.run.exportUrl = run.export_url || '';
    }
}

let noticeTimer = null;
let queueRequest = 0;

const { state, actions, callbacks } = store( NAMESPACE, {
    state: {
        get isOverviewScreen() {
            return 'overview' === state.screen;
        },
        get isRunScreen() {
            return 'run' === state.screen;
        },
        get runExportText() {
            return sprintf( config.i18n.downloadFiles, state.run.exportFiles || 0 );
        },
        get currentWorkflow() {
            return state.workflows.find( ( workflow ) => workflow.id === state.run.workflowId ) || null;
        },
        get title() {
            return state.isRunScreen && state.currentWorkflow ? state.currentWorkflow.title : config.i18n.title;
        },
        get currentWorkflowTitle() {
            return state.currentWorkflow ? state.currentWorkflow.title : '';
        },
        get showOrderBreadcrumb() {
            return state.isRunScreen && !! state.currentItem;
        },
        get currentItemHasBackorders() {
            return state.showOrderBreadcrumb && !! state.currentItem.has_backorders;
        },
        get showShipmentsButton() {
            return state.showOrderBreadcrumb && state.hasLayout;
        },
        get isErrorNotice() {
            return 'error' === state.notice.type;
        },
        get isSuccessNotice() {
            return 'success' === state.notice.type;
        },
        get hasWorkflows() {
            return state.workflows.length > 0;
        },
        get workflowStepsText() {
            const { workflow } = getContext();
            return workflow.steps.map( ( step ) => step.title ).join( ' → ' );
        },
        get workflowOpenText() {
            const { workflow } = getContext();
            return sprintf( config.i18n.openOrders, workflow.open_count || 0 );
        },
        get isSelectedQueueWorkflow() {
            const { workflow } = getContext();
            return workflow.id === state.queue.workflowId;
        },
        get showPagination() {
            return state.queue.pages > 1;
        },
        get hasPrevPage() {
            return state.queue.page > 1;
        },
        get hasNextPage() {
            return state.queue.page < state.queue.pages;
        },
        get isPrevPageDisabled() {
            return ! state.hasPrevPage || state.queue.loading;
        },
        get isNextPageDisabled() {
            return ! state.hasNextPage || state.queue.loading;
        },
        get queueRangeText() {
            const from = ( state.queue.page - 1 ) * state.queue.perPage + 1;
            const to = Math.min( state.queue.total, from + state.queue.items.length - 1 );

            return sprintf( config.i18n.queueRange, from, to, state.queue.total );
        },
        get isActiveQueueTab() {
            const { status } = getContext();
            return status === state.queue.status;
        },
        get queueTabCount() {
            const { status } = getContext();
            return state.counts[ status ] || 0;
        },
        get hasQueueItems() {
            return state.queue.items.length > 0;
        },
        get queueItemMeta() {
            const { queue_item: item } = getContext();
            return [ item.location_title || item.location || config.i18n.defaultLocation, sprintf( config.i18n.items, item.item_count ) ].join( ' · ' );
        },
        get canResetQueueItem() {
            const { queue_item: item } = getContext();
            return config.canManage && 'error' === item.status;
        },
        get currentItem() {
            return state.run.items.length > 0 ? state.run.items[ 0 ] : null;
        },
        get currentStep() {
            const item = state.currentItem;
            return item && item.current && item.current.step ? item.current.step : null;
        },
        /**
         * Steps shown in the step bar: the workflow's steps incl. the ones running automatically (marked as such).
         * Injected dependencies (e.g. creating shipments) are not shown unless they failed.
         */
        get visibleSteps() {
            const failed = state.currentStep && state.hasStepError ? state.currentStep.key : '';

            return state.currentItem && state.currentItem.steps ? state.currentItem.steps.filter( ( step ) => ! step.injected || step.key === failed ) : [];
        },
        get isAutoStep() {
            const { step } = getContext();
            return 'background' === step.mode;
        },
        /**
         * Automatic steps running once the current (interactive) step is submitted, up to the next interactive step.
         */
        get upcomingAutoSteps() {
            const index = state.currentStep ? state.visibleSteps.findIndex( ( step ) => step.key === state.currentStep.key ) : -1;

            return index >= 0 && 'interactive' === state.visibleSteps[ index ].mode ? autoStepsFrom( index + 1 ) : [];
        },
        // Hint within the action bar (hidden while the steps run: the progress panel shows them).
        get autoStepsText() {
            const upcoming = state.upcomingAutoSteps;

            return upcoming.length > 0 && ! state.autoSteps.active ? sprintf( config.i18n.autoNext, stepTitles( upcoming ) ) : '';
        },
        get isAutoStepRunning() {
            const { step } = getContext();
            return 'running' === step.status;
        },
        get isAutoStepDone() {
            const { step } = getContext();
            return 'done' === step.status;
        },
        get isAutoStepFailed() {
            const { step } = getContext();
            return 'error' === step.status;
        },
        get isAutoStepsDone() {
            return state.autoSteps.steps.length > 0 && state.autoSteps.steps.every( ( step ) => 'done' === step.status );
        },
        get autoStepsTitle() {
            if ( state.autoSteps.paused ) {
                return config.i18n.autoPaused;
            }

            return state.isAutoStepsDone ? config.i18n.autoRunDone : config.i18n.autoRunTitle;
        },
        // Jumping into a failed automatic step: interactively in case the action supports it, the error view otherwise.
        get autoFixLabel() {
            const failed = state.autoSteps.steps.find( ( step ) => 'error' === step.status );
            return failed && failed.modes && failed.modes.includes( 'interactive' ) ? config.i18n.autoOpenStep : config.i18n.autoShowError;
        },
        // An automatic step handled interactively after it failed (state.manualStep).
        get manualStepText() {
            const step = state.currentStep;
            return step && state.manualStep.key === step.key && state.manualStep.message ? sprintf( config.i18n.manualStep, state.manualStep.message ) : '';
        },
        get currentOrderUrl() {
            return state.currentItem ? state.currentItem.order_url || '' : '';
        },
        // Failed automatic steps: e.g. fix the address within the order, then retry.
        get canOpenOrder() {
            return state.hasStepError && ! state.isInputStep && ! state.isExternalItem && !! state.currentOrderUrl;
        },
        /**
         * Numbers shipment-scope steps in case an action runs for multiple shipments, e.g. Pack 1, Pack 2.
         */
        get stepTitle() {
            const { step } = getContext();
            return stepLabel( step );
        },
        get currentStepIndex() {
            return state.currentStep ? state.visibleSteps.findIndex( ( step ) => step.key === state.currentStep.key ) : -1;
        },
        get stepCountText() {
            return sprintf( config.i18n.stepCount, Math.max( 1, state.currentStepIndex + 1 ), state.visibleSteps.length );
        },
        get currentStepTitle() {
            return state.currentStep ? stepLabel( state.currentStep ) : '';
        },
        // Automatic steps cannot be redone from the app.
        get isStepReopenable() {
            const { step } = getContext();
            return !! step.is_done && 'interactive' === step.mode && ! state.busy;
        },
        /**
         * The last done (interactive) step before the current one, e.g. to navigate back.
         */
        get previousStep() {
            const steps = state.visibleSteps.filter( ( step ) => 'interactive' === step.mode || ( state.currentStep && step.key === state.currentStep.key ) );
            const index = state.currentStep ? steps.findIndex( ( step ) => step.key === state.currentStep.key ) : steps.length;

            for ( let i = ( index < 0 ? steps.length : index ) - 1; i >= 0; i-- ) {
                if ( steps[ i ].is_done ) {
                    return steps[ i ];
                }
            }

            return null;
        },
        get canGoBack() {
            return !! state.previousStep;
        },
        get isCurrentStep() {
            const { step } = getContext();
            return !! state.currentStep && step.key === state.currentStep.key;
        },
        get needsInput() {
            const item = state.currentItem;
            return !! item && !! item.current && 'needs_input' === item.current.status;
        },
        /**
         * Client side validation message of the current step (shown after a submit attempt).
         */
        get validationMessage() {
            const step = state.currentStep;
            const validate = step && state.showValidation ? callbacks[ 'validate_' + step.action ] : null;

            return validate ? validate() : '';
        },
        get showStepError() {
            return !! state.validationMessage || state.hasStepError;
        },
        get stepErrorMessage() {
            return state.validationMessage || ( state.hasStepError ? state.currentItem.current.message : '' );
        },
        get isQueueLoading() {
            return state.queue.loading;
        },
        get showQueueEmpty() {
            return ! state.queue.loading && ! state.hasQueueItems;
        },
        get hasStepError() {
            const item = state.currentItem;
            return !! item && !! item.current && 'error' === item.current.status;
        },
        /**
         * Interactive steps keep showing their form on errors (e.g. a short pick) so that the input may be corrected.
         */
        get isInputStep() {
            return !! state.currentStep && 'interactive' === state.currentStep.mode && ( state.needsInput || state.hasStepError );
        },
        get canRetry() {
            return state.hasStepError && ! state.isInputStep && ! state.isExternalItem;
        },
        /**
         * The current order belongs to a run of an extension (e.g. fixing an order an automation failed on): no actions
         * of interactive runs (retry, move to exceptions, storing the run locally).
         */
        get isExternalItem() {
            return 'run' === state.screen && !! hooks.applyFilters( 'isExternalItem', false );
        },
        get isCurrentAction() {
            const { action } = getContext();
            return state.isInputStep && action === state.currentStep.action;
        },
        get showActionBar() {
            return state.isRunScreen && state.isInputStep;
        },
        get showEmptyRun() {
            return ! state.currentItem && ! state.busy;
        },
        /**
         * No order shown while the next orders are being loaded, e.g. after the last order of the run was finished
         * (claiming further orders) or while starting/resuming a run.
         */
        get showRunLoading() {
            return state.isRunScreen && ! state.currentItem && state.busy;
        },
        get progressText() {
            return hooks.applyFilters( 'progressText', sprintf( config.i18n.orderProgress, state.run.done + 1, state.run.total ) );
        },
        get submitLabel() {
            return config.i18n.continue;
        },
        get emptyRunText() {
            return config.i18n.noItems;
        },
    },
    actions: {
        notify( message, type = 'info', timeout = 0 ) {
            const notice = { message, type };

            state.notice = notice;
            window.clearTimeout( noticeTimer );

            if ( timeout > 0 ) {
                noticeTimer = window.setTimeout( () => {
                    if ( state.notice.message === notice.message ) {
                        actions.dismissNotice();
                    }
                }, timeout );
            }
        },
        dismissNotice() {
            state.notice = { message: '', type: '' };
        },
        handleError( error ) {
            if ( ! state.online ) {
                actions.notify( config.i18n.offline, 'error' );
            } else {
                actions.notify( error && error.message ? error.message : config.i18n.error, 'error' );
            }
        },
        *loadQueue() {
            const query = { status: state.queue.status, per_page: state.queue.perPage, page: state.queue.page };
            const request = ++queueRequest;

            state.queue.loading = true;

            if ( state.queue.workflowId ) {
                query.workflow_id = state.queue.workflowId;
            }

            try {
                const data = yield api.request( 'queue', { query } );

                // A newer request (e.g. another tab) is running.
                if ( request !== queueRequest ) {
                    return;
                }

                // E.g. the last item of the last page was processed in the meantime.
                if ( data.pages > 0 && state.queue.page > data.pages ) {
                    state.queue.page = data.pages;
                    yield actions.loadQueue();
                    return;
                }

                state.queue.items = data.items;
                state.queue.total = data.total;
                state.queue.pages = data.pages;
                state.counts = data.counts;

                state.workflows.forEach( ( workflow ) => {
                    if ( data.open_counts && undefined !== data.open_counts[ workflow.id ] ) {
                        workflow.open_count = data.open_counts[ workflow.id ];
                    }
                } );
            } catch ( error ) {
                if ( request === queueRequest ) {
                    actions.handleError( error );
                }
            } finally {
                if ( request === queueRequest ) {
                    state.queue.loading = false;
                }
            }
        },
        *showQueue() {
            const { status } = getContext();

            state.queue.status = status;
            state.queue.page = 1;
            yield actions.loadQueue();
        },
        *setQueueWorkflow( event ) {
            state.queue.workflowId = parseInt( event.target.value, 10 ) || 0;
            state.queue.page = 1;
            yield actions.loadQueue();
        },
        *prevPage() {
            if ( state.hasPrevPage ) {
                state.queue.page -= 1;
                yield actions.loadQueue();
            }
        },
        *nextPage() {
            if ( state.hasNextPage ) {
                state.queue.page += 1;
                yield actions.loadQueue();
            }
        },
        *resetQueueItem() {
            const { queue_item: item } = getContext();

            try {
                yield api.request( `queue/${ item.id }/reset`, { method: 'POST' } );
                yield actions.loadQueue();
            } catch ( error ) {
                actions.handleError( error );
            }
        },
        *startWorkflow() {
            const { workflow } = getContext();

            actions.dismissNotice();
            state.busy = true;

            try {
                // Starts an interactive run - or continues the user's active one (e.g. left on another device).
                const run = yield api.request( 'runs', { method: 'POST', body: { workflow_id: workflow.id, mode: 'interactive' } } );

                state.busy = false;
                yield actions.resumeSession( run );
            } catch ( error ) {
                actions.handleError( error );
            } finally {
                state.busy = false;
            }
        },
        /**
         * Opens a run by its id (URL): an active interactive run of the user is continued, other runs show their
         * report screen (e.g. a finished interactive run, runs of extensions).
         *
         * @param {number} runId
         * @param {Object} route item/step of the URL
         */
        *openRun( runId, route = {} ) {
            try {
                const run = yield api.request( `runs/${ runId }` );

                if ( run.is_interactive && run.is_active && run.user_id === config.userId ) {
                    yield actions.resumeSession( run, route );
                } else {
                    yield actions.report.show( run );
                }
            } catch ( error ) {
                actions.handleError( error );
                state.screen = 'overview';
                yield actions.loadQueue();
            }
        },
        /**
         * Shows the run screen of an interactive run: restores the local state (IndexedDB) or loads the run's locked
         * orders from the server, claims orders in case there are none.
         *
         * @param {Object} run
         * @param {Object} route item (queue id) to show first
         */
        *resumeSession( run, route = {} ) {
            const saved = yield db.get( runKey() );

            state.overlay.open = false;
            state.currentKey = '';
            state.sessions = [ ...state.sessions.filter( ( session ) => session.workflow_id !== run.workflow_id ), { id: run.id, workflow_id: run.workflow_id } ];

            if ( saved && saved.id === run.id && saved.items ) {
                state.run = saved;
            } else {
                state.run = { id: run.id, workflowId: run.workflow_id, items: [], skipped: [], total: 0, done: 0, exhausted: false, exportFiles: run.export_files || 0, exportUrl: run.export_url || '' };

                try {
                    const data = yield api.request( `runs/${ run.id }/items` );

                    state.run.items = data.items;
                    state.run.total = data.items.length;
                    applyRunExport( data.run );
                } catch ( error ) {
                    actions.handleError( error );
                }
            }

            // Deep link to an order of the run: show it first.
            const itemId = parseInt( route.item, 10 ) || 0;
            const index = state.run.items.findIndex( ( item ) => item.id === itemId );

            if ( index > 0 ) {
                state.run.items = [ state.run.items[ index ], ...state.run.items.filter( ( item ) => item.id !== itemId ) ];
            }

            state.screen = 'run';
            window.scrollTo( 0, 0 );

            if ( state.run.items.length > 0 ) {
                callbacks.syncCurrent();
                yield actions.heartbeat();
                yield actions.retryStep();
            } else {
                yield actions.claim();
            }
        },
        *claim() {
            state.busy = true;

            try {
                const data = yield api.request( `workflows/${ state.run.workflowId }/claim`, { method: 'POST', body: { run: true, run_id: state.run.id || 0, exclude: state.run.skipped || [] } } );

                applyRunExport( data.run );
                const known = state.run.items.map( ( item ) => item.id );
                const items = data.items.filter( ( item ) => ! known.includes( item.id ) );

                state.run.items = [ ...state.run.items, ...items ];
                state.run.total += items.length;

                if ( 0 === items.length ) {
                    state.run.exhausted = true;
                }

                callbacks.syncCurrent();
            } catch ( error ) {
                actions.handleError( error );
            } finally {
                state.busy = false;
            }

            yield callbacks.persist();
        },
        *refill() {
            if ( ! state.run.exhausted && state.run.items.length <= config.refillAt && state.online ) {
                yield actions.claim();
            }
        },
        *finishCurrent( notice = '' ) {
            state.overlay.open = false;
            state.manualStep = { key: '', message: '' };
            state.run.items = state.run.items.slice( 1 );
            state.run.done += 1;

            if ( notice ) {
                actions.notify( notice, 'error' );
            }

            callbacks.syncCurrent();
            yield callbacks.persist();
            yield actions.refill();
        },
        /**
         * @param {Object}  data  The queue item response (current step, steps, layout).
         * @param {boolean} reset Whether to reset the local form state of the current step, e.g. after the shipments changed.
         */
        *applyResponse( data, reset = false ) {
            applyRunExport( data.run );

            if ( data.current && 'completed' === data.current.status ) {
                yield actions.finishCurrent();
                return;
            }

            const item = state.currentItem;
            const current = { ...data.current };

            // A failed submit of the same step: keep the view (and the local form state) to allow corrections.
            if ( 'error' === current.status && current.step && item.current && item.current.step && current.step.key === item.current.step.key ) {
                const hasView = current.view_data && Object.keys( current.view_data ).length > 0;
                current.view_data = hasView ? current.view_data : item.current.view_data;
            }

            state.run.items = [ { ...item, ...data, current, lock_token: data.lock_token || item.lock_token, steps: data.steps || item.steps, layout: data.layout || item.layout }, ...state.run.items.slice( 1 ) ];

            callbacks.syncCurrent( reset );
            yield callbacks.persist();
        },
        *handleItemError( error ) {
            // The lock is gone (expired and claimed by someone else, released or deleted).
            if ( error && [ 403, 404, 409 ].includes( error.status ) ) {
                yield actions.finishCurrent( config.i18n.lockLost );
            } else {
                actions.handleError( error );
            }
        },
        *submitStep() {
            const item = state.currentItem;
            const step = state.currentStep;

            if ( ! item || ! step || state.busy ) {
                return;
            }

            const collect = callbacks[ 'collect_' + step.action ];
            const validate = callbacks[ 'validate_' + step.action ];

            // Client side validation: nothing is sent, the message is shown (and updated) until the step is valid.
            if ( validate && validate() ) {
                state.showValidation = true;
                return;
            }

            state.busy = true;
            actions.dismissNotice();
            // The following automatic steps are shown right away, they run one by one afterwards.
            openAutoSteps( state.upcomingAutoSteps );

            try {
                const manual = state.manualStep.key === step.key ? step.key : '';
                const data = yield api.request( `queue/${ item.id }/steps/${ step.key }`, {
                    method: 'POST',
                    body: {
                        token: item.lock_token,
                        version: item.version,
                        idempotency_key: api.uuid(),
                        data: collect ? collect() : {},
                        auto_steps: 0,
                        manual,
                    },
                } );

                if ( manual && ! ( data.current && 'error' === data.current.status ) ) {
                    state.manualStep = { key: '', message: '' };
                }

                yield actions.continueResponse( data );
            } catch ( error ) {
                yield closeAutoSteps();
                yield actions.handleItemError( error );
            } finally {
                state.busy = false;
            }
        },
        /**
         * Navigates back: reopens a done step (the clicked one or the previous one), its data prefills the form.
         */
        toggleStepList() {
            state.stepListOpen = ! state.stepListOpen;
        },
        closeStepListOutside( event ) {
            if ( state.stepListOpen && ! event.target.closest( '.stc-step-nav' ) ) {
                state.stepListOpen = false;
            }
        },
        closeStepListOnEscape( event ) {
            if ( state.stepListOpen && 'Escape' === event.key ) {
                state.stepListOpen = false;
            }
        },
        *reopenStep() {
            const context = getContext();
            state.stepListOpen = false;
            const step = context && context.step ? context.step : state.previousStep;
            const item = state.currentItem;

            if ( ! item || ! step || ! step.is_done || state.busy ) {
                return;
            }

            state.busy = true;
            actions.dismissNotice();

            try {
                const data = yield api.request( `queue/${ item.id }/steps/${ step.key }/reopen`, {
                    method: 'POST',
                    body: { token: item.lock_token, idempotency_key: api.uuid() },
                } );

                yield actions.applyResponse( data, true );
            } catch ( error ) {
                yield actions.handleItemError( error );
            } finally {
                state.busy = false;
            }
        },
        /**
         * Applies a step response: continues with the automatic steps (one by one) in case the next one is automatic.
         *
         * @param {Object} data
         */
        *continueResponse( data ) {
            if ( data.current && 'automatic' === data.current.status ) {
                yield actions.applyResponse( data );
                yield actions.runAutomaticSteps();
            } else {
                yield closeAutoSteps();
                yield actions.applyResponse( data );
            }
        },
        /**
         * Runs the automatic steps of the current item one by one (one request each) and shows their progress. Pauses
         * at the first error: the error is shown within the panel, the user may retry, jump into the step to fix it or
         * move the order to the exceptions. Automation continues afterwards (retryStep, submitting the fixed step).
         */
        *runAutomaticSteps() {
            const done = [];
            let item = state.currentItem;

            while ( item && item.current && 'automatic' === item.current.status && item.current.step ) {
                const step = item.current.step;
                const index = state.visibleSteps.findIndex( ( candidate ) => candidate.key === step.key );

                openAutoSteps( index >= 0 ? autoStepsFrom( index ) : [ step ] );
                state.autoSteps.paused = false;
                setAutoStepStatus( step, 'running' );

                let data = null;

                try {
                    data = yield api.request( `queue/${ item.id }/run`, { method: 'POST', body: { token: item.lock_token, auto_steps: 1 } } );
                } catch ( error ) {
                    yield closeAutoSteps();
                    yield actions.handleItemError( error );
                    return;
                }

                const current = data.current || {};

                ( current.automated || [] ).forEach( ( automated ) => {
                    setAutoStepStatus( automated, 'done' );
                    done.push( automated );
                } );

                // Pause: the error is shown within the panel.
                if ( 'error' === current.status && current.step && 'background' === current.step.mode ) {
                    setAutoStepStatus( current.step, 'error', current.message || config.i18n.error );
                    state.autoSteps.paused = true;
                    yield actions.applyResponse( data );
                    return;
                }

                if ( 'automatic' !== current.status ) {
                    yield closeAutoSteps( true );

                    if ( done.length > 0 ) {
                        const order = 'completed' === current.status ? '#' + data.order_number + ': ' : '';
                        actions.notify( order + sprintf( config.i18n.autoDone, stepTitles( done ) ), 'success', 5000 );
                    }

                    yield actions.applyResponse( data );
                    return;
                }

                yield actions.applyResponse( data );
                item = state.currentItem;
            }

            yield closeAutoSteps();
        },
        /**
         * Continues the current item on the server, e.g. after an automatic step failed (retry) or after a reload:
         * remaining automatic steps run one by one.
         */
        *retryStep() {
            const item = state.currentItem;

            if ( ! item || state.busy ) {
                return;
            }

            state.busy = true;
            actions.dismissNotice();

            try {
                yield actions.continueResponse( yield api.request( `queue/${ item.id }/run`, { method: 'POST', body: { token: item.lock_token, auto_steps: 0 } } ) );
            } catch ( error ) {
                yield closeAutoSteps();
                yield actions.handleItemError( error );
            } finally {
                state.busy = false;
            }
        },
        /**
         * Jumps into the failed automatic step: handled interactively in case its action supports it (the automation
         * continues once it is submitted), otherwise the error view offers editing the shipments/order and retrying.
         */
        *fixAutoStep() {
            const item = state.currentItem;
            const failed = state.autoSteps.steps.find( ( step ) => 'error' === step.status );

            yield closeAutoSteps();

            if ( ! item || ! failed || ! failed.modes || ! failed.modes.includes( 'interactive' ) ) {
                return;
            }

            state.manualStep = { key: failed.key, message: failed.message };
            state.busy = true;

            try {
                yield actions.applyResponse( yield api.request( `queue/${ item.id }/run`, { method: 'POST', body: { token: item.lock_token, auto_steps: 0, manual: failed.key } } ), true );
            } catch ( error ) {
                yield actions.handleItemError( error );
            } finally {
                state.busy = false;
            }
        },
        *skipAutoStep() {
            yield closeAutoSteps();
            yield actions.skipItem();
        },
        *skipItem() {
            const item = state.currentItem;

            state.busy = true;

            try {
                yield api.request( `queue/${ item.id }/skip`, { method: 'POST', body: { token: item.lock_token, idempotency_key: api.uuid() } } );
                yield actions.finishCurrent();
            } catch ( error ) {
                yield actions.handleItemError( error );
            } finally {
                state.busy = false;
            }
        },
        *releaseItem() {
            const item = state.currentItem;

            state.busy = true;

            try {
                yield api.request( `queue/${ item.id }/release`, { method: 'POST', body: { token: item.lock_token, idempotency_key: api.uuid() } } );
            } catch ( error ) {
                // The lock is gone anyway.
            } finally {
                state.busy = false;
            }

            state.overlay.open = false;

            // Do not claim the item again during this run.
            state.run.skipped = [ ...( state.run.skipped || [] ), item.id ];
            state.run.total -= 1;
            state.run.items = state.run.items.slice( 1 );
            callbacks.syncCurrent();
            yield callbacks.persist();
            yield actions.refill();
        },
        /**
         * Ends the interactive run (its remaining orders are released on the server). In case its steps created
         * files, the run's report (download) is shown, otherwise the overview.
         *
         * @param {boolean} showReport False e.g. for browser back navigation (the run is listed in the overview).
         */
        *stopRun( showReport = true ) {
            const runId = state.run.id || 0;
            const requests = runId ? [] : state.run.items.map( ( item ) => ( {
                path: `queue/${ item.id }/release`,
                body: { token: item.lock_token, idempotency_key: api.uuid() },
            } ) );

            // Leave the run screen right away, the release happens in the background.
            state.overlay.open = false;
            state.run = { workflowId: 0, items: [], skipped: [], total: 0, done: 0, exhausted: false };
            state.screen = 'overview';
            state.currentKey = '';
            state.view = {};
            state.form = {};
            state.sessions = state.sessions.filter( ( session ) => session.id !== runId );

            yield db.remove( runKey() );

            let finished = null;

            try {
                if ( runId ) {
                    finished = yield api.request( `runs/${ runId }/finish`, { method: 'POST' } );
                } else if ( requests.length > 0 ) {
                    yield api.request( 'batch', { method: 'POST', body: { requests } } );
                }
            } catch ( error ) {
                // Locks expire anyway, idle runs are finished by the daily cleanup.
            }

            if ( finished && finished.export_files > 0 && showReport ) {
                yield actions.report.show( finished );
                return;
            }

            actions.report.loadRuns();
            yield actions.loadQueue();
        },
        *heartbeat() {
            const tokens = [ ...new Set( state.run.items.map( ( item ) => item.lock_token ).filter( Boolean ) ) ];

            for ( const token of tokens ) {
                try {
                    yield api.request( 'queue/heartbeat', { method: 'POST', body: { token } } );
                } catch ( error ) {
                    // Retried with the next heartbeat.
                }
            }
        },
    },
    callbacks: {
        /**
         * Updates the view data for the current step. The local form state is (re)initialized for a new step or
         * in case a reset is requested (e.g. the shipments changed).
         *
         * @param {boolean} reset
         */
        syncCurrent( reset = false ) {
            const item = state.currentItem;
            const step = state.currentStep;
            const key = item && step ? item.id + ':' + step.key : '';
            const view = item && item.current && item.current.view_data ? item.current.view_data : null;

            if ( view && ( ! Array.isArray( view ) || view.length > 0 ) ) {
                state.view = view;
            } else if ( key !== state.currentKey ) {
                state.view = {};
            }

            if ( key === state.currentKey && ! reset ) {
                return;
            }

            state.currentKey = key;
            state.showValidation = false;
            state.stepListOpen = false;

            const initForm = step ? callbacks[ 'initForm_' + step.action ] : null;
            state.form = initForm ? initForm( state.view ) : {};
        },
        persist() {
            // Orders of runs of extensions are not part of the (stored) interactive run.
            if ( state.isRunScreen && ! state.isExternalItem ) {
                return db.set( runKey(), state.run );
            }

            return Promise.resolve();
        },
        *init() {
            const updateOnline = () => {
                state.online = navigator.onLine;
            };

            gestures.init();

            window.addEventListener( 'online', updateOnline );
            window.addEventListener( 'offline', updateOnline );
            updateOnline();

            window.setInterval( () => actions.heartbeat(), config.heartbeatInterval * 1000 );

            actions.report.loadRuns();

            const route = getRoute();

            // Deep links: runs (incl. order/step), workflow editor. A run left without ending it (e.g. the browser
            // was closed) is continued via its workflow ("Continue") or its URL.
            if ( route.run || route.workflow ) {
                yield actions.applyRoute();
            } else {
                yield actions.loadQueue();
            }

            state.routerReady = true;
        },
    },
} );

export { state, actions, callbacks, sprintf, withSyncEvent, hooks, api };

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
 *
 * Offline: queue items carry the view data of their following offline capable steps (`views`, steps flagged
 * `offline`). Without a connection, submits are stored in the outbox (outbox.js) and the app continues with the next
 * step locally. Orders reaching a step which needs the server (automatic steps, steps requiring a connection, the
 * end of the workflow) wait for the connection (`run.waiting`). The outbox is replayed in order once online
 * (actions.sync): the server dedupes by idempotency key, orders needing attention afterwards come back to the run,
 * orders whose lock was lost are listed as conflicts.
 */
import { store, getContext, getConfig, withSyncEvent } from '@wordpress/interactivity';
import * as api from './api.js';
import * as db from './db.js';
import * as gestures from './gestures.js';
import * as hooks from './hooks.js';
import * as outbox from './outbox.js';
import { applyLayout, mapShipmentIds, mapStepKey } from './offline-layout.js';
import './checklist.js';
import './shipments.js';
import './report.js';
import './order.js';
import { preloadChoices } from './workflows.js';
import { getRoute } from './router.js';
import { preloadCountries } from './countries.js';

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
 * @param {Error} error
 * @return {boolean} Whether the request did not reach the server (fetch failed, no HTTP status).
 */
const isNetworkError = ( error ) => ! error || ! error.status;

/**
 * @param {Error} error
 * @return {boolean} Whether the session/nonce is not valid anymore (the user needs to log in again).
 */
const isAuthError = ( error ) => !! error && ( 401 === error.status || 'rest_cookie_invalid_nonce' === error.code );

const SYNC_INTERVAL = 30;

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

/**
 * The result of the steps starting a batch (claim response): a failed step blocks the batch's orders.
 *
 * @param {Object|null} batch status, message, entry
 */
function applyBatchResult( batch ) {
    if ( batch && 'error' === batch.status && batch.entry ) {
        state.runStepError = { id: batch.entry.id, title: batch.entry.title, message: batch.message || batch.entry.message || '' };
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
        // E.g. "Maria · Pick & Pack · until 14:32", "Automation · Labels · until 14:10", "Reservation expired".
        get queueItemLockText() {
            const { queue_item: item } = getContext();

            if ( 'locked' !== item.status ) {
                return '';
            }

            const expires = item.lock_expires ? new Date( item.lock_expires.endsWith( 'Z' ) ? item.lock_expires : item.lock_expires + 'Z' ) : null;

            if ( expires && expires.getTime() < Date.now() ) {
                return config.i18n.lockExpired;
            }

            const holder = 'interactive' === item.lock_source ? item.locked_by_name : sprintf( config.i18n.lockAutomation, item.locked_by_name || '' ).replace( / \(\)$/, '' );
            const parts = [ holder, item.workflow_title ];

            if ( expires ) {
                parts.push( sprintf( config.i18n.lockUntil, expires.toLocaleTimeString( document.documentElement.lang || undefined, { hour: '2-digit', minute: '2-digit' } ) ) );
            }

            return parts.filter( Boolean ).join( ' · ' );
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
        // Steps running after each shipment/order are tagged within the step list.
        get stepPhaseTag() {
            const { step } = getContext();
            return step.phase && 'item' !== step.phase ? step.phase_title || '' : '';
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
            return state.isRunScreen && state.isInputStep && ! state.runStepError.id;
        },
        // The order screen is hidden while the steps starting the batch failed (retry/skip first).
        get showOrderView() {
            return !! state.currentItem && ! state.runStepError.id;
        },
        get runStepErrorTitle() {
            return state.runStepError.id ? sprintf( config.i18n.runSteps.failed, state.runStepError.title ) : '';
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
        get syncText() {
            if ( state.sync.running ) {
                return config.i18n.syncRunning;
            }

            return state.sync.pending > 0 ? sprintf( config.i18n.syncPending, state.sync.pending ) : '';
        },
        get waitingText() {
            const count = ( state.run.waiting || [] ).filter( ( waiting ) => ! waiting.ready ).length;
            return state.isRunScreen && count > 0 ? sprintf( config.i18n.waitingOrders, count ) : '';
        },
        get readyText() {
            const count = ( state.run.waiting || [] ).filter( ( waiting ) => waiting.ready ).length;
            return state.isRunScreen && count > 0 ? sprintf( config.i18n.readyOrders, count ) : '';
        },
        get hasWaiting() {
            return state.isRunScreen && ( state.run.waiting || [] ).length > 0;
        },
        // Orders done offline: waiting for the connection or ready to finish (after syncing).
        get waitingStatusText() {
            const { waiting } = getContext();

            if ( ! waiting.ready ) {
                return config.i18n.waitingStatus;
            }

            return waiting.failed && waiting.message ? waiting.message : sprintf( config.i18n.readyStatus, waiting.step_title || '' );
        },
        get hasSyncConflicts() {
            return state.sync.conflicts.length > 0;
        },
        get progressText() {
            return hooks.applyFilters( 'progressText', sprintf( config.i18n.orderProgress, state.run.done + 1, state.run.total ) );
        },
        get submitLabel() {
            return config.i18n.continue;
        },
        get emptyRunText() {
            return state.waitingText || state.readyText || config.i18n.noItems;
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
                const saved = yield db.get( runKey() );

                // Offline (e.g. a reload without connection): continue the locally stored run.
                if ( isNetworkError( error ) && saved && saved.id === runId ) {
                    yield actions.resumeSession( { id: saved.id, workflow_id: saved.workflowId, export_files: saved.exportFiles, export_url: saved.exportUrl }, route );
                    return;
                }

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
                state.run = { id: run.id, workflowId: run.workflow_id, items: [], skipped: [], waiting: [], total: 0, done: 0, exhausted: false, exportFiles: run.export_files || 0, exportUrl: run.export_url || '' };

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

            state.run.waiting = state.run.waiting || [];

            if ( state.run.items.length > 0 ) {
                callbacks.syncCurrent();

                if ( ! state.online ) {
                    yield actions.ensureCurrent();
                    return;
                }

                yield actions.heartbeat();
                yield actions.sync();

                // Orders with unsynced changes keep their local progress.
                if ( state.currentItem && ! outbox.hasItem( state.currentItem.id ) ) {
                    yield actions.retryStep();
                }

                yield actions.checkRunStepError();
            } else if ( state.online ) {
                yield actions.sync();

                if ( 0 === state.run.items.length ) {
                    yield actions.claim();
                }
            }
        },
        *claim() {
            state.busy = true;

            try {
                const data = yield api.request( `workflows/${ state.run.workflowId }/claim`, { method: 'POST', body: { run: true, run_id: state.run.id || 0, exclude: state.run.skipped || [] } } );

                applyRunExport( data.run );
                applyBatchResult( data.batch );
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
            if ( state.isRunScreen && state.run.workflowId && ! state.run.exhausted && state.run.items.length <= config.refillAt && state.online ) {
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
            yield actions.ensureCurrent();
            yield actions.refill();
        },
        /**
         * Orders of a run loaded from the server (e.g. resumed without local data) only carry their steps: the next
         * order's current step is loaded once it is shown - or derived locally from its prefetched views while offline.
         */
        *ensureCurrent() {
            const item = state.currentItem;

            if ( ! state.isRunScreen || ! item || item.current ) {
                return;
            }

            if ( state.online && ! outbox.hasItem( item.id ) ) {
                try {
                    yield actions.continueResponse( yield api.request( `queue/${ item.id }/run`, { method: 'POST', body: { token: item.lock_token, auto_steps: 0 } } ) );
                    return;
                } catch ( error ) {
                    if ( ! isNetworkError( error ) ) {
                        yield actions.handleItemError( error );
                        return;
                    }
                }
            }

            yield actions.continueLocally( item );
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

            const payload = collect ? collect() : {};
            const idempotencyKey = api.uuid();

            // Offline, or earlier changes of the order are not synced yet (they must not be overtaken).
            if ( ! state.online || outbox.hasItem( item.id ) ) {
                if ( step.offline ) {
                    yield actions.submitOffline( item, step, payload, idempotencyKey );
                    yield actions.sync();
                } else {
                    actions.notify( config.i18n.requiresNetwork, 'error', 4000 );
                }

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
                        idempotency_key: idempotencyKey,
                        data: payload,
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

                // The connection dropped: continue offline (the request may have reached the server, the replay
                // uses the same idempotency key).
                if ( isNetworkError( error ) && step.offline ) {
                    state.busy = false;
                    yield actions.submitOffline( item, step, payload, idempotencyKey );
                } else {
                    yield actions.handleItemError( error );
                }
            } finally {
                state.busy = false;
            }
        },
        /**
         * Stores a step submit in the outbox and continues locally: with the next step in case it can be done offline
         * (prefetched view data), otherwise the order waits for the connection and the next order is shown.
         *
         * @param {Object} item
         * @param {Object} step
         * @param {Object} data The step data.
         * @param {string} idempotencyKey
         */
        *submitOffline( item, step, data, idempotencyKey ) {
            yield outbox.add( {
                id: idempotencyKey,
                itemId: item.id,
                orderNumber: item.order_number,
                type: 'step',
                stepKey: step.key,
                path: `queue/${ item.id }/steps/${ step.key }`,
                body: { token: item.lock_token, idempotency_key: idempotencyKey, data },
            } );
            state.sync.pending = outbox.count();

            const steps = ( item.steps || [] ).map( ( candidate ) => ( candidate.key === step.key ? { ...candidate, is_done: true } : candidate ) );

            yield actions.continueLocally( { ...item, steps, offline_data: { ...( item.offline_data || {} ), [ step.key ]: data } } );
        },
        /**
         * Continues the current item locally (offline): with its next open step in case it can be done offline
         * (view data available), otherwise the order waits for the connection and the next order is shown.
         *
         * @param {Object} item The current item with its updated steps.
         */
        *continueLocally( item ) {
            const next = ( item.steps || [] ).find( ( candidate ) => ! candidate.is_done ) || null;
            const view = next && next.offline && item.views ? item.views[ next.key ] : null;

            if ( view ) {
                const viewData = { ...view };
                const offlineData = item.offline_data || {};

                // Submitted offline before (e.g. reopened): prefill the form.
                if ( offlineData[ next.key ] ) {
                    viewData.previous = offlineData[ next.key ];
                }

                const isSame = item.current && item.current.step && item.current.step.key === next.key && 'needs_input' === item.current.status;

                state.run.items = [ { ...item, current: { status: 'needs_input', step: next, view_data: viewData, message: '', code: '', automated: [] } }, ...state.run.items.slice( 1 ) ];
                callbacks.syncCurrent( ! isSame );
                yield callbacks.persist();
                return;
            }

            // The order continues on the server once synced. Orders without changes (e.g. their next step needs the
            // server) come back to the run as soon as the connection is back (see actions.restoreWaiting()).
            const hasChanges = outbox.hasItem( item.id );

            state.overlay.open = false;
            state.manualStep = { key: '', message: '' };
            state.run.waiting = [ ...( state.run.waiting || [] ), { id: item.id, order_number: item.order_number, lock_token: item.lock_token, ...( hasChanges ? {} : { unchanged: true, item } ) } ];
            state.run.items = state.run.items.slice( 1 );
            state.run.done += 1;

            actions.notify( sprintf( hasChanges ? config.i18n.savedOffline : config.i18n.movedWaiting, item.order_number ), 'info', 5000 );
            callbacks.syncCurrent();
            yield callbacks.persist();
            yield actions.ensureCurrent();
        },
        /**
         * Saves the shipments (overlay) offline: stored in the outbox and applied locally (steps of changed shipments
         * reopened, views rebuilt via callbacks.offlineView_{action}, see offline-layout.js). New shipments keep their
         * temporary key until the server created them.
         *
         * @param {Object}   item
         * @param {Object[]} shipments Editable shipments of the overlay.
         * @param {Object[]} payload   The shipments as submitted to the server.
         * @param {string}   idempotencyKey
         */
        *saveShipmentsOffline( item, shipments, payload, idempotencyKey ) {
            yield outbox.add( {
                id: idempotencyKey,
                itemId: item.id,
                orderNumber: item.order_number,
                type: 'shipments',
                path: `queue/${ item.id }/shipments`,
                body: { token: item.lock_token, idempotency_key: idempotencyKey, continue: true, shipments: payload },
            } );
            state.sync.pending = outbox.count();

            const buildView = ( step, next ) => {
                const build = callbacks[ 'offlineView_' + step.action ];
                return build ? build( next, step ) || null : null;
            };
            const result = applyLayout( item, shipments, buildView );

            yield actions.continueLocally( result.item );
        },
        /**
         * An outbox entry was rejected by the server (e.g. an invalid shipment layout): the order's later entries are
         * discarded, its server state is loaded and it comes back to the run with the error.
         *
         * @param {Object} entry
         * @param {Error}  error
         */
        *recoverItem( entry, error ) {
            yield outbox.removeItem( entry.itemId );
            state.sync.pending = outbox.count();

            const local = state.run.items.find( ( candidate ) => candidate.id === entry.itemId ) || ( state.run.waiting || [] ).find( ( candidate ) => candidate.id === entry.itemId );
            let data = null;

            try {
                data = yield api.request( `queue/${ entry.itemId }/run`, { method: 'POST', body: { token: entry.body.token, auto_steps: 0 } } );
            } catch ( recoverError ) {
                yield actions.syncConflict( entry, recoverError );
                return;
            }

            const current = { ...( data.current || {} ), status: 'error', message: error.message || config.i18n.error, code: error.code || '' };

            yield actions.applySynced( { ...entry, type: 'step' }, { ...data, lock_token: data.lock_token || ( local ? local.lock_token : '' ), current } );
        },
        /**
         * Stores a queue mutation (skip, put back) in the outbox, e.g. while offline.
         *
         * @param {Object} item
         * @param {string} type skip|release
         */
        *queueOffline( item, type ) {
            const idempotencyKey = api.uuid();

            yield outbox.add( {
                id: idempotencyKey,
                itemId: item.id,
                orderNumber: item.order_number,
                type,
                path: `queue/${ item.id }/${ type }`,
                body: { token: item.lock_token, idempotency_key: idempotencyKey },
            } );
            state.sync.pending = outbox.count();
        },
        /**
         * Replays the outbox in order (one request per entry). Stops at network errors (retried later), an expired
         * session (the user needs to log in again) and server errors. Orders whose lock was lost cannot be saved
         * (conflict), orders needing attention afterwards (e.g. a step failed on the server) come back to the run.
         */
        *sync() {
            yield actions.restoreWaiting();

            if ( state.sync.running || ! state.online || 0 === outbox.count() ) {
                state.sync.pending = outbox.count();
                return;
            }

            let synced = 0;

            state.sync.running = true;
            state.sync.authError = false;

            try {
                while ( outbox.count() > 0 ) {
                    const entry = outbox.all()[ 0 ];
                    let data = null;

                    try {
                        data = yield api.request( entry.path, { method: 'POST', body: entry.body } );
                    } catch ( error ) {
                        if ( isNetworkError( error ) || error.status >= 500 ) {
                            break;
                        }

                        if ( isAuthError( error ) ) {
                            state.sync.authError = true;
                            actions.notify( config.i18n.syncAuth, 'error' );
                            break;
                        }

                        if ( [ 403, 404, 409 ].includes( error.status ) || 'step' !== entry.type && 'shipments' !== entry.type ) {
                            yield actions.syncConflict( entry, error );
                        } else {
                            yield actions.recoverItem( entry, error );
                        }

                        continue;
                    }

                    yield outbox.remove( entry.id );
                    state.sync.pending = outbox.count();
                    synced++;

                    // New shipments created offline: their temporary keys are replaced with the ids.
                    if ( 'shipments' === entry.type && data.id_map && Object.keys( data.id_map ).length > 0 ) {
                        yield actions.mapShipmentIds( entry.itemId, data.id_map );
                    }

                    yield actions.applySynced( entry, data );
                }
            } finally {
                state.sync.running = false;
                state.sync.pending = outbox.count();
            }

            yield callbacks.persist();

            if ( synced > 0 && 0 === outbox.count() ) {
                if ( ! state.notice.message ) {
                    actions.notify( config.i18n.syncDone, 'success', 3000 );
                }

                yield actions.refill();
            }
        },
        /**
         * Online again: orders moved to the waiting list without changes (their next step needs the server) come back
         * to the run.
         */
        *restoreWaiting() {
            const unchanged = state.online && state.isRunScreen ? ( state.run.waiting || [] ).filter( ( waiting ) => waiting.unchanged && waiting.item ) : [];

            if ( 0 === unchanged.length ) {
                return;
            }

            state.run.waiting = state.run.waiting.filter( ( waiting ) => ! unchanged.includes( waiting ) );
            state.run.done = Math.max( 0, state.run.done - unchanged.length );
            state.run.items = [ ...state.run.items, ...unchanged.map( ( waiting ) => waiting.item ) ];

            if ( state.run.items.length === unchanged.length ) {
                callbacks.syncCurrent( true );
                yield actions.ensureCurrent();
            }

            yield callbacks.persist();
        },
        /**
         * Applies the server response of a replayed outbox entry.
         *
         * @param {Object} entry
         * @param {Object} data
         */
        *applySynced( entry, data ) {
            const waiting = ( state.run.waiting || [] ).find( ( candidate ) => candidate.id === entry.itemId );
            const index = state.run.items.findIndex( ( candidate ) => candidate.id === entry.itemId );

            if ( 'step' !== entry.type && 'shipments' !== entry.type ) {
                state.run.waiting = ( state.run.waiting || [] ).filter( ( candidate ) => candidate.id !== entry.itemId );
                return;
            }

            const current = data.current || {};
            const failed = 'error' === current.status || ( data.step_result && 'error' === data.step_result.status );

            // The following changes depend on the failed step.
            if ( failed ) {
                yield outbox.removeItem( entry.itemId );
                state.sync.pending = outbox.count();
            }

            const pending = outbox.hasItem( entry.itemId );

            if ( index >= 0 ) {
                const item = state.run.items[ index ];

                if ( failed ) {
                    // Back to the server state: the failed step is shown with its error.
                    state.run.items = state.run.items.map( ( candidate, i ) => ( i === index ? { ...item, ...data, offline_data: {}, current: { ...current, view_data: current.view_data || {} } } : candidate ) );

                    if ( 0 === index ) {
                        callbacks.syncCurrent( true );
                    } else {
                        actions.notify( sprintf( config.i18n.syncAttention, item.order_number ), 'error' );
                    }
                } else {
                    // Still being worked on locally: keep the local progress, update the server state.
                    state.run.items = state.run.items.map( ( candidate, i ) => ( i === index ? { ...candidate, version: data.version, lock_token: data.lock_token || candidate.lock_token, layout: ! pending && data.layout ? data.layout : candidate.layout } : candidate ) );
                }

                return;
            }

            if ( ! waiting || pending ) {
                return;
            }

            if ( 'completed' === current.status ) {
                state.run.waiting = state.run.waiting.filter( ( candidate ) => candidate.id !== entry.itemId );
                return;
            }

            // E.g. a step needing the connection or a failed step: listed as ready to finish.
            state.run.waiting = state.run.waiting.map( ( candidate ) => ( candidate.id === entry.itemId ? {
                ...candidate,
                ready: true,
                failed: 'error' === current.status,
                message: 'error' === current.status ? current.message || '' : '',
                step_title: current.step ? current.step.title : '',
                item: { ...data, lock_token: data.lock_token || waiting.lock_token },
            } : candidate ) );

            // Nothing else to do: continue with it right away.
            if ( 0 === state.run.items.length ) {
                yield actions.finishWaiting( entry.itemId );
            } else {
                actions.notify( sprintf( config.i18n.syncAttention, entry.orderNumber || waiting.order_number ), 'info', 5000 );
            }
        },
        /**
         * Continues an order which is ready to finish after syncing (e.g. a step needing the connection): it becomes
         * the current order.
         *
         * @param {number} itemId Optional, defaults to the order of the context (ready list).
         */
        *finishWaiting( itemId = 0 ) {
            // Called from the list (event as first argument) or with the order's queue id.
            const id = 'number' === typeof itemId && itemId > 0 ? itemId : getContext().waiting.id;
            const waiting = ( state.run.waiting || [] ).find( ( candidate ) => candidate.id === id && candidate.ready );

            if ( ! waiting || state.busy ) {
                return;
            }

            state.overlay.open = false;
            state.run.waiting = state.run.waiting.filter( ( candidate ) => candidate.id !== id );
            state.run.done = Math.max( 0, state.run.done - 1 );
            state.run.items = [ waiting.item, ...state.run.items ];
            callbacks.syncCurrent( true );
            window.scrollTo( 0, 0 );
            yield callbacks.persist();
        },
        /**
         * The changes of an order could not be saved (e.g. the lock expired while offline and someone else claimed the
         * order): its entries are removed and the order is listed as a conflict.
         *
         * @param {Object} entry
         * @param {Error}  error
         */
        *syncConflict( entry, error ) {
            const wasCurrent = state.currentItem && state.currentItem.id === entry.itemId;

            yield outbox.removeItem( entry.itemId );

            state.sync.pending = outbox.count();
            state.sync.conflicts = [
                ...state.sync.conflicts.filter( ( conflict ) => conflict.id !== entry.itemId ),
                { id: entry.itemId, order_number: entry.orderNumber || entry.itemId, message: [ 403, 404, 409 ].includes( error.status ) ? config.i18n.syncConflict : error.message || config.i18n.error },
            ];
            state.run.waiting = ( state.run.waiting || [] ).filter( ( candidate ) => candidate.id !== entry.itemId );
            state.run.items = state.run.items.filter( ( candidate ) => candidate.id !== entry.itemId );
            // E.g. the "saved on this device" notice of the order is outdated (the conflict is listed instead).
            actions.dismissNotice();

            if ( wasCurrent ) {
                state.overlay.open = false;
                callbacks.syncCurrent();
            }
        },
        /**
         * Replaces temporary shipment keys of an item (local state and its outbox entries) with the server's ids.
         *
         * @param {number} itemId
         * @param {Object} idMap temporary key => shipment id
         */
        *mapShipmentIds( itemId, idMap ) {
            yield outbox.update( ( entry ) => {
                if ( entry.itemId !== itemId || ! entry.stepKey ) {
                    return entry;
                }

                const stepKey = mapStepKey( entry.stepKey, idMap );

                return { ...entry, stepKey, path: `queue/${ itemId }/steps/${ stepKey }` };
            } );

            const index = state.run.items.findIndex( ( candidate ) => candidate.id === itemId );

            if ( index < 0 ) {
                return;
            }

            state.run.items = state.run.items.map( ( candidate, i ) => ( i === index ? mapShipmentIds( candidate, idMap ) : candidate ) );

            // The current step keeps its form state.
            if ( 0 === index && state.currentItem.current && state.currentItem.current.step ) {
                state.currentKey = itemId + ':' + state.currentItem.current.step.key;

                if ( state.view && idMap[ state.view.shipmentId ] ) {
                    state.view = { ...state.view, shipmentId: idMap[ state.view.shipmentId ] };
                }
            }
        },
        *retryRunStep() {
            yield actions.resolveRunStep( 'retry' );
        },
        *skipRunStep() {
            yield actions.resolveRunStep( 'skip' );
        },
        /**
         * Retries or skips the failed step starting the batch. Once resolved, the batch's orders (blocked so far)
         * continue: their current step is loaded again.
         *
         * @param {string} type retry|skip
         */
        *resolveRunStep( type ) {
            const id = state.runStepError.id;

            if ( ! id || state.busy || ! state.run.id ) {
                return;
            }

            state.busy = true;
            actions.dismissNotice();

            let data = null;

            try {
                data = yield api.request( `runs/${ state.run.id }/run-steps/${ id }/${ type }`, { method: 'POST' } );
            } catch ( error ) {
                actions.handleError( error );
            } finally {
                state.busy = false;
            }

            if ( ! data ) {
                return;
            }

            applyRunExport( data.run );

            if ( data.result && 'error' === data.result.status ) {
                state.runStepError = { id: data.result.entry ? data.result.entry.id : id, title: data.result.entry ? data.result.entry.title : state.runStepError.title, message: data.result.message };
                return;
            }

            state.runStepError = { id: 0, title: '', message: '' };
            state.run.items = state.run.items.map( ( item ) => ( item.current && 'run_step_pending' === item.current.code ? { ...item, current: null } : item ) );
            actions.notify( config.i18n.runSteps.resumed, 'success', 3000 );
            callbacks.syncCurrent( true );
            yield callbacks.persist();
            yield actions.ensureCurrent();
        },
        /**
         * The current order is blocked by a failed step starting its batch (e.g. after a reload): load the step.
         */
        *checkRunStepError() {
            const item = state.currentItem;

            if ( ! item || ! item.current || 'run_step_pending' !== item.current.code || state.runStepError.id || ! state.run.id ) {
                return;
            }

            try {
                const run = yield api.request( `runs/${ state.run.id }` );
                const failed = ( run.run_steps || [] ).find( ( entry ) => 'before_queue' === entry.phase && 'error' === entry.status );

                if ( failed ) {
                    state.runStepError = { id: failed.id, title: failed.title, message: failed.message };
                }
            } catch ( error ) {
                // Shown as the order's error.
            }
        },
        dismissConflicts() {
            state.sync.conflicts = [];
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

            // Done offline (not synced yet): reopened locally, the step's change and the following ones are discarded.
            const entries = outbox.forItem( item.id ).filter( ( entry ) => 'step' === entry.type );
            const position = entries.findIndex( ( entry ) => entry.stepKey === step.key );

            if ( position >= 0 && item.views && item.views[ step.key ] ) {
                const discarded = entries.slice( position );
                const keys = discarded.map( ( entry ) => entry.stepKey );

                yield outbox.removeItem( item.id, ( entry ) => discarded.some( ( candidate ) => candidate.id === entry.id ) );
                state.sync.pending = outbox.count();

                const viewData = { ...item.views[ step.key ] };

                if ( item.offline_data && item.offline_data[ step.key ] ) {
                    viewData.previous = item.offline_data[ step.key ];
                }

                state.run.items = [ {
                    ...item,
                    steps: item.steps.map( ( candidate ) => ( keys.includes( candidate.key ) ? { ...candidate, is_done: false } : candidate ) ),
                    current: { status: 'needs_input', step: item.steps.find( ( candidate ) => candidate.key === step.key ), view_data: viewData, message: '', code: '', automated: [] },
                }, ...state.run.items.slice( 1 ) ];

                callbacks.syncCurrent( true );
                yield callbacks.persist();
                return;
            }

            if ( ! state.online || outbox.hasItem( item.id ) ) {
                actions.notify( config.i18n.requiresNetwork, 'error', 4000 );
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

            if ( ! item ) {
                return;
            }

            if ( ! state.online || outbox.hasItem( item.id ) ) {
                yield actions.queueOffline( item, 'skip' );
                yield actions.finishCurrent();
                yield actions.sync();
                return;
            }

            state.busy = true;

            try {
                yield api.request( `queue/${ item.id }/skip`, { method: 'POST', body: { token: item.lock_token, idempotency_key: api.uuid() } } );
                yield actions.finishCurrent();
            } catch ( error ) {
                if ( isNetworkError( error ) ) {
                    state.busy = false;
                    yield actions.queueOffline( item, 'skip' );
                    yield actions.finishCurrent();
                } else {
                    yield actions.handleItemError( error );
                }
            } finally {
                state.busy = false;
            }
        },
        *releaseItem() {
            const item = state.currentItem;

            if ( ! item ) {
                return;
            }

            if ( ! state.online || outbox.hasItem( item.id ) ) {
                yield actions.queueOffline( item, 'release' );
            } else {
                state.busy = true;

                try {
                    yield api.request( `queue/${ item.id }/release`, { method: 'POST', body: { token: item.lock_token, idempotency_key: api.uuid() } } );
                } catch ( error ) {
                    // Released once synced, otherwise the lock is gone anyway.
                    if ( isNetworkError( error ) ) {
                        state.busy = false;
                        yield actions.queueOffline( item, 'release' );
                    }
                } finally {
                    state.busy = false;
                }
            }

            state.overlay.open = false;

            // Do not claim the item again during this run.
            state.run.skipped = [ ...( state.run.skipped || [] ), item.id ];
            state.run.total -= 1;
            state.run.items = state.run.items.slice( 1 );
            callbacks.syncCurrent();
            yield callbacks.persist();
            yield actions.ensureCurrent();
            yield actions.refill();
        },
        /**
         * Ends the interactive run (its remaining orders are released on the server). In case its steps created
         * files, the run's report (download) is shown, otherwise the overview.
         *
         * @param {boolean} showReport False e.g. for browser back navigation (the run is listed in the overview).
         */
        *stopRun( showReport = true ) {
            // Changes made offline are synced first, the run cannot end before (its orders would be released).
            if ( outbox.count() > 0 ) {
                yield actions.sync();

                if ( outbox.count() > 0 ) {
                    actions.notify( config.i18n.stopPending, 'error' );
                    return;
                }
            }

            const runId = state.run.id || 0;
            const requests = runId ? [] : state.run.items.map( ( item ) => ( {
                path: `queue/${ item.id }/release`,
                body: { token: item.lock_token, idempotency_key: api.uuid() },
            } ) );

            // Leave the run screen right away, the release happens in the background.
            state.overlay.open = false;
            state.run = { workflowId: 0, items: [], skipped: [], waiting: [], total: 0, done: 0, exhausted: false };
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

            state.runStepError = { id: 0, title: '', message: '' };

            // Files to download or a failed step at the end of the run (e.g. a shipment list): show the report.
            if ( finished && ( finished.export_files > 0 || ( finished.run_steps || [] ).some( ( entry ) => 'error' === entry.status ) ) && showReport ) {
                yield actions.report.show( finished );
                return;
            }

            actions.report.loadRuns();
            yield actions.loadQueue();
        },
        *heartbeat() {
            const tokens = [ ...new Set( [ ...state.run.items, ...( state.run.waiting || [] ) ].map( ( item ) => item.lock_token ).filter( Boolean ) ) ];

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
                const wasOffline = ! state.online;

                state.online = navigator.onLine;

                // Back online: replay the changes made offline, then claim further orders.
                if ( wasOffline && state.online ) {
                    actions.sync();
                }
            };

            gestures.init();

            window.addEventListener( 'online', updateOnline );
            window.addEventListener( 'offline', updateOnline );
            updateOnline();

            // Keeps the app available offline (secure contexts only).
            if ( config.serviceWorker && 'serviceWorker' in navigator ) {
                navigator.serviceWorker.register( config.serviceWorker.url, { scope: config.serviceWorker.scope } ).catch( () => {} );
            }

            // Needed by the order details and the workflow editor: loaded (and cached) in the background.
            preloadCountries();

            // Choices of the workflow editor (actions, locations, providers): loaded in the background for managers.
            if ( config.canManage ) {
                preloadChoices();
            }

            yield outbox.load( config.userId );
            state.sync.pending = outbox.count();

            window.setInterval( () => actions.heartbeat(), config.heartbeatInterval * 1000 );
            // Retries (e.g. the connection was flaky or the session expired in the meantime).
            window.setInterval( () => actions.sync(), SYNC_INTERVAL * 1000 );

            actions.report.loadRuns();

            const route = getRoute();
            const saved = state.online ? null : yield db.get( runKey() );

            // Opened without connection (e.g. via the service worker): continue the locally stored run.
            if ( ! route.run && saved && saved.id && saved.items ) {
                yield actions.resumeSession( { id: saved.id, workflow_id: saved.workflowId, export_files: saved.exportFiles, export_url: saved.exportUrl } );
                state.routeLoading = false;
                state.routerReady = true;
                return;
            }

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

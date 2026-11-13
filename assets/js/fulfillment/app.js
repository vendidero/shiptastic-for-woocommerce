/**
 * Fulfillment app store (shiptastic/fulfillment).
 *
 * Screens: overview (workflows + queue) and run (claimed orders of a workflow, one step at a time).
 * Navigation is client-side only, data comes from the REST API. The current run is persisted in IndexedDB to resume
 * it after a reload.
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
import './checklist.js';
import './shipments.js';

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
        get isBackgroundWorkflow() {
            const { workflow } = getContext();
            return 'background' === workflow.mode;
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
         * Steps shown in the step bar: background steps (e.g. creating shipments) are not shown.
         */
        get visibleSteps() {
            return state.currentItem && state.currentItem.steps ? state.currentItem.steps.filter( ( step ) => 'interactive' === step.mode ) : [];
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
        get isStepReopenable() {
            const { step } = getContext();
            return !! step.is_done && ! state.busy;
        },
        /**
         * The last done (interactive) step before the current one, e.g. to navigate back.
         */
        get previousStep() {
            const steps = state.visibleSteps;
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
            return state.hasStepError && ! state.isInputStep;
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
        get progressText() {
            return sprintf( config.i18n.orderProgress, state.run.done + 1, state.run.total );
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

            state.run = { workflowId: workflow.id, items: [], skipped: [], total: 0, done: 0, exhausted: false };
            state.screen = 'run';
            actions.dismissNotice();

            yield actions.claim();
        },
        *claim() {
            state.busy = true;

            try {
                const data = yield api.request( `workflows/${ state.run.workflowId }/claim`, { method: 'POST', body: { run: true, exclude: state.run.skipped || [] } } );
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

            try {
                const data = yield api.request( `queue/${ item.id }/steps/${ step.key }`, {
                    method: 'POST',
                    body: {
                        token: item.lock_token,
                        version: item.version,
                        idempotency_key: api.uuid(),
                        data: collect ? collect() : {},
                    },
                } );

                yield actions.applyResponse( data );
            } catch ( error ) {
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
         * Continues the current item on the server, e.g. after a background step failed or to refresh (cached) step data.
         */
        *retryStep() {
            const item = state.currentItem;

            if ( ! item ) {
                return;
            }

            state.busy = true;

            try {
                yield actions.applyResponse( yield api.request( `queue/${ item.id }/run`, { method: 'POST', body: { token: item.lock_token } } ) );
            } catch ( error ) {
                yield actions.handleItemError( error );
            } finally {
                state.busy = false;
            }
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
        *stopRun() {
            const requests = state.run.items.map( ( item ) => ( {
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

            yield db.remove( runKey() );

            try {
                if ( requests.length > 0 ) {
                    yield api.request( 'batch', { method: 'POST', body: { requests } } );
                }
            } catch ( error ) {
                // Locks expire anyway.
            }

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
            if ( state.isRunScreen ) {
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

            const run = yield db.get( runKey() );

            if ( run && run.workflowId && run.items && run.items.length > 0 && state.workflows.find( ( workflow ) => workflow.id === run.workflowId ) ) {
                state.run = run;
                state.screen = 'run';
                yield actions.heartbeat();
                yield actions.retryStep();
            } else {
                yield actions.loadQueue();
            }
        },
    },
} );

export { state, actions, callbacks, sprintf, withSyncEvent };

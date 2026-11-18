/**
 * Runs within the fulfillment app: the overview's run list (e.g. finished interactive runs with files) and the
 * report screen of a run (status, progress, files created by its steps). Extensions add runs (e.g. automation runs)
 * and content (controls, errors) via hooks (see hooks.js) and template actions.
 */
import { store, getContext, getConfig } from '@wordpress/interactivity';
import * as api from './api.js';
import * as hooks from './hooks.js';

const NAMESPACE = 'shiptastic/fulfillment';
const i18n = () => getConfig( NAMESPACE ).i18n.runs;
const format = ( text, ...values ) => {
    let index = 0;
    return text.replace( /%(\d+\$)?[sd]/g, ( match, position ) => String( position ? values[ parseInt( position, 10 ) - 1 ] : values[ index++ ] ) );
};

const RUNS_PER_PAGE = 10;

/**
 * @param {Object} run
 * @return {boolean} Whether the run belongs to the history: ended (finished or cancelled) and something happened
 *                   (orders processed or files created), see Run::query( finished ).
 */
function isFinished( run ) {
    return [ 'completed', 'cancelled' ].includes( run.status ) && ( run.succeeded + run.failed > 0 || run.export_files > 0 );
}

/**
 * Latest result per step (e.g. a step retried within the run).
 *
 * @param {Object[]} steps
 * @return {Object[]}
 */
function latestSteps( steps ) {
    const latest = {};

    ( steps || [] ).forEach( ( step ) => {
        latest[ step.key ] = step;
    } );

    return Object.values( latest );
}

/**
 * @param {Object} step
 * @return {number} The shipment of a shipment scope step (key action:shipment id), 0 otherwise.
 */
const stepShipment = ( step ) => parseInt( String( step.key ).split( ':' )[ 1 ] || 0, 10 ) || 0;

/**
 * @param {Object} file
 * @param {Object} order
 * @return {number} The shipment a file belongs to (prefix order number-shipment id-), 0 for files of the order.
 */
function fileShipment( file, order ) {
    const shipment = ( order.shipments || [] ).find( ( candidate ) => file.name.startsWith( order.order_number + '-' + candidate.id + '-' ) );

    return shipment ? shipment.id : 0;
}


/**
 * @param {Object} run
 * @param {number} count
 * @return {string} Width of a progress bar segment (share of the run's orders).
 */
function segmentStyle( run, count ) {
    const total = run ? Math.max( run.total || 0, run.processed || 0 ) : 0;

    return 'width: ' + ( total ? Math.min( 100, ( count / total ) * 100 ) : 0 ) + '%';
}

function statusText( run ) {
    return run ? i18n().status[ run.status ] || run.status : '';
}

/**
 * @param {string|null} date REST date (UTC, without timezone suffix)
 * @param {Object}      options toLocaleString options
 * @return {string}
 */
function formatDate( date, options = { dateStyle: 'medium', timeStyle: 'short' } ) {
    if ( ! date ) {
        return '';
    }

    const parsed = new Date( /Z|[+-]\d\d:\d\d$/.test( date ) ? date : date + 'Z' );

    return isNaN( parsed.getTime() ) ? '' : parsed.toLocaleString( document.documentElement.lang || undefined, options );
}

/**
 * @param {Object} run
 * @return {string} E.g. "18.11.2026, 10:35 – 11:02" (same day: end time only).
 */
function runPeriod( run ) {
    const start = formatDate( run.date_created );

    if ( ! run.date_finished ) {
        return start;
    }

    const sameDay = formatDate( run.date_created, { dateStyle: 'short' } ) === formatDate( run.date_finished, { dateStyle: 'short' } );

    return format( i18n().period, start, sameDay ? formatDate( run.date_finished, { timeStyle: 'short' } ) : formatDate( run.date_finished ) );
}

const { state, actions } = store( NAMESPACE, {
    state: {
        get isReportScreen() {
            return 'report' === state.screen;
        },
        get isSubScreen() {
            return ! [ 'overview', 'loading' ].includes( state.screen );
        },
        get headerWorkflowTitle() {
            const title = state.isReportScreen ? ( state.report.run ? state.report.run.workflow_title : '' ) : state.currentWorkflowTitle;

            return hooks.applyFilters( 'headerWorkflowTitle', title );
        },
        get backTitle() {
            return hooks.applyFilters( 'backTitle', state.isReportScreen ? i18n().backOverview : getConfig( NAMESPACE ).i18n.backReleases );
        },
        get reportTitle() {
            const run = state.report.run;

            if ( ! run ) {
                return '';
            }

            // The workflow is the previous breadcrumb: the run is identified by its number and start.
            return hooks.applyFilters( 'reportTitle', format( i18n().runTitle, run.id, formatDate( run.date_created ) ), run );
        },
        get startLabel() {
            return state.hasWorkflowSession ? i18n().continueSession : i18n().startSession;
        },
        // Whether the user has an unfinished session (interactive run) of the workflow, e.g. left on another device.
        get hasWorkflowSession() {
            const { workflow } = getContext();
            return state.sessions.some( ( session ) => session.workflow_id === workflow.id );
        },
        /**
         * Hidden in case no orders are waiting for the workflow - unless an unfinished session can be continued.
         */
        get canStartInteractive() {
            const { workflow } = getContext();
            return 'interactive' === workflow.mode && ( ( workflow.open_count || 0 ) > 0 || state.hasWorkflowSession );
        },
        // Runs list (overview)
        get hasRuns() {
            return state.runs.length > 0;
        },
        // The first page of the history is loading: placeholder rows instead of an empty section.
        get showRunsSkeleton() {
            return state.runsLoading && 0 === state.runs.length;
        },
        get showMoreRuns() {
            return state.runsHasMore && ! state.showRunsSkeleton;
        },
        get showRunsSection() {
            return state.hasRuns || state.showRunsSkeleton;
        },
        get runsListTitle() {
            return hooks.applyFilters( 'runsListTitle', i18n().listTitle );
        },
        get runListStatus() {
            const { run } = getContext();
            return statusText( run );
        },
        // Date of the run (start - end) and who ran it (in case it was someone else, e.g. managers see all runs).
        get runListDate() {
            const { run } = getContext();
            const parts = [ runPeriod( run ) ];

            if ( run.user_id && run.user_id !== getConfig( NAMESPACE ).userId && run.user_name ) {
                parts.push( format( i18n().by, run.user_name ) );
            }

            return parts.join( ' · ' );
        },
        get moreRunsLabel() {
            return state.runsLoading ? getConfig( NAMESPACE ).i18n.loading : i18n().moreRuns;
        },
        get runListMeta() {
            const { run } = getContext();
            const parts = [ run.is_interactive ? i18n().modes.interactive : hooks.applyFilters( 'reportModeText', '', run ) || run.mode, format( i18n().progress, run.processed, run.total ) ].filter( Boolean );

            if ( run.export_files > 0 && ! run.export_expired ) {
                parts.push( format( i18n().files, run.export_files ) );
            }

            if ( run.failed > 0 ) {
                parts.push( format( i18n().errors, run.failed ) );
            }

            if ( run.released > 0 ) {
                parts.push( format( i18n().putBack, run.released ) );
            }

            return parts.join( ' · ' );
        },
        // All listed runs ended: only cancelled ones are marked.
        get showRunListStatus() {
            const { run } = getContext();
            return 'completed' !== run.status;
        },
        get runListStatusClass() {
            const { run } = getContext();
            return 'stc-badge is-' + run.status;
        },
        // Report screen
        get reportRun() {
            return state.report.run || {};
        },
        get reportStatusText() {
            return statusText( state.report.run );
        },
        get reportModeText() {
            const run = state.report.run;
            return run ? hooks.applyFilters( 'reportModeText', i18n().modes[ run.mode ] || ( run.is_interactive ? i18n().modes.interactive : '' ), run ) : '';
        },
        get reportProgressText() {
            const run = state.report.run;
            return run ? format( i18n().progress, run.processed, run.total ) : '';
        },
        // Done + failed + put back = processed (orders put back are listed as such).
        get reportCountsText() {
            const run = state.report.run;

            if ( ! run ) {
                return '';
            }

            return run.released > 0 ? format( i18n().countsReleased, run.succeeded, run.failed, run.released ) : format( i18n().counts, run.succeeded, run.failed );
        },
        get reportExportText() {
            const run = state.report.run;

            if ( ! run || ! run.export_files ) {
                return '';
            }

            return format( run.is_active ? i18n().exportFilesActive : i18n().exportFiles, run.export_files );
        },
        get reportDoneStyle() {
            const run = state.report.run;
            return segmentStyle( run, run ? run.succeeded : 0 );
        },
        get reportFailedStyle() {
            const run = state.report.run;
            return segmentStyle( run, run ? run.failed : 0 );
        },
        get reportReleasedStyle() {
            const run = state.report.run;
            return segmentStyle( run, run ? run.released || 0 : 0 );
        },
        get reportHasCurrent() {
            const run = state.report.run;
            return !! run && !! run.current && run.is_active;
        },
        get reportCurrentOrder() {
            const run = state.report.run;
            return run && run.current ? '#' + run.current.order_number : '';
        },
        get reportCurrentStep() {
            const run = state.report.run;
            return run && run.current ? run.current.step_title : '';
        },
        get reportIsActive() {
            return !! state.report.run && state.report.run.is_active;
        },
        get reportIsFinished() {
            return !! state.report.run && ! state.report.run.is_active;
        },
        get reportDateText() {
            const run = state.report.run;
            const by = run && run.user_name && run.user_id !== getConfig( NAMESPACE ).userId ? ' · ' + format( i18n().by, run.user_name ) : '';

            return run ? runPeriod( run ) + by : '';
        },
        get reportFilesExpiredText() {
            return i18n().filesExpired;
        },
        get reportOrdersTitle() {
            return i18n().ordersTitle;
        },
        get showNoReportOrders() {
            return ! state.report.ordersLoading && 0 === state.report.orders.length;
        },
        get noReportOrdersText() {
            return i18n().noOrders;
        },
        // Like the queue: location · number of items.
        get runOrderMeta() {
            const { runorder } = getContext();
            const texts = getConfig( NAMESPACE ).i18n;

            return [ runorder.location || texts.defaultLocation, format( texts.items, runorder.item_count || 0 ) ].join( ' · ' );
        },
        get runOrderStatusText() {
            const { runorder } = getContext();
            return i18n().orderStatus[ runorder.status ] || runorder.status;
        },
        get isRunOrderFailed() {
            const { runorder } = getContext();
            return 'failed' === runorder.status;
        },
        get isRunOrderCompleted() {
            const { runorder } = getContext();
            return 'completed' === runorder.status;
        },
        // Steps of the order itself (e.g. picking), shipment steps are listed with their shipment.
        get runOrderSteps() {
            const { runorder } = getContext();
            const ids = ( runorder.shipments || [] ).map( ( shipment ) => shipment.id );

            return latestSteps( runorder.steps ).filter( ( step ) => ! ids.includes( stepShipment( step ) ) );
        },
        get runOrderFiles() {
            const { runorder } = getContext();
            return ( runorder.files || [] ).filter( ( file ) => ! fileShipment( file, runorder ) );
        },
        get hasRunOrderSteps() {
            return state.runOrderSteps.length > 0;
        },
        get hasRunOrderFiles() {
            return state.runOrderFiles.length > 0;
        },
        // E.g. "Shipment #4617 · Ready for shipping · DHL · 00340434161094042557 · Karton S"
        get runShipmentTitle() {
            const { runshipment } = getContext();
            return format( i18n().shipment, runshipment.id );
        },
        get runShipmentMeta() {
            const { runshipment } = getContext();
            return [ runshipment.status_title, runshipment.provider_title, runshipment.tracking_id, runshipment.packaging ].filter( Boolean ).join( ' · ' );
        },
        get runShipmentSteps() {
            const { runorder, runshipment } = getContext();
            return latestSteps( runorder.steps ).filter( ( step ) => stepShipment( step ) === runshipment.id );
        },
        get runShipmentFiles() {
            const { runorder, runshipment } = getContext();
            return ( runorder.files || [] ).filter( ( file ) => fileShipment( file, runorder ) === runshipment.id );
        },
        get hasRunShipmentSteps() {
            return state.runShipmentSteps.length > 0;
        },
        get hasRunShipmentFiles() {
            return state.runShipmentFiles.length > 0;
        },
        get isRunOrderStepFailed() {
            const { runorderstep } = getContext();
            return 'error' === runorderstep.status;
        },
        get reportHasRunSteps() {
            return !! state.reportRun && ( state.reportRun.run_steps || [] ).length > 0;
        },
        // E.g. "Start of batch 2 · 25 order(s)", "End of run · 40 order(s)".
        get runStepMeta() {
            const { runstep } = getContext();
            const texts = getConfig( NAMESPACE ).i18n.runSteps;
            const phase = 'before_queue' === runstep.phase ? format( texts.batch, runstep.batch ) : runstep.phase_title;

            return runstep.order_count > 0 ? phase + ' · ' + format( texts.orders, runstep.order_count ) : phase;
        },
        get runStepStatusText() {
            const { runstep } = getContext();
            return getConfig( NAMESPACE ).i18n.runSteps.status[ runstep.status ] || runstep.status;
        },
        get isRunStepFailed() {
            const { runstep } = getContext();
            return 'error' === runstep.status;
        },
        get isRunStepDone() {
            const { runstep } = getContext();
            return 'done' === runstep.status;
        },
        // Finished runs are not marked (like the history), other states are (e.g. running, cancelled).
        get showReportStatus() {
            return !! state.report.run && 'completed' !== state.report.run.status;
        },
        get reportStatusClass() {
            const run = state.report.run;
            return 'stc-badge is-' + ( run ? run.status : '' );
        },
    },
    actions: {
        report: {
            /**
             * Adds/updates a run within the overview's list (removed in case it is no longer listed).
             *
             * @param {Object} run
             */
            upsert( run ) {
                const exists = state.runs.some( ( candidate ) => candidate.id === run.id );

                // The history only lists finished runs (e.g. an automation is added once it finished).
                if ( ! isFinished( run ) ) {
                    state.runs = state.runs.filter( ( candidate ) => candidate.id !== run.id );
                    return;
                }

                state.runs = exists ? state.runs.map( ( candidate ) => ( candidate.id === run.id ? { ...candidate, ...run } : candidate ) ) : [ run, ...state.runs ];
            },
            /**
             * Run history: all runs (interactive runs and automations of extensions), newest first. Managers see the
             * runs of all users.
             *
             * @param {number} page
             */
            *loadRuns( page = 1 ) {
                const query = { source: 'all', finished: 1, per_page: RUNS_PER_PAGE, page: 'number' === typeof page ? page : 1 };

                if ( getConfig( NAMESPACE ).canManage ) {
                    query.all_users = 1;
                }

                state.runsLoading = true;

                try {
                    const runs = yield hooks.applyFiltersAsync( 'runs', yield api.request( 'runs', { query } ) );
                    const known = query.page > 1 ? state.runs : [];
                    const ids = known.map( ( run ) => run.id );

                    state.runs = [ ...known, ...runs.filter( ( run ) => ! ids.includes( run.id ) ) ].sort( ( a, b ) => b.id - a.id );
                    state.runsPage = query.page;
                    state.runsHasMore = runs.length >= RUNS_PER_PAGE;
                } catch ( error ) {
                    // Not critical for the overview.
                } finally {
                    state.runsLoading = false;
                }
            },
            *loadMoreRuns() {
                yield actions.report.loadRuns( state.runsPage + 1 );
            },
            /**
             * Orders processed within the run shown on the report screen.
             *
             * @param {number} runId
             */
            *loadOrders( runId ) {
                state.report.ordersLoading = true;

                try {
                    const orders = yield api.request( `runs/${ runId }/orders` );

                    if ( state.report.run && state.report.run.id === runId ) {
                        state.report.orders = orders;
                    }
                } catch ( error ) {
                    state.report.orders = [];
                } finally {
                    state.report.ordersLoading = false;
                }
            },
            *openFromList() {
                const { run } = getContext();

                // The user's own active run within the app: continue it.
                if ( run.is_interactive && run.is_active && run.user_id === getConfig( NAMESPACE ).userId ) {
                    yield actions.openRun( run.id );
                    return;
                }

                yield actions.report.show( run );
            },
            /**
             * Shows the report screen of a run (loads its details).
             *
             * @param {Object} run
             */
            *show( run ) {
                state.report = { run, loading: true, orders: [], ordersLoading: true };
                state.screen = 'report';
                window.scrollTo( 0, 0 );

                try {
                    state.report.run = yield api.request( `runs/${ run.id }` );
                } catch ( error ) {
                    actions.handleError( error );
                } finally {
                    state.report.loading = false;
                }

                // Not awaited: the summary shows right away.
                actions.report.loadOrders( run.id );

                yield hooks.applyFiltersAsync( 'reportShown', undefined, state.report.run );
            },
            *retryRunStep() {
                yield actions.report.resolveRunStep( 'retry' );
            },
            *skipRunStep() {
                yield actions.report.resolveRunStep( 'skip' );
            },
            /**
             * Retries or skips a failed step of a run level phase (e.g. a shipment list at the end of the run).
             *
             * @param {string} type retry|skip
             */
            *resolveRunStep( type ) {
                const { runstep } = getContext();
                const run = state.report.run;

                state.report.loading = true;

                try {
                    const data = yield api.request( `runs/${ run.id }/run-steps/${ runstep.id }/${ type }`, { method: 'POST' } );

                    state.report.run = data.run;

                    if ( data.result && 'error' === data.result.status ) {
                        actions.notify( data.result.message, 'error', 5000 );
                    }
                } catch ( error ) {
                    actions.handleError( error );
                } finally {
                    state.report.loading = false;
                }
            },
            /**
             * Back to the overview (runs of extensions may continue in the background).
             */
            *leave() {
                state.screen = 'overview';
                state.report = { run: null, loading: false, orders: [], ordersLoading: false };

                yield actions.loadQueue();
                yield actions.report.loadRuns();
            },
        },
        /**
         * Header back navigation: depends on the screen.
         *
         * @param {boolean} showReport Whether ending an interactive run shows its report (in case it has files).
         */
        *goToOverview( showReport = true ) {
            const handled = yield hooks.applyFiltersAsync( 'goToOverview', false, false !== showReport );

            if ( handled ) {
                return;
            }

            if ( state.isReportScreen ) {
                yield actions.report.leave();
            } else if ( 'run' === state.screen ) {
                yield actions.stopRun( false !== showReport );
            } else {
                state.screen = 'overview';
            }
        },
    },
} );

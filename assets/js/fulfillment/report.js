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

const DAY_IN_SECONDS = 86400;

function progressPercent( run ) {
    if ( ! run ) {
        return 0;
    }

    if ( ! run.total ) {
        return run.is_active ? 0 : 100;
    }

    return Math.min( 100, Math.round( ( run.processed / run.total ) * 100 ) );
}

function statusText( run ) {
    return run ? i18n().status[ run.status ] || run.status : '';
}

/**
 * @param {Object} run
 * @return {boolean} Whether the run belongs to the overview's list: finished interactive runs with files are listed
 *                   for a day (download, the export is kept longer). Extensions may list other runs.
 */
function isListed( run ) {
    const listed = run.is_interactive ? ! run.is_active && run.export_files > 0 && Date.now() / 1000 - run.finished_at < DAY_IN_SECONDS : false;

    return hooks.applyFilters( 'isListedRun', listed, run );
}

const { state, actions } = store( NAMESPACE, {
    state: {
        get isReportScreen() {
            return 'report' === state.screen;
        },
        get isSubScreen() {
            return 'overview' !== state.screen;
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

            return hooks.applyFilters( 'reportTitle', run.is_interactive ? i18n().sessionTitle : format( i18n().screenTitle, run.workflow_title ), run );
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
            return 'active' === workflow.status && 'interactive' === workflow.mode && ( ( workflow.open_count || 0 ) > 0 || state.hasWorkflowSession );
        },
        // Runs list (overview)
        get hasRuns() {
            return state.runs.length > 0;
        },
        get runsListTitle() {
            return hooks.applyFilters( 'runsListTitle', i18n().listTitle );
        },
        get runListStatus() {
            const { run } = getContext();
            return statusText( run );
        },
        get runListMeta() {
            const { run } = getContext();
            const parts = [ run.is_interactive ? i18n().modes.interactive : run.workflow_title, format( i18n().progress, run.processed, run.total ) ];

            if ( run.export_files > 0 ) {
                parts.push( format( i18n().files, run.export_files ) );
            }

            if ( run.failed > 0 ) {
                parts.push( format( i18n().errors, run.failed ) );
            }

            return parts.join( ' · ' );
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
        get reportCountsText() {
            const run = state.report.run;
            return run ? format( i18n().counts, run.succeeded, run.failed ) : '';
        },
        get reportExportText() {
            const run = state.report.run;

            if ( ! run || ! run.export_files ) {
                return '';
            }

            return format( run.is_active ? i18n().exportFilesActive : i18n().exportFiles, run.export_files );
        },
        get reportProgressStyle() {
            return 'width: ' + progressPercent( state.report.run ) + '%';
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
                if ( ! isListed( run ) ) {
                    state.runs = state.runs.filter( ( candidate ) => candidate.id !== run.id );
                    return;
                }

                const exists = state.runs.some( ( candidate ) => candidate.id === run.id );
                state.runs = exists ? state.runs.map( ( candidate ) => ( candidate.id === run.id ? { ...candidate, ...run } : candidate ) ) : [ run, ...state.runs ];
            },
            *loadRuns() {
                try {
                    const sessions = yield api.request( 'runs', { query: { source: 'interactive', undismissed: 1 } } );
                    const runs = yield hooks.applyFiltersAsync( 'runs', sessions );

                    state.runs = runs.filter( isListed ).sort( ( a, b ) => b.id - a.id );
                } catch ( error ) {
                    // Not critical for the overview.
                }
            },
            *openFromList() {
                const { run } = getContext();
                yield actions.report.show( run );
            },
            /**
             * Shows the report screen of a run (loads its details).
             *
             * @param {Object} run
             */
            *show( run ) {
                state.report = { run, loading: true };
                state.screen = 'report';
                window.scrollTo( 0, 0 );

                try {
                    state.report.run = yield api.request( `runs/${ run.id }` );
                } catch ( error ) {
                    actions.handleError( error );
                } finally {
                    state.report.loading = false;
                }

                yield hooks.applyFiltersAsync( 'reportShown', undefined, state.report.run );
            },
            /**
             * Back to the overview (runs of extensions may continue in the background).
             */
            *leave() {
                state.screen = 'overview';
                state.report = { run: null, loading: false };

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

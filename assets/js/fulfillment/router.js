/**
 * URLs + browser history of the fulfillment app (query args of the admin page):
 *
 * - overview:                     ?page=wc-shiptastic-fulfillment
 * - workflow editor:              &workflow={id|new}
 * - run (interactive run or report screen): &run={id}
 * - order + step within a run:    &run={id}&item={queue id}&step={step key, e.g. pack:123}
 *
 * The URL follows the state (callbacks.syncUrl, a watch on the app root): switching screens (overview, editor, run)
 * adds a history entry, the current order/step within a run replaces the URL only, so browser back never reopens
 * steps on the server. Back/forward and reloads apply the URL to the state (actions.applyRoute).
 */
import { store } from '@wordpress/interactivity';
import * as hooks from './hooks.js';

const NAMESPACE = 'shiptastic/fulfillment';
const PARAMS = [ 'run', 'item', 'step', 'workflow' ];

/**
 * @return {Object} run, item, step, workflow of the current URL (empty strings in case missing).
 */
export function getRoute() {
    const params = new URLSearchParams( window.location.search );
    const route = {};

    PARAMS.forEach( ( key ) => {
        route[ key ] = params.get( key ) || '';
    } );

    return route;
}

function buildUrl( route ) {
    const params = new URLSearchParams( window.location.search );

    PARAMS.forEach( ( key ) => {
        if ( route[ key ] ) {
            params.set( key, route[ key ] );
        } else {
            params.delete( key );
        }
    } );

    return window.location.pathname + '?' + params.toString();
}

/**
 * Screens with their own history entry.
 *
 * @param {Object} route
 * @return {string}
 */
function screenKey( route ) {
    return route.run ? 'run:' + route.run : 'workflow:' + route.workflow;
}

/**
 * @return {Object|null} The route of the current state, null while it is in transition (e.g. a run starting).
 */
function routeFromState() {
    const empty = { run: '', item: '', step: '', workflow: '' };

    if ( 'run' === state.screen ) {
        // E.g. fixing an order a run of an extension failed on: the URL refers to that run.
        const runId = hooks.applyFilters( 'currentRunId', state.run.id );

        if ( ! runId ) {
            return null;
        }

        const item = state.currentItem;
        const step = item ? state.currentStep : null;

        return { ...empty, run: String( runId ), item: item ? String( item.id ) : '', step: step ? step.key : '' };
    }

    if ( 'report' === state.screen ) {
        return state.report.run ? { ...empty, run: String( state.report.run.id ) } : null;
    }

    const editor = state.workflowEditor;

    return { ...empty, workflow: editor.open ? String( editor.form.id || 'new' ) : '' };
}

/**
 * Updates the URL to the current state (adds a history entry for a new screen).
 */
export function syncUrl() {
    const route = routeFromState();

    if ( ! route ) {
        return;
    }

    const url = buildUrl( route );

    if ( url === window.location.pathname + window.location.search ) {
        return;
    }

    if ( screenKey( route ) !== screenKey( getRoute() ) ) {
        window.history.pushState( null, '', url );
    } else {
        window.history.replaceState( null, '', url );
    }
}

const { state, actions } = store( NAMESPACE, {
    state: {
        // The URL is applied (initially or after back/forward): the state must not be written to the URL meanwhile.
        routerReady: false,
        routing: false,
    },
    actions: {
        /**
         * Applies the URL to the state: opens the run (interactive run or report screen), the workflow editor or the overview.
         */
        *applyRoute() {
            const route = getRoute();

            state.routing = true;

            try {
                if ( route.run ) {
                    const runId = parseInt( route.run, 10 );
                    const isCurrent = ( 'run' === state.screen && ( state.run.id === runId || hooks.applyFilters( 'currentRunId', state.run.id ) === runId ) ) || ( 'report' === state.screen && state.report.run && state.report.run.id === runId );

                    if ( ! isCurrent ) {
                        // Leave the current screen (e.g. ends an interactive run) without showing its report.
                        if ( ! [ 'overview', 'loading' ].includes( state.screen ) ) {
                            yield actions.goToOverview( false );
                        }

                        state.workflowEditor.open = false;
                        yield actions.openRun( runId, route );
                    }

                    return;
                }

                if ( 'loading' === state.screen ) {
                    state.screen = 'overview';
                } else if ( 'overview' !== state.screen ) {
                    yield actions.goToOverview( false );
                }

                if ( route.workflow ) {
                    const id = 'new' === route.workflow ? 0 : parseInt( route.workflow, 10 );

                    // Rendered open (loading) for deep links: loaded here.
                    if ( ! state.workflowEditor.open || state.workflowEditor.loading || ( state.workflowEditor.form.id || 0 ) !== id ) {
                        yield actions.workflowEditor.show( id ? state.workflows.find( ( workflow ) => workflow.id === id ) || null : null );
                    }
                } else if ( state.workflowEditor.open ) {
                    // Unsaved changes: stay within the editor (restore its history entry).
                    if ( ! actions.workflowEditor.close() ) {
                        window.history.pushState( null, '', buildUrl( routeFromState() ) );
                    }
                }
            } finally {
                state.routing = false;
                // Deep link opened (or failed: the overview is shown).
                state.routeLoading = false;
            }
        },
    },
    callbacks: {
        syncUrl() {
            // Read before the early return: the watch subscribes to these.
            const ready = state.routerReady && ! state.routing;

            routeFromState();

            if ( ready ) {
                syncUrl();
            }
        },
    },
} );

window.addEventListener( 'popstate', () => {
    if ( state.routerReady ) {
        actions.applyRoute();
    }
} );

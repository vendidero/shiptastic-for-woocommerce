/**
 * Extension points of the fulfillment app: filters (value in, value out) registered by extensions, e.g. via
 * `import { hooks } from 'shiptastic/fulfillment'` within a script module depending on the app module.
 *
 * - applyFilters(): synchronous, e.g. within state getters (reactive: reads within callbacks are tracked).
 * - applyFiltersAsync(): awaits each callback, e.g. within actions (callbacks may return promises).
 *
 * Filters used by the app (value, ...args):
 * - currentRunId (runId): the run the current order belongs to (URL)
 * - isExternalItem (false): the current order belongs to a run of an extension (no interactive run actions)
 * - progressText (text): header progress of the run screen
 * - backTitle (title), headerWorkflowTitle (title), reportTitle (title, run)
 * - goToOverview (handled, showReport): async, true in case an extension handled the navigation
 * - runs (runs): async, runs listed in the overview
 * - isListedRun (listed, run)
 * - reportShown (undefined, run): async, after the report screen of a run was shown
 * - shipmentsContinue (true): whether saving the shipments continues the workflow
 * - shipmentsSaved (handled, data): async, after the shipments were saved (without continuing)
 * - orderDetailsSaved (handled): async, after the order details were saved
 * - workflowForm (form, workflow): editor form of a workflow (workflow null: new workflow)
 * - workflowSaveBody (body, form): request body when saving a workflow
 * - workflowCanSave (canSave, form)
 * - workflowStepVisible (visible, step, form): hidden steps are not saved
 * - workflowActionAvailable (available, action, form): actions which may be added as steps
 * - workflowModeChanged (undefined, mode, form)
 */
const filters = {};

/**
 * @param {string}   name
 * @param {Function} callback (value, ...args) => value
 * @param {number}   priority Lower runs first.
 */
export function addFilter( name, callback, priority = 10 ) {
    filters[ name ] = [ ...( filters[ name ] || [] ), { callback, priority } ].sort( ( a, b ) => a.priority - b.priority );
}

export function hasFilter( name ) {
    return !! filters[ name ] && filters[ name ].length > 0;
}

export function applyFilters( name, value, ...args ) {
    return ( filters[ name ] || [] ).reduce( ( result, filter ) => filter.callback( result, ...args ), value );
}

export async function applyFiltersAsync( name, value, ...args ) {
    let result = value;

    for ( const filter of filters[ name ] || [] ) {
        result = await filter.callback( result, ...args );
    }

    return result;
}

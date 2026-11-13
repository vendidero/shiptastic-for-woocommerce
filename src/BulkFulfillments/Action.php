<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

/**
 * A fulfillment action (step type), e.g. create shipments, pick, pack. Actions are registered via
 * `ActionRegistry` (filter `woocommerce_shiptastic_fulfillment_actions`) and may be provided by third parties.
 *
 * Capabilities are static, execution happens per step with a `StepContext`:
 * - background mode: `run()`
 * - interactive mode: `get_view_data()` (data for the client) and `process()` (submitted data)
 */
abstract class Action {

	abstract public static function get_name();

	abstract public static function get_title();

	abstract public static function get_description();

	/**
	 * @return string[] order and/or shipment
	 */
	public static function get_scopes() {
		return array( 'order' );
	}

	/**
	 * @return string[] before_queue, item and/or after_queue
	 */
	public static function get_phases() {
		return array( 'item' );
	}

	/**
	 * @return string[] interactive and/or background
	 */
	public static function get_modes() {
		return array( 'interactive' );
	}

	/**
	 * Actions which must be done (by any workflow) before this action can run. Missing actions are
	 * injected automatically.
	 *
	 * @return string[]
	 */
	public static function get_requires() {
		return array( 'create_shipments' );
	}

	/**
	 * Whether the action runs again within each workflow (e.g. printing). Otherwise, an action done by one
	 * workflow is considered done for all workflows.
	 *
	 * @return bool
	 */
	public static function is_repeatable() {
		return false;
	}

	/**
	 * Whether the action needs a connection to the server/third parties (e.g. carrier APIs) and thus cannot
	 * be performed offline.
	 *
	 * @return bool
	 */
	public static function requires_network() {
		return false;
	}

	/**
	 * Settings fields used by the workflow editor, keyed by setting name: type, label, default, options.
	 *
	 * @return array
	 */
	public static function get_settings_schema() {
		return array();
	}

	public static function supports_mode( $mode ) {
		return in_array( $mode, static::get_modes(), true );
	}

	public static function supports_scope( $scope ) {
		return in_array( $scope, static::get_scopes(), true );
	}

	public static function get_default_scope() {
		$scopes = static::get_scopes();

		return in_array( 'order', $scopes, true ) ? 'order' : $scopes[0];
	}

	/**
	 * Markup (with Interactivity API directives) rendered once per app load for this action's view.
	 *
	 * @return string
	 */
	public static function get_template() {
		return '';
	}

	/**
	 * Optional script module (handle => url) providing the action's client store.
	 *
	 * @return array|null
	 */
	public static function get_script_module() {
		return null;
	}

	/**
	 * Runs the step in background mode.
	 *
	 * @param StepContext $context
	 *
	 * @return StepResult
	 */
	public function run( $context ) {
		return StepResult::error( sprintf( _x( '%s cannot run in the background.', 'shipments', 'shiptastic-for-woocommerce' ), static::get_title() ) );
	}

	/**
	 * Data needed by the client to render the step (interactive mode).
	 *
	 * @param StepContext $context
	 *
	 * @return array
	 */
	public function get_view_data( $context ) {
		return array();
	}

	/**
	 * Processes data submitted by the client (interactive mode).
	 *
	 * @param StepContext $context
	 * @param array $data Unsanitized data.
	 *
	 * @return StepResult
	 */
	public function process( $context, $data ) {
		return StepResult::error( sprintf( _x( '%s does not support interactive mode.', 'shipments', 'shiptastic-for-woocommerce' ), static::get_title() ) );
	}
}

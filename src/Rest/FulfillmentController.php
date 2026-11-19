<?php

namespace Vendidero\Shiptastic\Rest;

use Vendidero\Shiptastic\BulkFulfillments\ActionRegistry;
use Vendidero\Shiptastic\BulkFulfillments\App;
use Vendidero\Shiptastic\BulkFulfillments\Idempotency;
use Vendidero\Shiptastic\BulkFulfillments\Queue;
use Vendidero\Shiptastic\BulkFulfillments\Run;
use Vendidero\Shiptastic\BulkFulfillments\RunProcessor;
use Vendidero\Shiptastic\BulkFulfillments\QueueItem;
use Vendidero\Shiptastic\BulkFulfillments\Runner;
use Vendidero\Shiptastic\BulkFulfillments\RunItems;
use Vendidero\Shiptastic\BulkFulfillments\RunPhases;
use Vendidero\Shiptastic\BulkFulfillments\RunSteps;
use Vendidero\Shiptastic\BulkFulfillments\ShipmentLayout;
use Vendidero\Shiptastic\BulkFulfillments\StepLog;
use Vendidero\Shiptastic\BulkFulfillments\Workflow;
use Vendidero\Shiptastic\DataStores\FulfillmentWorkflow;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST API for bulk fulfillment: workflows, the queue (claim, heartbeat, steps, release) and a batch endpoint to
 * replay queued (offline) mutations. Mutations accept an idempotency key (body `idempotency_key` or header
 * `Idempotency-Key`) - retried requests return the stored response.
 *
 * Lock errors return HTTP 409 (lock lost/version conflict) or 404. Step errors (e.g. a short pick) are business
 * results and return HTTP 200 with `current.status = error`.
 *
 * Refreshing an expired REST nonce (e.g. after a long offline period) is done via WordPress core:
 * admin-ajax.php?action=rest-nonce.
 */
class FulfillmentController extends \WC_REST_Controller {

	protected $namespace = 'wc/v3';

	protected $rest_base = 'shiptastic/fulfillment';

	const MAX_BATCH_REQUESTS = 50;

	public function register_routes() {
		$base = '/' . $this->rest_base;

		register_rest_route(
			$this->namespace,
			$base . '/workflows',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_workflows' ),
					'permission_callback' => array( $this, 'can_fulfill' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_workflow' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/workflows/sort',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sort_workflows' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'ids' => array(
						'description' => _x( 'Workflow ids in list order.', 'shipments', 'shiptastic-for-woocommerce' ),
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'required'    => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/workflows/editor',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_workflow_editor_data' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/workflows/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_workflow' ),
					'permission_callback' => array( $this, 'can_fulfill' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_workflow' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_workflow' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/workflows/(?P<id>[\d]+)/claim',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'claim' ),
				'permission_callback' => array( $this, 'can_fulfill' ),
				'args'                => array(
					'limit'   => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
					),
					'run_id'  => array(
						'description' => _x( 'The interactive run claiming the items (shares its lock token).', 'shipments', 'shiptastic-for-woocommerce' ),
						'type'        => 'integer',
						'default'     => 0,
					),
					'run'     => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'exclude' => array(
						'description' => _x( 'Queue item ids not to claim, e.g. items put back during the current run.', 'shipments', 'shiptastic-for-woocommerce' ),
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'default'     => array(),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/queue',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_queue' ),
				'permission_callback' => array( $this, 'can_fulfill' ),
				'args'                => array(
					'status'      => array( 'type' => array( 'string', 'array' ) ),
					'location'    => array( 'type' => 'string' ),
					'order_id'    => array( 'type' => 'integer' ),
					'workflow_id' => array(
						'description' => _x( 'Limit to items of a workflow: matching its filters, open items only in case they have open steps for it.', 'shipments', 'shiptastic-for-woocommerce' ),
						'type'        => 'integer',
					),
					'page'        => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page'    => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/queue/heartbeat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'heartbeat' ),
				'permission_callback' => array( $this, 'can_fulfill' ),
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/queue/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_queue_item' ),
				'permission_callback' => array( $this, 'can_fulfill' ),
			)
		);

		foreach ( array( 'run', 'release', 'skip' ) as $action ) {
			register_rest_route(
				$this->namespace,
				$base . '/queue/(?P<id>[\d]+)/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $action . '_queue_item' ),
					'permission_callback' => array( $this, 'can_fulfill' ),
				)
			);
		}

		register_rest_route(
			$this->namespace,
			$base . '/queue/(?P<id>[\d]+)/reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reset_queue_item' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/queue/(?P<id>[\d]+)/steps/(?P<step>[a-z0-9_\-]+(?::[\d]+)?)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit_step' ),
				'permission_callback' => array( $this, 'can_fulfill' ),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/queue/(?P<id>[\d]+)/steps/(?P<step>[a-z0-9_\-]+(?::[\d]+)?)/reopen',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reopen_step' ),
				'permission_callback' => array( $this, 'can_fulfill' ),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/queue/(?P<id>[\d]+)/shipments',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_shipments' ),
				'permission_callback' => array( $this, 'can_fulfill' ),
				'args'                => array(
					'shipments' => array(
						'description' => _x( 'Shipment layout of the package: id (shipment id or temporary key), items (order item id => quantity), props (packaging_id, provider, weight, length, width, height).', 'shipments', 'shiptastic-for-woocommerce' ),
						'type'        => 'array',
						'required'    => true,
					),
					'continue'  => array(
						'description' => _x( 'Whether to continue the workflow afterwards. Disabled while fixing an order of a stopped automation (the automation continues it).', 'shipments', 'shiptastic-for-woocommerce' ),
						'type'        => 'boolean',
						'default'     => true,
					),
				),
			)
		);

		// Label form of a package shipment (create label step: edit the whole label within the app).
		register_rest_route(
			$this->namespace,
			$base . '/queue/(?P<id>[\d]+)/shipments/(?P<shipment_id>[\d]+)/label-form',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_label_form' ),
				'permission_callback' => array( $this, 'can_fulfill' ),
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/runs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_runs' ),
					'permission_callback' => array( $this, 'can_fulfill' ),
					'args'                => array(
						'active'      => array( 'type' => 'boolean' ),
						'finished'    => array(
							'description' => _x( 'Finished runs only (history): ended runs which processed orders or created files.', 'shipments', 'shiptastic-for-woocommerce' ),
							'type'        => 'boolean',
						),
						'undismissed' => array( 'type' => 'boolean' ),
						'workflow_id' => array( 'type' => 'integer' ),
						'source'      => array(
							'description' => _x( 'Runs of a source: interactive (default), other sources (e.g. automation runs of extensions) or all (run history).', 'shipments', 'shiptastic-for-woocommerce' ),
							'type'        => 'string',
							'enum'        => array( 'automation', 'interactive', 'all' ),
							'default'     => 'interactive',
						),
						'page'        => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page'    => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
						'all_users'   => array(
							'description' => _x( 'Runs of all users (managers only).', 'shipments', 'shiptastic-for-woocommerce' ),
							'type'        => 'boolean',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start_run' ),
					'permission_callback' => array( $this, 'can_fulfill' ),
					'args'                => array(
						'workflow_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'step'        => array(
							'description' => _x( 'The step (action name) of the workflow the run is limited to, empty for the whole workflow (runs of extensions).', 'shipments', 'shiptastic-for-woocommerce' ),
							'type'        => 'string',
							'default'     => '',
						),
						'mode'        => array(
							'type'        => 'string',
							'description' => _x( 'Run mode: interactive starts (or resumes) an interactive run within the app. Extensions may add modes.', 'shipments', 'shiptastic-for-woocommerce' ),
							'enum'        => self::get_run_modes(),
							'default'     => 'interactive',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/runs/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_run' ),
				'permission_callback' => array( $this, 'can_fulfill' ),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/runs/(?P<id>[\d]+)/dismiss',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_run' ),
				'permission_callback' => array( $this, 'can_fulfill' ),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/runs/(?P<id>[\d]+)/items',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_run_items' ),
				'permission_callback' => array( $this, 'can_fulfill' ),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/runs/(?P<id>[\d]+)/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_run_orders' ),
				'permission_callback' => array( $this, 'can_fulfill' ),
			)
		);

		foreach ( array( 'retry', 'skip' ) as $run_step_action ) {
			register_rest_route(
				$this->namespace,
				$base . '/runs/(?P<id>[\d]+)/run-steps/(?P<entry_id>[\d]+)/' . $run_step_action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'retry' === $run_step_action ? 'retry_run_step' : 'skip_run_step' ),
					'permission_callback' => array( $this, 'can_fulfill' ),
				)
			);
		}

		register_rest_route(
			$this->namespace,
			$base . '/runs/(?P<id>[\d]+)/finish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'finish_run' ),
				'permission_callback' => array( $this, 'can_fulfill' ),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/batch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'batch' ),
				'permission_callback' => array( $this, 'can_fulfill' ),
				'args'                => array(
					'requests' => array(
						'type'     => 'array',
						'required' => true,
					),
				),
			)
		);
	}

	public function can_fulfill() {
		$permission = current_user_can( 'edit_shop_orders' );

		if ( ! apply_filters( 'woocommerce_shiptastic_rest_check_permissions', $permission, 'fulfillment', 'edit', 0 ) ) {
			return new WP_Error( 'woocommerce_stc_rest_cannot_fulfill', _x( 'Sorry, you cannot fulfill orders.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	public function can_manage() {
		$permission = current_user_can( 'manage_woocommerce' );

		if ( ! apply_filters( 'woocommerce_shiptastic_rest_check_permissions', $permission, 'fulfillment_workflow', 'edit', 0 ) ) {
			return new WP_Error( 'woocommerce_stc_rest_cannot_manage', _x( 'Sorry, you cannot manage fulfillment workflows.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/*
	|--------------------------------------------------------------------------
	| Workflows
	|--------------------------------------------------------------------------
	*/

	/**
	 * @param Workflow $workflow
	 *
	 * @return array
	 */
	public static function prepare_workflow( $workflow ) {
		$actions = array();

		foreach ( $workflow->get_steps() as $step ) {
			$action    = ActionRegistry::get( $step['action'] );
			$actions[] = array(
				'action'   => $step['action'],
				'title'    => $action ? $action::get_title() : $step['action'],
				'settings' => $step['settings'],
				'mode'     => $workflow->get_step_mode( $step['action'], $step['settings'] ),
				'phase'    => Workflow::get_step_phase( $step['action'], $step['settings'] ),
			);
		}

		$data = array(
			'id'            => $workflow->get_id(),
			'title'         => $workflow->get_title(),
			'priority'      => $workflow->get_priority(),
			'mode'          => $workflow->get_mode(),
			'filters'       => Workflow::sanitize_filters( $workflow->get_filters() ),
			'steps'         => $actions,
			// Orders waiting for this workflow.
			'open_count'    => $workflow->get_id() ? Queue::count(
				array(
					'status'   => 'pending',
					'workflow' => $workflow,
				)
			) : 0,
			'date_created'  => wc_rest_prepare_date_response( $workflow->get_date_created() ),
			'date_modified' => wc_rest_prepare_date_response( $workflow->get_date_modified() ),
		);

		/**
		 * Filter to adjust the REST (and fulfillment app) data of a workflow, e.g. settings of extensions.
		 *
		 * @param array    $data
		 * @param Workflow $workflow
		 *
		 * @package Vendidero/Shiptastic
		 */
		return apply_filters( 'woocommerce_shiptastic_fulfillment_rest_prepare_workflow', $data, $workflow );
	}

	protected function get_workflow_object( $id ) {
		$workflow = new Workflow( absint( $id ) );

		if ( ! $workflow->get_id() ) {
			return new WP_Error( 'woocommerce_stc_rest_workflow_invalid_id', _x( 'Invalid workflow.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 404 ) );
		}

		return $workflow;
	}

	public function get_workflows( $request ) {
		return rest_ensure_response(
			array_map(
				function ( $id ) {
					return self::prepare_workflow( new Workflow( $id ) );
				},
				FulfillmentWorkflow::get_workflow_ids()
			)
		);
	}

	/**
	 * Sorts the workflows (arrows within the overview): the given ids in list order, workflows missing from the list
	 * keep their relative order after them.
	 */
	public function sort_workflows( $request ) {
		$ids      = array_values( array_unique( array_filter( array_map( 'absint', (array) $request['ids'] ) ) ) );
		$existing = FulfillmentWorkflow::get_workflow_ids();
		$ordered  = array_merge( array_values( array_intersect( $ids, $existing ) ), array_values( array_diff( $existing, $ids ) ) );
		$count    = count( $ordered );

		foreach ( $ordered as $position => $workflow_id ) {
			$workflow = new Workflow( $workflow_id );
			$priority = $count - $position;

			if ( $workflow->get_id() && $workflow->get_priority() !== $priority ) {
				$workflow->set_priority( $priority );
				$workflow->save();
			}
		}

		FulfillmentWorkflow::clear_caches();

		return $this->get_workflows( $request );
	}

	/**
	 * Choices of the workflow editor which are specific to Shiptastic: available actions (incl. settings), locations,
	 * shipping providers and users workflows may run as. Countries and shipping methods are loaded from the
	 * WooCommerce core REST API.
	 */
	public function get_workflow_editor_data( $request ) {
		$actions = array();

		foreach ( ActionRegistry::get_all() as $name => $action ) {
			$settings = array();

			foreach ( (array) $action::get_settings_schema() as $key => $field ) {
				$field   = (array) $field;
				$options = array();

				foreach ( (array) ( isset( $field['options'] ) ? $field['options'] : array() ) as $value => $label ) {
					$options[] = array(
						'value' => (string) $value,
						'label' => wp_strip_all_tags( $label ),
					);
				}

				$type    = isset( $field['type'] ) && in_array( $field['type'], array( 'select', 'multiselect', 'checkbox', 'number', 'text' ), true ) ? $field['type'] : 'text';
				$setting = array(
					'key'     => (string) $key,
					'type'    => $type,
					'label'   => wp_strip_all_tags( isset( $field['label'] ) ? $field['label'] : $key ),
					'default' => isset( $field['default'] ) ? $field['default'] : '',
					'options' => $options,
				);

				/*
				 * Multiselects are presented like the list filters of a workflow: all options by default, restricted to
				 * the selected options on request.
				 */
				if ( 'multiselect' === $type ) {
					$setting['default'] = array_values( array_map( 'strval', (array) ( isset( $field['default'] ) ? $field['default'] : array() ) ) );
					$setting['texts']   = array(
						'all'   => wp_strip_all_tags( isset( $field['all_label'] ) ? $field['all_label'] : _x( 'All', 'shipments', 'shiptastic-for-woocommerce' ) ),
						'add'   => wp_strip_all_tags( isset( $field['add_label'] ) ? $field['add_label'] : _x( '+ Add…', 'shipments', 'shiptastic-for-woocommerce' ) ),
						'empty' => wp_strip_all_tags( isset( $field['empty_label'] ) ? $field['empty_label'] : _x( 'Nothing selected yet - all options are included.', 'shipments', 'shiptastic-for-woocommerce' ) ),
					);
				}

				$settings[] = $setting;
			}

			$actions[] = array(
				'name'          => (string) $name,
				'title'         => $action::get_title(),
				'description'   => $action::get_description(),
				// Modes a step of the action may run in within workflows, the first one is the default.
				'modes'         => Workflow::get_action_modes( $name ),
				'default_mode'  => Workflow::get_action_modes( $name )[0],
				// Modes the action supports which require Shiptastic Pro (presented with a pro marker).
				'pro_modes'     => self::get_action_pro_modes( $name ),
				'scopes'        => array_values( $action::get_scopes() ),
				'default_scope' => $action::get_default_scope(),
				// Phases a step of the action may run in (see RunPhases), the first one is the default.
				'phases'        => array_values(
					array_map(
						function ( $phase ) {
							$descriptions = RunPhases::get_descriptions();
							$data         = array(
								'value'       => $phase,
								'label'       => RunPhases::get_title( $phase ),
								'description' => isset( $descriptions[ $phase ] ) ? $descriptions[ $phase ] : '',
								'run_level'   => RunPhases::is_run_level( $phase ),
							);

							// The item phase of shipment scope steps runs per shipment.
							if ( RunPhases::ITEM === $phase ) {
								$texts                        = RunPhases::get_item_shipment_texts();
								$data['shipment_label']       = $texts['title'];
								$data['shipment_description'] = $texts['description'];
							}

							return $data;
						},
						// The default phase first.
						array_unique( array_merge( array( $action::get_default_phase() ), $action::get_phases() ) )
					)
				),
				'default_phase' => $action::get_default_phase(),
				'requires'      => array_values( $action::get_requires() ),
				'settings'      => $settings,
			);
		}

		$locations = array(
			array(
				'value' => '',
				'label' => _x( 'Default location (products without warehouse)', 'shipments', 'shiptastic-for-woocommerce' ),
			),
		);

		foreach ( \Vendidero\Shiptastic\Warehouse\Helper::get_all_warehouses() as $warehouse ) {
			// Locations are warehouse groups and warehouses which are not part of a group.
			if ( $warehouse->is_group() || ! $warehouse->get_group() ) {
				$locations[] = array(
					'value' => $warehouse->get_name(),
					/* translators: %s group title */
					'label' => $warehouse->is_group() ? sprintf( _x( '%s (group)', 'shipments', 'shiptastic-for-woocommerce' ), $warehouse->get_title() ) : $warehouse->get_title(),
				);
			}
		}

		$providers = array();

		foreach ( wc_stc_get_shipping_provider_select( false ) as $name => $title ) {
			$providers[] = array(
				'value' => (string) $name,
				'label' => wp_strip_all_tags( $title ),
			);
		}

		$modes = array();

		foreach ( Workflow::get_modes() as $mode => $title ) {
			$modes[] = array(
				'value' => (string) $mode,
				'label' => wp_strip_all_tags( $title ),
			);
		}

		/**
		 * Filter to adjust the choices of the fulfillment workflow editor.
		 *
		 * @param array $data actions, modes, locations, providers, weight_unit
		 *
		 * @package Vendidero/Shiptastic
		 */
		return rest_ensure_response(
			apply_filters(
				'woocommerce_shiptastic_fulfillment_rest_workflow_editor_data',
				array(
					'actions'     => $actions,
					'modes'       => $modes,
					'locations'   => $locations,
					'providers'   => $providers,
					'weight_unit' => get_option( 'woocommerce_weight_unit', 'kg' ),
				)
			)
		);
	}

	public function get_workflow( $request ) {
		$workflow = $this->get_workflow_object( $request['id'] );

		return is_wp_error( $workflow ) ? $workflow : rest_ensure_response( self::prepare_workflow( $workflow ) );
	}

	/**
	 * @param Workflow $workflow
	 * @param WP_REST_Request $request
	 *
	 * @return Workflow|WP_Error
	 */
	protected function save_workflow( $workflow, $request ) {
		$props = array();

		foreach ( array( 'title', 'mode', 'filters', 'steps' ) as $prop ) {
			if ( isset( $request[ $prop ] ) ) {
				$props[ $prop ] = $request[ $prop ];
			}
		}

		$workflow->set_props( $props );

		/**
		 * Action before a workflow is validated and saved via the REST API, e.g. to apply settings of extensions.
		 *
		 * @param Workflow        $workflow
		 * @param WP_REST_Request $request
		 *
		 * @package Vendidero/Shiptastic
		 */
		do_action( 'woocommerce_shiptastic_fulfillment_rest_before_save_workflow', $workflow, $request );

		$valid = $workflow->validate();

		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'woocommerce_stc_rest_invalid_workflow', implode( ' ', $valid->get_error_messages() ), array( 'status' => 400 ) );
		}

		$workflow->save();

		return $workflow;
	}

	public function create_workflow( $request ) {
		global $wpdb;

		$workflow = new Workflow();

		// New workflows are added at the end of the list.
		$lowest = $wpdb->get_var( "SELECT MIN(workflow_priority) FROM {$wpdb->stc_fulfillment_workflows}" );
		$workflow->set_priority( is_null( $lowest ) ? 0 : (int) $lowest - 1 );

		$workflow = $this->save_workflow( $workflow, $request );

		if ( is_wp_error( $workflow ) ) {
			return $workflow;
		}

		$response = rest_ensure_response( self::prepare_workflow( $workflow ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_workflow( $request ) {
		$workflow = $this->get_workflow_object( $request['id'] );

		if ( is_wp_error( $workflow ) ) {
			return $workflow;
		}

		$workflow = $this->save_workflow( $workflow, $request );

		return is_wp_error( $workflow ) ? $workflow : rest_ensure_response( self::prepare_workflow( $workflow ) );
	}

	public function delete_workflow( $request ) {
		$workflow = $this->get_workflow_object( $request['id'] );

		if ( is_wp_error( $workflow ) ) {
			return $workflow;
		}

		$data = self::prepare_workflow( $workflow );
		$workflow->delete( true );

		return rest_ensure_response( $data );
	}

	/*
	|--------------------------------------------------------------------------
	| Queue
	|--------------------------------------------------------------------------
	*/

	/**
	 * @param string $location_name
	 *
	 * @return string The location's (warehouse or group) title, empty for the default location.
	 */
	protected static function get_location_title( $location_name ) {
		if ( '' === $location_name ) {
			return '';
		}

		$location = \Vendidero\Shiptastic\Warehouse\Helper::get_location( $location_name );

		return $location ? $location->get_title() : $location_name;
	}

	/**
	 * @param QueueItem $item
	 * @param array $args workflow (Workflow), execution (Runner::execute() result), include_token
	 *
	 * @return array
	 */
	public static function prepare_queue_item( $item, $args = array() ) {
		$args  = wp_parse_args(
			$args,
			array(
				'workflow'      => null,
				'execution'     => null,
				'include_token' => false,
			)
		);
		$order = $item->get_order();

		$data = array(
			'id'                => $item->get_id(),
			'order_id'          => $item->get_order_id(),
			'order_number'      => $order ? $order->get_order_number() : (string) $item->get_order_id(),
			'order_url'         => $order ? esc_url_raw( $order->get_edit_order_url() ) : '',
			'package_key'       => $item->get_package_key(),
			'location'          => $item->get_location(),
			'location_title'    => self::get_location_title( $item->get_location() ),
			'shipping_method'   => $item->get_shipping_method(),
			'shipping_provider' => $item->get_shipping_provider(),
			'country'           => $item->get_country(),
			// Compact shipping address (run header): name (company), street, postcode + city, country.
			'shipping_address'  => $order ? self::get_short_address( $order ) : '',
			'item_count'        => $item->get_item_count(),
			'weight'            => $item->get_weight(),
			'has_backorders'    => $item->has_backorders(),
			'is_ready'          => $item->is_ready(),
			'status'            => $item->get_status(),
			'is_stale'          => $item->is_stale(),
			'workflow_id'       => $item->get_workflow_id(),
			'locked_by'         => $item->get_locked_by(),
			// Who/what holds the item (in progress): user name, workflow, lock source (interactive|background|scheduled).
			'locked_by_name'    => self::get_user_name( $item->get_locked_by() ),
			'workflow_title'    => self::get_workflow_title( $item->get_workflow_id() ),
			'lock_source'       => $item->get_lock_source(),
			'lock_expires'      => $item->get_lock_expires() ? wc_rest_prepare_date_response( $item->get_lock_expires() ) : null,
			'version'           => $item->get_version(),
			'last_error'        => $item->get_last_error(),
			'date_queued'       => $item->get_date_queued() ? wc_rest_prepare_date_response( $item->get_date_queued() ) : null,
		);

		if ( $args['include_token'] ) {
			$data['lock_token'] = $item->get_lock_token();
		}

		if ( $args['workflow'] ) {
			$data['steps'] = array_map(
				function ( $step ) {
					return $step->to_array();
				},
				Runner::get_steps( $item, $args['workflow'] )
			);
		}

		// Offline support: view data of the following (offline capable) steps.
		if ( $args['workflow'] && ( ! $args['execution'] || 'completed' !== $args['execution']['status'] ) ) {
			$data['views'] = (object) Runner::get_offline_views( $item, $args['workflow'], $item->get_lock_token() );
		}

		// The shipments overlay is available during the whole run.
		if ( $args['execution'] && 'completed' !== $args['execution']['status'] ) {
			$data['layout'] = ShipmentLayout::get_data( $item );
		}

		if ( $args['execution'] ) {
			$execution       = $args['execution'];
			$data['current'] = array(
				'status'    => $execution['status'],
				'step'      => $execution['step'] ? $execution['step']->to_array() : null,
				'message'   => $execution['message'],
				'code'      => $execution['code'],
				'view_data' => $execution['view_data'],
				'automated' => array_map(
					function ( $step ) {
						return $step->to_array();
					},
					isset( $execution['automated'] ) ? $execution['automated'] : array()
				),
			);
		}

		return $data;
	}

	/**
	 * @param \WC_Order $order
	 *
	 * @return string E.g. "Max Mustermann (ACME), Musterstr. 12, 12222 Berlin, DE" (shipping address, billing address
	 *                as fallback).
	 */
	public static function get_short_address( $order ) {
		$type = $order->has_shipping_address() ? 'shipping' : 'billing';
		$get  = function ( $prop ) use ( $order, $type ) {
			$getter = "get_{$type}_{$prop}";

			return is_callable( array( $order, $getter ) ) ? trim( (string) $order->$getter() ) : '';
		};

		$name    = trim( $get( 'first_name' ) . ' ' . $get( 'last_name' ) );
		$company = $get( 'company' );

		if ( '' !== $company ) {
			$name = '' !== $name ? sprintf( '%1$s (%2$s)', $name, $company ) : $company;
		}

		return implode(
			', ',
			array_filter(
				array(
					$name,
					trim( $get( 'address_1' ) . ' ' . $get( 'address_2' ) ),
					trim( $get( 'postcode' ) . ' ' . $get( 'city' ) ),
					$get( 'country' ),
				)
			)
		);
	}

	/**
	 * @param int $user_id
	 *
	 * @return string
	 */
	protected static function get_user_name( $user_id ) {
		static $names = array();

		if ( ! $user_id ) {
			return '';
		}

		if ( ! isset( $names[ $user_id ] ) ) {
			$user              = get_userdata( $user_id );
			$names[ $user_id ] = $user ? $user->display_name : '';
		}

		return $names[ $user_id ];
	}

	/**
	 * @param int $workflow_id
	 *
	 * @return string
	 */
	protected static function get_workflow_title( $workflow_id ) {
		static $titles = array();

		if ( ! $workflow_id ) {
			return '';
		}

		if ( ! isset( $titles[ $workflow_id ] ) ) {
			$workflow               = new Workflow( $workflow_id );
			$titles[ $workflow_id ] = $workflow->get_id() ? $workflow->get_title() : '';
		}

		return $titles[ $workflow_id ];
	}

	/**
	 * @return array workflow_id => number of orders waiting for the workflow
	 */
	protected static function get_open_counts() {
		$counts = array();

		foreach ( FulfillmentWorkflow::get_workflow_ids() as $workflow_id ) {
			$counts[ $workflow_id ] = Queue::count(
				array(
					'status'   => 'pending',
					'workflow' => new Workflow( $workflow_id ),
				)
			);
		}

		return $counts;
	}

	public function get_queue( $request ) {
		$workflow = null;

		if ( ! empty( $request['workflow_id'] ) ) {
			$workflow = $this->get_workflow_object( $request['workflow_id'] );

			if ( is_wp_error( $workflow ) ) {
				return $workflow;
			}
		}

		$statuses = isset( $request['status'] ) ? (array) $request['status'] : array();
		$ready    = null;

		/*
		 * Overview tabs: `pending` (open) excludes items ready for shipping, `ready` lists them (pending items whose
		 * shipments are all ready for shipping).
		 */
		if ( array( 'ready' ) === array_values( $statuses ) ) {
			$statuses = array( 'pending' );
			$ready    = true;
		} elseif ( array( 'pending' ) === array_values( $statuses ) ) {
			$ready = false;
		}

		$args = array(
			'status'   => $statuses,
			'location' => isset( $request['location'] ) ? $request['location'] : null,
			'order_id' => isset( $request['order_id'] ) ? $request['order_id'] : 0,
			'workflow' => $workflow,
			'ready'    => $ready,
		);

		$items = Queue::query(
			array_merge(
				$args,
				array(
					'limit'  => $request['per_page'],
					'offset' => ( $request['page'] - 1 ) * $request['per_page'],
				)
			)
		);

		$total    = Queue::count( $args );
		$pages    = (int) ceil( $total / $request['per_page'] );
		$response = rest_ensure_response(
			array(
				'items'       => array_map(
					function ( $item ) {
						return self::prepare_queue_item( $item );
					},
					$items
				),
				'counts'      => Queue::count_by_status( $workflow ),
				'total'       => $total,
				'pages'       => $pages,
				// Orders waiting per active workflow (overview).
				'open_counts' => (object) self::get_open_counts(),
			)
		);

		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $pages );

		return $response;
	}

	protected function get_queue_item_object( $id ) {
		$item = Queue::get( absint( $id ) );

		if ( ! $item ) {
			return new WP_Error( 'queue_item_not_found', _x( 'The queue item does not exist.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 404 ) );
		}

		return $item;
	}

	public function get_queue_item( $request ) {
		$item = $this->get_queue_item_object( $request['id'] );

		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$workflow = null;

		if ( ! empty( $request['workflow_id'] ) ) {
			$workflow = $this->get_workflow_object( $request['workflow_id'] );

			if ( is_wp_error( $workflow ) ) {
				return $workflow;
			}
		}

		$data = self::prepare_queue_item( $item, array( 'workflow' => $workflow ) );

		if ( $workflow ) {
			$data['log'] = StepLog::get_entries( $item->get_id() );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Claims the next queue items for a workflow. By default each item is executed right away until its first
	 * interactive step (background steps run), so the response contains everything needed to work offline.
	 */
	public function claim( $request ) {
		$workflow = $this->get_workflow_object( $request['id'] );

		if ( is_wp_error( $workflow ) ) {
			return $workflow;
		}

		$run = null;

		if ( ! empty( $request['run_id'] ) ) {
			$run = $this->get_interactive_run( $request['run_id'], $workflow );

			if ( is_wp_error( $run ) ) {
				return $run;
			}
		}

		$items = Queue::claim(
			$workflow,
			array(
				'limit'   => isset( $request['limit'] ) ? absint( $request['limit'] ) : null,
				'user_id' => get_current_user_id(),
				'source'  => 'interactive',
				'exclude' => (array) $request['exclude'],
				'token'   => $run ? $run->lock_token : '',
			)
		);

		$token = empty( $items ) ? '' : $items[0]->get_lock_token();
		$data  = array();

		$batch = null;

		if ( $run && ! empty( $items ) ) {
			$run = Run::update( $run->get_id(), array( 'total' => $run->total + count( $items ) ) );

			// Each claim of a run is a batch: steps running at its start (e.g. a picking list) run first.
			$batch = RunSteps::start_batch( $run, $items, $token, 'interactive' );
			$run   = $batch['run'];
		}

		foreach ( $items as $item ) {
			$execution = $request['run'] ? Runner::execute( $item->get_id(), $workflow, $token, array( 'run' => $run ) ) : null;
			$item      = Queue::get( $item->get_id() );

			$data[] = self::prepare_queue_item(
				$item,
				array(
					'workflow'      => $workflow,
					'execution'     => $execution,
					'include_token' => $item->get_lock_token() === $token,
				)
			);
		}

		return rest_ensure_response(
			array(
				'token'        => $token,
				'lock_expires' => empty( $items ) ? null : wc_rest_prepare_date_response( Queue::get( $items[0]->get_id() )->get_lock_expires() ),
				'items'        => $data,
				'run'          => $run ? self::prepare_run_export( Run::get( $run->get_id() ) ) : null,
				// Steps of the batch's start (run level).
				'batch'        => $batch ? self::prepare_phase_result( $batch ) : null,
			)
		);
	}

	/**
	 * @param array $result Result of a run level phase (RunSteps).
	 *
	 * @return array status (none|done|error), batch, message, entry (the failed step)
	 */
	protected static function prepare_phase_result( $result ) {
		return array(
			'status'  => $result['status'],
			'batch'   => isset( $result['batch'] ) ? $result['batch'] : 0,
			'message' => $result['message'],
			'entry'   => $result['entry'] ? RunSteps::prepare_entry( $result['entry'] ) : null,
		);
	}

	/**
	 * @param int $run_id
	 * @param Workflow|null $workflow
	 *
	 * @return Run|WP_Error The current user's active interactive run.
	 */
	protected function get_interactive_run( $run_id, $workflow = null ) {
		$run = Run::get( $run_id );

		if ( ! $run || ! $run->is_interactive() || get_current_user_id() !== $run->user_id ) {
			return new WP_Error( 'woocommerce_stc_rest_invalid_run', _x( 'This run does not exist.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 404 ) );
		}

		if ( ! $run->is_active() ) {
			return new WP_Error( 'woocommerce_stc_rest_run_finished', _x( 'This run has finished already.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 409 ) );
		}

		if ( $workflow && $workflow->get_id() !== $run->workflow_id ) {
			return new WP_Error( 'woocommerce_stc_rest_invalid_run', _x( 'The run belongs to another workflow.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 400 ) );
		}

		return $run;
	}

	/**
	 * @param string $token
	 *
	 * @return Run|null The active run owning a queue lock token (interactive runs, orders of stopped automations).
	 */
	protected static function get_token_run( $token ) {
		return Run::get_by_lock_token( (string) $token );
	}

	/**
	 * @param Run $run
	 *
	 * @return array Export state of a run, returned with step responses of interactive runs.
	 */
	public static function prepare_run_export( $run ) {
		return array(
			'id'           => $run->get_id(),
			'total'        => $run->total,
			'processed'    => $run->processed,
			'export_files' => \Vendidero\Shiptastic\BulkFulfillments\RunExport::has_export( $run ) ? $run->export_files : 0,
			'export_url'   => \Vendidero\Shiptastic\BulkFulfillments\RunExport::get_download_url( $run ),
		);
	}

	public function heartbeat( $request ) {
		$count = Queue::heartbeat( $request['token'] );

		if ( $run = self::get_token_run( $request['token'] ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
			Run::touch( $run->get_id() );
		}

		return rest_ensure_response(
			array(
				'extended' => $count,
			)
		);
	}

	/**
	 * Runs an idempotent mutation: returns the stored response for a known key, stores the new one otherwise.
	 *
	 * @param WP_REST_Request $request
	 * @param callable $callback
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	protected function idempotent( $request, $callback ) {
		$key = $request->get_header( 'Idempotency-Key' );
		$key = empty( $key ) && isset( $request['idempotency_key'] ) ? $request['idempotency_key'] : $key;
		$key = wc_clean( (string) $key );

		if ( $stored = Idempotency::get( $key, get_current_user_id() ) ) {
			$response = rest_ensure_response( $stored['data'] );
			$response->set_status( $stored['status'] );
			$response->header( 'Idempotent-Replayed', 'true' );

			return $response;
		}

		$response = call_user_func( $callback );

		// Errors (e.g. lock lost) are not stored - the client may retry after re-claiming.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = rest_ensure_response( $response );
		Idempotency::store( $key, get_current_user_id(), $response->get_status(), $response->get_data() );

		return $response;
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_Error queue item and workflow
	 */
	protected function get_locked_context( $request ) {
		$item = $this->get_queue_item_object( $request['id'] );

		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$version  = isset( $request['version'] ) && '' !== $request['version'] ? absint( $request['version'] ) : null;
		$verified = Queue::verify_lock( $item->get_id(), (string) $request['token'], $version );

		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		if ( $item->get_locked_by() !== get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'queue_lock_lost', _x( 'The order is reserved for another user.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 403 ) );
		}

		$workflow = $this->get_workflow_object( ! empty( $request['workflow_id'] ) ? $request['workflow_id'] : $item->get_workflow_id() );

		if ( is_wp_error( $workflow ) ) {
			return $workflow;
		}

		return array(
			'item'     => $item,
			'workflow' => $workflow,
		);
	}

	protected function execution_response( $item_id, $workflow, $token, $execution, $submitted_step = '' ) {
		if ( 'error' === $execution['status'] && in_array( $execution['code'], array( 'queue_lock_lost', 'queue_version_conflict' ), true ) ) {
			return new WP_Error( $execution['code'], $execution['message'], array( 'status' => 409 ) );
		}

		$item = Queue::get( $item_id );
		$data = self::prepare_queue_item(
			$item,
			array(
				'workflow'      => 'completed' === $execution['status'] ? null : $workflow,
				'execution'     => $execution,
				'include_token' => $item->get_lock_token() === $token,
			)
		);

		// Interactive runs: files (export) created so far.
		if ( ( $run = self::get_token_run( $token ) ) && $run->is_interactive() ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
			$data['run'] = self::prepare_run_export( $run );
		}

		// Labels created within the request (incl. automatic steps), offered for printing right away.
		$data['labels'] = \Vendidero\Shiptastic\BulkFulfillments\Actions\CreateLabel::get_created_labels();

		if ( $submitted_step ) {
			list( $action, $shipment_id ) = array_pad( explode( ':', $submitted_step ), 2, 0 );

			foreach ( StepLog::get_entries( $item_id ) as $entry ) {
				if ( $entry['action'] === $action && absint( $shipment_id ) === $entry['shipment_id'] ) {
					$data['step_result'] = array(
						'status' => $entry['status'],
						'data'   => $entry['data'],
						'error'  => $entry['error'],
					);
				}
			}
		}

		return $data;
	}

	/**
	 * Optional request args to run the workflow's automatic steps one by one (the app shows their progress):
	 * auto_steps (number of automatic steps to run, 0 = return the next one) and manual (key of an automatic step to
	 * handle interactively, e.g. to fix an error).
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array
	 */
	protected static function get_execution_limits( $request ) {
		$args = array();

		if ( isset( $request['auto_steps'] ) && '' !== $request['auto_steps'] && null !== $request['auto_steps'] ) {
			$args['auto_steps'] = absint( $request['auto_steps'] );
		}

		if ( ! empty( $request['manual'] ) ) {
			$args['manual'] = sanitize_text_field( wp_unslash( (string) $request['manual'] ) );
		}

		return $args;
	}

	/**
	 * Continues the workflow for a locked item: runs background steps and returns the next step needing input.
	 */
	public function run_queue_item( $request ) {
		$context = $this->get_locked_context( $request );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$execution = Runner::execute( $context['item']->get_id(), $context['workflow'], (string) $request['token'], array_merge( array( 'run' => self::get_token_run( $request['token'] ) ), self::get_execution_limits( $request ) ) );

		return rest_ensure_response( $this->execution_response( $context['item']->get_id(), $context['workflow'], (string) $request['token'], $execution ) );
	}

	/**
	 * Submits the data of an interactive step and continues the workflow.
	 */
	public function submit_step( $request ) {
		return $this->idempotent(
			$request,
			function () use ( $request ) {
				$context = $this->get_locked_context( $request );

				if ( is_wp_error( $context ) ) {
					return $context;
				}

				$step      = (string) $request['step'];
				$execution = Runner::execute(
					$context['item']->get_id(),
					$context['workflow'],
					(string) $request['token'],
					array(
						'step' => $step,
						'data' => isset( $request['data'] ) ? (array) $request['data'] : array(),
						'run'  => self::get_token_run( $request['token'] ),
					) + self::get_execution_limits( $request )
				);

				return $this->execution_response( $context['item']->get_id(), $context['workflow'], (string) $request['token'], $execution, $step );
			}
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Automation runs
	|--------------------------------------------------------------------------
	*/

	/**
	 * @param string $key Step key, e.g. pack:123
	 *
	 * @return string
	 */
	protected static function get_step_title( $key ) {
		if ( empty( $key ) ) {
			return '';
		}

		list( $action_name, $shipment_id ) = array_pad( explode( ':', $key ), 2, 0 );
		$action                            = ActionRegistry::get( $action_name );
		$title                             = $action ? $action::get_title() : $action_name;

		/* translators: 1: step title 2: shipment id */
		return absint( $shipment_id ) ? sprintf( _x( '%1$s (shipment #%2$d)', 'shipments', 'shiptastic-for-woocommerce' ), $title, absint( $shipment_id ) ) : $title;
	}

	protected static function get_order_data( $order_id ) {
		$order = $order_id ? wc_get_order( $order_id ) : false;

		return array(
			'order_id'     => absint( $order_id ),
			'order_number' => $order ? $order->get_order_number() : (string) $order_id,
			'order_url'    => $order ? esc_url_raw( $order->get_edit_order_url() ) : '',
		);
	}

	/**
	 * @param Run $run
	 * @param bool $details Whether to include details (e.g. the errors of automation runs, see the filter).
	 *
	 * @return array
	 */
	public static function prepare_run( $run, $details = false ) {
		$workflow = $run->get_workflow();
		$current  = null;

		if ( $run->current_queue_id && ( $item = Queue::get( $run->current_queue_id ) ) ) {
			$step = $run->current_step;

			// While running, show the step processed next.
			if ( 'running' === $run->status && $workflow && ( $next = Runner::get_next_step( $item, $workflow ) ) ) {
				$step = $next->get_key();
			}

			$current = array_merge(
				self::get_order_data( $item->get_order_id() ),
				array(
					'queue_id'   => $item->get_id(),
					'step'       => $step,
					'step_title' => self::get_step_title( $step ),
				)
			);
		}

		$data = array(
			'id'             => $run->get_id(),
			'workflow_id'    => $run->workflow_id,
			'workflow_title' => $workflow ? $workflow->get_title() : '',
			'is_interactive' => $run->is_interactive(),
			'mode'           => $run->mode,
			'source'         => $run->source,
			'status'         => $run->status,
			'is_active'      => $run->is_active(),
			'user_id'        => $run->user_id,
			'user_name'      => self::get_user_name( $run->user_id ),
			// Run history: whether the run's export was removed meanwhile (retention).
			'export_expired' => '' === (string) $run->export && $run->export_files > 0,
			'total'          => max( $run->total, $run->processed ),
			'processed'      => $run->processed,
			'succeeded'      => $run->succeeded,
			'failed'         => $run->failed,
			'released'       => $run->released,
			// Files created by the steps (zip), downloadable during and after the run.
			'export_files'   => \Vendidero\Shiptastic\BulkFulfillments\RunExport::has_export( $run ) ? $run->export_files : 0,
			'export_url'     => \Vendidero\Shiptastic\BulkFulfillments\RunExport::get_download_url( $run ),
			'current'        => $current,
			// Steps of run level phases (start of batch, end of run) and their results.
			'run_steps'      => RunSteps::prepare( $run ),
			'message'        => $run->message,
			'dismissed'      => $run->dismissed,
			'date_created'   => $run->date_created ? wc_rest_prepare_date_response( $run->date_created ) : null,
			'date_finished'  => $run->date_finished ? wc_rest_prepare_date_response( $run->date_finished ) : null,
			'finished_at'    => $run->date_finished ? strtotime( $run->date_finished . ' UTC' ) : 0,
			// The run's screen in the fulfillment app (progress, report).
			'url'            => esc_url_raw( add_query_arg( 'run', $run->get_id(), \Vendidero\Shiptastic\BulkFulfillments\App::get_url() ) ),
		);

		/**
		 * Filter to adjust the REST (and fulfillment app) data of a run, e.g. data of runs of extensions.
		 *
		 * @param array $data
		 * @param Run   $run
		 * @param bool  $details Whether details were requested (e.g. a single run).
		 *
		 * @package Vendidero/Shiptastic
		 */
		return apply_filters( 'woocommerce_shiptastic_fulfillment_rest_prepare_run', $data, $run, $details );
	}

	/**
	 * Modes an action supports which require Shiptastic Pro, e.g. running a step which supports user interaction
	 * automatically. Presented with a pro marker within the workflow editor in case automations are not available.
	 *
	 * @param string $action_name
	 *
	 * @return string[]
	 */
	public static function get_action_pro_modes( $action_name ) {
		$action = ActionRegistry::get( $action_name );

		if ( ! $action || App::has_automation() || ! $action::supports_mode( 'interactive' ) || ! $action::supports_mode( 'background' ) ) {
			return array();
		}

		return array_values( array_diff( array( 'background' ), Workflow::get_action_modes( $action_name ) ) );
	}

	/**
	 * @return string[] Run modes which may be started via the REST API: interactive, extensions may add modes.
	 */
	public static function get_run_modes() {
		/**
		 * Filter to adjust the run modes which may be started via the REST API.
		 *
		 * @param string[] $modes
		 *
		 * @package Vendidero/Shiptastic
		 */
		return array_values( array_unique( array_merge( array( 'interactive' ), (array) apply_filters( 'woocommerce_shiptastic_fulfillment_rest_run_modes', array( 'interactive' ) ) ) ) );
	}

	/**
	 * @param int $id
	 *
	 * @return Run|WP_Error The run in case the current user started it (or may manage WooCommerce).
	 */
	protected function get_run_object( $id ) {
		$run = Run::get( $id );

		if ( ! $run ) {
			return new WP_Error( 'run_not_found', _x( 'The run does not exist.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 404 ) );
		}

		if ( get_current_user_id() !== $run->user_id && ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'run_forbidden', _x( 'Sorry, you cannot access this run.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return $run;
	}

	public function get_runs( $request ) {
		$args = array(
			'user_id'     => ! empty( $request['all_users'] ) && current_user_can( 'manage_woocommerce' ) ? 0 : get_current_user_id(),
			'workflow_id' => absint( $request['workflow_id'] ),
			'status'      => ! empty( $request['active'] ) ? Run::ACTIVE_STATUSES : array(),
			'dismissed'   => ! empty( $request['undismissed'] ) ? false : null,
			'finished'    => ! empty( $request['finished'] ),
			'limit'       => absint( $request['per_page'] ),
			'offset'      => ( max( 1, absint( $request['page'] ) ) - 1 ) * absint( $request['per_page'] ),
		);

		if ( 'interactive' === $request['source'] ) {
			$args['source'] = 'interactive';
		} elseif ( 'all' !== $request['source'] ) {
			$args['exclude_source'] = 'interactive';
		}

		$total    = Run::query( array_merge( $args, array( 'count' => true ) ) );
		$response = rest_ensure_response(
			array_map(
				function ( $run ) {
					return self::prepare_run( $run );
				},
				Run::query( $args )
			)
		);

		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total / max( 1, absint( $request['per_page'] ) ) ) );

		return $response;
	}

	/**
	 * @param QueueItem $queue_item
	 *
	 * @return array Shipments of the queue item's package: id, status (+ title), provider (title), tracking id, packaging.
	 */
	protected static function get_run_order_shipments( $queue_item ) {
		$order_shipment = $queue_item->get_shipment_order();
		$shipments      = array();

		if ( ! $order_shipment ) {
			return $shipments;
		}

		foreach ( $order_shipment->get_simple_shipments() as $shipment ) {
			if ( \Vendidero\Shiptastic\BulkFulfillments\QueueSync::get_package_key( $shipment->get_location(), $shipment->get_shipping_method() ) !== $queue_item->get_package_key() ) {
				continue;
			}

			$packaging   = $shipment->get_packaging();
			$shipments[] = array(
				'id'             => $shipment->get_id(),
				'status'         => $shipment->get_status(),
				'status_title'   => wc_stc_get_shipment_status_name( $shipment->get_status() ),
				'provider_title' => html_entity_decode( wp_strip_all_tags( (string) $shipment->get_shipping_provider_title() ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				'tracking_id'    => (string) $shipment->get_tracking_id(),
				'packaging'      => $packaging ? html_entity_decode( wp_strip_all_tags( $packaging->get_title() ), ENT_QUOTES, get_bloginfo( 'charset' ) ) : '',
			);
		}

		return $shipments;
	}

	/**
	 * The orders processed within a run (report): outcome, the steps executed for each order within the run, the
	 * error of failed orders and the order's files of the run's export.
	 */
	public function get_run_orders( $request ) {
		$run = $this->get_run_object( $request['id'] );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		$items  = RunItems::get_items( $run->get_id() );
		$orders = array();

		foreach ( $items as $run_item ) {
			$queue_item = Queue::get( $run_item['queue_id'] );
			$order      = $queue_item ? wc_get_order( $queue_item->get_order_id() ) : null;

			$orders[] = array_merge(
				$run_item,
				array(
					'order_id'       => $queue_item ? $queue_item->get_order_id() : 0,
					'order_number'   => $order ? $order->get_order_number() : ( $queue_item ? (string) $queue_item->get_order_id() : '' ),
					'order_url'      => $order ? esc_url_raw( $order->get_edit_order_url() ) : '',
					'location'       => $queue_item ? self::get_location_title( $queue_item->get_location() ) : '',
					'item_count'     => $queue_item ? $queue_item->get_item_count() : 0,
					'has_backorders' => $queue_item ? $queue_item->has_backorders() : false,
					// Failed orders (moved to the exceptions): the reason, as long as the order is still an exception.
					'message'        => $queue_item && 'failed' === $run_item['status'] && 'error' === $queue_item->get_status() ? (string) $queue_item->get_last_error() : '',
					'files'          => array(),
					// Shipments of the order's package (incl. shipped ones): steps and files are grouped per shipment.
					'shipments'      => $queue_item ? self::get_run_order_shipments( $queue_item ) : array(),
				)
			);
		}

		$files = \Vendidero\Shiptastic\BulkFulfillments\RunExport::get_files_by_order( $run, array_filter( wp_list_pluck( $orders, 'order_number' ) ) );

		foreach ( $orders as $index => $data ) {
			$orders[ $index ]['files'] = isset( $files[ (string) $data['order_number'] ] ) ? $files[ (string) $data['order_number'] ] : array();
		}

		return rest_ensure_response( $orders );
	}

	public function start_run( $request ) {
		$workflow = $this->get_workflow_object( $request['workflow_id'] );

		if ( is_wp_error( $workflow ) ) {
			return $workflow;
		}

		if ( 'interactive' === $request['mode'] ) {
			$run = RunProcessor::start_interactive( $workflow, get_current_user_id() );
		} else {
			/**
			 * Filter to start a run of another mode (e.g. an automation run) via the REST API.
			 *
			 * @param Run|\WP_Error   $run
			 * @param Workflow        $workflow
			 * @param WP_REST_Request $request
			 *
			 * @package Vendidero/Shiptastic
			 */
			$run = apply_filters( 'woocommerce_shiptastic_fulfillment_rest_start_run', new WP_Error( 'woocommerce_stc_rest_run_mode_unsupported', _x( 'This run mode is not supported.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 400 ) ), $workflow, $request );
		}

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		$response = rest_ensure_response( self::prepare_run( $run, true ) );
		$response->set_status( 201 );

		return $response;
	}

	public function get_run( $request ) {
		$run = $this->get_run_object( $request['id'] );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		/**
		 * Action before a single run is returned via the REST API (e.g. while its screen polls it).
		 *
		 * @param Run $run
		 *
		 * @package Vendidero/Shiptastic
		 */
		do_action( 'woocommerce_shiptastic_fulfillment_rest_get_run', $run );

		return rest_ensure_response( self::prepare_run( $run, true ) );
	}

	/**
	 * Hides a finished run from the run lists (overview).
	 */
	/**
	 * Retries a failed step of a run level phase (start of batch, end of run).
	 */
	public function retry_run_step( $request ) {
		return $this->resolve_run_step( $request, 'retry' );
	}

	/**
	 * Skips a failed step of a run level phase, the following steps of its phase run.
	 */
	public function skip_run_step( $request ) {
		return $this->resolve_run_step( $request, 'skip' );
	}

	/**
	 * @param WP_REST_Request $request
	 * @param string $type retry|skip
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	protected function resolve_run_step( $request, $type ) {
		$run = $this->get_run_object( $request['id'] );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		$source = $run->is_interactive() ? 'interactive' : ( 'scheduled' === $run->source ? 'scheduled' : 'background' );
		$result = 'retry' === $type ? RunSteps::retry( $run, absint( $request['entry_id'] ), $source ) : RunSteps::skip( $run, absint( $request['entry_id'] ), $source );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/**
		 * Action that fires after a failed step of a run level phase was retried or skipped, e.g. to continue a
		 * stopped automation run.
		 *
		 * @param Run    $run
		 * @param array  $result Result of the phase (status: done|error|none, message).
		 * @param string $type retry|skip
		 *
		 * @package Vendidero/Shiptastic
		 */
		do_action( 'woocommerce_shiptastic_fulfillment_run_step_resolved', $run, $result, $type );

		return rest_ensure_response(
			array(
				'result' => self::prepare_phase_result( $result ),
				'run'    => self::prepare_run( Run::get( $run->get_id() ), true ),
			)
		);
	}

	public function dismiss_run( $request ) {
		$run = $this->get_run_object( $request['id'] );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		return rest_ensure_response( self::prepare_run( Run::update( $run->get_id(), array( 'dismissed' => true ) ), true ) );
	}

	/**
	 * The orders locked by an interactive run (incl. the lock token), e.g. to resume it after a reload or on another
	 * device. The current step of each order is determined by the client (queue/{id}/run).
	 */
	public function get_run_items( $request ) {
		$run = $this->get_interactive_run( $request['id'] );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		$workflow = $run->get_workflow();
		$items    = array();

		foreach ( Queue::get_by_token( $run->lock_token ) as $item ) {
			$items[] = self::prepare_queue_item(
				$item,
				array(
					'workflow'      => $workflow,
					'include_token' => true,
				)
			);
		}

		Run::touch( $run->get_id() );

		return rest_ensure_response(
			array(
				'items' => $items,
				'run'   => self::prepare_run_export( $run ),
			)
		);
	}

	/**
	 * Ends an interactive run: remaining orders are released, the export stays available.
	 */
	public function finish_run( $request ) {
		$run = $this->get_interactive_run( $request['id'] );

		if ( is_wp_error( $run ) ) {
			// Finished already (e.g. on another device): nothing to do.
			$existing = Run::get( $request['id'] );

			if ( $existing && $existing->is_interactive() && get_current_user_id() === $existing->user_id ) {
				return rest_ensure_response( self::prepare_run( $existing, true ) );
			}

			return $run;
		}

		return rest_ensure_response( self::prepare_run( RunProcessor::finish_interactive( $run ), true ) );
	}

	/**
	 * The queue item (incl. lock token, steps and shipments layout) with a failed step as current step, e.g. to fix an
	 * order a run (of an extension) failed on within the fulfillment app.
	 *
	 * @param QueueItem $item
	 * @param Workflow $workflow
	 * @param string $step_key
	 * @param string $message
	 *
	 * @return array
	 */
	public static function prepare_failed_item( $item, $workflow, $step_key, $message ) {
		$step = null;

		foreach ( Runner::get_steps( $item, $workflow ) as $candidate ) {
			if ( $candidate->get_key() === $step_key ) {
				$step = $candidate;
				break;
			}
		}

		return self::prepare_queue_item(
			$item,
			array(
				'workflow'      => $workflow,
				'include_token' => true,
				'execution'     => array(
					'status'    => 'error',
					'step'      => $step,
					'message'   => $message,
					'code'      => 'automation_failed',
					'view_data' => array(),
				),
			)
		);
	}

	/**
	 * Reopens a done interactive step (navigating back) and continues the workflow, i.e. the reopened step is the
	 * current step afterwards (its previous data is part of the view data).
	 */
	public function reopen_step( $request ) {
		return $this->idempotent(
			$request,
			function () use ( $request ) {
				$context = $this->get_locked_context( $request );

				if ( is_wp_error( $context ) ) {
					return $context;
				}

				$token    = (string) $request['token'];
				$reopened = Runner::reopen( $context['item']->get_id(), $context['workflow'], $token, (string) $request['step'] );

				if ( is_wp_error( $reopened ) ) {
					return $reopened;
				}

				$execution = Runner::execute( $context['item']->get_id(), $context['workflow'], $token, array( 'run' => self::get_token_run( $token ) ) );

				return $this->execution_response( $context['item']->get_id(), $context['workflow'], $token, $execution );
			}
		);
	}

	/**
	 * Applies the shipment layout (shipments overlay) of a locked item and continues the workflow, e.g. steps of
	 * changed shipments are reopened.
	 */
	public function get_label_form( $request ) {
		$context = $this->get_locked_context( $request );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$shipments   = Runner::get_package_shipments( $context['item'] );
		$shipment_id = absint( $request['shipment_id'] );

		if ( ! isset( $shipments[ $shipment_id ] ) ) {
			return new WP_Error( 'shipment_invalid', _x( 'The shipment is not part of the order.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 404 ) );
		}

		$html = \Vendidero\Shiptastic\BulkFulfillments\Actions\CreateLabel::get_form_html( $shipments[ $shipment_id ] );

		if ( '' === $html ) {
			return new WP_Error( 'label_form_unavailable', _x( 'There are no label settings for this shipment.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'html' => $html ) );
	}

	public function update_shipments( $request ) {
		return $this->idempotent(
			$request,
			function () use ( $request ) {
				$context = $this->get_locked_context( $request );

				if ( is_wp_error( $context ) ) {
					return $context;
				}

				$item    = $context['item'];
				$token   = (string) $request['token'];
				$applied = ShipmentLayout::apply( $item, (array) $request['shipments'] );

				if ( is_wp_error( $applied ) ) {
					return $applied;
				}

				Queue::bump_version( $item->get_id(), $token );

				if ( false === $request['continue'] ) {
					$item = Queue::get( $item->get_id() );

					return array_merge(
						self::prepare_queue_item(
							$item,
							array(
								'workflow'      => $context['workflow'],
								'include_token' => $item->get_lock_token() === $token,
							)
						),
						array(
							'layout' => ShipmentLayout::get_data( $item ),
							'id_map' => (object) $applied['id_map'],
						)
					);
				}

				$execution = Runner::execute( $item->get_id(), $context['workflow'], $token, array( 'run' => self::get_token_run( $token ) ) );
				$response  = $this->execution_response( $item->get_id(), $context['workflow'], $token, $execution );

				if ( is_array( $response ) ) {
					$response['id_map'] = (object) $applied['id_map'];
				}

				return $response;
			}
		);
	}

	public function release_queue_item( $request ) {
		return $this->idempotent(
			$request,
			function () use ( $request ) {
				$run      = self::get_token_run( $request['token'] );
				$released = Queue::release( absint( $request['id'] ), (string) $request['token'] );

				if ( is_wp_error( $released ) ) {
					return $released;
				}

				// Put back during an interactive run: counted as put back (processed = done + failed + put back).
				if ( $run && $run->is_interactive() ) {
					Run::update( $run->get_id(), array( 'increment' => array( 'processed', 'released' ) ) );
				}

				if ( $run ) {
					RunItems::set_status( $run->get_id(), absint( $request['id'] ), 'released' );
				}

				return self::prepare_queue_item( Queue::get( absint( $request['id'] ) ) );
			}
		);
	}

	public function skip_queue_item( $request ) {
		return $this->idempotent(
			$request,
			function () use ( $request ) {
				$run     = self::get_token_run( $request['token'] );
				$skipped = Runner::skip( absint( $request['id'] ), (string) $request['token'], isset( $request['message'] ) ? wc_clean( $request['message'] ) : '' );

				if ( is_wp_error( $skipped ) ) {
					return $skipped;
				}

				// Moved to the exceptions during an interactive run.
				if ( $run && $run->is_interactive() ) {
					Run::update( $run->get_id(), array( 'increment' => array( 'processed', 'failed' ) ) );
				}

				if ( $run ) {
					RunItems::set_status( $run->get_id(), absint( $request['id'] ), 'failed' );
				}

				return self::prepare_queue_item( Queue::get( absint( $request['id'] ) ) );
			}
		);
	}

	public function reset_queue_item( $request ) {
		$item = $this->get_queue_item_object( $request['id'] );

		if ( is_wp_error( $item ) ) {
			return $item;
		}

		Queue::reset( $item->get_id() );

		return rest_ensure_response( self::prepare_queue_item( Queue::get( $item->get_id() ) ) );
	}

	/**
	 * Executes multiple queue mutations (e.g. an offline outbox) in order. Each request: method (POST), path relative
	 * to the fulfillment base (e.g. queue/12/steps/pick), body. Requests are independent: a failing request does not
	 * stop the following ones.
	 */
	public function batch( $request ) {
		$requests = (array) $request['requests'];

		if ( count( $requests ) > self::MAX_BATCH_REQUESTS ) {
			/* translators: %d max number of requests */
			return new WP_Error( 'woocommerce_stc_rest_batch_too_large', sprintf( _x( 'A batch may contain up to %d requests.', 'shipments', 'shiptastic-for-woocommerce' ), self::MAX_BATCH_REQUESTS ), array( 'status' => 400 ) );
		}

		$responses = array();

		foreach ( $requests as $sub_request ) {
			$sub_request = wp_parse_args(
				(array) $sub_request,
				array(
					'method' => 'POST',
					'path'   => '',
					'body'   => array(),
				)
			);

			$path = ltrim( (string) $sub_request['path'], '/' );

			if ( 'POST' !== strtoupper( $sub_request['method'] ) || ! preg_match( '#^queue/[\d]+/(run|release|skip|shipments|steps/[a-z0-9_\-]+(?::[\d]+)?(?:/reopen)?)$#', $path ) ) {
				$responses[] = array(
					'status' => 400,
					'body'   => array(
						'code'    => 'woocommerce_stc_rest_batch_invalid_request',
						'message' => _x( 'Only queue mutations are allowed within a batch.', 'shipments', 'shiptastic-for-woocommerce' ),
					),
				);
				continue;
			}

			$inner = new WP_REST_Request( 'POST', '/' . $this->namespace . '/' . $this->rest_base . '/' . $path );
			$inner->set_body_params( (array) $sub_request['body'] );

			$response    = rest_do_request( $inner );
			$responses[] = array(
				'status' => $response->get_status(),
				'body'   => rest_get_server()->response_to_data( $response, false ),
			);
		}

		return rest_ensure_response( array( 'responses' => $responses ) );
	}
}

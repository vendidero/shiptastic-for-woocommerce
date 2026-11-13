<?php

namespace Vendidero\Shiptastic\Rest;

use Vendidero\Shiptastic\BulkFulfillments\ActionRegistry;
use Vendidero\Shiptastic\BulkFulfillments\Idempotency;
use Vendidero\Shiptastic\BulkFulfillments\Queue;
use Vendidero\Shiptastic\BulkFulfillments\QueueItem;
use Vendidero\Shiptastic\BulkFulfillments\Runner;
use Vendidero\Shiptastic\BulkFulfillments\Scheduler;
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
			$base . '/workflows/(?P<id>[\d]+)/resume',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resume_workflow' ),
				'permission_callback' => array( $this, 'can_manage' ),
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
				),
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
			);
		}

		return array(
			'id'            => $workflow->get_id(),
			'title'         => $workflow->get_title(),
			'status'        => $workflow->get_status(),
			'priority'      => $workflow->get_priority(),
			'mode'          => $workflow->get_mode(),
			'filters'       => Workflow::sanitize_filters( $workflow->get_filters() ),
			'steps'         => $actions,
			'on_error'      => $workflow->get_on_error(),
			'run_as'        => $workflow->get_run_as(),
			'schedule'      => $workflow->get_schedule(),
			'paused'        => $workflow->is_paused(),
			'paused_reason' => $workflow->get_paused_reason(),
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
	}

	protected function get_workflow_object( $id ) {
		$workflow = new Workflow( absint( $id ) );

		if ( ! $workflow->get_id() ) {
			return new WP_Error( 'woocommerce_stc_rest_workflow_invalid_id', _x( 'Invalid workflow.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 404 ) );
		}

		return $workflow;
	}

	public function get_workflows( $request ) {
		global $wpdb;

		$ids = $wpdb->get_col( "SELECT workflow_id FROM {$wpdb->stc_fulfillment_workflows} ORDER BY workflow_priority DESC, workflow_id ASC" );

		return rest_ensure_response(
			array_map(
				function ( $id ) {
					return self::prepare_workflow( new Workflow( $id ) );
				},
				$ids
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

		foreach ( array( 'title', 'status', 'priority', 'mode', 'filters', 'steps', 'on_error', 'run_as', 'schedule' ) as $prop ) {
			if ( isset( $request[ $prop ] ) ) {
				$props[ $prop ] = $request[ $prop ];
			}
		}

		$workflow->set_props( $props );

		if ( ! $workflow->get_run_as() ) {
			$workflow->set_run_as( get_current_user_id() );
		}

		$valid = $workflow->validate();

		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'woocommerce_stc_rest_invalid_workflow', implode( ' ', $valid->get_error_messages() ), array( 'status' => 400 ) );
		}

		$workflow->save();

		return $workflow;
	}

	public function create_workflow( $request ) {
		$workflow = $this->save_workflow( new Workflow(), $request );

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

	public function resume_workflow( $request ) {
		$workflow = $this->get_workflow_object( $request['id'] );

		if ( is_wp_error( $workflow ) ) {
			return $workflow;
		}

		Scheduler::resume( $workflow );

		return rest_ensure_response( self::prepare_workflow( $workflow ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Queue
	|--------------------------------------------------------------------------
	*/

	/**
	 * @param QueueItem $item
	 * @param array $args workflow (Workflow), execution (Runner::execute() result), include_token
	 *
	 * @return array
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
			'package_key'       => $item->get_package_key(),
			'location'          => $item->get_location(),
			'location_title'    => self::get_location_title( $item->get_location() ),
			'shipping_method'   => $item->get_shipping_method(),
			'shipping_provider' => $item->get_shipping_provider(),
			'country'           => $item->get_country(),
			'item_count'        => $item->get_item_count(),
			'weight'            => $item->get_weight(),
			'has_backorders'    => $item->has_backorders(),
			'status'            => $item->get_status(),
			'is_stale'          => $item->is_stale(),
			'workflow_id'       => $item->get_workflow_id(),
			'locked_by'         => $item->get_locked_by(),
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
			);
		}

		return $data;
	}

	/**
	 * @return array workflow_id => number of orders waiting for the (active) workflow
	 */
	protected static function get_open_counts() {
		$counts = array();

		foreach ( FulfillmentWorkflow::get_active_workflow_ids() as $workflow_id ) {
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

		$args = array(
			'status'   => isset( $request['status'] ) ? (array) $request['status'] : array(),
			'location' => isset( $request['location'] ) ? $request['location'] : null,
			'order_id' => isset( $request['order_id'] ) ? $request['order_id'] : 0,
			'workflow' => $workflow,
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

		if ( ! $workflow->is_active() ) {
			return new WP_Error( 'woocommerce_stc_rest_workflow_inactive', _x( 'The workflow is inactive.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$items = Queue::claim(
			$workflow,
			array(
				'limit'   => isset( $request['limit'] ) ? absint( $request['limit'] ) : null,
				'user_id' => get_current_user_id(),
				'source'  => 'interactive',
				'exclude' => (array) $request['exclude'],
			)
		);

		$token = empty( $items ) ? '' : $items[0]->get_lock_token();
		$data  = array();

		foreach ( $items as $item ) {
			$execution = $request['run'] ? Runner::execute( $item->get_id(), $workflow, $token ) : null;
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
			)
		);
	}

	public function heartbeat( $request ) {
		$count = Queue::heartbeat( $request['token'] );

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
	 * Continues the workflow for a locked item: runs background steps and returns the next step needing input.
	 */
	public function run_queue_item( $request ) {
		$context = $this->get_locked_context( $request );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$execution = Runner::execute( $context['item']->get_id(), $context['workflow'], (string) $request['token'] );

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
					)
				);

				return $this->execution_response( $context['item']->get_id(), $context['workflow'], (string) $request['token'], $execution, $step );
			}
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

				$execution = Runner::execute( $context['item']->get_id(), $context['workflow'], $token );

				return $this->execution_response( $context['item']->get_id(), $context['workflow'], $token, $execution );
			}
		);
	}

	/**
	 * Applies the shipment layout (shipments overlay) of a locked item and continues the workflow, e.g. steps of
	 * changed shipments are reopened.
	 */
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

				$execution = Runner::execute( $item->get_id(), $context['workflow'], $token );
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
				$released = Queue::release( absint( $request['id'] ), (string) $request['token'] );

				if ( is_wp_error( $released ) ) {
					return $released;
				}

				return self::prepare_queue_item( Queue::get( absint( $request['id'] ) ) );
			}
		);
	}

	public function skip_queue_item( $request ) {
		return $this->idempotent(
			$request,
			function () use ( $request ) {
				$skipped = Runner::skip( absint( $request['id'] ), (string) $request['token'], isset( $request['message'] ) ? wc_clean( $request['message'] ) : '' );

				if ( is_wp_error( $skipped ) ) {
					return $skipped;
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

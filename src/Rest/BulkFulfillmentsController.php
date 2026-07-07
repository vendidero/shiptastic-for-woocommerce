<?php

namespace Vendidero\Shiptastic\Rest;

use Vendidero\Shiptastic\BulkFulfillments\BulkFulfillment;
use Vendidero\Shiptastic\BulkFulfillments\Factory;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

class BulkFulfillmentsController extends \WC_REST_Controller {
	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wc/v3';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'bulk-fulfillments';

	/**
	 * Registers rest routes for this controller.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array(
						'force' => array(
							'default'     => false,
							'type'        => 'boolean',
							'description' => _x( 'Whether to bypass trash and force deletion.', 'shipments', 'shiptastic-for-woocommerce' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/next',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_next_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_next_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/prev',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_prev_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_prev_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Get object.
	 *
	 * @param  int|BulkFulfillment $id Object ID.
	 * @return BulkFulfillment BulkFulfillment object or WP_Error object.
	 */
	protected function get_object( $id ) {
		return $this->get_bulk_fulfillment( $id );
	}

	/**
	 * Retrieves a fulfillment by id.
	 *
	 * @param int $fulfillment_id
	 *
	 * @return BulkFulfillment|false
	 */
	private function get_bulk_fulfillment( $fulfillment_id ) {
		return Factory::get_bulk_fulfillment( $fulfillment_id );
	}

	/**
	 * Checks if a given request has access to get a specific item.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return true|WP_Error True if the request has read access for the item, WP_Error object otherwise.
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! $this->check_permissions() ) {
			return new WP_Error( 'woocommerce_stc_rest_cannot_view', _x( 'Sorry, you cannot list resources.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	protected function check_permissions( $object_type = 'bulk_fulfillment', $context = 'read', $object_id = 0 ) {
		if ( 'delete' === $context || 'edit' === $context ) {
			$post_type_object = get_post_type_object( 'shop_order' );
			$capped           = 'delete' === $context ? $post_type_object->cap->delete_posts : $post_type_object->cap->edit_posts;
			$permission       = current_user_can( $capped, $object_id );
		} else {
			$permission = wc_rest_check_post_permissions( 'shop_order', $context );
		}

		return apply_filters( 'woocommerce_shiptastic_rest_check_permissions', $permission, $object_type, $context, $object_id );
	}

	/**
	 * @param BulkFulfillment $fulfillment
	 * @param string $context
	 * @param bool|int $dp
	 *
	 * @return array
	 */
	public static function prepare_fulfillment( $fulfillment, $context = 'view' ) {
		return array(
			'id'                => $fulfillment->get_id(),
			'date_created'      => wc_rest_prepare_date_response( $fulfillment->get_date_created( $context ), false ),
			'date_created_gmt'  => wc_rest_prepare_date_response( $fulfillment->get_date_created( $context ) ),
			'date_modified'     => wc_rest_prepare_date_response( $fulfillment->get_date_modified( $context ), false ),
			'date_modified_gmt' => wc_rest_prepare_date_response( $fulfillment->get_date_modified( $context ) ),
			'date_start'        => wc_rest_prepare_date_response( $fulfillment->get_date_start( $context ), false ),
			'date_start_gmt'    => wc_rest_prepare_date_response( $fulfillment->get_date_start( $context ) ),
			'date_end'          => wc_rest_prepare_date_response( $fulfillment->get_date_end( $context ), false ),
			'date_end_gmt'      => wc_rest_prepare_date_response( $fulfillment->get_date_end( $context ) ),
			'status'            => $fulfillment->get_status( $context ),
			'type'              => $fulfillment->get_type(),
			'is_initialized'    => $fulfillment->get_is_initialized( $context ),
			'filters'           => $fulfillment->get_filters( $context ),
			'actions'           => $fulfillment->get_actions( $context ),
			'current_order'     => $fulfillment->get_current_order_id( $context ),
			'current_action'    => $fulfillment->get_current_action( $context ),
			'first_order'       => $fulfillment->get_first_order_id( $context ),
			'last_order'        => $fulfillment->get_last_order_id( $context ),
			'progress'          => $fulfillment->get_progress( $context ),
			'order_count'       => $fulfillment->get_order_count( $context ),
			'meta_data'         => $fulfillment->get_meta_data(),
		);
	}

	/**
	 * Get a single item.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_item( $request ) {
		$object = $this->get_object( (int) $request['id'] );

		if ( ! $object || 0 === $object->get_id() ) {
			return new WP_Error( 'woocommerce_stc_rest_bulk_fulfillment_invalid_id', _x( 'Invalid ID.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 404 ) );
		}

		$data     = $this->prepare_object_for_response( $object, $request );
		$response = rest_ensure_response( $data );

		return $response;
	}

	/**
	 * Prepares the object for the REST response.
	 *
	 * @param  BulkFulfillment $fulfillment Object data.
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	protected function prepare_object_for_response( $fulfillment, $request ) {
		$context       = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$this->request = $request;
		$data          = self::prepare_fulfillment( $fulfillment, $context );
		$data          = $this->add_additional_fields_to_object( $data, $request );
		$data          = $this->filter_response_by_context( $data, $context );
		$response      = rest_ensure_response( $data );

		$response->add_links( $this->prepare_links( $fulfillment, $request ) );

		/**
		 * Filter the bulk fulfillment data for a response.
		 *
		 * @param WP_REST_Response $response The response object.
		 * @param BulkFulfillment  $fulfillment   Object data.
		 * @param WP_REST_Request  $request  Request object.
		 */
		return apply_filters( 'woocommerce_shiptastic_rest_prepare_bulk_fulfillment_object', $response, $fulfillment, $request );
	}

	/**
	 * Prepare links for the request.
	 *
	 * @param BulkFulfillment $fulfillment  Object data.
	 * @param WP_REST_Request $request Request object.
	 * @return array                   Links for the given post.
	 */
	protected function prepare_links( $fulfillment, $request ) {
		$links = array(
			'self'       => array(
				'href' => rest_url( sprintf( '/%s/%s/%d', $this->namespace, $this->rest_base, $fulfillment->get_id() ) ),
			),
			'collection' => array(
				'href' => rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ),
			),
		);

		return $links;
	}

	protected static function get_fulfillment_statuses() {
		return array();
	}

	/**
	 * Retrieves the item's schema, conforming to JSON Schema.
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		return $this->add_additional_fields_schema( self::get_single_item_schema() );
	}

	/**
	 * Get the schema of a single shipment
	 *
	 * @return array
	 */
	public static function get_single_item_schema() {
		return array(
			'description' => _x( 'Single bulk fulfillment.', 'shipment', 'shiptastic-for-woocommerce' ),
			'context'     => array( 'view', 'edit' ),
			'readonly'    => false,
			'type'        => 'object',
			'properties'  => array(
				'id'                => array(
					'description' => _x( 'Fulfillment ID.', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'status'            => array(
					'description' => _x( 'Fulfillment status.', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'enum'        => self::get_fulfillment_statuses(),
				),
				'date_created'      => array(
					'description' => _x( "The date the fulfillment was created, in the site's timezone.", 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_created_gmt'  => array(
					'description' => _x( 'The date the fulfillment was created, as GMT.', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_modified'     => array(
					'description' => _x( "The date the fulfillment was modified, in the site's timezone.", 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_modified_gmt' => array(
					'description' => _x( 'The date the fulfillment was modified, as GMT.', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_start'        => array(
					'description' => _x( "The start date for the fulfillment, in the site's timezone.", 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
				),
				'date_start_gmt'    => array(
					'description' => _x( 'The start date for the fulfillment, as GMT.', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
				),
				'date_end'          => array(
					'description' => _x( "The end date for the fulfillment, in the site's timezone.", 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
				),
				'date_end_gmt'      => array(
					'description' => _x( 'The end date for the fulfillment, as GMT.', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
				),
				'type'              => array(
					'description' => _x( 'Fulfillment, e.g. manual or auto.', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'enum'        => array( 'manual', 'auto' ),
				),
				'filters'           => array(
					'description' => _x( 'Fulfillment filters.', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'properties'  => array(
						'shipping_status' => array(
							'description' => _x( 'Shipping status.', 'shipments', 'shiptastic-for-woocommerce' ),
							'type'        => 'array',
							'context'     => array( 'view', 'edit' ),
						),
					),
				),
				'actions'           => array(
					'description' => _x( 'Fulfillment actions.', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'     => array(
								'description' => _x( 'Action name.', 'shipments', 'shiptastic-for-woocommerce' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
							),
							'settings' => array(
								'description' => _x( 'Action settings.', 'shipments', 'shiptastic-for-woocommerce' ),
								'type'        => 'array',
								'context'     => array( 'view', 'edit' ),
							),
						),
					),
				),
				'is_initialized'    => array(
					'description' => _x( 'Is initialized?', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'current_order'     => array(
					'description' => _x( 'Current order.', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'current_action'    => array(
					'description' => _x( 'Current action.', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'progress'          => array(
					'description' => _x( 'Progress.', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'order_count'       => array(
					'description' => _x( 'Order count.', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'last_order'        => array(
					'description' => _x( 'Last order.', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'first_order'       => array(
					'description' => _x( 'First order.', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'parent_id'         => array(
					'description' => _x( 'Parent id.', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'meta_data'         => array(
					'description' => _x( 'Meta data.', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'    => array(
								'description' => _x( 'Meta ID.', 'shipments', 'shiptastic-for-woocommerce' ),
								'type'        => 'integer',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'key'   => array(
								'description' => _x( 'Meta key.', 'shipments', 'shiptastic-for-woocommerce' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
							),
							'value' => array(
								'description' => _x( 'Meta value.', 'shipments', 'shiptastic-for-woocommerce' ),
								'type'        => 'mixed',
								'context'     => array( 'view', 'edit' ),
							),
						),
					),
				),
			),
		);
	}
}

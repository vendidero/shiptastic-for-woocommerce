<?php

namespace Vendidero\Shiptastic\Fulfillments;

use Automattic\WooCommerce\Internal\Fulfillments\OrderFulfillmentsRestController;
use Automattic\WooCommerce\Internal\Admin\Settings\Exceptions\ApiException;
use Vendidero\Shiptastic\Emails;
use Vendidero\Shiptastic\SimpleShipment;

class FulfillmentsRestController extends OrderFulfillmentsRestController {

	public function register() {
	}

	/**
	 * Get the fulfillments for the order.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response The fulfillments for the order, or an error if the request fails.
	 */
	public function get_fulfillments( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id     = (int) $request->get_param( 'order_id' );
		$fulfillments = array();
		$query        = new \Vendidero\Shiptastic\ShipmentQuery(
			array(
				'order_id'      => $order_id,
				'shipment_type' => 'simple',
			)
		);
		$shipments    = $query->get_shipments();

		foreach ( $shipments as $shipment ) {
			$fulfillments[] = new Fulfillment( $shipment );
		}

		// Return the fulfillments.
		return new \WP_REST_Response(
			array_map(
				function ( $fulfillment ) {
					return $fulfillment->get_raw_data(); },
				$fulfillments
			),
			\WP_Http::OK
		);
	}

	/**
	 * Prepare an error response.
	 *
	 * @param string $code The error code.
	 * @param string $message The error message.
	 * @param int    $status The HTTP status code.
	 *
	 * @return \WP_REST_Response The error response.
	 */
	private function prepare_error_response( $code, $message, $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
				'data'    => array( 'status' => $status ),
			),
			$status
		);
	}

	/**
	 * Create a new fulfillment with the given data for the order.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response The created fulfillment, or an error if the request fails.
	 */
	public function create_fulfillment( \WP_REST_Request $request ) {
		$order_id        = (int) $request->get_param( 'order_id' );
		$fulfillment_id  = (int) $request->get_param( 'id' );
		$notify_customer = (bool) $request->get_param( 'notify_customer' );

		// Create a new fulfillment (or fulfill a draft).
		try {
			$shipment = false;

			if ( ! empty( $fulfillment_id ) ) {
				$shipment = wc_stc_get_shipment( $fulfillment_id );
			}

			if ( ! $shipment ) {
				$shipment = new SimpleShipment();
			}

			$shipment->set_order_id( $order_id );

			$fulfillment = new Fulfillment( $shipment );
			$fulfillment->set_entity_type( \WC_Order::class );
			$fulfillment->set_entity_id( "$order_id" );
			$fulfillment->sync();

			$fulfillment->set_props( $request->get_json_params() );
			$fulfillment->set_meta_data( $request->get_json_params()['meta_data'] );

			Emails::prevent_notifications();
			$fulfillment->save();

			if ( $fulfillment->get_is_fulfilled() && $notify_customer ) {
				do_action( 'woocommerce_shiptastic_shipment_notify_customer', $fulfillment->get_id() );
			}

			Emails::reset_notifications();
		} catch ( ApiException $ex ) {
			return $this->prepare_error_response(
				$ex->getErrorCode(),
				$ex->getMessage(),
				\WP_Http::BAD_REQUEST
			);

		} catch ( \Exception $e ) {
			return $this->prepare_error_response(
				$e->getCode(),
				$e->getMessage(),
				\WP_Http::BAD_REQUEST
			);
		}

		return new \WP_REST_Response( $fulfillment->get_raw_data(), \WP_Http::CREATED );
	}

	/**
	 * Get a specific fulfillment for the order.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response The fulfillment for the order, or an error if the request fails.
	 *
	 * @throws \Exception If the fulfillment is not found or is deleted.
	 */
	public function get_fulfillment( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id       = (int) $request->get_param( 'order_id' );
		$fulfillment_id = (int) $request->get_param( 'fulfillment_id' );

		// Fetch the fulfillment for the order.
		try {
			$fulfillment = new Fulfillment( $fulfillment_id );
			$this->validate_fulfillment( $fulfillment, $fulfillment_id, $order_id );
		} catch ( \Exception $e ) {
			return $this->prepare_error_response(
				$e->getCode(),
				$e->getMessage(),
				\WP_Http::BAD_REQUEST
			);
		}

		return new \WP_REST_Response(
			$fulfillment->get_raw_data(),
			\WP_Http::OK
		);
	}

	/**
	 * Update a specific fulfillment for the order.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response The updated fulfillment, or an error if the request fails.
	 */
	public function update_fulfillment( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id        = (int) $request->get_param( 'order_id' );
		$fulfillment_id  = (int) $request->get_param( 'fulfillment_id' );
		$notify_customer = (bool) $request->get_param( 'notify_customer' );

		// Update the fulfillment for the order.
		try {
			$fulfillment    = new Fulfillment( $fulfillment_id );
			$previous_state = $fulfillment->get_is_fulfilled();
			$this->validate_fulfillment( $fulfillment, $fulfillment_id, $order_id );

			$fulfillment->set_props( $request->get_json_params() );
			$next_state = $fulfillment->get_is_fulfilled();

			if ( isset( $request->get_json_params()['meta_data'] ) && is_array( $request->get_json_params()['meta_data'] ) ) {
				// Update the meta data keys that exist in the request.
				foreach ( $request->get_json_params()['meta_data'] as $meta ) {
					$fulfillment->update_meta_data( $meta['key'], $meta['value'], $meta['id'] ?? 0 );
				}

				// Remove the meta data keys that don't exist in the request, by matching their keys.
				$existing_meta_data = $fulfillment->get_meta_data();
				foreach ( $existing_meta_data as $meta ) {
					if ( ! in_array( $meta->key, array_column( $request->get_json_params()['meta_data'], 'key' ), true ) ) {
						$fulfillment->delete_meta_data( $meta->key );
					}
				}
			}

			Emails::prevent_notifications();
			$fulfillment->save();

			if ( $notify_customer ) {
				if ( ! $previous_state && $next_state ) {
					do_action( 'woocommerce_shiptastic_shipment_notify_customer', $fulfillment->get_id() );
				} elseif ( $next_state ) {
					do_action( 'woocommerce_shiptastic_shipment_notify_customer', $fulfillment->get_id(), true );
				}
			}

			Emails::reset_notifications();
		} catch ( ApiException $ex ) {
			return $this->prepare_error_response(
				$ex->getErrorCode(),
				$ex->getMessage(),
				\WP_Http::BAD_REQUEST
			);
		} catch ( \Exception $e ) {
			return $this->prepare_error_response(
				$e->getCode(),
				$e->getMessage(),
				\WP_Http::BAD_REQUEST
			);
		}

		return new \WP_REST_Response(
			$fulfillment->get_raw_data(),
			\WP_Http::OK
		);
	}

	/**
	 * Delete a specific fulfillment for the order.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response The deleted fulfillment, or an error if the request fails.
	 */
	public function delete_fulfillment( \WP_REST_Request $request ) {
		$order_id        = (int) $request->get_param( 'order_id' );
		$fulfillment_id  = (int) $request->get_param( 'fulfillment_id' );
		$notify_customer = (bool) $request->get_param( 'notify_customer' );
		$is_fulfilled    = false;

		// Delete the fulfillment for the order.
		try {
			$fulfillment = new Fulfillment( $fulfillment_id );
			$this->validate_fulfillment( $fulfillment, $fulfillment_id, $order_id );
			$is_fulfilled = $fulfillment->get_is_fulfilled();
			$fulfillment->delete( true );
		} catch ( ApiException $ex ) {
			return $this->prepare_error_response(
				$ex->getErrorCode(),
				$ex->getMessage(),
				\WP_Http::BAD_REQUEST
			);
		} catch ( \Exception $e ) {
			return $this->prepare_error_response(
				$e->getCode(),
				$e->getMessage(),
				\WP_Http::BAD_REQUEST
			);
		}

		if ( $is_fulfilled && $notify_customer ) {
			/**
			 * Trigger the fulfillment deleted notification.
			 */
			do_action( 'woocommerce_shiptastic_shipment_deleted_notify_customer', $fulfillment->get_shipment() );
		}

		return new \WP_REST_Response(
			array(
				'message' => _x( 'Fulfillment deleted successfully.', 'shipments', 'shiptastic-for-woocommerce' ),
			),
			\WP_Http::OK
		);
	}

	/**
	 * Validate the fulfillment.
	 *
	 * @param Fulfillment $fulfillment The fulfillment object.
	 * @param int         $fulfillment_id The fulfillment ID.
	 * @param int         $order_id The order ID.
	 *
	 * @throws \Exception If the fulfillment ID is invalid.
	 */
	private function validate_fulfillment( Fulfillment $fulfillment, int $fulfillment_id, int $order_id ) {
		if ( $fulfillment->get_id() !== $fulfillment_id || $fulfillment->get_entity_type() !== \WC_Order::class || $fulfillment->get_entity_id() !== "$order_id" ) {
			throw new \Exception( esc_html_x( 'Invalid fulfillment ID.', 'shipments', 'shiptastic-for-woocommerce' ) );
		}
	}
}

<?php

namespace Vendidero\Shiptastic\BulkFulfillments\Actions;

use Vendidero\Shiptastic\BulkFulfillments\Action;
use Vendidero\Shiptastic\BulkFulfillments\RunPhases;
use Vendidero\Shiptastic\BulkFulfillments\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the shipping label of a shipment via its shipping provider (`Shipment::create_label()`), using the
 * provider's default label settings (product, services, ...).
 *
 * - Background: the label is created with the defaults, errors (e.g. invalid address) stop at the step.
 * - Interactive: review + create (summary of the defaults), the whole label form of the provider may be opened within
 *   the app on request (same fields as the label modal of the shipment admin).
 *
 * The label file is added to the run's files, labels created within a request are offered for printing right away
 * (see `get_created_labels()`). Shipments of other shipping providers than the ones chosen by the step (setting
 * `shipping_providers`) are skipped, so are shipments of providers without label support.
 */
class CreateLabel extends Action {

	/**
	 * Labels created within the current request: shipment_id, url, tracking_id.
	 *
	 * @var array[]
	 */
	protected static $created = array();

	public static function get_name() {
		return 'create_label';
	}

	public static function get_title() {
		return _x( 'Create label', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public static function get_description() {
		return _x( 'Create the shipping label with the default settings of the shipping provider.', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public static function get_scopes() {
		return array( 'shipment' );
	}

	/**
	 * Right after each shipment's steps (default, e.g. label 1 right after packing shipment 1) or once all shipments
	 * went through the previous steps (pack all, then create all labels).
	 */
	public static function get_phases() {
		return array( RunPhases::AFTER_SHIPMENT, RunPhases::ITEM );
	}

	public static function get_modes() {
		return array( 'interactive', 'background' );
	}

	public static function requires_network() {
		return true;
	}

	public static function get_settings_schema() {
		$providers = array();

		foreach ( wc_stc_get_shipping_providers() as $provider ) {
			if ( $provider->is_activated() && $provider->supports_labels( 'simple' ) ) {
				$providers[ $provider->get_name() ] = $provider->get_title();
			}
		}

		return array(
			'shipping_providers' => array(
				'type'        => 'multiselect',
				'label'       => _x( 'Shipping providers', 'shipments', 'shiptastic-for-woocommerce' ),
				'default'     => array(),
				'options'     => $providers,
				'all_label'   => _x( 'All shipping providers', 'shipments', 'shiptastic-for-woocommerce' ),
				'add_label'   => _x( '+ Add shipping provider…', 'shipments', 'shiptastic-for-woocommerce' ),
				'empty_label' => _x( 'Nothing selected yet - labels are created for all shipping providers.', 'shipments', 'shiptastic-for-woocommerce' ),
			),
		);
	}

	/**
	 * @return array[] Labels created within the current request (shipment_id, url, tracking_id).
	 */
	public static function get_created_labels() {
		return array_values( self::$created );
	}

	public static function reset_created_labels() {
		self::$created = array();
	}

	/**
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 * @param \Vendidero\Shiptastic\Shipment|null $shipment
	 *
	 * @return StepResult|null A result in case the label cannot (or needs not) be created, null otherwise.
	 */
	protected function check( $context, $shipment ) {
		if ( ! $shipment ) {
			return StepResult::error( _x( 'There is no shipment, e.g. because all items are on backorder.', 'shipments', 'shiptastic-for-woocommerce' ), 'no_shipment' );
		}

		if ( $shipment->has_label() ) {
			return $this->done( $shipment, false );
		}

		$provider_name = (string) $shipment->get_shipping_provider();
		$providers     = array_filter( array_map( 'strval', (array) $context->get_setting( 'shipping_providers', array() ) ) );

		if ( ! empty( $providers ) && ! in_array( $provider_name, $providers, true ) ) {
			/* translators: %d shipment id */
			return StepResult::skipped( sprintf( _x( 'Shipment #%d is not shipped with one of the chosen shipping providers.', 'shipments', 'shiptastic-for-woocommerce' ), $shipment->get_id() ) );
		}

		if ( '' === $provider_name ) {
			/* translators: %d shipment id */
			return StepResult::error( sprintf( _x( 'Shipment #%d has no shipping provider.', 'shipments', 'shiptastic-for-woocommerce' ), $shipment->get_id() ), 'missing_shipping_provider' );
		}

		if ( ! $shipment->supports_label() ) {
			/* translators: 1: shipment id 2: shipping provider */
			return StepResult::skipped( sprintf( _x( 'Labels for shipment #%1$d cannot be created via %2$s.', 'shipments', 'shiptastic-for-woocommerce' ), $shipment->get_id(), $shipment->get_shipping_provider_title() ) );
		}

		// E.g. a tracking number entered manually.
		if ( ! $shipment->needs_label( false ) ) {
			/* translators: %d shipment id */
			return StepResult::skipped( sprintf( _x( 'Shipment #%d does not need a label.', 'shipments', 'shiptastic-for-woocommerce' ), $shipment->get_id() ) );
		}

		return null;
	}

	/**
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 * @param bool $created Whether the label was created by this step (offered for printing).
	 *
	 * @return StepResult
	 */
	protected function done( $shipment, $created ) {
		$label  = $shipment->get_label();
		$data   = self::get_label_data( $shipment );
		$result = StepResult::done( $data );

		if ( $label && ( $file = $label->get_file() ) && file_exists( $file ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
			$result->add_file( $file );
		}

		if ( $created && $label ) {
			self::$created[ $shipment->get_id() ] = array_merge( array( 'shipment_id' => $shipment->get_id() ), $data );
		}

		return $result;
	}

	/**
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 *
	 * @return array url (download/print), tracking_id
	 */
	protected static function get_label_data( $shipment ) {
		$label = $shipment->get_label();

		return array(
			'url'         => $label ? $label->get_download_url() : '',
			'tracking_id' => $shipment->get_tracking_id(),
		);
	}

	/**
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 * @param array|false $props Label props (label form), false to use the provider's defaults.
	 *
	 * @return StepResult
	 */
	protected function create( $shipment, $props = false ) {
		$result = $shipment->create_label( $props );

		if ( is_wp_error( $result ) ) {
			$result = wc_stc_get_shipment_error( $result );

			if ( ! $result->is_soft_error() || ! $shipment->has_label() ) {
				return StepResult::error( implode( ' ', array_unique( $result->get_error_messages() ) ), 'label_error' );
			}
		}

		if ( ! $shipment->has_label() ) {
			/* translators: %d shipment id */
			return StepResult::error( sprintf( _x( 'The label for shipment #%d could not be created.', 'shipments', 'shiptastic-for-woocommerce' ), $shipment->get_id() ), 'label_error' );
		}

		return $this->done( $shipment, true );
	}

	/**
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 *
	 * @return StepResult
	 */
	public function run( $context ) {
		$shipment = $context->get_shipment();

		if ( $result = $this->check( $context, $shipment ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
			return $result;
		}

		return $this->create( $shipment );
	}

	/**
	 * Review data: the label's product + further options (e.g. print format) and services (provider defaults) and the
	 * package (packaging, dimensions, total weight). The whole label form is loaded on request
	 * (REST queue/{id}/shipments/{shipment_id}/label-form).
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 *
	 * @return array
	 */
	public function get_view_data( $context ) {
		$shipment = $context->get_shipment();
		$data     = array(
			'description'  => static::get_description(),
			'shipmentId'   => $shipment ? $shipment->get_id() : 0,
			'provider'     => '',
			'productLabel' => '',
			'product'      => '',
			'options'      => array(),
			'services'     => array(),
			'package'      => array(),
			'hasLabel'     => false,
			'label'        => array(),
			'canEdit'      => false,
			'notice'       => '',
		);

		if ( ! $shipment ) {
			return $data;
		}

		$data['provider'] = (string) $shipment->get_shipping_provider_title();

		if ( $shipment->has_label() ) {
			$data['hasLabel'] = true;
			$data['label']    = self::get_label_data( $shipment );

			return $data;
		}

		$check = $this->check( $context, $shipment );

		if ( $check ) {
			$data['notice'] = $check->get_message();
		}

		$provider = $shipment->get_shipping_provider_instance();

		if ( ! $check && $provider && is_a( $provider, '\Vendidero\Shiptastic\Interfaces\ShippingProviderAuto' ) ) {
			$fields = $provider->get_label_fields( $shipment );

			if ( ! is_wp_error( $fields ) ) {
				$data['canEdit'] = true;
				$data            = array_merge( $data, self::get_field_summary( $fields ) );
			}
		}

		$data['package'] = self::get_package_summary( $shipment );

		return $data;
	}

	/**
	 * @param string $text Formatted text (may contain markup/entities, e.g. dimensions).
	 *
	 * @return string Plain text.
	 */
	protected static function plain( $text ) {
		return html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, get_bloginfo( 'charset' ) );
	}

	/**
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 *
	 * @return array[] label, value, hint: packaging (name), dimensions, total weight (content + packaging)
	 */
	protected static function get_package_summary( $shipment ) {
		$package = array();
		$unit    = $shipment->get_weight_unit();
		$format  = function ( $weight ) use ( $unit ) {
			return self::plain( wc_stc_format_shipment_weight( wc_format_decimal( (float) $weight, 2 ), $unit ) );
		};

		if ( $packaging = $shipment->get_packaging() ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
			$package[] = array(
				'label' => _x( 'Packaging', 'shipments', 'shiptastic-for-woocommerce' ),
				'value' => self::plain( $packaging->get_description() ),
				'hint'  => '',
			);
		}

		if ( $shipment->has_dimensions() ) {
			$package[] = array(
				'label' => _x( 'Dimensions', 'shipments', 'shiptastic-for-woocommerce' ),
				'value' => self::plain( $shipment->get_formatted_dimensions() ),
				'hint'  => '',
			);
		}

		$packaging_weight = (float) $shipment->get_packaging_weight();

		$package[] = array(
			'label' => _x( 'Total weight', 'shipments', 'shiptastic-for-woocommerce' ),
			'value' => $format( $shipment->get_total_weight() ),
			/* translators: 1: content weight 2: packaging weight */
			'hint'  => $packaging_weight > 0 ? sprintf( _x( 'Content %1$s + packaging %2$s', 'shipments', 'shiptastic-for-woocommerce' ), $format( $shipment->get_weight() ), $format( $packaging_weight ) ) : '',
		);

		return $package;
	}

	/**
	 * Summary of the label form's defaults: the product (first select), further selected options (e.g. print format)
	 * and checked services.
	 *
	 * @param array $fields Label fields of the provider.
	 *
	 * @return array productLabel, product, options (label, value), services (titles)
	 */
	protected static function get_field_summary( $fields ) {
		$summary = array(
			'productLabel' => '',
			'product'      => '',
			'options'      => array(),
			'services'     => array(),
		);

		foreach ( $fields as $field ) {
			$type  = isset( $field['type'] ) ? $field['type'] : '';
			$id    = isset( $field['id'] ) ? (string) $field['id'] : '';
			$value = isset( $field['value'] ) ? $field['value'] : '';
			$label = self::plain( isset( $field['label'] ) ? $field['label'] : '' );

			if ( 'select' === $type && ! empty( $field['options'] ) && '' !== (string) $value && isset( $field['options'][ $value ] ) && '' !== $label ) {
				$option = self::plain( $field['options'][ $value ] );

				if ( 'product_id' === $id ) {
					$summary['productLabel'] = $label;
					$summary['product']      = $option;
				} else {
					$summary['options'][] = array(
						'label' => $label,
						'value' => $option,
					);
				}
			} elseif ( 'checkbox' === $type && 0 === strpos( $id, 'service_' ) && 'yes' === $value && '' !== $label ) {
				$summary['services'][] = $label;
			}
		}

		return $summary;
	}

	/**
	 * Interactive: creates the label with the defaults or the props of the label form (`props`).
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 * @param array $data
	 *
	 * @return StepResult
	 */
	public function process( $context, $data ) {
		$shipment = $context->get_shipment();

		if ( $result = $this->check( $context, $shipment ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
			return $result;
		}

		$props = false;

		if ( ! empty( $data['props'] ) && is_array( $data['props'] ) ) {
			$props = array();

			foreach ( $data['props'] as $key => $value ) {
				$key = sanitize_key( $key );

				if ( '' === $key || in_array( $key, array( 'action', 'security', 'shipment_id', 'reference_id' ), true ) ) {
					continue;
				}

				$props[ $key ] = is_array( $value ) ? array_map( 'wc_clean', array_map( 'wp_unslash', $value ) ) : wc_clean( wp_unslash( (string) $value ) );
			}
		}

		return $this->create( $shipment, $props );
	}

	/**
	 * The provider's label form (HTML of the shipment admin's label modal) for the shipment.
	 *
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 *
	 * @return string
	 */
	public static function get_form_html( $shipment ) {
		if ( ! $shipment->supports_label() || ! $shipment->needs_label( false ) ) {
			return '';
		}

		// The fields are rendered via WooCommerce's admin field functions which are not loaded within REST requests.
		if ( ! function_exists( 'woocommerce_wp_select' ) && function_exists( 'WC' ) ) {
			include_once WC()->plugin_path() . '/includes/admin/wc-meta-box-functions.php';
		}

		return (string) $shipment->get_label_settings_html();
	}
}

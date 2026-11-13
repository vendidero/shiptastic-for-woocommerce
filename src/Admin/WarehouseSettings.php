<?php

namespace Vendidero\Shiptastic\Admin;

use Vendidero\Shiptastic\Admin\Tabs\General;
use Vendidero\Shiptastic\Warehouse;
use Vendidero\Shiptastic\Warehouse\LocationSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Warehouse management within the Pick & Pack settings tab (list) and a separate edit page per warehouse.
 */
class WarehouseSettings {

	const PAGE = 'shipment-warehouse';

	public static function init() {
		add_action( 'woocommerce_admin_field_warehouse_list', array( __CLASS__, 'output_warehouse_list' ) );
		add_action( 'woocommerce_admin_field_warehouse_group_list', array( __CLASS__, 'output_group_list' ) );
		add_action( 'woocommerce_shiptastic_admin_settings_after_save_packaging_warehouses', array( __CLASS__, 'save_warehouse_list' ), 10 );

		add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 25 );
		add_action( 'admin_head', array( __CLASS__, 'hide_page_from_menu' ) );
		add_action( 'admin_post_woocommerce_stc_save_warehouse_settings', array( __CLASS__, 'save_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ), 20 );

		add_action( 'woocommerce_admin_field_warehouse_location_levels', array( __CLASS__, 'output_location_levels' ) );
		add_action( 'woocommerce_admin_field_warehouse_location_lists', array( __CLASS__, 'output_location_lists' ) );
		add_action( 'wp_ajax_woocommerce_stc_preview_warehouse_locations', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'woocommerce_admin_field_warehouse_location_overrides', array( __CLASS__, 'output_location_overrides' ) );
		add_action( 'woocommerce_admin_field_warehouse_location_preview', array( __CLASS__, 'output_location_preview' ) );
	}

	public static function enqueue_scripts() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && 'woocommerce_page_' . self::PAGE === $screen->id ) {
			// Show/hide dependent settings, e.g. address fields.
			wp_enqueue_script( 'wc-shiptastic-admin-settings' );
			wp_enqueue_script( 'wc-shiptastic-admin-warehouse', \Vendidero\Shiptastic\Package::get_assets_url( 'static/admin-warehouse.js' ), array( 'wc-shiptastic-admin-settings', 'jquery-ui-sortable' ), \Vendidero\Shiptastic\Package::get_version(), true );
			wp_localize_script(
				'wc-shiptastic-admin-warehouse',
				'wc_shiptastic_admin_warehouse_params',
				array(
					'ajax_url'          => admin_url( 'admin-ajax.php' ),
					'preview_nonce'     => wp_create_nonce( 'wc-stc-warehouse-location-preview' ),
					/* translators: %d level number */
					'i18n_level_number' => _x( 'Level %d', 'shipments-location-level', 'shiptastic-for-woocommerce' ),
				)
			);
		}
	}

	public static function get_settings_url( $warehouse_id ) {
		return esc_url_raw( add_query_arg( array( 'warehouse' => absint( $warehouse_id ) ), admin_url( 'admin.php?page=' . self::PAGE ) ) );
	}

	public static function get_list_url() {
		return Settings::get_settings_url( 'packaging', 'warehouses' );
	}

	/**
	 * Settings shown within the Pick & Pack > Warehouses section.
	 *
	 * @return array
	 */
	public static function get_section_settings() {
		return array(
			array(
				'title' => '',
				'type'  => 'title',
				'id'    => 'warehouse_list_options',
			),
			array(
				'type' => 'warehouse_group_list',
			),
			array(
				'type' => 'warehouse_list',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'warehouse_list_options',
			),
		);
	}

	public static function output_group_list( $value ) {
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html_x( 'Warehouse groups', 'shipments', 'shiptastic-for-woocommerce' ); ?> <?php echo wc_help_tip( _x( 'A group represents a physical location. Warehouses within the same group ship together, warehouses of different groups (or without a group) are split into separate packages and shipments. Configure the sender address per group.', 'shipments', 'shiptastic-for-woocommerce' ) ); ?></th>
			<td class="forminp" id="warehouse_group_list_wrapper">
				<div class="wc_input_table_wrapper">
					<table class="widefat wc_input_table sortable" cellspacing="0">
						<thead>
						<tr>
							<th class="sort">&nbsp;</th>
							<th><?php echo esc_html_x( 'Title', 'shipments', 'shiptastic-for-woocommerce' ); ?></th>
							<th style="width: 12ch;"><?php echo esc_html_x( 'Warehouses', 'shipments', 'shiptastic-for-woocommerce' ); ?></th>
							<th class="actions" style="width: 5ch;"><?php echo esc_html_x( 'Actions', 'shipments', 'shiptastic-for-woocommerce' ); ?></th>
						</tr>
						</thead>
						<tbody class="warehouse_group_list">
						<?php
						$count  = 0;
						$counts = self::get_group_warehouse_counts();

						foreach ( wc_stc_get_warehouse_group_list() as $group ) :
							?>
							<tr class="item">
								<td class="sort"></td>
								<td>
									<input type="text" name="warehouse_group[<?php echo esc_attr( $count ); ?>][title]" value="<?php echo esc_attr( wp_unslash( $group->get_title() ) ); ?>" />
									<input type="hidden" name="warehouse_group[<?php echo esc_attr( $count ); ?>][warehouse_id]" value="<?php echo esc_attr( $group->get_id() ); ?>" />
								</td>
								<td class="text" style="width: 12ch;"><?php echo esc_html( isset( $counts[ $group->get_name() ] ) ? $counts[ $group->get_name() ] : 0 ); ?></td>
								<td class="actions" style="width: 5ch;">
									<a class="button wc-stc-shipment-action-button wc-stc-warehouse-edit edit tip" aria-label="<?php echo esc_attr_x( 'Edit group', 'shipments', 'shiptastic-for-woocommerce' ); ?>" href="<?php echo esc_url( self::get_settings_url( $group->get_id() ) ); ?>"><?php echo esc_html_x( 'Edit group', 'shipments', 'shiptastic-for-woocommerce' ); ?></a>
								</td>
							</tr>
							<?php
							++$count;
						endforeach;
						?>
						</tbody>
						<tfoot>
						<tr>
							<th colspan="4"><a href="#" class="add button"><?php echo esc_html_x( '+ Add group', 'shipments', 'shiptastic-for-woocommerce' ); ?></a> <a href="#" class="remove_rows button"><?php echo esc_html_x( 'Remove selected group(s)', 'shipments', 'shiptastic-for-woocommerce' ); ?></a></th>
						</tr>
						<tr class="template item" style="display: none">
							<td class="sort"></td>
							<td><input type="text" name="warehouse_group[size][title]" value="" /></td>
							<td class="text" style="width: 12ch;"></td>
							<td style="width: 5ch;"></td>
						</tr>
						</tfoot>
					</table>
				</div>
			</td>
		</tr>
		<?php
		$html = ob_get_clean();

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	protected static function get_group_warehouse_counts() {
		$counts = array();

		foreach ( wc_stc_get_warehouse_list() as $warehouse ) {
			if ( $group = $warehouse->get_group_instance() ) {
				$counts[ $group->get_name() ] = isset( $counts[ $group->get_name() ] ) ? $counts[ $group->get_name() ] + 1 : 1;
			}
		}

		return $counts;
	}

	protected static function render_select( $name, $options, $selected = '' ) {
		?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<?php foreach ( $options as $option => $option_title ) : ?>
				<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $selected, $option ); ?>><?php echo esc_html( $option_title ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public static function output_warehouse_list( $value ) {
		$types  = wc_stc_get_warehouse_types();
		$groups = wc_stc_get_warehouse_group_select();
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html_x( 'Warehouses', 'shipments', 'shiptastic-for-woocommerce' ); ?> <?php echo wc_help_tip( _x( 'Assign products to a warehouse within the product shipping settings. Products without a warehouse are shipped from your default location (shipper address). A warehouse without a group is a location of its own.', 'shipments', 'shiptastic-for-woocommerce' ) ); ?></th>
			<td class="forminp" id="warehouse_list_wrapper">
				<div class="wc_input_table_wrapper">
					<table class="widefat wc_input_table sortable" cellspacing="0">
						<thead>
						<tr>
							<th class="sort">&nbsp;</th>
							<th><?php echo esc_html_x( 'Title', 'shipments', 'shiptastic-for-woocommerce' ); ?></th>
							<th style="width: 14ch;"><?php echo esc_html_x( 'Type', 'shipments', 'shiptastic-for-woocommerce' ); ?></th>
							<th style="width: 16ch;"><?php echo esc_html_x( 'Group', 'shipments', 'shiptastic-for-woocommerce' ); ?> <?php echo wc_help_tip( _x( 'Warehouses within the same group ship together.', 'shipments', 'shiptastic-for-woocommerce' ) ); ?></th>
							<th class="actions" style="width: 5ch;"><?php echo esc_html_x( 'Actions', 'shipments', 'shiptastic-for-woocommerce' ); ?></th>
						</tr>
						</thead>
						<tbody class="warehouse_list">
						<?php
						$count = 0;
						foreach ( wc_stc_get_warehouse_list() as $warehouse ) :
							?>
							<tr class="item">
								<td class="sort"></td>
								<td>
									<input type="text" name="warehouse[<?php echo esc_attr( $count ); ?>][title]" value="<?php echo esc_attr( wp_unslash( $warehouse->get_title() ) ); ?>" />
									<input type="hidden" name="warehouse[<?php echo esc_attr( $count ); ?>][warehouse_id]" value="<?php echo esc_attr( $warehouse->get_id() ); ?>" />
								</td>
								<td style="width: 14ch;"><?php self::render_select( "warehouse[{$count}][type]", $types, $warehouse->get_type() ); ?></td>
								<td style="width: 16ch;"><?php self::render_select( "warehouse[{$count}][group]", $groups, $warehouse->get_group_instance() ? $warehouse->get_group() : '' ); ?></td>
								<td class="actions" style="width: 5ch;">
									<a class="button wc-stc-shipment-action-button wc-stc-warehouse-edit edit tip" aria-label="<?php echo esc_attr_x( 'Edit warehouse', 'shipments', 'shiptastic-for-woocommerce' ); ?>" href="<?php echo esc_url( self::get_settings_url( $warehouse->get_id() ) ); ?>"><?php echo esc_html_x( 'Edit warehouse', 'shipments', 'shiptastic-for-woocommerce' ); ?></a>
								</td>
							</tr>
							<?php
							++$count;
						endforeach;
						?>
						</tbody>
						<tfoot>
						<tr>
							<th colspan="5"><a href="#" class="add button"><?php echo esc_html_x( '+ Add warehouse', 'shipments', 'shiptastic-for-woocommerce' ); ?></a> <a href="#" class="remove_rows button"><?php echo esc_html_x( 'Remove selected warehouse(s)', 'shipments', 'shiptastic-for-woocommerce' ); ?></a></th>
						</tr>
						<tr class="template item" style="display: none">
							<td class="sort"></td>
							<td><input type="text" name="warehouse[size][title]" value="" /></td>
							<td style="width: 14ch;"><?php self::render_select( 'warehouse[size][type]', $types ); ?></td>
							<td style="width: 16ch;"><?php self::render_select( 'warehouse[size][group]', $groups ); ?></td>
							<td style="width: 5ch;"></td>
						</tr>
						</tfoot>
					</table>
				</div>
			</td>
		</tr>
		<?php
		$html = ob_get_clean();

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Saves the group and warehouse lists. Rows without a title are removed, existing entries missing
	 * from the posted lists are deleted. Groups are saved first so that warehouses may reference them.
	 *
	 * @return void
	 */
	public static function save_warehouse_list() {
		// Nonce is verified by WC_Admin_Settings before saving.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$posted_groups     = isset( $_POST['warehouse_group'] ) && is_array( $_POST['warehouse_group'] ) ? wc_clean( wp_unslash( $_POST['warehouse_group'] ) ) : array();
		$posted_warehouses = isset( $_POST['warehouse'] ) && is_array( $_POST['warehouse'] ) ? wc_clean( wp_unslash( $_POST['warehouse'] ) ) : array();
		// phpcs:enable

		self::save_list( $posted_groups, wc_stc_get_warehouse_group_list(), true );
		self::save_list( $posted_warehouses, wc_stc_get_warehouse_list(), false );
	}

	/**
	 * @param array $posted
	 * @param Warehouse[] $existing
	 * @param bool $is_group
	 *
	 * @return void
	 */
	protected static function save_list( $posted, $existing, $is_group ) {
		$existing_ids    = array();
		$saved_ids       = array();
		$order           = 0;
		$available_types = array_keys( wc_stc_get_warehouse_types() );

		foreach ( $existing as $warehouse ) {
			$existing_ids[] = $warehouse->get_id();
		}

		foreach ( $posted as $key => $data ) {
			/**
			 * Skip the hidden template row.
			 */
			if ( 'size' === $key || ! is_array( $data ) ) {
				continue;
			}

			$warehouse_id = isset( $data['warehouse_id'] ) ? absint( $data['warehouse_id'] ) : 0;
			$title        = isset( $data['title'] ) ? trim( $data['title'] ) : '';

			if ( empty( $title ) ) {
				continue;
			}

			$warehouse = $warehouse_id && in_array( $warehouse_id, $existing_ids, true ) ? wc_stc_get_warehouse( $warehouse_id ) : new Warehouse();

			if ( ! $warehouse ) {
				continue;
			}

			$props = array(
				'title' => $title,
				'order' => ++$order,
			);

			if ( $is_group ) {
				$props['type'] = 'group';
			} else {
				$group          = isset( $data['group'] ) ? $data['group'] : '';
				$group_instance = $group ? \Vendidero\Shiptastic\Warehouse\Helper::get_warehouse_by_name( $group ) : null;

				$props['type']  = isset( $data['type'] ) && in_array( $data['type'], $available_types, true ) ? $data['type'] : 'self';
				$props['group'] = ( $group_instance && $group_instance->is_group() ) ? $group : '';
			}

			$warehouse->set_props( $props );
			$warehouse->save();
			$saved_ids[] = $warehouse->get_id();
		}

		foreach ( array_diff( $existing_ids, $saved_ids ) as $delete_id ) {
			if ( $warehouse = wc_stc_get_warehouse( $delete_id ) ) {
				$warehouse->delete( true );
			}
		}
	}

	/**
	 * Settings shown on the single warehouse edit page.
	 *
	 * @param Warehouse $warehouse
	 *
	 * @return array
	 */
	public static function get_settings( $warehouse ) {
		$settings = array(
			array(
				'title' => '',
				'type'  => 'title',
				'id'    => 'warehouse_general_options',
			),
			array(
				'title' => _x( 'Title', 'shipments', 'shiptastic-for-woocommerce' ),
				'type'  => 'text',
				'id'    => 'warehouse_title',
				'value' => $warehouse->get_title( 'edit' ),
			),
			array(
				'title'    => _x( 'Description', 'shipments', 'shiptastic-for-woocommerce' ),
				'type'     => 'text',
				'id'       => 'warehouse_description',
				'desc_tip' => _x( 'An internal description, e.g. to identify the warehouse.', 'shipments', 'shiptastic-for-woocommerce' ),
				'value'    => $warehouse->get_description( 'edit' ),
			),
		);

		if ( ! $warehouse->is_group() ) {
			$settings = array_merge(
				$settings,
				array(
					array(
						'title'   => _x( 'Type', 'shipments', 'shiptastic-for-woocommerce' ),
						'type'    => 'select',
						'id'      => 'warehouse_type',
						'options' => wc_stc_get_warehouse_types(),
						'value'   => $warehouse->get_type( 'edit' ),
					),
					array(
						'title'    => _x( 'Group', 'shipments', 'shiptastic-for-woocommerce' ),
						'type'     => 'select',
						'id'       => 'warehouse_group',
						'desc_tip' => _x( 'Warehouses within the same group ship together and share the group\'s sender address. A warehouse without a group is a location of its own.', 'shipments', 'shiptastic-for-woocommerce' ),
						'options'  => wc_stc_get_warehouse_group_select(),
						'value'    => $warehouse->get_group_instance() ? $warehouse->get_group( 'edit' ) : '',
					),
				)
			);
		}

		/**
		 * Location settings are managed by the group in case the warehouse belongs to one.
		 */
		if ( $group = $warehouse->get_group_instance() ) {
			$settings[] = array(
				'type' => 'sectionend',
				'id'   => 'warehouse_general_options',
			);

			$settings[] = array(
				'title' => _x( 'Location', 'shipments', 'shiptastic-for-woocommerce' ),
				/* translators: 1: group edit url 2: group title */
				'desc'  => sprintf( _x( 'The sender address is managed by the group <a href="%1$s">%2$s</a>.', 'shipments', 'shiptastic-for-woocommerce' ), esc_url( self::get_settings_url( $group->get_id() ) ), esc_html( $group->get_title() ) ),
				'type'  => 'title',
				'id'    => 'warehouse_location_options',
			);

			$settings[] = array(
				'type' => 'sectionend',
				'id'   => 'warehouse_location_options',
			);

			$settings = array_merge( $settings, self::get_storage_location_settings( $warehouse ) );

			return apply_filters( 'woocommerce_shiptastic_warehouse_settings', $settings, $warehouse );
		}

		/**
		 * Addresses may only be overridden for groups. Ungrouped warehouses use the shipper address.
		 */
		if ( ! $warehouse->is_group() ) {
			$settings[] = array(
				'type' => 'sectionend',
				'id'   => 'warehouse_general_options',
			);

			$settings[] = array(
				'title' => _x( 'Address', 'shipments', 'shiptastic-for-woocommerce' ),
				'desc'  => _x( 'Shipments from this warehouse use your shipper address. Add the warehouse to a group to use a different sender address.', 'shipments', 'shiptastic-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'warehouse_address_info',
			);

			$settings[] = array(
				'type' => 'sectionend',
				'id'   => 'warehouse_address_info',
			);

			$settings = array_merge( $settings, self::get_storage_location_settings( $warehouse ) );

			return apply_filters( 'woocommerce_shiptastic_warehouse_settings', $settings, $warehouse );
		}

		$settings = array_merge(
			$settings,
			array(
				array(
					'title' => _x( 'Custom address', 'shipments', 'shiptastic-for-woocommerce' ),
					'desc'  => _x( 'Use a different sender address for shipments from this group.', 'shipments', 'shiptastic-for-woocommerce' ) . '<div class="wc-shiptastic-additional-desc">' . _x( 'By default, your shipper address is used.', 'shipments', 'shiptastic-for-woocommerce' ) . '</div>',
					'type'  => 'shiptastic_toggle',
					'id'    => 'warehouse_override_address',
					'value' => $warehouse->get_override_address( 'edit' ),
				),
				array(
					'type' => 'sectionend',
					'id'   => 'warehouse_general_options',
				),
				array(
					'title' => '',
					'type'  => 'title',
					'id'    => 'warehouse_address_options',
				),
			)
		);

		foreach ( Warehouse::get_address_props() as $prop ) {
			$settings[] = array(
				'title'             => General::get_address_label_by_prop( $prop ),
				'type'              => 'country' === $prop ? 'shipments_country_select' : 'text',
				'id'                => "warehouse_address_{$prop}",
				/**
				 * The country select does not offer an empty option - default to the store country.
				 */
				'value'             => ( 'country' === $prop && '' === $warehouse->get_address_prop( $prop, 'edit' ) ) ? get_option( 'woocommerce_default_country', '' ) : $warehouse->get_address_prop( $prop, 'edit' ),
				'custom_attributes' => array(
					'data-show_if_warehouse_override_address' => '',
				),
			);
		}

		$settings[] = array(
			'type' => 'sectionend',
			'id'   => 'warehouse_address_options',
		);

		return apply_filters( 'woocommerce_shiptastic_warehouse_settings', $settings, $warehouse );
	}

	/**
	 * Storage location schema settings (warehouses only).
	 *
	 * @param Warehouse $warehouse
	 *
	 * @return array
	 */
	protected static function get_storage_location_settings( $warehouse ) {
		$schema = $warehouse->get_location_schema_instance();

		return array(
			array(
				'title' => _x( 'Storage locations', 'shipments', 'shiptastic-for-woocommerce' ),
				'desc'  => _x( 'Describe how storage location codes (e.g. A-03-2-B) are structured in this warehouse. Locations are sorted level by level to order pick lists along the walking path. Without levels, location codes are sorted naturally.', 'shipments', 'shiptastic-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'warehouse_storage_location_options',
			),
			array(
				'title'    => _x( 'Separator', 'shipments', 'shiptastic-for-woocommerce' ),
				'type'     => 'text',
				'id'       => 'warehouse_location_separator',
				'css'      => 'max-width: 60px;',
				'desc_tip' => _x( 'The character(s) separating the levels within a location code.', 'shipments', 'shiptastic-for-woocommerce' ),
				'value'    => $schema->get_separator(),
			),
			array(
				'type'   => 'warehouse_location_levels',
				'id'     => 'warehouse_location_levels',
				'schema' => $schema,
			),
			array(
				'type'   => 'warehouse_location_lists',
				'id'     => 'warehouse_location_lists',
				'schema' => $schema,
			),
			array(
				'type'   => 'warehouse_location_overrides',
				'id'     => 'warehouse_location_overrides',
				'schema' => $schema,
			),
			array(
				'type'      => 'warehouse_location_preview',
				'id'        => 'warehouse_location_preview',
				'warehouse' => $warehouse,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'warehouse_storage_location_options',
			),
		);
	}

	protected static function render_input_table_start( $title, $tip, $wrapper_id, $columns ) {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $title ); ?> <?php echo wc_help_tip( $tip ); ?></th>
			<td class="forminp" id="<?php echo esc_attr( $wrapper_id ); ?>">
				<div class="wc_input_table_wrapper">
					<table class="widefat wc_input_table sortable" cellspacing="0">
						<thead>
						<tr>
							<th class="sort">&nbsp;</th>
							<?php
							foreach ( $columns as $column => $column_args ) :
								$column_args = is_array( $column_args ) ? $column_args : array( $column_args, '' );
								?>
								<th class="<?php echo esc_attr( isset( $column_args[2] ) ? $column_args[2] : '' ); ?>" <?php echo $column_args[0] ? 'style="width: ' . esc_attr( $column_args[0] ) . ';"' : ''; ?>><?php echo esc_html( $column ); ?><?php echo $column_args[1] ? ' ' . wc_help_tip( $column_args[1] ) : ''; ?></th>
							<?php endforeach; ?>
						</tr>
						</thead>
		<?php
	}

	protected static function render_input_table_end( $add_label, $remove_label, $colspan, $template_cells ) {
		?>
						<tfoot>
						<tr>
							<th colspan="<?php echo esc_attr( $colspan ); ?>"><a href="#" class="add button"><?php echo esc_html( $add_label ); ?></a> <a href="#" class="remove_rows button"><?php echo esc_html( $remove_label ); ?></a></th>
						</tr>
						<tr class="template item" style="display: none">
							<td class="sort"></td>
							<?php echo $template_cells; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</tr>
						</tfoot>
					</table>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param int|string $index
	 * @param array $level
	 * @param string $key A key identifying the level row (to assign list values), independent of its position.
	 *
	 * @return string
	 */
	protected static function get_level_cells( $index, $level = array(), $key = '' ) {
		$level = wp_parse_args(
			$level,
			array(
				'name'      => '',
				'type'      => 'numeric',
				'direction' => 'asc',
				'snake'     => false,
			)
		);

		ob_start();
		?>
		<td>
			<input type="text" name="warehouse_location_levels[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $level['name'] ); ?>" placeholder="<?php echo esc_attr_x( 'e.g. Aisle', 'shipments', 'shiptastic-for-woocommerce' ); ?>" />
			<input type="hidden" class="wc-stc-location-level-key" name="warehouse_location_levels[<?php echo esc_attr( $index ); ?>][key]" value="<?php echo esc_attr( $key ); ?>" />
		</td>
		<td style="width: 18ch;"><?php self::render_select( "warehouse_location_levels[{$index}][type]", LocationSchema::get_level_types(), $level['type'] ); ?></td>
		<td style="width: 14ch;"><?php self::render_select( "warehouse_location_levels[{$index}][direction]", LocationSchema::get_directions(), $level['direction'] ); ?></td>
		<td style="width: 8ch;" class="toggle wc-stc-location-level-snake">
			<?php
			Admin::render_toggle_field(
				array(
					'id'    => 'warehouse_location_level_snake_' . $index,
					'name'  => "warehouse_location_levels[{$index}][snake]",
					'value' => $level['snake'],
				)
			);
			?>
		</td>
		<?php
		return ob_get_clean();
	}

	protected static function get_level_key( $index ) {
		return 'level-' . $index;
	}

	public static function output_location_levels( $value ) {
		/**
		 * @var LocationSchema $schema
		 */
		$schema = $value['schema'];

		self::render_input_table_start(
			_x( 'Levels', 'shipments', 'shiptastic-for-woocommerce' ),
			_x( 'The parts of a storage location code, from coarse to fine, in the order they appear in the code. Example: with the levels Aisle and Rack, the code 3-12 means aisle 3, rack 12. Pickers are routed through the warehouse level by level: first by the first level, then within it by the second level and so on.', 'shipments', 'shiptastic-for-woocommerce' ),
			'warehouse_location_levels_wrapper',
			array(
				_x( 'Level', 'shipments', 'shiptastic-for-woocommerce' )     => array( '', _x( 'A name to identify the level, e.g. Aisle. Only used for display and error messages.', 'shipments', 'shiptastic-for-woocommerce' ) ),
				_x( 'Type', 'shipments', 'shiptastic-for-woocommerce' )      => array( '18ch', _x( 'Number: 1, 2, 10 (sorted numerically, leading zeros are ignored). Letters: A, B, Z, AA. List: any names (e.g. zones like COLD or BULK) in an order you define below - only available for the first level.', 'shipments', 'shiptastic-for-woocommerce' ) ),
				_x( 'Direction', 'shipments', 'shiptastic-for-woocommerce' ) => array( '14ch', _x( 'Ascending walks from the lowest to the highest value (1, 2, 3), descending the other way round (3, 2, 1), e.g. in case your racks are numbered from the back.', 'shipments', 'shiptastic-for-woocommerce' ) ),
				_x( 'Snake', 'shipments', 'shiptastic-for-woocommerce' )     => array( '8ch', _x( 'Serpentine walking path: reverse this level\'s direction for every second value of the previous level. Example (Aisle, Rack with snake): walk aisle 1 from rack 1 to 10, then aisle 2 from rack 10 back to 1, so pickers do not walk back to the start of each aisle.', 'shipments', 'shiptastic-for-woocommerce' ), 'toggle' ),
			)
		);
		?>
		<tbody class="warehouse_location_levels">
			<?php foreach ( $schema->get_levels() as $index => $level ) : ?>
				<tr class="item">
					<td class="sort"></td>
					<?php echo self::get_level_cells( $index, $level, self::get_level_key( $index ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
		<?php
		self::render_input_table_end( _x( '+ Add level', 'shipments', 'shiptastic-for-woocommerce' ), _x( 'Remove selected level(s)', 'shipments', 'shiptastic-for-woocommerce' ), 5, self::get_level_cells( 'size' ) );
	}

	/**
	 * Renders one sortable value table per list level (named after the level) plus a template
	 * used to create tables for levels switched to type list in the browser.
	 *
	 * @param array $value
	 *
	 * @return void
	 */
	public static function output_location_lists( $value ) {
		/**
		 * @var LocationSchema $schema
		 */
		$schema = $value['schema'];

		foreach ( $schema->get_levels() as $index => $level ) {
			if ( 'list' === $level['type'] ) {
				self::render_location_list( self::get_level_key( $index ), $schema->get_level_name( $index ), $level['values'] );
			}
		}

		self::render_location_list( '__KEY__', '', array(), true );
	}

	protected static function render_location_list( $key, $level_name, $values, $is_template = false ) {
		$name = 'warehouse_location_level_values[' . $key . '][]';
		?>
		<tr valign="top" class="wc-stc-location-list <?php echo esc_attr( $is_template ? 'wc-stc-location-list-template' : '' ); ?>" data-level-key="<?php echo esc_attr( $key ); ?>" <?php echo $is_template ? 'style="display: none;"' : ''; ?>>
			<th scope="row" class="titledesc">
				<?php /* translators: %s level name, e.g. Zone */ ?>
				<span class="wc-stc-location-list-title" data-title="<?php echo esc_attr_x( 'Order: %s', 'shipments-location-list', 'shiptastic-for-woocommerce' ); ?>"><?php echo esc_html( sprintf( _x( 'Order: %s', 'shipments-location-list', 'shiptastic-for-woocommerce' ), $level_name ) ); ?></span>
				<?php echo wc_help_tip( _x( 'The values of this level (type List) in walking order, e.g. zones or floors. Drag & drop to reorder. Codes with a value missing from this list are sorted last.', 'shipments', 'shiptastic-for-woocommerce' ) ); ?>
			</th>
			<td class="forminp">
				<div class="wc_input_table_wrapper">
					<table class="widefat wc_input_table sortable wc-stc-location-list-values" cellspacing="0">
						<thead>
						<tr>
							<th class="sort">&nbsp;</th>
							<th><?php echo esc_html_x( 'Value', 'shipments', 'shiptastic-for-woocommerce' ); ?> <?php echo wc_help_tip( _x( 'As used within your location codes, e.g. COLD for COLD-3-12. Case-insensitive.', 'shipments', 'shiptastic-for-woocommerce' ) ); ?></th>
						</tr>
						</thead>
						<tbody>
						<?php foreach ( $values as $list_value ) : ?>
							<tr class="item">
								<td class="sort"></td>
								<td><input type="text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $list_value ); ?>" /></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
						<tfoot>
						<tr>
							<th colspan="2"><a href="#" class="add button"><?php echo esc_html_x( '+ Add value', 'shipments', 'shiptastic-for-woocommerce' ); ?></a> <a href="#" class="remove_rows button"><?php echo esc_html_x( 'Remove selected value(s)', 'shipments', 'shiptastic-for-woocommerce' ); ?></a></th>
						</tr>
						<tr class="template item" style="display: none">
							<td class="sort"></td>
							<td><input type="text" name="<?php echo esc_attr( $name ); ?>" value="" placeholder="<?php echo esc_attr_x( 'e.g. A', 'shipments', 'shiptastic-for-woocommerce' ); ?>" /></td>
						</tr>
						</tfoot>
					</table>
				</div>
			</td>
		</tr>
		<?php
	}

	public static function output_location_overrides( $value ) {
		/**
		 * @var LocationSchema $schema
		 */
		$schema = $value['schema'];

		self::render_input_table_start(
			_x( 'Exceptions', 'shipments', 'shiptastic-for-woocommerce' ),
			_x( 'For storage spots which do not follow your levels, e.g. a packing table, goods receipt or a pallet spot. Without an exception they are sorted last. Assign the position of a regular location instead, e.g. sort GOODS-RECEIPT like 1-1 to pick from it first.', 'shipments', 'shiptastic-for-woocommerce' ),
			'warehouse_location_overrides_wrapper',
			array(
				_x( 'Location', 'shipments', 'shiptastic-for-woocommerce' ) => array( '', _x( 'The storage location code as entered for your products, e.g. GOODS-RECEIPT.', 'shipments', 'shiptastic-for-woocommerce' ) ),
				_x( 'Sort like', 'shipments', 'shiptastic-for-woocommerce' ) => array( '', _x( 'A regular location code whose position in the walking path is used, e.g. 1-1.', 'shipments', 'shiptastic-for-woocommerce' ) ),
			)
		);
		?>
		<tbody class="warehouse_location_overrides">
			<?php
			$index = 0;
			foreach ( $schema->get_overrides() as $code => $sort_as ) :
				?>
				<tr class="item">
					<td class="sort"></td>
					<td><input type="text" name="warehouse_location_overrides[<?php echo esc_attr( $index ); ?>][code]" value="<?php echo esc_attr( $code ); ?>" /></td>
					<td><input type="text" name="warehouse_location_overrides[<?php echo esc_attr( $index ); ?>][sort_as]" value="<?php echo esc_attr( $sort_as ); ?>" /></td>
				</tr>
				<?php
				++$index;
			endforeach;
			?>
		</tbody>
		<?php
		self::render_input_table_end( _x( '+ Add exception', 'shipments', 'shiptastic-for-woocommerce' ), _x( 'Remove selected exception(s)', 'shipments', 'shiptastic-for-woocommerce' ), 3, '<td><input type="text" name="warehouse_location_overrides[size][code]" value="" /></td><td><input type="text" name="warehouse_location_overrides[size][sort_as]" value="" /></td>' );
	}

	/**
	 * Preview field: not saved, refreshed via AJAX whenever the storage location settings change.
	 *
	 * @param array $value
	 *
	 * @return void
	 */
	public static function output_location_preview( $value ) {
		/**
		 * @var Warehouse $warehouse
		 */
		$warehouse = $value['warehouse'];
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label for="warehouse_location_test_codes"><?php echo esc_html_x( 'Preview', 'shipments', 'shiptastic-for-woocommerce' ); ?> <?php echo wc_help_tip( _x( 'Shows the walking path for example locations generated from your levels - based on the current (unsaved) settings. Enter location codes (one per line) to add them to the walking path. This field is not saved.', 'shipments', 'shiptastic-for-woocommerce' ) ); ?></label></th>
			<td class="forminp">
				<textarea id="warehouse_location_test_codes" data-warehouse-id="<?php echo esc_attr( $warehouse->get_id() ); ?>" rows="3" style="width: 100%; max-width: 400px;" placeholder="<?php echo esc_attr_x( 'Enter location codes to try, one per line', 'shipments', 'shiptastic-for-woocommerce' ); ?>"></textarea>
				<div id="warehouse_location_preview" class="wc-shiptastic-additional-desc"><?php echo self::get_preview_html( $warehouse->get_location_schema_instance() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders the walking path (sample codes + entered codes).
	 *
	 * @param LocationSchema $schema
	 * @param string[] $entered_codes
	 *
	 * @return string
	 */
	protected static function get_preview_html( $schema, $entered_codes = array() ) {
		$entries = array();

		foreach ( $schema->get_sample_codes() as $code ) {
			$entries[ $code ] = false;
		}

		foreach ( array_slice( array_filter( array_map( 'trim', $entered_codes ) ), 0, 50 ) as $code ) {
			$entries[ LocationSchema::normalize_code( $code ) ] = true;
		}

		$path = array();

		foreach ( $entries as $code => $is_entered ) {
			$validation = $schema->validate( $code );
			$path[]     = array(
				'code'     => (string) $code,
				'entered'  => $is_entered,
				'sequence' => $schema->get_pick_sequence( $code ),
				'error'    => is_wp_error( $validation ) ? $validation->get_error_message() : '',
			);
		}

		usort(
			$path,
			function ( $a, $b ) {
				$compare = strcmp( $a['sequence'], $b['sequence'] );

				return 0 !== $compare ? $compare : strcmp( $a['code'], $b['code'] );
			}
		);

		ob_start();
		?>
		<h4 class="wc-stc-location-preview-title"><?php echo esc_html_x( 'Walking path', 'shipments', 'shiptastic-for-woocommerce' ); ?></h4>
		<div class="wc-stc-location-preview-wrapper">
			<ol class="wc-stc-location-preview">
				<?php foreach ( $path as $entry ) : ?>
					<li class="<?php echo esc_attr( implode( ' ', array_filter( array( $entry['error'] ? 'invalid' : '', $entry['entered'] ? 'entered' : '' ) ) ) ); ?>">
						<code><?php echo esc_html( $entry['code'] ); ?></code>
						<?php if ( $entry['entered'] ) : ?>
							<span class="description"><?php echo esc_html_x( '(entered)', 'shipments-storage-location-preview', 'shiptastic-for-woocommerce' ); ?></span>
						<?php endif; ?>
						<?php if ( $entry['error'] ) : ?>
							<span class="description"><?php echo esc_html( $entry['error'] ); ?> <?php echo esc_html_x( 'Sorted after all valid values of this level.', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>

		<?php
		return ob_get_clean();
	}

	/**
	 * Builds the storage location schema data from posted settings.
	 *
	 * @param array $posted Unslashed request data.
	 *
	 * @return array
	 */
	protected static function get_posted_location_schema( $posted ) {
		$posted_levels    = isset( $posted['warehouse_location_levels'] ) ? wc_clean( (array) $posted['warehouse_location_levels'] ) : array();
		$posted_values    = isset( $posted['warehouse_location_level_values'] ) ? wc_clean( (array) $posted['warehouse_location_level_values'] ) : array();
		$posted_overrides = isset( $posted['warehouse_location_overrides'] ) ? wc_clean( (array) $posted['warehouse_location_overrides'] ) : array();
		$levels           = array();
		$overrides        = array();

		unset( $posted_levels['size'], $posted_overrides['size'] );

		foreach ( $posted_levels as $level ) {
			if ( ! is_array( $level ) ) {
				continue;
			}

			$key             = isset( $level['key'] ) ? $level['key'] : '';
			$level['values'] = ( '' !== $key && '__KEY__' !== $key && isset( $posted_values[ $key ] ) ) ? array_values( (array) $posted_values[ $key ] ) : array();

			unset( $level['key'] );

			$levels[] = $level;
		}

		foreach ( $posted_overrides as $override ) {
			if ( is_array( $override ) && ! empty( $override['code'] ) && ! empty( $override['sort_as'] ) ) {
				$overrides[ $override['code'] ] = $override['sort_as'];
			}
		}

		return LocationSchema::sanitize(
			array(
				'separator' => isset( $posted['warehouse_location_separator'] ) ? wc_clean( $posted['warehouse_location_separator'] ) : '-',
				'levels'    => $levels,
				'overrides' => $overrides,
			)
		);
	}

	public static function ajax_preview() {
		check_ajax_referer( 'wc-stc-warehouse-location-preview', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( -1 );
		}

		$warehouse_id = isset( $_POST['warehouse_id'] ) ? absint( $_POST['warehouse_id'] ) : 0;
		$warehouse    = wc_stc_get_warehouse( $warehouse_id );

		if ( ! $warehouse || $warehouse->is_group() ) {
			wp_send_json_error();
		}

		$posted        = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$schema        = new LocationSchema( self::get_posted_location_schema( $posted ) );
		$entered_codes = isset( $posted['test_codes'] ) ? explode( "\n", sanitize_textarea_field( $posted['test_codes'] ) ) : array();

		wp_send_json_success(
			array(
				'html' => self::get_preview_html( $schema, $entered_codes ),
			)
		);
	}

	/**
	 * @param Warehouse $warehouse
	 *
	 * @return void
	 */
	public static function save_settings( $warehouse ) {
		// Nonce verified in save_page().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$get_posted = function ( $key ) {
			return isset( $_POST[ $key ] ) ? wc_clean( wp_unslash( $_POST[ $key ] ) ) : '';
		};

		$title = $get_posted( 'warehouse_title' );

		if ( ! empty( $title ) ) {
			$warehouse->set_title( $title );
		}

		$warehouse->set_description( $get_posted( 'warehouse_description' ) );

		if ( ! $warehouse->is_group() ) {
			$type = $get_posted( 'warehouse_type' );

			if ( array_key_exists( $type, wc_stc_get_warehouse_types() ) ) {
				$warehouse->set_type( $type );
			}

			if ( isset( $_POST['warehouse_group'] ) ) {
				$group = $get_posted( 'warehouse_group' );
				$group = $group ? \Vendidero\Shiptastic\Warehouse\Helper::get_warehouse_by_name( $group ) : null;

				$warehouse->set_group( ( $group && $group->is_group() ) ? $group->get_name() : '' );
			}
		}

		/**
		 * Storage location schema (warehouses only).
		 */
		if ( ! $warehouse->is_group() && isset( $_POST['warehouse_location_separator'] ) ) {
			$warehouse->set_location_schema( self::get_posted_location_schema( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		/**
		 * Addresses may only be overridden for groups.
		 */
		if ( $warehouse->is_group() ) {
			$warehouse->set_override_address( ! empty( $_POST['warehouse_override_address'] ) );

			$address = array();

			foreach ( Warehouse::get_address_props() as $prop ) {
				$address[ $prop ] = $get_posted( "warehouse_address_{$prop}" );
			}

			/**
			 * The country is always posted (select without empty option) - do not store an address
			 * consisting of the country only.
			 */
			if ( '' === implode( '', array_diff_key( $address, array( 'country' => '' ) ) ) ) {
				$address = array();
			}

			$warehouse->set_address( $address );
		}
		// phpcs:enable

		do_action( 'woocommerce_shiptastic_before_save_warehouse_settings', $warehouse );

		$warehouse->save();
	}

	public static function add_page() {
		add_submenu_page( 'woocommerce', _x( 'Warehouse', 'shipments', 'shiptastic-for-woocommerce' ), _x( 'Warehouse', 'shipments', 'shiptastic-for-woocommerce' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	public static function hide_page_from_menu() {
		remove_submenu_page( 'woocommerce', self::PAGE );
	}

	public static function render_page() {
		$warehouse_id = isset( $_GET['warehouse'] ) ? absint( wp_unslash( $_GET['warehouse'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $warehouse_id || ! ( $warehouse = wc_stc_get_warehouse( $warehouse_id ) ) ) {
			return;
		}
		?>
		<div class="wrap woocommerce woocommerce_page_wc-settings wc-shiptastic-warehouse warehouse-<?php echo esc_attr( $warehouse->get_id() ); ?>">
			<h1 class="wp-heading-inline"><?php echo esc_html( $warehouse->get_title() ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( self::get_list_url() ); ?>"><?php echo esc_html_x( 'All warehouses', 'shipments', 'shiptastic-for-woocommerce' ); ?></a>
			<hr class="wp-header-end" />

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success inline"><p><?php echo esc_html_x( 'Your settings have been saved.', 'shipments', 'shiptastic-for-woocommerce' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<div class="wc-shiptastic-admin-settings">
					<?php \WC_Admin_Settings::output_fields( self::get_settings( $warehouse ) ); ?>
				</div>

				<p class="submit">
					<input type="hidden" name="action" value="woocommerce_stc_save_warehouse_settings" />
					<input type="hidden" name="warehouse_id" value="<?php echo esc_attr( $warehouse->get_id() ); ?>" />
					<button name="save" class="button-primary woocommerce-save-button" type="submit" value="<?php echo esc_attr_x( 'Save changes', 'shipments', 'shiptastic-for-woocommerce' ); ?>"><?php echo esc_html_x( 'Save changes', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
					<?php wp_nonce_field( 'woocommerce-stc-warehouse-settings' ); ?>
				</p>
			</form>
		</div>
		<?php
	}

	public static function save_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wc_clean( wp_unslash( $_POST['_wpnonce'] ) ), 'woocommerce-stc-warehouse-settings' ) ) {
			wp_die( '', 400 );
		}

		$warehouse_id = isset( $_POST['warehouse_id'] ) ? absint( wp_unslash( $_POST['warehouse_id'] ) ) : 0;

		if ( ! $warehouse = wc_stc_get_warehouse( $warehouse_id ) ) {
			wp_die( '', 400 );
		}

		self::save_settings( $warehouse );

		wp_safe_redirect( esc_url_raw( add_query_arg( 'updated', 1, self::get_settings_url( $warehouse->get_id() ) ) ) );
		exit;
	}
}

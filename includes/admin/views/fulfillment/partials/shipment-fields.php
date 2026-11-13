<?php
/**
 * Shipment fields operating on `context.shipment`: packaging, shipping provider, content weight and dimensions
 * (locked while a packaging is chosen, the packaging defines them).
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="stc-fields">
	<div class="stc-field-row">
		<label class="stc-field">
			<span class="stc-label"><?php echo esc_html_x( 'Packaging', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
			<select class="stc-input" data-wp-on--change="actions.shipmentFields.setPackaging" data-wp-bind--disabled="state.shipmentFields.isLocked">
				<option value="0"><?php echo esc_html_x( 'No packaging', 'shipments', 'shiptastic-for-woocommerce' ); ?></option>
				<template data-wp-each--packaging="state.packaging" data-wp-each-key="context.packaging.id">
					<option data-wp-bind--value="context.packaging.id" data-wp-bind--selected="state.shipmentFields.isSelectedPackaging" data-wp-text="context.packaging.title"></option>
				</template>
			</select>
		</label>
		<label class="stc-field">
			<span class="stc-label"><?php echo esc_html_x( 'Shipping provider', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
			<select class="stc-input" data-wp-on--change="actions.shipmentFields.setProvider" data-wp-bind--disabled="state.shipmentFields.isLocked">
				<option value=""><?php echo esc_html_x( 'None', 'shipments', 'shiptastic-for-woocommerce' ); ?></option>
				<template data-wp-each--provider="state.providers" data-wp-each-key="context.provider.id">
					<option data-wp-bind--value="context.provider.id" data-wp-bind--selected="state.shipmentFields.isSelectedProvider" data-wp-text="context.provider.title"></option>
				</template>
			</select>
		</label>
	</div>
	<div class="stc-field-row">
		<label class="stc-field">
			<span class="stc-label"><?php echo esc_html_x( 'Content weight', 'shipments', 'shiptastic-for-woocommerce' ); ?> (<?php echo esc_html( get_option( 'woocommerce_weight_unit', 'kg' ) ); ?>)</span>
			<input class="stc-input" type="number" min="0" step="0.001" inputmode="decimal" data-wp-bind--value="context.shipment.weight" data-wp-bind--placeholder="state.shipmentFields.weightPlaceholder" data-wp-bind--disabled="state.shipmentFields.isLocked" data-wp-on--input="actions.shipmentFields.setWeight" />
			<span class="stc-hint" data-wp-text="state.shipmentFields.totalWeightText"></span>
			<span class="stc-error-text" data-wp-bind--hidden="!state.shipmentFields.exceedsMaxWeight"><?php echo esc_html_x( 'Exceeds the packaging\'s max. content weight.', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
		</label>
		<div class="stc-field">
			<span class="stc-label"><?php echo esc_html_x( 'Dimensions', 'shipments', 'shiptastic-for-woocommerce' ); ?> (<?php echo esc_html( get_option( 'woocommerce_dimension_unit', 'cm' ) ); ?>)</span>
			<div class="stc-dimensions">
				<?php
				foreach ( array(
					'length' => _x( 'Length', 'shipments', 'shiptastic-for-woocommerce' ),
					'width'  => _x( 'Width', 'shipments', 'shiptastic-for-woocommerce' ),
					'height' => _x( 'Height', 'shipments', 'shiptastic-for-woocommerce' ),
				) as $dimension => $dimension_title ) :
					?>
					<input class="stc-input" type="number" min="0" step="0.1" inputmode="decimal" aria-label="<?php echo esc_attr( $dimension_title ); ?>" title="<?php echo esc_attr( $dimension_title ); ?>" data-wp-context='<?php echo wp_json_encode( array( 'dimension' => $dimension ) ); ?>' data-wp-bind--value="state.shipmentFields.dimensionValue" data-wp-bind--placeholder="state.shipmentFields.dimensionPlaceholder" data-wp-bind--disabled="state.shipmentFields.isDimensionLocked" data-wp-on--input="actions.shipmentFields.setDimension" />
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</div>

<?php
/**
 * Order details overlay (opened via the order number in the header): fulfillment relevant data and the editable
 * shipping address. The address fields are the edit order page's fields (incl. custom fields).
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="stc-sheet stc-order-sheet" role="dialog" aria-modal="true" aria-labelledby="stc-order-title" data-wp-bind--hidden="!state.orderDetails.open">
	<div class="stc-sheet-panel">
		<header class="stc-sheet-header">
			<h2 id="stc-order-title" class="stc-sheet-title"><?php echo esc_html_x( 'Order', 'shipments', 'shiptastic-for-woocommerce' ); ?> #<span data-wp-text="state.currentItem.order_number"></span></h2>
			<div class="stc-sheet-actions">
				<a class="stc-button is-small" target="_blank" data-wp-bind--href="state.orderDetailsData.editUrl"><?php echo esc_html_x( 'Open order', 'shipments', 'shiptastic-for-woocommerce' ); ?></a>
				<button type="button" class="stc-icon-button" data-wp-on--click="actions.orderDetails.close" aria-label="<?php echo esc_attr_x( 'Close', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&times;</button>
			</div>
		</header>

		<div class="stc-sheet-body">
			<p class="stc-empty" data-wp-bind--hidden="!state.orderDetails.loading"><?php echo esc_html_x( 'Loading…', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>

			<div data-wp-bind--hidden="state.orderDetails.loading">
				<div class="stc-card stc-order-summary">
					<dl class="stc-order-facts">
						<div><dt><?php echo esc_html_x( 'Date', 'shipments', 'shiptastic-for-woocommerce' ); ?></dt><dd data-wp-text="state.orderDetails.data.date_created"></dd></div>
						<div><dt><?php echo esc_html_x( 'Status', 'shipments', 'shiptastic-for-woocommerce' ); ?></dt><dd data-wp-text="state.orderDetails.data.status"></dd></div>
						<div data-wp-bind--hidden="!state.orderDetails.data.shipping_method"><dt><?php echo esc_html_x( 'Shipping method', 'shipments', 'shiptastic-for-woocommerce' ); ?></dt><dd data-wp-text="state.orderDetails.data.shipping_method"></dd></div>
						<div data-wp-bind--hidden="!state.orderDetails.data.payment_method"><dt><?php echo esc_html_x( 'Payment method', 'shipments', 'shiptastic-for-woocommerce' ); ?></dt><dd data-wp-text="state.orderDetails.data.payment_method"></dd></div>
					</dl>
					<div class="stc-order-note" data-wp-bind--hidden="!state.orderDetailsData.hasNote">
						<span class="stc-label"><?php echo esc_html_x( 'Customer note', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
						<p data-wp-text="state.orderDetails.data.customer_note"></p>
					</div>
				</div>

				<h3 class="stc-section-title"><?php echo esc_html_x( 'Shipping address', 'shipments', 'shiptastic-for-woocommerce' ); ?></h3>
				<div class="stc-card stc-address-form">
					<div class="stc-step-error" role="alert" data-wp-bind--hidden="!state.orderDetails.error">
						<p data-wp-text="state.orderDetails.error"></p>
					</div>
					<div class="stc-form-grid">
						<template data-wp-each--field="state.orderDetailsData.fields" data-wp-each-key="context.field.key">
							<div data-wp-bind--class="state.orderDetailsData.fieldClass" data-wp-bind--hidden="state.orderDetailsData.isFieldHidden">
								<label class="stc-label" data-wp-bind--for="state.orderDetailsData.fieldId" data-wp-text="context.field.label"></label>
								<input class="stc-input" data-wp-bind--hidden="!state.orderDetailsData.isInput" data-wp-bind--id="state.orderDetailsData.fieldId" data-wp-bind--type="state.orderDetailsData.inputType" data-wp-bind--placeholder="context.field.placeholder" data-wp-bind--required="context.field.required" data-wp-bind--value="state.orderDetailsData.fieldValue" data-wp-on--input="actions.orderDetails.setField" />
								<textarea class="stc-input" rows="3" data-wp-bind--hidden="!state.orderDetailsData.isTextarea" data-wp-bind--placeholder="context.field.placeholder" data-wp-bind--value="state.orderDetailsData.fieldValue" data-wp-on--input="actions.orderDetails.setField"></textarea>
								<select class="stc-input" data-wp-bind--hidden="!state.orderDetailsData.isSelect" data-wp-on--change="actions.orderDetails.setField">
									<template data-wp-each--option="context.field.options" data-wp-each-key="context.option.value">
										<option data-wp-bind--value="context.option.value" data-wp-bind--selected="state.orderDetailsData.isSelectedOption" data-wp-text="context.option.label"></option>
									</template>
								</select>
								<select class="stc-input" autocomplete="country" data-wp-bind--hidden="!state.orderDetailsData.isCountry" data-wp-on--change="actions.orderDetails.setField">
									<option value=""><?php echo esc_html_x( 'Select a country / region…', 'shipments', 'shiptastic-for-woocommerce' ); ?></option>
									<template data-wp-each--country="state.countries" data-wp-each-key="context.country.code">
										<option data-wp-bind--value="context.country.code" data-wp-bind--selected="state.orderDetailsData.isSelectedCountry" data-wp-text="context.country.name"></option>
									</template>
								</select>
								<select class="stc-input" autocomplete="address-level1" data-wp-bind--hidden="!state.orderDetailsData.isStateSelect" data-wp-on--change="actions.orderDetails.setField">
									<option value=""><?php echo esc_html_x( 'Select a state…', 'shipments', 'shiptastic-for-woocommerce' ); ?></option>
									<template data-wp-each--region="state.orderDetailsData.states" data-wp-each-key="context.region.code">
										<option data-wp-bind--value="context.region.code" data-wp-bind--selected="state.orderDetailsData.isSelectedState" data-wp-text="context.region.name"></option>
									</template>
								</select>
							</div>
						</template>
						<label class="stc-field is-half">
							<span class="stc-label"><?php echo esc_html_x( 'Email', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
							<input class="stc-input" type="email" autocomplete="email" data-wp-bind--value="state.orderDetailsData.emailValue" data-wp-on--input="actions.orderDetails.setEmail" />
						</label>
					</div>
					<p class="stc-hint"><?php echo esc_html_x( 'Changes apply to the order and its shipments which are not shipped yet.', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>
				</div>
			</div>
		</div>

		<footer class="stc-sheet-footer">
			<button type="button" class="stc-button" data-wp-on--click="actions.orderDetails.close"><?php echo esc_html_x( 'Cancel', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
			<button type="button" class="stc-button is-primary is-large" data-wp-on--click="actions.orderDetails.save" data-wp-bind--disabled="!state.orderDetailsData.canSave"><?php echo esc_html_x( 'Save address', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
		</footer>
	</div>
</div>

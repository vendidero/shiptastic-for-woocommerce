<?php
/**
 * Item check list (pick, pack): scan, swipe (right: +1, left: -1) or count items of `state.view.items`.
 *
 * @var string $all_label Label of the button checking all items.
 */
defined( 'ABSPATH' ) || exit;

$scan_id = 'stc-scan-' . wp_unique_id();
?>
<div class="stc-checklist-progress">
	<span class="stc-checklist-progress-text" data-wp-text="state.checklist.progressText"></span>
	<span class="stc-progress-bar" aria-hidden="true"><span class="stc-progress-bar-value" data-wp-bind--style="state.checklist.progressStyle"></span></span>
</div>
<div class="stc-scan">
	<label class="screen-reader-text" for="<?php echo esc_attr( $scan_id ); ?>"><?php echo esc_html_x( 'Scan or enter SKU/GTIN', 'shipments', 'shiptastic-for-woocommerce' ); ?></label>
	<input id="<?php echo esc_attr( $scan_id ); ?>" class="stc-input" type="text" autocomplete="off" placeholder="<?php echo esc_attr_x( 'Scan or enter SKU/GTIN', 'shipments', 'shiptastic-for-woocommerce' ); ?>" data-wp-on--keydown="actions.checklist.onScanKeydown" />
</div>
<ul class="stc-item-list">
	<template data-wp-each--item="state.view.items" data-wp-each-key="context.item.id">
		<li class="stc-item is-swipeable" data-stc-swipe data-wp-on--stcswipe="actions.checklist.onSwipe" data-wp-class--is-complete="state.checklist.isItemComplete" data-wp-class--is-missing="state.checklist.isItemMissing">
			<span class="stc-location" data-wp-bind--hidden="!context.item.location" data-wp-text="context.item.location"></span>
			<div class="stc-item-body">
				<strong data-wp-text="context.item.name"></strong>
				<span class="stc-meta" data-wp-text="context.item.sku"></span>
			</div>
			<div class="stc-stepper">
				<button type="button" class="stc-stepper-button" data-wp-on--click="actions.checklist.decrement" aria-label="<?php echo esc_attr_x( 'Decrease', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&minus;</button>
				<span class="stc-stepper-value"><span data-wp-text="state.checklist.count"></span>/<span data-wp-text="context.item.quantity"></span></span>
				<button type="button" class="stc-stepper-button" data-wp-on--click="actions.checklist.increment" aria-label="<?php echo esc_attr_x( 'Increase', 'shipments', 'shiptastic-for-woocommerce' ); ?>">+</button>
			</div>
		</li>
	</template>
</ul>
<button type="button" class="stc-button" data-wp-on--click="actions.checklist.all"><?php echo esc_html( $all_label ); ?></button>

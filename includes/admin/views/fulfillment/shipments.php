<?php
/**
 * Shipments overlay of the current order (package), available during the whole run. Items are added, removed or moved
 * by drag & drop (handle), swiping (right: +1, left: -1) or the steppers.
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="stc-sheet" role="dialog" aria-modal="true" aria-labelledby="stc-shipments-title" data-wp-bind--hidden="!state.overlay.open">
	<div class="stc-sheet-backdrop" data-wp-on--click="actions.shipments.close"></div>
	<div class="stc-sheet-panel">
		<header class="stc-sheet-header">
			<h2 id="stc-shipments-title" class="stc-sheet-title"><?php echo esc_html_x( 'Shipments', 'shipments', 'shiptastic-for-woocommerce' ); ?> &middot; #<span data-wp-text="state.currentItem.order_number"></span></h2>
			<button type="button" class="stc-icon-button" data-wp-on--click="actions.shipments.close" aria-label="<?php echo esc_attr_x( 'Close', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&times;</button>
		</header>

		<div class="stc-sheet-body" data-stc-scroll>
			<section class="stc-pool" data-stc-drop="pool">
				<h3 class="stc-section-title"><?php echo esc_html_x( 'Not in a shipment', 'shipments', 'shiptastic-for-woocommerce' ); ?></h3>
				<ul class="stc-item-list">
					<template data-wp-each--pool_item="state.shipments.poolItems" data-wp-each-key="context.pool_item.id">
						<li class="stc-item is-swipeable" data-stc-swipe data-stc-draggable data-wp-on--stcswipe="actions.shipments.poolSwipe" data-wp-on--stcdrop="actions.shipments.poolDrop" data-wp-class--has-backorder="state.shipments.poolItemHasBackorder">
							<span class="stc-drag-handle" data-stc-drag-handle aria-hidden="true">&#x2807;</span>
							<div class="stc-item-body">
								<strong data-wp-text="context.pool_item.name"></strong>
								<span class="stc-meta" data-wp-text="state.shipments.poolItemMeta"></span>
							</div>
							<span class="stc-quantity">&times; <span data-wp-text="context.pool_item.quantity"></span></span>
							<button type="button" class="stc-stepper-button" data-wp-on--click="actions.shipments.poolAdd" aria-label="<?php echo esc_attr_x( 'Add to the selected shipment', 'shipments', 'shiptastic-for-woocommerce' ); ?>">+</button>
						</li>
					</template>
				</ul>
				<p class="stc-hint stc-drop-hint" data-wp-bind--hidden="state.shipments.hasPoolItems"><?php echo esc_html_x( 'All items are part of a shipment. Drop or swipe items out of a shipment to move them into another (new) shipment.', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>
			</section>

			<template data-wp-each--shipment="state.overlay.shipments" data-wp-each-key="context.shipment.key">
				<section class="stc-card stc-shipment" data-wp-bind--data-stc-drop="context.shipment.key" data-wp-class--is-active="state.shipments.isActive" data-wp-class--is-locked="state.shipmentFields.isLocked" data-wp-on--click="actions.shipments.select">
					<div class="stc-shipment-header">
						<strong data-wp-text="state.shipments.title"></strong>
						<span class="stc-badge" data-wp-bind--hidden="!state.shipmentFields.isLocked"><?php echo esc_html_x( 'Locked', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
						<button type="button" class="stc-icon-button" data-wp-on--click="actions.shipments.removeShipment" data-wp-bind--hidden="state.shipmentFields.isLocked" aria-label="<?php echo esc_attr_x( 'Remove shipment', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&times;</button>
					</div>
					<ul class="stc-item-list">
						<template data-wp-each--line="state.shipments.lines" data-wp-each-key="context.line.id">
							<li class="stc-item" data-wp-bind--data-stc-swipe="context.shipment.editable" data-wp-bind--data-stc-draggable="context.shipment.editable" data-wp-class--is-swipeable="context.shipment.editable" data-wp-on--stcswipe="actions.shipments.lineSwipe" data-wp-on--stcdrop="actions.shipments.lineDrop">
								<span class="stc-drag-handle" data-stc-drag-handle aria-hidden="true" data-wp-bind--hidden="state.shipmentFields.isLocked">&#x2807;</span>
								<div class="stc-item-body">
									<strong data-wp-text="context.line.name"></strong>
									<span class="stc-meta" data-wp-text="context.line.sku"></span>
								</div>
								<div class="stc-stepper">
									<button type="button" class="stc-stepper-button" data-wp-on--click="actions.shipments.lineDecrement" data-wp-bind--disabled="state.shipmentFields.isLocked" aria-label="<?php echo esc_attr_x( 'Decrease', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&minus;</button>
									<span class="stc-stepper-value" data-wp-text="context.line.quantity"></span>
									<button type="button" class="stc-stepper-button" data-wp-on--click="actions.shipments.lineIncrement" data-wp-bind--disabled="!state.shipments.canAddLine" aria-label="<?php echo esc_attr_x( 'Increase', 'shipments', 'shiptastic-for-woocommerce' ); ?>">+</button>
								</div>
							</li>
						</template>
					</ul>
					<p class="stc-hint stc-drop-hint" data-wp-bind--hidden="!state.shipments.isEmptyShipment"><?php echo esc_html_x( 'Drop items here or add them from the list above.', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>
					<?php echo \Vendidero\Shiptastic\BulkFulfillments\App::render_view( 'partials/shipment-fields' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</section>
			</template>

			<button type="button" class="stc-button" data-wp-on--click="actions.shipments.addShipment" data-wp-bind--hidden="!state.shipments.canAddShipment"><?php echo esc_html_x( '+ Add shipment', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
		</div>

		<footer class="stc-sheet-footer">
			<button type="button" class="stc-button" data-wp-on--click="actions.shipments.close"><?php echo esc_html_x( 'Cancel', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
			<button type="button" class="stc-button is-primary is-large" data-wp-on--click="actions.shipments.save" data-wp-bind--disabled="!state.shipments.canSave"><?php echo esc_html_x( 'Save shipments', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
		</footer>
	</div>
</div>

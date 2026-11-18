<?php
/**
 * Report screen of a run (state.report.run): status, progress, the order/step currently processed and the files
 * created by its steps, e.g. the report of a finished interactive run. Extensions add content (e.g. controls and
 * errors of automation runs).
 */
defined( 'ABSPATH' ) || exit;
?>
<section class="stc-screen stc-report" data-wp-bind--hidden="!state.isReportScreen">
	<div class="stc-card stc-report-summary">
		<div class="stc-report-summary-header">
			<span class="stc-badge" data-wp-bind--class="state.reportStatusClass" data-wp-bind--hidden="!state.showReportStatus" data-wp-text="state.reportStatusText"></span>
			<span class="stc-hint" data-wp-text="state.reportModeText"></span>
		</div>
		<p class="stc-hint stc-report-date" data-wp-text="state.reportDateText"></p>
		<div class="stc-checklist-progress">
			<span class="stc-checklist-progress-text" data-wp-text="state.reportProgressText"></span>
			<?php /* Segmented by outcome: done (green), failed (red), put back (grey), not processed yet (empty). */ ?>
			<span class="stc-progress-bar is-segmented" aria-hidden="true">
				<span class="stc-progress-bar-segment is-done" data-wp-bind--style="state.reportDoneStyle"></span>
				<span class="stc-progress-bar-segment is-failed" data-wp-bind--style="state.reportFailedStyle"></span>
				<span class="stc-progress-bar-segment is-released" data-wp-bind--style="state.reportReleasedStyle"></span>
			</span>
		</div>
		<p class="stc-hint" data-wp-text="state.reportCountsText"></p>

		<div class="stc-report-current" data-wp-bind--hidden="!state.reportHasCurrent" aria-live="polite">
			<span class="stc-label"><?php echo esc_html_x( 'Processing', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
			<span><a target="_blank" data-wp-bind--href="state.reportRun.current.order_url" data-wp-text="state.reportCurrentOrder"></a> &middot; <span data-wp-text="state.reportCurrentStep"></span></span>
		</div>

		<?php
		/**
		 * Action to output additional content of the report summary (e.g. controls of automation runs).
		 *
		 * @package Vendidero/Shiptastic
		 */
		do_action( 'woocommerce_shiptastic_fulfillment_app_report_summary' );
		?>

		<div class="stc-report-export" data-wp-bind--hidden="!state.reportRun.export_url">
			<span class="stc-hint" data-wp-text="state.reportExportText"></span>
			<a class="stc-button" data-wp-bind--href="state.reportRun.export_url" download><?php echo esc_html_x( 'Download files (zip)', 'shipments', 'shiptastic-for-woocommerce' ); ?></a>
		</div>
		<p class="stc-hint" data-wp-bind--hidden="!state.reportRun.export_expired" data-wp-text="state.reportFilesExpiredText"></p>
	</div>

	<?php /* Steps of run level phases: start of each batch (e.g. picking lists), end of the run (e.g. shipment lists). */ ?>
	<div class="stc-card stc-report-run-steps" data-wp-bind--hidden="!state.reportHasRunSteps">
		<h3 class="stc-section-title"><?php echo esc_html_x( 'Batch and run steps', 'shipments', 'shiptastic-for-woocommerce' ); ?></h3>
		<ul class="stc-run-step-list">
			<template data-wp-each--runstep="state.reportRun.run_steps" data-wp-each-key="context.runstep.id">
				<li data-wp-class--is-error="state.isRunStepFailed">
					<div class="stc-run-step-header">
						<span><strong data-wp-text="context.runstep.title"></strong> <span class="stc-hint" data-wp-text="state.runStepMeta"></span></span>
						<span class="stc-badge" data-wp-class--is-stopped="state.isRunStepFailed" data-wp-class--is-completed="state.isRunStepDone" data-wp-text="state.runStepStatusText"></span>
					</div>
					<p class="stc-hint" data-wp-bind--hidden="!context.runstep.message" data-wp-class--stc-error-text="state.isRunStepFailed" data-wp-text="context.runstep.message"></p>
					<div class="stc-button-row" data-wp-bind--hidden="!state.isRunStepFailed">
						<button type="button" class="stc-button is-small" data-wp-on--click="actions.report.retryRunStep" data-wp-bind--disabled="state.report.loading"><?php echo esc_html_x( 'Retry', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
						<button type="button" class="stc-button is-small" data-wp-on--click="actions.report.skipRunStep" data-wp-bind--disabled="state.report.loading"><?php echo esc_html_x( 'Skip', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
					</div>
				</li>
			</template>
		</ul>
	</div>

	<?php /* Orders processed within the run (queue entries, same layout as the queue): outcome, steps, error, files. */ ?>
	<h2 class="stc-section-title stc-report-orders-title" data-wp-text="state.reportOrdersTitle"></h2>
	<ul class="stc-list stc-skeleton-list" aria-hidden="true" data-wp-bind--hidden="!state.report.ordersLoading">
		<?php for ( $i = 0; $i < 3; $i++ ) : ?>
			<li class="stc-list-item stc-skeleton"><span class="stc-skeleton-line is-short"></span><span class="stc-skeleton-line"></span></li>
		<?php endfor; ?>
	</ul>
	<p class="stc-empty" data-wp-bind--hidden="!state.showNoReportOrders" data-wp-text="state.noReportOrdersText"></p>
	<ul class="stc-list stc-run-orders" data-wp-bind--hidden="state.report.ordersLoading">
		<template data-wp-each--runorder="state.report.orders" data-wp-each-key="context.runorder.queue_id">
			<li class="stc-list-item">
				<div class="stc-list-body">
					<strong><a target="_blank" data-wp-bind--href="context.runorder.order_url">#<span data-wp-text="context.runorder.order_number"></span></a></strong>
					<span class="stc-list-meta" data-wp-text="state.runOrderMeta"></span>
					<span class="stc-badge is-warning" data-wp-bind--hidden="!context.runorder.has_backorders"><?php echo esc_html_x( 'Backorder', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
					<?php /* Steps of the order itself (e.g. picking). */ ?>
					<ul class="stc-run-order-steps" data-wp-bind--hidden="!state.hasRunOrderSteps">
						<template data-wp-each--runorderstep="state.runOrderSteps" data-wp-each-key="context.runorderstep.key">
							<li data-wp-class--is-error="state.isRunOrderStepFailed" data-wp-bind--title="context.runorderstep.error"><span class="stc-run-order-step-status" aria-hidden="true"></span><span data-wp-text="context.runorderstep.title"></span></li>
						</template>
					</ul>
					<span class="stc-error-text" data-wp-bind--hidden="!context.runorder.message" data-wp-text="context.runorder.message"></span>
					<ul class="stc-run-order-files" data-wp-bind--hidden="!state.hasRunOrderFiles">
						<template data-wp-each--runfile="state.runOrderFiles" data-wp-each-key="context.runfile.url">
							<li><a data-wp-bind--href="context.runfile.url" data-wp-text="context.runfile.name" download></a></li>
						</template>
					</ul>
					<?php /* The order's shipments: status, provider, tracking, packaging, their steps and files within the run. */ ?>
					<ul class="stc-run-order-shipments" data-wp-bind--hidden="!context.runorder.shipments.length">
						<template data-wp-each--runshipment="context.runorder.shipments" data-wp-each-key="context.runshipment.id">
							<li>
								<span class="stc-run-shipment-header"><strong data-wp-text="state.runShipmentTitle"></strong> <span class="stc-list-meta" data-wp-text="state.runShipmentMeta"></span></span>
								<ul class="stc-run-order-steps" data-wp-bind--hidden="!state.hasRunShipmentSteps">
									<template data-wp-each--runorderstep="state.runShipmentSteps" data-wp-each-key="context.runorderstep.key">
										<li data-wp-class--is-error="state.isRunOrderStepFailed" data-wp-bind--title="context.runorderstep.error"><span class="stc-run-order-step-status" aria-hidden="true"></span><span data-wp-text="context.runorderstep.title"></span></li>
									</template>
								</ul>
								<ul class="stc-run-order-files" data-wp-bind--hidden="!state.hasRunShipmentFiles">
									<template data-wp-each--runfile="state.runShipmentFiles" data-wp-each-key="context.runfile.url">
										<li><a data-wp-bind--href="context.runfile.url" data-wp-text="context.runfile.name" download></a></li>
									</template>
								</ul>
							</li>
						</template>
					</ul>
				</div>
				<span class="stc-badge" data-wp-class--is-completed="state.isRunOrderCompleted" data-wp-class--is-stopped="state.isRunOrderFailed" data-wp-text="state.runOrderStatusText"></span>
			</li>
		</template>
	</ul>

	<?php
	/**
	 * Action to output additional content of the report screen (e.g. errors of automation runs).
	 *
	 * @package Vendidero/Shiptastic
	 */
	do_action( 'woocommerce_shiptastic_fulfillment_app_report_after' );
	?>
</section>

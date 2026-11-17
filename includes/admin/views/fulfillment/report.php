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
			<span class="stc-badge" data-wp-bind--class="state.reportStatusClass" data-wp-text="state.reportStatusText"></span>
			<span class="stc-hint" data-wp-text="state.reportModeText"></span>
		</div>
		<div class="stc-checklist-progress">
			<span class="stc-checklist-progress-text" data-wp-text="state.reportProgressText"></span>
			<span class="stc-progress-bar" aria-hidden="true"><span class="stc-progress-bar-value" data-wp-bind--style="state.reportProgressStyle"></span></span>
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
	</div>

	<?php
	/**
	 * Action to output additional content of the report screen (e.g. errors of automation runs).
	 *
	 * @package Vendidero/Shiptastic
	 */
	do_action( 'woocommerce_shiptastic_fulfillment_app_report_after' );
	?>
</section>

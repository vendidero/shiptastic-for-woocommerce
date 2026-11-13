<?php
/**
 * Fulfillment app shell. Rendered once per load, processed by the Interactivity API.
 *
 * @var string $action_templates
 */
defined( 'ABSPATH' ) || exit;

$queue_tabs = array(
	'pending' => _x( 'Open', 'shipments-queue-status', 'shiptastic-for-woocommerce' ),
	'locked'  => _x( 'In progress', 'shipments-queue-status', 'shiptastic-for-woocommerce' ),
	'error'   => _x( 'Exceptions', 'shipments-queue-status', 'shiptastic-for-woocommerce' ),
	'done'    => _x( 'Done', 'shipments-queue-status', 'shiptastic-for-woocommerce' ),
);
?>
<div class="stc-app" data-wp-interactive="shiptastic/fulfillment" data-wp-init="callbacks.init" data-wp-class--is-busy="state.busy" data-wp-class--is-run="state.isRunScreen">
	<header class="stc-page-header">
		<div class="stc-page-header-main">
			<button type="button" class="stc-icon-button stc-header-back" data-wp-on--click="actions.stopRun" data-wp-bind--hidden="!state.isRunScreen" aria-label="<?php echo esc_attr_x( 'Back to overview', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&larr;</button>
			<h1 class="stc-breadcrumbs">
				<nav aria-label="<?php echo esc_attr_x( 'Breadcrumbs', 'shipments', 'shiptastic-for-woocommerce' ); ?>">
					<ol>
						<li>
							<span data-wp-bind--hidden="state.isRunScreen" aria-current="page"><?php echo esc_html_x( 'Fulfillment', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
							<button type="button" class="stc-breadcrumb-link" data-wp-on--click="actions.stopRun" data-wp-bind--hidden="!state.isRunScreen" title="<?php echo esc_attr_x( 'Back to overview (unfinished orders are put back into the queue)', 'shipments', 'shiptastic-for-woocommerce' ); ?>"><?php echo esc_html_x( 'Fulfillment', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
						</li>
						<li class="stc-breadcrumb-workflow" data-wp-bind--hidden="!state.isRunScreen" data-wp-text="state.currentWorkflowTitle"></li>
						<li class="stc-breadcrumb-current" data-wp-bind--hidden="!state.showOrderBreadcrumb" aria-current="page">#<span data-wp-text="state.currentItem.order_number"></span></li>
					</ol>
				</nav>
			</h1>
			<span class="stc-badge is-warning" data-wp-bind--hidden="!state.currentItemHasBackorders"><?php echo esc_html_x( 'Backorder', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
			<span class="stc-badge is-warning" data-wp-bind--hidden="state.online"><?php echo esc_html_x( 'Offline', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
			<p class="stc-page-subtitle" data-wp-bind--hidden="!state.showOrderBreadcrumb"><span class="stc-subtitle-workflow" data-wp-text="state.currentWorkflowTitle"></span><span data-wp-text="state.progressText"></span></p>
		</div>
		<div class="stc-page-actions">
			<button type="button" class="stc-button is-small" data-wp-on--click="actions.shipments.open" data-wp-bind--hidden="!state.showShipmentsButton" data-wp-text="state.shipmentsButtonText"></button>
			<a class="stc-button is-small is-tertiary stc-header-exit" data-wp-class--is-run="state.isRunScreen" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders' ) ); ?>"><?php echo esc_html_x( 'Exit', 'shipments', 'shiptastic-for-woocommerce' ); ?></a>
		</div>
	</header>

	<div class="stc-notice" role="status" data-wp-bind--hidden="!state.notice.message" data-wp-class--is-error="state.isErrorNotice">
		<p data-wp-text="state.notice.message"></p>
		<button type="button" class="stc-icon-button" data-wp-on--click="actions.dismissNotice" aria-label="<?php echo esc_attr_x( 'Dismiss', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&times;</button>
	</div>

	<main class="stc-main">
		<section class="stc-screen stc-overview" data-wp-bind--hidden="!state.isOverviewScreen">
			<h2 class="stc-section-title"><?php echo esc_html_x( 'Workflows', 'shipments', 'shiptastic-for-woocommerce' ); ?></h2>
			<ul class="stc-cards">
				<template data-wp-each--workflow="state.workflows" data-wp-each-key="context.workflow.id">
					<li class="stc-card">
						<div class="stc-card-body">
							<strong class="stc-card-title" data-wp-text="context.workflow.title"></strong>
							<span class="stc-card-meta" data-wp-text="state.workflowStepsText"></span>
							<span class="stc-card-meta" data-wp-text="state.workflowOpenText"></span>
						</div>
						<button type="button" class="stc-button is-primary" data-wp-on--click="actions.startWorkflow" data-wp-bind--hidden="state.isBackgroundWorkflow" data-wp-bind--disabled="state.busy"><?php echo esc_html_x( 'Start', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
						<span class="stc-badge" data-wp-bind--hidden="!state.isBackgroundWorkflow"><?php echo esc_html_x( 'Runs in the background', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
					</li>
				</template>
			</ul>
			<p class="stc-empty" data-wp-bind--hidden="state.hasWorkflows"><?php echo esc_html_x( 'There are no active workflows yet.', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>

			<div class="stc-section-header">
				<h2 class="stc-section-title"><?php echo esc_html_x( 'Queue', 'shipments', 'shiptastic-for-woocommerce' ); ?></h2>
				<label class="stc-queue-filter">
					<span class="screen-reader-text"><?php echo esc_html_x( 'Show orders of', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
					<select class="stc-input" data-wp-on--change="actions.setQueueWorkflow">
						<option value="0"><?php echo esc_html_x( 'All orders', 'shipments', 'shiptastic-for-woocommerce' ); ?></option>
						<template data-wp-each--workflow="state.workflows" data-wp-each-key="context.workflow.id">
							<option data-wp-bind--value="context.workflow.id" data-wp-bind--selected="state.isSelectedQueueWorkflow" data-wp-text="context.workflow.title"></option>
						</template>
					</select>
				</label>
			</div>
			<nav class="stc-tabs" role="tablist">
				<?php foreach ( $queue_tabs as $queue_status => $queue_title ) : ?>
					<button type="button" role="tab" class="stc-tab" data-wp-context='<?php echo wp_json_encode( array( 'status' => $queue_status ) ); ?>' data-wp-on--click="actions.showQueue" data-wp-class--is-active="state.isActiveQueueTab" data-wp-bind--aria-selected="state.isActiveQueueTab">
						<?php echo esc_html( $queue_title ); ?> <span class="stc-count" data-wp-text="state.queueTabCount"></span>
					</button>
				<?php endforeach; ?>
			</nav>
			<ul class="stc-list stc-skeleton-list" aria-hidden="true" data-wp-bind--hidden="!state.isQueueLoading">
				<?php for ( $i = 0; $i < 5; $i++ ) : ?>
					<li class="stc-list-item stc-skeleton"><span class="stc-skeleton-line is-short"></span><span class="stc-skeleton-line"></span></li>
				<?php endfor; ?>
			</ul>
			<ul class="stc-list" data-wp-bind--hidden="state.isQueueLoading" data-wp-bind--aria-busy="state.isQueueLoading">
				<template data-wp-each--queue_item="state.queue.items" data-wp-each-key="context.queue_item.id">
					<li class="stc-list-item">
						<div class="stc-list-body">
							<strong>#<span data-wp-text="context.queue_item.order_number"></span></strong>
							<span class="stc-list-meta" data-wp-text="state.queueItemMeta"></span>
							<span class="stc-badge is-warning" data-wp-bind--hidden="!context.queue_item.has_backorders"><?php echo esc_html_x( 'Backorder', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
							<span class="stc-error-text" data-wp-bind--hidden="!context.queue_item.last_error" data-wp-text="context.queue_item.last_error"></span>
						</div>
						<button type="button" class="stc-button" data-wp-on--click="actions.resetQueueItem" data-wp-bind--hidden="!state.canResetQueueItem"><?php echo esc_html_x( 'Back to queue', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
					</li>
				</template>
			</ul>
			<p class="stc-empty" data-wp-bind--hidden="!state.showQueueEmpty"><?php echo esc_html_x( 'No orders.', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>
			<nav class="stc-pagination" data-wp-bind--hidden="!state.showPagination" aria-label="<?php echo esc_attr_x( 'Queue pages', 'shipments', 'shiptastic-for-woocommerce' ); ?>">
				<button type="button" class="stc-button" data-wp-on--click="actions.prevPage" data-wp-bind--disabled="state.isPrevPageDisabled" aria-label="<?php echo esc_attr_x( 'Previous page', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&larr;</button>
				<span class="stc-pagination-text" data-wp-text="state.queueRangeText"></span>
				<button type="button" class="stc-button" data-wp-on--click="actions.nextPage" data-wp-bind--disabled="state.isNextPageDisabled" aria-label="<?php echo esc_attr_x( 'Next page', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&rarr;</button>
			</nav>
		</section>

		<section class="stc-screen stc-run" data-wp-bind--hidden="!state.isRunScreen">
			<div data-wp-bind--hidden="!state.currentItem">
				<?php /* Step navigation: scales to any number of steps (summary + segmented progress, full list on demand). */ ?>
				<div class="stc-step-nav" data-wp-class--is-open="state.stepListOpen" data-wp-on-document--click="actions.closeStepListOutside" data-wp-on-document--keydown="actions.closeStepListOnEscape">
					<button type="button" class="stc-step-summary" data-wp-on--click="actions.toggleStepList" data-wp-bind--aria-expanded="state.stepListOpen" aria-controls="stc-step-list">
						<span class="stc-step-count" data-wp-text="state.stepCountText"></span>
						<strong class="stc-step-current" data-wp-text="state.currentStepTitle"></strong>
						<span class="stc-step-toggle" aria-hidden="true"></span>
					</button>
					<div class="stc-step-segments" aria-hidden="true">
						<template data-wp-each--step="state.visibleSteps" data-wp-each-key="context.step.key">
							<span class="stc-step-segment" data-wp-class--is-done="context.step.is_done" data-wp-class--is-current="state.isCurrentStep"></span>
						</template>
					</div>
					<ol id="stc-step-list" class="stc-step-list" data-wp-bind--hidden="!state.stepListOpen">
						<template data-wp-each--step="state.visibleSteps" data-wp-each-key="context.step.key">
							<li class="stc-step-list-item" data-wp-class--is-done="context.step.is_done" data-wp-class--is-current="state.isCurrentStep">
								<button type="button" data-wp-on--click="actions.reopenStep" data-wp-bind--disabled="!state.isStepReopenable" data-wp-bind--aria-current="state.isCurrentStep">
									<span class="stc-step-status" aria-hidden="true"></span>
									<span data-wp-text="state.stepTitle"></span>
									<span class="stc-step-hint" data-wp-bind--hidden="!state.isStepReopenable"><?php echo esc_html_x( 'Redo', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
								</button>
							</li>
						</template>
					</ol>
				</div>

				<div class="stc-step-error" role="alert" data-wp-bind--hidden="!state.showStepError">
					<p data-wp-text="state.stepErrorMessage"></p>
					<div class="stc-button-row">
						<button type="button" class="stc-button" data-wp-on--click="actions.shipments.open" data-wp-bind--hidden="!state.hasLayout"><?php echo esc_html_x( 'Edit shipments', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
						<button type="button" class="stc-button" data-wp-on--click="actions.retryStep" data-wp-bind--hidden="!state.canRetry"><?php echo esc_html_x( 'Retry', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
						<button type="button" class="stc-button is-destructive" data-wp-on--click="actions.skipItem"><?php echo esc_html_x( 'Move to exceptions', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
					</div>
				</div>

				<?php echo $action_templates; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php echo \Vendidero\Shiptastic\BulkFulfillments\App::render_view( 'shipments' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<div class="stc-empty" data-wp-bind--hidden="!state.showEmptyRun">
				<p data-wp-text="state.emptyRunText"></p>
				<button type="button" class="stc-button" data-wp-on--click="actions.stopRun"><?php echo esc_html_x( 'Back to overview', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
			</div>
		</section>
	</main>

	<footer class="stc-actionbar" data-wp-bind--hidden="!state.showActionBar">
		<button type="button" class="stc-button" data-wp-on--click="actions.reopenStep" data-wp-bind--hidden="!state.canGoBack" data-wp-bind--disabled="state.busy" aria-label="<?php echo esc_attr_x( 'Back to the previous step', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&larr; <?php echo esc_html_x( 'Back', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
		<button type="button" class="stc-button" data-wp-on--click="actions.releaseItem" data-wp-bind--disabled="state.busy"><?php echo esc_html_x( 'Put back', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
		<button type="button" class="stc-button is-primary is-large" data-wp-on--click="actions.submitStep" data-wp-bind--disabled="state.busy" data-wp-text="state.submitLabel"><?php echo esc_html_x( 'Continue', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
	</footer>
</div>

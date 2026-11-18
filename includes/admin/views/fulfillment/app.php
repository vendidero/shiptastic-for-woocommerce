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
	// Processed (all shipments ready for shipping), waiting to be shipped.
	'ready'   => _x( 'Ready for shipping', 'shipments-queue-status', 'shiptastic-for-woocommerce' ),
	'error'   => _x( 'Exceptions', 'shipments-queue-status', 'shiptastic-for-woocommerce' ),
);
?>
<div class="stc-app" data-wp-interactive="shiptastic/fulfillment" data-wp-init="callbacks.init" data-wp-watch="callbacks.syncUrl" data-wp-class--is-busy="state.busy" data-wp-class--is-run="state.isSubScreen">
	<header class="stc-page-header">
		<div class="stc-page-header-main">
			<button type="button" class="stc-icon-button stc-header-back" data-wp-on--click="actions.goToOverview" data-wp-bind--hidden="!state.isSubScreen" aria-label="<?php echo esc_attr_x( 'Back to overview', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&larr;</button>
			<h1 class="stc-breadcrumbs">
				<nav aria-label="<?php echo esc_attr_x( 'Breadcrumbs', 'shipments', 'shiptastic-for-woocommerce' ); ?>">
					<ol>
						<li>
							<span data-wp-bind--hidden="state.isSubScreen" aria-current="page"><?php echo esc_html_x( 'Fulfillment', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
							<button type="button" class="stc-breadcrumb-link" data-wp-on--click="actions.goToOverview" data-wp-bind--hidden="!state.isSubScreen" data-wp-bind--title="state.backTitle"><?php echo esc_html_x( 'Fulfillment', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
						</li>
						<li class="stc-breadcrumb-workflow" data-wp-bind--hidden="!state.isSubScreen" data-wp-text="state.headerWorkflowTitle"></li>
						<li class="stc-breadcrumb-current" data-wp-bind--hidden="!state.isReportScreen" aria-current="page" data-wp-text="state.reportTitle"></li>
						<li class="stc-breadcrumb-current" data-wp-bind--hidden="!state.showOrderBreadcrumb" aria-current="page">
							<button type="button" class="stc-breadcrumb-order" data-wp-on--click="actions.orderDetails.open" title="<?php echo esc_attr_x( 'Order details', 'shipments', 'shiptastic-for-woocommerce' ); ?>">#<span data-wp-text="state.currentItem.order_number"></span></button>
						</li>
					</ol>
				</nav>
			</h1>
			<span class="stc-badge is-warning" data-wp-bind--hidden="!state.currentItemHasBackorders"><?php echo esc_html_x( 'Backorder', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
			<span class="stc-badge is-warning" data-wp-bind--hidden="state.online"><?php echo esc_html_x( 'Offline', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
			<?php /* Changes made offline, waiting to be synced (outbox). */ ?>
			<span class="stc-badge is-info stc-sync-badge" role="status" data-wp-bind--hidden="!state.syncText" data-wp-class--is-running="state.sync.running" data-wp-text="state.syncText"></span>
			<p class="stc-page-subtitle" data-wp-bind--hidden="!state.showOrderBreadcrumb"><span class="stc-subtitle-workflow" data-wp-text="state.currentWorkflowTitle"></span><span data-wp-text="state.progressText"></span></p>
		</div>
		<div class="stc-page-actions">
			<button type="button" class="stc-button is-small" data-wp-on--click="actions.shipments.open" data-wp-bind--hidden="!state.showShipmentsButton" data-wp-text="state.shipmentsButtonText"></button>
			<a class="stc-button is-small is-tertiary stc-header-exit" data-wp-class--is-run="state.isSubScreen" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders' ) ); ?>"><?php echo esc_html_x( 'Exit', 'shipments', 'shiptastic-for-woocommerce' ); ?></a>
		</div>
	</header>

	<?php /* Notices: on top of the screen, during a run in the bottom right corner (above the action bar). */ ?>
	<div class="stc-notice" role="status" data-wp-bind--hidden="!state.notice.message" data-wp-class--is-error="state.isErrorNotice" data-wp-class--is-success="state.isSuccessNotice" data-wp-class--is-run="state.isRunScreen" data-wp-class--is-raised="state.autoStepsText">
		<p data-wp-text="state.notice.message"></p>
		<button type="button" class="stc-icon-button" data-wp-on--click="actions.dismissNotice" aria-label="<?php echo esc_attr_x( 'Dismiss', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&times;</button>
	</div>

	<main class="stc-main">
		<?php /* Deep links (e.g. a run): shown until the run/report is opened, instead of flashing the overview. */ ?>
		<section class="stc-screen" data-wp-bind--hidden="!state.routeLoading">
			<div class="stc-empty stc-run-loading" role="status">
				<span class="stc-spinner" aria-hidden="true"></span>
				<p><?php echo esc_html_x( 'Loading…', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>
			</div>
		</section>
		<section class="stc-screen stc-overview" data-wp-bind--hidden="!state.isOverviewScreen">
			<div class="stc-section-header">
				<h2 class="stc-section-title"><?php echo esc_html_x( 'Workflows', 'shipments', 'shiptastic-for-woocommerce' ); ?></h2>
				<?php if ( current_user_can( 'manage_woocommerce' ) ) : ?>
					<button type="button" class="stc-button is-small" data-wp-on--click="actions.workflowEditor.open"><?php echo esc_html_x( '+ New workflow', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
				<?php endif; ?>
			</div>
			<ul class="stc-cards">
				<template data-wp-each--workflow="state.workflows" data-wp-each-key="context.workflow.id">
					<li class="stc-card">
						<div class="stc-card-body">
							<strong class="stc-card-title"><span data-wp-text="context.workflow.title"></span></strong>
							<span class="stc-card-meta" data-wp-text="state.workflowStepsText"></span>
							<span class="stc-card-meta" data-wp-text="state.workflowOpenText"></span>
						</div>
						<div class="stc-card-actions">
							<?php if ( current_user_can( 'manage_woocommerce' ) ) : ?>
								<button type="button" class="stc-button is-tertiary" data-wp-on--click="actions.workflowEditor.open"><?php echo esc_html_x( 'Edit', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
							<?php endif; ?>
							<?php
							/**
							 * Action to output additional actions (buttons) of a workflow card (context.workflow) in the fulfillment app.
							 *
							 * @package Vendidero/Shiptastic
							 */
							do_action( 'woocommerce_shiptastic_fulfillment_app_workflow_card_actions' );
							?>
							<button type="button" class="stc-button is-primary" data-wp-on--click="actions.startWorkflow" data-wp-bind--hidden="!state.canStartInteractive" data-wp-bind--disabled="state.busy" data-wp-text="state.startLabel"><?php echo esc_html_x( 'Start', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
						</div>
						<?php
						/**
						 * Action to output additional content of a workflow card (context.workflow) in the fulfillment app.
						 *
						 * @package Vendidero/Shiptastic
						 */
						do_action( 'woocommerce_shiptastic_fulfillment_app_workflow_card_after' );
						?>
					</li>
				</template>
			</ul>
			<p class="stc-empty" data-wp-bind--hidden="state.hasWorkflows"><?php echo esc_html_x( 'There are no workflows yet.', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>
			<?php
			if ( current_user_can( 'manage_woocommerce' ) ) {
				echo \Vendidero\Shiptastic\BulkFulfillments\App::render_view( 'workflow-editor' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>

			<?php /* Finished runs (history): app runs and automations which processed orders, newest first (managers: all users). */ ?>
			<div class="stc-overview-section" data-wp-bind--hidden="!state.showRunsSection">
				<h2 class="stc-section-title" data-wp-text="state.runsListTitle"></h2>
				<?php /* Loading the history (first page): placeholder rows (title, date, meta + status). */ ?>
				<ul class="stc-list stc-skeleton-list" aria-hidden="true" data-wp-bind--hidden="!state.showRunsSkeleton">
					<?php for ( $i = 0; $i < 3; $i++ ) : ?>
						<li class="stc-list-item stc-skeleton is-run"><span class="stc-skeleton-line is-short"></span><span class="stc-skeleton-line is-medium"></span><span class="stc-skeleton-line"></span><span class="stc-skeleton-badge"></span></li>
					<?php endfor; ?>
				</ul>
				<ul class="stc-list" data-wp-bind--hidden="state.showRunsSkeleton">
					<template data-wp-each--run="state.runs" data-wp-each-key="context.run.id">
						<li class="stc-list-item">
							<button type="button" class="stc-list-button" data-wp-on--click="actions.report.openFromList">
								<span class="stc-list-body">
									<strong data-wp-text="context.run.workflow_title"></strong>
									<span class="stc-list-meta" data-wp-text="state.runListDate"></span>
									<span class="stc-list-meta" data-wp-text="state.runListMeta"></span>
								</span>
								<span data-wp-bind--class="state.runListStatusClass" data-wp-bind--hidden="!state.showRunListStatus" data-wp-text="state.runListStatus"></span>
							</button>
						</li>
					</template>
				</ul>
				<button type="button" class="stc-button is-small stc-runs-more" data-wp-on--click="actions.report.loadMoreRuns" data-wp-bind--hidden="!state.showMoreRuns" data-wp-bind--disabled="state.runsLoading" data-wp-class--is-loading="state.runsLoading" data-wp-text="state.moreRunsLabel"></button>
			</div>

			<div class="stc-section-header stc-overview-section">
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
							<?php /* In progress: who holds the order, for which workflow and until when. */ ?>
							<span class="stc-list-meta stc-queue-lock" data-wp-bind--hidden="!state.queueItemLockText" data-wp-text="state.queueItemLockText"></span>
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

		<?php echo \Vendidero\Shiptastic\BulkFulfillments\App::render_view( 'report' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<?php
		/**
		 * Action to output additional screens of the fulfillment app.
		 *
		 * @package Vendidero/Shiptastic
		 */
		do_action( 'woocommerce_shiptastic_fulfillment_app_screens' );
		?>

		<section class="stc-screen stc-run" data-wp-bind--hidden="!state.isRunScreen">
			<?php /* A step at the start of the batch (e.g. a picking list) failed: the batch's orders wait until it is retried or skipped. */ ?>
			<div class="stc-step-error stc-run-step-error" role="alert" data-wp-bind--hidden="!state.runStepError.id">
				<p><strong data-wp-text="state.runStepErrorTitle"></strong> <span data-wp-text="state.runStepError.message"></span></p>
				<p class="stc-hint"><?php echo esc_html_x( 'The orders of this batch continue once the step is retried or skipped.', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>
				<div class="stc-button-row">
					<button type="button" class="stc-button is-primary" data-wp-on--click="actions.retryRunStep" data-wp-bind--disabled="state.busy"><?php echo esc_html_x( 'Retry', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
					<button type="button" class="stc-button" data-wp-on--click="actions.skipRunStep" data-wp-bind--disabled="state.busy"><?php echo esc_html_x( 'Skip', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
				</div>
			</div>

			<?php /* Offline: orders which could not be synced (e.g. the reservation expired) and orders waiting for the connection. */ ?>
			<div class="stc-sync-conflicts" role="alert" data-wp-bind--hidden="!state.hasSyncConflicts">
				<ul>
					<template data-wp-each--conflict="state.sync.conflicts" data-wp-each-key="context.conflict.id">
						<li><strong>#<span data-wp-text="context.conflict.order_number"></span></strong> <span data-wp-text="context.conflict.message"></span></li>
					</template>
				</ul>
				<button type="button" class="stc-button is-small" data-wp-on--click="actions.dismissConflicts"><?php echo esc_html_x( 'Dismiss', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
			</div>
			<div class="stc-sync-waiting" role="status" data-wp-bind--hidden="!state.hasWaiting">
				<div class="stc-sync-waiting-header">
					<strong data-wp-bind--hidden="!state.readyText" data-wp-text="state.readyText"></strong>
					<span data-wp-bind--hidden="!state.waitingText" data-wp-text="state.waitingText"></span>
					<button type="button" class="stc-button is-small" data-wp-on--click="actions.sync" data-wp-bind--hidden="!state.waitingText" data-wp-bind--disabled="state.sync.running"><?php echo esc_html_x( 'Sync now', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
				</div>
				<ul class="stc-sync-waiting-list">
					<template data-wp-each--waiting="state.run.waiting" data-wp-each-key="context.waiting.id">
						<li data-wp-class--is-ready="context.waiting.ready" data-wp-class--is-error="context.waiting.failed">
							<span><strong>#<span data-wp-text="context.waiting.order_number"></span></strong> <span class="stc-hint" data-wp-text="state.waitingStatusText"></span></span>
							<button type="button" class="stc-button is-small is-primary" data-wp-bind--hidden="!context.waiting.ready" data-wp-on--click="actions.finishWaiting" data-wp-bind--disabled="state.busy"><?php echo esc_html_x( 'Finish', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
						</li>
					</template>
				</ul>
			</div>
			<div data-wp-bind--hidden="!state.showOrderView">
				<?php /* Step navigation: scales to any number of steps (summary + segmented progress, full list on demand). */ ?>
				<?php /* Files created by the steps of this run so far (export). */ ?>
				<div class="stc-run-export" data-wp-bind--hidden="!state.run.exportUrl">
					<a class="stc-button is-small" data-wp-bind--href="state.run.exportUrl" download data-wp-text="state.runExportText"></a>
				</div>

				<div class="stc-step-nav" data-wp-class--is-open="state.stepListOpen" data-wp-on-document--click="actions.closeStepListOutside" data-wp-on-document--keydown="actions.closeStepListOnEscape">
					<button type="button" class="stc-step-summary" data-wp-on--click="actions.toggleStepList" data-wp-bind--aria-expanded="state.stepListOpen" aria-controls="stc-step-list">
						<span class="stc-step-count" data-wp-text="state.stepCountText"></span>
						<strong class="stc-step-current" data-wp-text="state.currentStepTitle"></strong>
						<span class="stc-step-toggle" aria-hidden="true"></span>
					</button>
					<div class="stc-step-segments" aria-hidden="true">
						<template data-wp-each--step="state.visibleSteps" data-wp-each-key="context.step.key">
							<span class="stc-step-segment" data-wp-class--is-done="context.step.is_done" data-wp-class--is-current="state.isCurrentStep" data-wp-class--is-auto="state.isAutoStep"></span>
						</template>
					</div>
					<ol id="stc-step-list" class="stc-step-list" data-wp-bind--hidden="!state.stepListOpen">
						<template data-wp-each--step="state.visibleSteps" data-wp-each-key="context.step.key">
							<li class="stc-step-list-item" data-wp-class--is-done="context.step.is_done" data-wp-class--is-current="state.isCurrentStep" data-wp-class--is-auto="state.isAutoStep">
								<button type="button" data-wp-on--click="actions.reopenStep" data-wp-bind--disabled="!state.isStepReopenable" data-wp-bind--aria-current="state.isCurrentStep">
									<span class="stc-step-status" aria-hidden="true"></span>
									<span data-wp-text="state.stepTitle"></span>
									<span class="stc-tag is-phase" data-wp-bind--hidden="!state.stepPhaseTag" data-wp-text="state.stepPhaseTag"></span>
									<span class="stc-tag is-auto" data-wp-bind--hidden="!state.isAutoStep"><?php echo esc_html_x( 'Automatic', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
									<span class="stc-step-hint" data-wp-bind--hidden="!state.isStepReopenable"><?php echo esc_html_x( 'Redo', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
								</button>
							</li>
						</template>
					</ol>
				</div>

				<?php /* An automatic step handled interactively after it failed: the error it failed with. */ ?>
				<p class="stc-manual-step" data-wp-bind--hidden="!state.manualStepText" data-wp-text="state.manualStepText"></p>

				<div class="stc-step-error" role="alert" data-wp-bind--hidden="!state.showStepError" data-wp-class--is-external="state.isExternalItem">
					<?php
					/**
					 * Action to output content before the error of the current step (e.g. while fixing an order a run of an
					 * extension failed on).
					 *
					 * @package Vendidero/Shiptastic
					 */
					do_action( 'woocommerce_shiptastic_fulfillment_app_step_error_before' );
					?>
					<p data-wp-text="state.stepErrorMessage"></p>
					<div class="stc-button-row">
						<button type="button" class="stc-button" data-wp-on--click="actions.shipments.open" data-wp-bind--hidden="!state.hasLayout"><?php echo esc_html_x( 'Edit shipments', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
						<?php
						/**
						 * Action to output additional actions (buttons) of the current step's error.
						 *
						 * @package Vendidero/Shiptastic
						 */
						do_action( 'woocommerce_shiptastic_fulfillment_app_step_error_actions' );
						?>
						<a class="stc-button" target="_blank" data-wp-bind--href="state.currentOrderUrl" data-wp-bind--hidden="!state.canOpenOrder"><?php echo esc_html_x( 'Open order', 'shipments', 'shiptastic-for-woocommerce' ); ?></a>
						<button type="button" class="stc-button" data-wp-on--click="actions.retryStep" data-wp-bind--hidden="!state.canRetry"><?php echo esc_html_x( 'Retry', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
						<button type="button" class="stc-button is-destructive" data-wp-on--click="actions.skipItem" data-wp-bind--hidden="state.isExternalItem"><?php echo esc_html_x( 'Move to exceptions', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
					</div>
					<?php
					/**
					 * Action to output content after the error of the current step.
					 *
					 * @package Vendidero/Shiptastic
					 */
					do_action( 'woocommerce_shiptastic_fulfillment_app_step_error_after' );
					?>
				</div>

				<?php echo $action_templates; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php
				/*
				 * Progress of the automatic steps, run one by one (after submitting a step, retrying or resuming). Pauses at the
				 * first error: shown inline, the user may retry, jump into the step to fix it or move the order to the exceptions.
				 */
				?>
				<div class="stc-auto-run" data-wp-bind--hidden="!state.autoSteps.active" data-wp-class--is-done="state.isAutoStepsDone" data-wp-class--is-paused="state.autoSteps.paused">
					<div class="stc-auto-run-card" role="status" aria-live="polite">
						<span class="stc-spinner" aria-hidden="true"></span>
						<strong class="stc-auto-run-title" data-wp-text="state.autoStepsTitle"></strong>
						<ul class="stc-auto-run-steps">
							<template data-wp-each--step="state.autoSteps.steps" data-wp-each-key="context.step.key">
								<li data-wp-class--is-running="state.isAutoStepRunning" data-wp-class--is-done="state.isAutoStepDone" data-wp-class--is-error="state.isAutoStepFailed">
									<span class="stc-auto-run-status" aria-hidden="true"></span>
									<span>
										<strong data-wp-text="state.stepTitle"></strong>
										<span class="stc-hint" data-wp-bind--hidden="!context.step.description" data-wp-text="context.step.description"></span>
										<span class="stc-error-text" role="alert" data-wp-bind--hidden="!context.step.message" data-wp-text="context.step.message"></span>
									</span>
								</li>
							</template>
						</ul>
						<div class="stc-button-row" data-wp-bind--hidden="!state.autoSteps.paused">
							<button type="button" class="stc-button is-primary" data-wp-on--click="actions.retryStep" data-wp-bind--disabled="state.busy"><?php echo esc_html_x( 'Retry', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
							<button type="button" class="stc-button" data-wp-on--click="actions.fixAutoStep" data-wp-bind--disabled="state.busy" data-wp-text="state.autoFixLabel"></button>
							<button type="button" class="stc-button is-destructive" data-wp-on--click="actions.skipAutoStep" data-wp-bind--disabled="state.busy"><?php echo esc_html_x( 'Move to exceptions', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
						</div>
						<p class="stc-hint" data-wp-bind--hidden="state.autoSteps.paused" data-wp-class--is-invisible="state.isAutoStepsDone">
							<span data-wp-bind--hidden="state.autoSteps.slow"><?php echo esc_html_x( 'Please keep this window open.', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
							<span data-wp-bind--hidden="!state.autoSteps.slow"><?php echo esc_html_x( 'Still working - this may take a moment, e.g. while a shipping provider creates a label. Please keep this window open.', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
						</p>
					</div>
				</div>

				<?php echo \Vendidero\Shiptastic\BulkFulfillments\App::render_view( 'shipments' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo \Vendidero\Shiptastic\BulkFulfillments\App::render_view( 'order' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<div class="stc-empty stc-run-loading" role="status" data-wp-bind--hidden="!state.showRunLoading">
				<span class="stc-spinner" aria-hidden="true"></span>
				<p><?php echo esc_html_x( 'Loading the next orders…', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>
			</div>

			<div class="stc-empty" data-wp-bind--hidden="!state.showEmptyRun">
				<p data-wp-text="state.emptyRunText"></p>
				<button type="button" class="stc-button" data-wp-on--click="actions.stopRun"><?php echo esc_html_x( 'Back to overview', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
			</div>
		</section>
	</main>

	<footer class="stc-actionbar" data-wp-bind--hidden="!state.showActionBar">
		<?php /* Steps running automatically once the current step is submitted. */ ?>
		<p class="stc-auto-hint" role="status" data-wp-bind--hidden="!state.autoStepsText" data-wp-class--is-running="state.busy" data-wp-text="state.autoStepsText"></p>
		<button type="button" class="stc-button" data-wp-on--click="actions.reopenStep" data-wp-bind--hidden="!state.canGoBack" data-wp-bind--disabled="state.busy" aria-label="<?php echo esc_attr_x( 'Back to the previous step', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&larr; <?php echo esc_html_x( 'Back', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
		<button type="button" class="stc-button" data-wp-on--click="actions.releaseItem" data-wp-bind--disabled="state.busy"><?php echo esc_html_x( 'Put back', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
		<button type="button" class="stc-button is-primary is-large" data-wp-on--click="actions.submitStep" data-wp-bind--disabled="state.busy" data-wp-text="state.submitLabel"><?php echo esc_html_x( 'Continue', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
	</footer>
</div>

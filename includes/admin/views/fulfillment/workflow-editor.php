<?php
/**
 * Workflow editor overlay (managers): general settings, steps, order filters and automation.
 */
defined( 'ABSPATH' ) || exit;

$list_filters = array(
	'locations'          => array(
		'label' => _x( 'Locations', 'shipments', 'shiptastic-for-woocommerce' ),
		'all'   => _x( 'All locations', 'shipments', 'shiptastic-for-woocommerce' ),
		'add'   => _x( '+ Add a location…', 'shipments', 'shiptastic-for-woocommerce' ),
		'empty' => _x( 'No location selected yet: all locations are included.', 'shipments', 'shiptastic-for-woocommerce' ),
	),
	'shipping_providers' => array(
		'label' => _x( 'Shipping providers', 'shipments', 'shiptastic-for-woocommerce' ),
		'all'   => _x( 'All shipping providers', 'shipments', 'shiptastic-for-woocommerce' ),
		'add'   => _x( '+ Add a shipping provider…', 'shipments', 'shiptastic-for-woocommerce' ),
		'empty' => _x( 'No shipping provider selected yet: all shipping providers are included.', 'shipments', 'shiptastic-for-woocommerce' ),
	),
	'countries'          => array(
		'label' => _x( 'Shipping countries', 'shipments', 'shiptastic-for-woocommerce' ),
		'all'   => _x( 'All countries', 'shipments', 'shiptastic-for-woocommerce' ),
		'add'   => _x( '+ Add a country…', 'shipments', 'shiptastic-for-woocommerce' ),
		'empty' => _x( 'No country selected yet: all countries are included.', 'shipments', 'shiptastic-for-woocommerce' ),
	),
);

$weight_unit   = get_option( 'woocommerce_weight_unit', 'kg' );
$range_filters = array(
	'items'  => array(
		'label' => _x( 'Number of items', 'shipments', 'shiptastic-for-woocommerce' ),
		'unit'  => _x( 'items', 'shipments', 'shiptastic-for-woocommerce' ),
		'step'  => '1',
	),
	'weight' => array(
		'label' => _x( 'Weight', 'shipments', 'shiptastic-for-woocommerce' ),
		'unit'  => $weight_unit,
		'step'  => '0.01',
	),
);
?>
<div class="stc-sheet stc-workflow-sheet" role="dialog" aria-modal="true" aria-labelledby="stc-workflow-title" data-wp-bind--hidden="!state.workflowEditor.open">
	<div class="stc-sheet-panel">
		<header class="stc-sheet-header">
			<h2 id="stc-workflow-title" class="stc-sheet-title" data-wp-text="state.workflowEditorData.title"></h2>
			<button type="button" class="stc-icon-button" data-wp-on--click="actions.workflowEditor.close" aria-label="<?php echo esc_attr_x( 'Close', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&times;</button>
		</header>

		<div class="stc-sheet-body">
			<p class="stc-empty" data-wp-bind--hidden="!state.workflowEditor.loading"><?php echo esc_html_x( 'Loading…', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>

			<div class="stc-workflow-form" data-wp-bind--hidden="state.workflowEditor.loading">
				<div class="stc-step-error" role="alert" data-wp-bind--hidden="!state.workflowEditor.error">
					<p data-wp-text="state.workflowEditor.error"></p>
				</div>

				<?php /* General */ ?>
				<h3 class="stc-section-title"><?php echo esc_html_x( 'General', 'shipments', 'shiptastic-for-woocommerce' ); ?></h3>
				<div class="stc-card stc-form-grid">
					<label class="stc-field is-full">
						<span class="stc-label"><?php echo esc_html_x( 'Title', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
						<input class="stc-input" type="text" required data-wp-bind--value="state.workflowEditor.form.title" data-wp-on--input="actions.workflowEditor.setTitle" />
					</label>
					<?php
					/*
					 * Workflow modes: interactive (staff works through the orders in the app), extensions may add modes. The
					 * choice is shown in case there are several modes.
					 */
					$workflow_modes = \Vendidero\Shiptastic\BulkFulfillments\Workflow::get_modes();

					/**
					 * Filter to adjust the hints of the workflow modes shown in the workflow editor.
					 *
					 * @param array $hints mode => hint
					 *
					 * @package Vendidero/Shiptastic
					 */
					$workflow_mode_hints = (array) apply_filters(
						'woocommerce_shiptastic_fulfillment_workflow_mode_hints',
						array(
							'interactive' => _x( 'Staff works through the orders in the fulfillment app.', 'shipments', 'shiptastic-for-woocommerce' ),
						)
					);

					/*
					 * Background workflows require Shiptastic Pro: presented with a pro marker in case not available.
					 */
					$has_automation = \Vendidero\Shiptastic\BulkFulfillments\App::has_automation();

					if ( count( $workflow_modes ) > 1 || ! $has_automation ) :
						?>
						<fieldset class="stc-choice-group stc-field is-full">
							<legend class="stc-label"><?php echo esc_html_x( 'How orders are processed', 'shipments', 'shiptastic-for-woocommerce' ); ?></legend>
							<?php foreach ( $workflow_modes as $workflow_mode => $workflow_mode_title ) : ?>
								<label class="stc-choice" data-wp-context='<?php echo wp_json_encode( array( 'value' => $workflow_mode ) ); ?>'>
									<input type="radio" name="stc-workflow-mode" value="<?php echo esc_attr( $workflow_mode ); ?>" data-wp-bind--checked="state.workflowEditorData.isWorkflowMode" data-wp-on--change="actions.workflowEditor.setMode" />
									<span>
										<strong><?php echo esc_html( $workflow_mode_title ); ?></strong>
										<?php if ( ! empty( $workflow_mode_hints[ $workflow_mode ] ) ) : ?>
											<span class="stc-hint"><?php echo esc_html( $workflow_mode_hints[ $workflow_mode ] ); ?></span>
										<?php endif; ?>
									</span>
								</label>
							<?php endforeach; ?>
							<?php if ( ! $has_automation ) : ?>
								<label class="stc-choice is-pro">
									<input type="radio" name="stc-workflow-mode" value="background" disabled />
									<span>
										<strong><?php echo esc_html_x( 'Background', 'shipments-workflow-mode', 'shiptastic-for-woocommerce' ); ?> <?php echo \Vendidero\Shiptastic\BulkFulfillments\App::get_pro_badge(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
										<span class="stc-hint"><?php echo esc_html_x( 'All steps run automatically, started manually or on a schedule. Steps of interactive workflows may run automatically too.', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
									</span>
								</label>
							<?php endif; ?>
						</fieldset>
					<?php endif; ?>
				</div>

				<?php /* Steps */ ?>
				<h3 class="stc-section-title"><?php echo esc_html_x( 'Steps', 'shipments', 'shiptastic-for-woocommerce' ); ?></h3>
				<ol class="stc-workflow-steps">
					<template data-wp-each--step="state.workflowEditorData.visibleSteps" data-wp-each-key="context.step.key">
						<li class="stc-card stc-workflow-step">
							<?php /* Header: what the step does, fixed properties as badges (descriptions as tooltips), ordering. */ ?>
							<div class="stc-workflow-step-header">
								<span class="stc-workflow-step-number" data-wp-text="state.workflowEditorData.stepNumber"></span>
								<strong class="stc-workflow-step-title" data-wp-text="state.workflowEditorData.stepTitle"></strong>
								<div class="stc-workflow-step-badges">
									<span class="stc-tag is-phase" tabindex="0" data-wp-bind--hidden="!state.workflowEditorData.stepPhaseText" data-wp-bind--title="state.workflowEditorData.stepPhaseDescription" data-wp-text="state.workflowEditorData.stepPhaseText"></span>
									<span class="stc-tag" tabindex="0" data-wp-bind--hidden="!state.workflowEditorData.stepModeText" data-wp-bind--title="state.workflowEditorData.stepModeDescription" data-wp-text="state.workflowEditorData.stepModeText"></span>
								</div>
								<div class="stc-workflow-step-actions">
									<button type="button" class="stc-icon-button" data-wp-on--click="actions.workflowEditor.moveStepUp" data-wp-bind--disabled="!state.workflowEditorData.canMoveUp" aria-label="<?php echo esc_attr_x( 'Move up', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&uarr;</button>
									<button type="button" class="stc-icon-button" data-wp-on--click="actions.workflowEditor.moveStepDown" data-wp-bind--disabled="!state.workflowEditorData.canMoveDown" aria-label="<?php echo esc_attr_x( 'Move down', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&darr;</button>
									<button type="button" class="stc-icon-button" data-wp-on--click="actions.workflowEditor.removeStep" aria-label="<?php echo esc_attr_x( 'Remove step', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&times;</button>
								</div>
								<?php /* Second row: description + dependencies, using the whole width below the title. */ ?>
								<div class="stc-workflow-step-description">
									<span class="stc-hint" data-wp-text="state.workflowEditorData.stepDescription"></span>
									<span class="stc-hint" data-wp-bind--hidden="!state.workflowEditorData.stepRequires" data-wp-text="state.workflowEditorData.stepRequires"></span>
								</div>
							</div>
							<?php /* Configuration: choices (when: phase + scope, mode) in case the action supports several, the action's settings. */ ?>
							<div class="stc-workflow-step-config" data-wp-bind--hidden="!state.workflowEditorData.hasStepConfig">
								<div class="stc-workflow-step-choices" data-wp-bind--hidden="!state.workflowEditorData.hasStepChoices">
									<div class="stc-workflow-step-choice" data-wp-bind--hidden="!state.workflowEditorData.hasStepPhaseChoice">
										<span class="stc-label"><?php echo esc_html_x( 'When', 'shipments-step-phase', 'shiptastic-for-woocommerce' ); ?></span>
										<div class="stc-segmented" role="radiogroup" aria-label="<?php echo esc_attr_x( 'When the step runs', 'shipments', 'shiptastic-for-woocommerce' ); ?>">
											<template data-wp-each--choice="state.workflowEditorData.stepPhaseChoices" data-wp-each-key="context.choice.value">
												<label><input type="radio" data-wp-bind--name="context.choice.name" data-wp-bind--value="context.choice.value" data-wp-bind--checked="context.choice.checked" data-wp-on--change="actions.workflowEditor.setStepPhase" /><span data-wp-text="context.choice.label"></span></label>
											</template>
										</div>
									</div>
									<div class="stc-workflow-step-choice" data-wp-bind--hidden="!state.workflowEditorData.hasStepModeChoice">
										<span class="stc-label"><?php echo esc_html_x( 'How', 'shipments-step-mode', 'shiptastic-for-woocommerce' ); ?></span>
										<div class="stc-segmented" role="radiogroup" aria-label="<?php echo esc_attr_x( 'How the step runs', 'shipments', 'shiptastic-for-woocommerce' ); ?>">
											<template data-wp-each--choice="state.workflowEditorData.stepModeChoices" data-wp-each-key="context.choice.value">
												<label data-wp-class--is-pro="context.choice.isPro" data-wp-bind--title="context.choice.title"><input type="radio" data-wp-bind--name="context.choice.name" data-wp-bind--value="context.choice.value" data-wp-bind--checked="context.choice.checked" data-wp-bind--disabled="context.choice.isPro" data-wp-on--change="actions.workflowEditor.setStepMode" /><span><span data-wp-text="context.choice.label"></span><?php echo \Vendidero\Shiptastic\BulkFulfillments\App::get_pro_badge( array( 'data-wp-bind--hidden' => '!context.choice.isPro' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></label>
											</template>
										</div>
									</div>
								</div>
								<p class="stc-hint" data-wp-bind--hidden="!state.workflowEditorData.stepPhaseChoiceDescription" data-wp-text="state.workflowEditorData.stepPhaseChoiceDescription"></p>
								<div class="stc-form-grid" data-wp-bind--hidden="!state.workflowEditorData.stepSettings.length">
									<template data-wp-each--setting="state.workflowEditorData.stepSettings" data-wp-each-key="context.setting.key">
										<div class="stc-field" data-wp-class--is-half="!state.workflowEditorData.isSettingMultiselect">
											<?php /* Multiselect: same layout as the list filters of the workflow (all options or only selected ones). */ ?>
											<fieldset class="stc-list-filter" data-wp-bind--hidden="!state.workflowEditorData.isSettingMultiselect">
												<legend class="stc-label" data-wp-text="context.setting.label"></legend>
												<div class="stc-segmented" role="radiogroup">
													<label><input type="radio" value="all" data-wp-bind--name="state.workflowEditorData.settingRadioName" data-wp-bind--checked="!state.workflowEditorData.isSettingLimited" data-wp-on--change="actions.workflowEditor.includeAllSettingValues" /><span data-wp-text="context.setting.texts.all"></span></label>
													<label><input type="radio" value="selected" data-wp-bind--name="state.workflowEditorData.settingRadioName" data-wp-bind--checked="state.workflowEditorData.isSettingLimited" data-wp-on--change="actions.workflowEditor.limitSettingValues" /><span><?php echo esc_html_x( 'Only selected', 'shipments', 'shiptastic-for-woocommerce' ); ?></span></label>
												</div>
												<div class="stc-list-filter-selection" data-wp-bind--hidden="!state.workflowEditorData.isSettingLimited">
													<ul class="stc-chips">
														<template data-wp-each--option="state.workflowEditorData.settingSelected" data-wp-each-key="context.option.value">
															<li class="stc-chip"><span data-wp-text="context.option.label"></span><button type="button" data-wp-on--click="actions.workflowEditor.removeSettingValue" aria-label="<?php echo esc_attr_x( 'Remove', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&times;</button></li>
														</template>
													</ul>
													<select class="stc-input" data-wp-on--change="actions.workflowEditor.addSettingValue" data-wp-bind--hidden="!state.workflowEditorData.settingAvailable.length">
														<option value="" data-wp-text="context.setting.texts.add"></option>
														<template data-wp-each--option="state.workflowEditorData.settingAvailable" data-wp-each-key="context.option.value">
															<option data-wp-bind--value="context.option.value" data-wp-text="context.option.label"></option>
														</template>
													</select>
													<p class="stc-hint" data-wp-bind--hidden="state.workflowEditorData.settingSelected.length" data-wp-text="context.setting.texts.empty"></p>
												</div>
											</fieldset>
											<label class="stc-toggle-field" data-wp-bind--hidden="!state.workflowEditorData.isSettingCheckbox">
												<input type="checkbox" data-wp-bind--checked="state.workflowEditorData.settingChecked" data-wp-on--change="actions.workflowEditor.setSetting" />
												<span data-wp-text="context.setting.label"></span>
											</label>
											<label class="stc-field" data-wp-bind--hidden="!state.workflowEditorData.isSettingField">
												<span class="stc-label" data-wp-text="context.setting.label"></span>
												<select class="stc-input" data-wp-bind--hidden="!state.workflowEditorData.isSettingSelect" data-wp-on--change="actions.workflowEditor.setSetting">
													<template data-wp-each--option="context.setting.options" data-wp-each-key="context.option.value">
														<option data-wp-bind--value="context.option.value" data-wp-bind--selected="state.workflowEditorData.isSelectedSettingOption" data-wp-text="context.option.label"></option>
													</template>
												</select>
												<input class="stc-input" data-wp-bind--hidden="!state.workflowEditorData.isSettingInput" data-wp-bind--type="context.setting.type" data-wp-bind--value="state.workflowEditorData.settingValue" data-wp-on--input="actions.workflowEditor.setSetting" />
											</label>
										</div>
									</template>
								</div>
							</div>
						</li>
					</template>
				</ol>
				<?php
				/**
				 * Action to output additional content after the steps of the workflow editor.
				 *
				 * @package Vendidero/Shiptastic
				 */
				do_action( 'woocommerce_shiptastic_fulfillment_app_workflow_editor_after_steps' );
				?>
				<p class="stc-empty" data-wp-bind--hidden="state.workflowEditorData.hasSteps"><?php echo esc_html_x( 'Add the first step.', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>
				<label class="stc-field" data-wp-bind--hidden="!state.workflowEditorData.canAddStep">
					<span class="screen-reader-text"><?php echo esc_html_x( 'Add a step', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
					<select class="stc-input" data-wp-on--change="actions.workflowEditor.addStep">
						<option value=""><?php echo esc_html_x( '+ Add a step…', 'shipments', 'shiptastic-for-woocommerce' ); ?></option>
						<template data-wp-each--action="state.workflowEditorData.availableActions" data-wp-each-key="context.action.name">
							<option data-wp-bind--value="context.action.name" data-wp-text="context.action.title"></option>
						</template>
					</select>
				</label>

				<?php /* Filters */ ?>
				<h3 class="stc-section-title"><?php echo esc_html_x( 'Orders', 'shipments', 'shiptastic-for-woocommerce' ); ?></h3>
				<div class="stc-card stc-workflow-filters">
					<p class="stc-hint"><?php echo esc_html_x( 'Only orders matching all criteria are part of the workflow. By default, all orders are included.', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>

					<?php
					/*
					 * List filters (locations, shipping providers, countries): all options are included by default, the
					 * user may restrict the workflow to selected options on request.
					 */
					foreach ( $list_filters as $list_filter => $list_filter_texts ) :
						?>
						<fieldset class="stc-list-filter" data-wp-context='<?php echo wp_json_encode( array( 'list' => $list_filter ) ); ?>'>
							<legend class="stc-label"><?php echo esc_html( $list_filter_texts['label'] ); ?></legend>
							<div class="stc-segmented" role="radiogroup">
								<label><input type="radio" name="stc-filter-<?php echo esc_attr( $list_filter ); ?>" value="all" data-wp-bind--checked="!state.workflowEditorData.isListLimited" data-wp-on--change="actions.workflowEditor.includeAll" /><span><?php echo esc_html( $list_filter_texts['all'] ); ?></span></label>
								<label><input type="radio" name="stc-filter-<?php echo esc_attr( $list_filter ); ?>" value="selected" data-wp-bind--checked="state.workflowEditorData.isListLimited" data-wp-on--change="actions.workflowEditor.limitList" /><span><?php echo esc_html_x( 'Only selected', 'shipments', 'shiptastic-for-woocommerce' ); ?></span></label>
							</div>
							<div class="stc-list-filter-selection" data-wp-bind--hidden="!state.workflowEditorData.isListLimited">
								<ul class="stc-chips">
									<template data-wp-each--choice="state.workflowEditorData.listSelected" data-wp-each-key="context.choice.value">
										<li class="stc-chip"><span data-wp-text="context.choice.label"></span><button type="button" data-wp-on--click="actions.workflowEditor.removeListValue" aria-label="<?php echo esc_attr_x( 'Remove', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&times;</button></li>
									</template>
								</ul>
								<select class="stc-input" data-wp-on--change="actions.workflowEditor.addListValue" data-wp-bind--hidden="!state.workflowEditorData.listAvailable.length">
									<option value=""><?php echo esc_html( $list_filter_texts['add'] ); ?></option>
									<template data-wp-each--choice="state.workflowEditorData.listAvailable" data-wp-each-key="context.choice.value">
										<option data-wp-bind--value="context.choice.value" data-wp-text="context.choice.label"></option>
									</template>
								</select>
								<p class="stc-hint" data-wp-bind--hidden="state.workflowEditorData.listSelected.length"><?php echo esc_html( $list_filter_texts['empty'] ); ?></p>
							</div>
						</fieldset>
					<?php endforeach; ?>

					<?php
					/*
					 * Range filters (from - to): both bounds optional, empty = no limit.
					 */
					foreach ( $range_filters as $range => $range_texts ) :
						?>
						<fieldset class="stc-range-filter" data-wp-context='
						<?php
						echo wp_json_encode(
							array(
								'range' => $range,
								'unit'  => $range_texts['unit'],
							)
						);
						?>
																			'>
							<legend class="stc-label"><?php echo esc_html( $range_texts['label'] ); ?></legend>
							<div class="stc-range" data-wp-class--is-invalid="state.workflowEditorData.isRangeInvalid">
								<label class="stc-range-input">
									<span class="stc-range-prefix"><?php echo esc_html_x( 'from', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
									<input class="stc-input" type="number" inputmode="decimal" min="0" step="<?php echo esc_attr( $range_texts['step'] ); ?>" placeholder="<?php echo esc_attr_x( 'any', 'shipments', 'shiptastic-for-woocommerce' ); ?>" data-wp-bind--value="state.workflowEditorData.rangeMin" data-wp-on--input="actions.workflowEditor.setRangeMin" />
									<span class="stc-range-unit"><?php echo esc_html( $range_texts['unit'] ); ?></span>
								</label>
								<span class="stc-range-separator" aria-hidden="true">&ndash;</span>
								<label class="stc-range-input">
									<span class="stc-range-prefix"><?php echo esc_html_x( 'to', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
									<input class="stc-input" type="number" inputmode="decimal" min="0" step="<?php echo esc_attr( $range_texts['step'] ); ?>" placeholder="<?php echo esc_attr_x( 'any', 'shipments', 'shiptastic-for-woocommerce' ); ?>" data-wp-bind--value="state.workflowEditorData.rangeMax" data-wp-on--input="actions.workflowEditor.setRangeMax" />
									<span class="stc-range-unit"><?php echo esc_html( $range_texts['unit'] ); ?></span>
								</label>
							</div>
							<p class="stc-hint" data-wp-bind--hidden="state.workflowEditorData.isRangeInvalid" data-wp-text="state.workflowEditorData.rangeSummary"></p>
							<p class="stc-error-text" role="alert" data-wp-bind--hidden="!state.workflowEditorData.isRangeInvalid"><?php echo esc_html_x( '"From" must not be greater than "to".', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>
						</fieldset>
					<?php endforeach; ?>

					<?php /* Backorders: same layout as the list filters (all orders by default, segmented choice). */ ?>
					<fieldset class="stc-list-filter stc-backorders-filter">
						<legend class="stc-label"><?php echo esc_html_x( 'Backorders', 'shipments', 'shiptastic-for-woocommerce' ); ?></legend>
						<div class="stc-segmented" role="radiogroup">
							<?php
							foreach ( array(
								'any'     => _x( 'All orders', 'shipments', 'shiptastic-for-woocommerce' ),
								'exclude' => _x( 'Without backorders', 'shipments', 'shiptastic-for-woocommerce' ),
								'only'    => _x( 'With backorders only', 'shipments', 'shiptastic-for-woocommerce' ),
							) as $backorders => $backorders_label ) :
								?>
								<label data-wp-context='<?php echo wp_json_encode( array( 'value' => $backorders ) ); ?>'><input type="radio" name="stc-filter-backorders" value="<?php echo esc_attr( $backorders ); ?>" data-wp-bind--checked="state.workflowEditorData.isBackorders" data-wp-on--change="actions.workflowEditor.setBackorders" /><span><?php echo esc_html( $backorders_label ); ?></span></label>
							<?php endforeach; ?>
						</div>
						<p class="stc-hint" data-wp-text="state.workflowEditorData.backordersHint"></p>
					</fieldset>
				</div>

				<?php
				/**
				 * Action to output additional sections of the workflow editor (e.g. automation settings).
				 *
				 * @package Vendidero/Shiptastic
				 */
				do_action( 'woocommerce_shiptastic_fulfillment_app_workflow_editor_after' );
				?>
			</div>
		</div>

		<footer class="stc-sheet-footer">
			<button type="button" class="stc-button is-destructive" data-wp-on--click="actions.workflowEditor.remove" data-wp-bind--hidden="!state.workflowEditorData.isEditing" data-wp-bind--disabled="state.workflowEditor.saving"><?php echo esc_html_x( 'Delete', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
			<button type="button" class="stc-button" data-wp-on--click="actions.workflowEditor.close"><?php echo esc_html_x( 'Cancel', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
			<button type="button" class="stc-button is-primary is-large" data-wp-on--click="actions.workflowEditor.save" data-wp-bind--disabled="!state.workflowEditorData.canSave"><?php echo esc_html_x( 'Save workflow', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
		</footer>
	</div>
</div>

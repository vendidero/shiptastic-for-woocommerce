<?php
/**
 * Admin View: Settings Tabs
 */
defined( 'ABSPATH' ) || exit;
?>

<?php $integration->header(); ?>

<table class="wc-gzd-shipments-setting-tabs widefat">
	<thead>
	<tr>
		<th class="wc-gzd-shipments-setting-tab-name"><?php echo esc_html_x( 'Name', 'shipments', 'woocommerce-germanized-shipments' ); ?></th>
		<th class="wc-gzd-shipments-setting-tab-enabled"><?php echo esc_html_x( 'Enabled', 'shipments', 'woocommerce-germanized-shipments' ); ?></th>
		<th class="wc-gzd-shipments-setting-tab-desc"><?php echo esc_html_x( 'Description', 'shipments', 'woocommerce-germanized-shipments' ); ?></th>
		<th class="wc-gzd-shipments-setting-tab-actions"></th>
	</tr>
	</thead>
	<tbody class="wc-gzd-shipments-setting-tab-rows">
	<?php
	foreach ( $tabs as $tab_id => $tab ) : // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( $tab->hide_from_main_panel() ) {
			continue;
		}
		?>
		<tr>
			<td class="wc-gzd-shipments-setting-tab-name <?php echo ( $tab->needs_install() ? 'tab-needs-install' : '' ); ?>" id="wc-gzd-shipments-setting-tab-name-<?php echo esc_attr( $tab->get_name() ); ?>">
				<?php if ( $tab->needs_install() ) : ?>
					<span class="wc-gzd-shipments-settings-tab-name-needs-install"><?php echo wp_kses_post( $tab->get_label() ); ?></span>
				<?php else : ?>
					<a href="<?php echo esc_url( $tab->get_url() ); ?>" class="wc-gzd-shipments-setting-tab-link"><?php echo wp_kses_post( $tab->get_label() ); ?></a>
				<?php endif; ?>
			</td>
			<td class="wc-gzd-shipments-setting-tab-enabled" id="wc-gzd-shipments-setting-tab-enabled-<?php echo esc_attr( $tab->get_name() ); ?>">
				<?php if ( $tab->needs_install() ) : ?>
					<?php if ( current_user_can( 'install_plugins' ) ) : ?>
						<a class="button button-secondary wc-gzd-shipments-install-extension-btn wc-gzd-ajax-loading-btn" data-extension="<?php echo esc_attr( $tab->get_extension_name() ); ?>" href="<?php echo esc_url( $tab->get_url() ); ?>"><span class="btn-text"><?php echo esc_html_x( 'Install', 'shipments', 'woocommerce-germanized-shipments' ); ?></span></a>
					<?php else : ?>
						<span class="<?php echo( $tab->is_enabled() ? 'status-enabled' : 'status-disabled' ); ?>"><?php echo( $tab->is_enabled() ? esc_attr_x( 'Yes', 'shipments', 'woocommerce-germanized-shipments' ) : esc_attr_x( 'No', 'shipments', 'woocommerce-germanized-shipments' ) ); ?></span>
					<?php endif; ?>
				<?php else : ?>
					<?php if ( $tab->supports_disabling() ) : ?>
						<fieldset>
							<?php
							\Vendidero\Germanized\Shipments\Admin\Admin::render_toggle_field(
								array(
									'id'                => "woocommerce_gzd_shipments_tab_status_{$tab->get_name()}",
									'value'             => $tab->is_enabled(),
									'custom_attributes' => array(
										'data-tab' => $tab->get_name(),
									),
									'class'             => 'woocommerce-gzd-shipments-tab-status-checkbox',
								)
							);
							?>
						</fieldset>
					<?php else : ?>
						<span class="<?php echo( $tab->is_enabled() ? 'status-enabled' : 'status-disabled' ); ?>"><?php echo( $tab->is_enabled() ? esc_attr_x( 'Yes', 'shipments', 'woocommerce-germanized-shipments' ) : esc_attr_x( 'No', 'shipments', 'woocommerce-germanized-shipments' ) ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
			</td>
			<td class="wc-gzd-shipments-setting-tab-desc"><?php echo wp_kses_post( $tab->get_description() ); ?></td>
			<td class="wc-gzd-shipments-setting-tab-actions">
				<?php if ( $tab->has_help_link() ) : ?>
					<a
						class="button button-secondary wc-gzd-shipments-dash-button help-link"
						title="<?php echo esc_attr_x( 'Find out more', 'shipments', 'woocommerce-germanized-shipments' ); ?>"
						aria-label="<?php echo esc_attr_x( 'Find out more', 'shipments', 'woocommerce-germanized-shipments' ); ?>"
						href="<?php echo esc_url( $tab->get_help_link() ); ?>"
					><?php echo esc_html_x( 'How to', 'shipments', 'woocommerce-germanized-shipments' ); ?></a>
				<?php endif; ?>

				<?php if ( ! $tab->needs_install() ) : ?>
				<a
					class="button button-secondary wc-gzd-shipments-dash-button"
					aria-label="<?php echo esc_attr_x( 'Manage settings', 'shipments', 'woocommerce-germanized-shipments' ); ?>"
					title="<?php echo esc_attr_x( 'Manage settings', 'shipments', 'woocommerce-germanized-shipments' ); ?>"
					href="<?php echo esc_url( $tab->get_url() ); ?>"
				><?php echo esc_html_x( 'Manage', 'shipments', 'woocommerce-germanized-shipments' ); ?></a>
				<?php elseif ( current_user_can( 'install_plugins' ) ) : ?>
					<a
						class="button button-secondary wc-gzd-shipments-dash-button wc-gzd-shipments-install-extension-btn wc-gzd-shipments-ajax-loading-btn install"
						title="<?php echo esc_attr_x( 'Install', 'shipments', 'woocommerce-germanized-shipments' ); ?>"
						aria-label="<?php echo esc_attr_x( 'Install', 'shipments', 'woocommerce-germanized-shipments' ); ?>"
						data-extension="<?php echo esc_attr( $tab->get_extension_name() ); ?>"
						href="<?php echo esc_url( $tab->get_url() ); ?>"
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

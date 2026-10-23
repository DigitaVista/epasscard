<?php
/**
 * Settings → Integrations.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<div class="epc-card epc-card--modules">
		<h2><?php esc_html_e( 'Integrations', 'epasscard' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Turn on the plugins EpassCard should issue cards for. Only plugins installed on this site are listed. Setup on Home turns on the one you choose.', 'epasscard' ); ?>
		</p>

		<?php $epc_missing = array(); ?>
		<?php if ( empty( $modules ) ) : ?>
			<p><?php esc_html_e( 'No integration modules are available.', 'epasscard' ); ?></p>
		<?php else : ?>
			<table class="widefat striped epc-modules-table">
				<thead>
					<tr>
						<th scope="col" class="check-column"><?php esc_html_e( 'Enable', 'epasscard' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Integration', 'epasscard' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Required plugin', 'epasscard' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'epasscard' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $modules as $slug => $module ) : ?>
						<?php
						$available  = $module->is_available();
						$is_enabled = in_array( $slug, $enabled, true );
						$epc_inactive_file = '';
						if ( ! $available ) {
							// Installed but inactive? Offer "Activate" instead of saying "Not installed".
							$epc_known = array(
								'memberpress'               => 'memberpress/memberpress.php',
								'ultimate-membership-pro'   => 'indeed-membership-pro/indeed-membership-pro.php',
								'woocommerce-subscriptions' => 'woocommerce-subscriptions/woocommerce-subscriptions.php',
							);
							$epc_files = isset( $epc_known[ $slug ] ) ? array( $epc_known[ $slug ] ) : array();
							foreach ( (array) $module->get_dependency_install_info() as $epc_dep ) {
								if ( ! empty( $epc_dep['plugin_file'] ) ) {
									$epc_files[] = (string) $epc_dep['plugin_file'];
								}
							}
							foreach ( $epc_files as $epc_file ) {
								if ( file_exists( WP_PLUGIN_DIR . '/' . $epc_file ) && ( ! function_exists( 'is_plugin_active' ) || ! is_plugin_active( $epc_file ) ) ) {
									$epc_inactive_file = $epc_file;
									break;
								}
							}
						}
						if ( ! $available && '' === $epc_inactive_file ) {
							$epc_missing[] = $module->get_dependency_label();
							continue;
						}
						?>
						<tr>
							<th scope="row" class="check-column">
								<input
									type="checkbox"
									name="epc_enabled_modules[]"
									value="<?php echo esc_attr( $slug ); ?>"
									id="epc-module-<?php echo esc_attr( $slug ); ?>"
									<?php checked( $is_enabled ); ?>
									<?php disabled( ! $available ); ?>
								/>
							</th>
							<td>
								<label for="epc-module-<?php echo esc_attr( $slug ); ?>">
									<strong><?php echo esc_html( $module->get_label() ); ?></strong>
								</label>
							</td>
							<td><?php echo esc_html( $module->get_dependency_label() ); ?></td>
							<td>
								<?php if ( $available ) : ?>
									<span class="epc-badge epc-badge--ok"><?php esc_html_e( 'Installed', 'epasscard' ); ?></span>
								<?php elseif ( '' !== $epc_inactive_file ) : ?>
									<span class="epc-badge epc-badge--muted"><?php esc_html_e( 'Inactive', 'epasscard' ); ?></span>
									<?php if ( current_user_can( 'activate_plugins' ) ) : ?>
										<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $epc_inactive_file ) ), 'activate-plugin_' . $epc_inactive_file ) ); ?>"><?php esc_html_e( 'Activate', 'epasscard' ); ?></a>
									<?php endif; ?>
								<?php else : ?>
									<span class="epc-badge epc-badge--muted"><?php esc_html_e( 'Not installed', 'epasscard' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( ! empty( $epc_missing ) ) : ?>
				<p class="description">
					<?php
					/* translators: %s: list of plugin names */
					echo esc_html( sprintf( __( 'Also works with: %s. Install one to use it here.', 'epasscard' ), implode( ', ', array_unique( $epc_missing ) ) ) );
					?>
				</p>
			<?php endif; ?>
			<p style="margin-bottom:0;">
				<button type="button" class="button button-secondary" id="epc-save-modules">
					<?php esc_html_e( 'Save integrations', 'epasscard' ); ?>
				</button>
			</p>
		<?php endif; ?>
	</div>

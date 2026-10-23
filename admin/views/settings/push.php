<?php
/**
 * Settings → Reminders & push: one row per turned-on integration, linking to its message settings.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$epc_rows = array();
foreach ( EPC_Module_Loader::get_enabled_modules() as $epc_slug => $epc_module ) {
	$epc_page = admin_url( 'admin.php?page=epc-' . $epc_slug );
	$epc_links = array();
	if ( 'woocommerce-loyalty' === $epc_slug ) {
		$epc_links[] = array( __( 'Loyalty emails and push (Notifications tab)', 'epasscard' ), $epc_page );
	} else {
		if ( $epc_module->uses_native_reminder_timing() ) {
			$epc_links[] = array( __( 'Reminders', 'epasscard' ), $epc_page . '#epc-section-reminders' );
		}
		if ( ! empty( $epc_module->get_notification_types() ) ) {
			$epc_links[] = array( __( 'Push messages', 'epasscard' ), $epc_page . '#epc-section-push' );
		}
	}
	if ( ! empty( $epc_links ) ) {
		$epc_rows[] = array( $epc_module->get_label(), $epc_links );
	}
}
?>
<p class="epc-lead"><?php esc_html_e( 'Messages that appear on the customer\'s lock screen through their wallet card: reminders before a card expires or an event starts, and messages you send yourself.', 'epasscard' ); ?></p>
<div class="epc-card">
	<?php if ( empty( $epc_rows ) ) : ?>
		<p style="margin:0;"><?php esc_html_e( 'Turn on an integration first. Its reminder and push settings appear here.', 'epasscard' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Integration', 'epasscard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Settings', 'epasscard' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $epc_rows as $epc_row ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $epc_row[0] ); ?></strong></td>
						<td>
							<?php foreach ( $epc_row[1] as $epc_i => $epc_link ) : ?>
								<?php echo $epc_i > 0 ? ' · ' : ''; ?><a href="<?php echo esc_url( $epc_link[1] ); ?>"><?php echo esc_html( $epc_link[0] ); ?></a>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

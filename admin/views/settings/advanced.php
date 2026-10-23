<?php
/**
 * Settings → Advanced: data on uninstall, developer key, API log.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<div class="epc-card epc-card--data">
		<h2><?php esc_html_e( 'Data when deleting the plugin', 'epasscard' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'By default, deleting EpassCard keeps its data so you can reinstall without losing anything. Turn this on to remove it all when the plugin is deleted from the Plugins screen.', 'epasscard' ); ?>
		</p>
		<form class="epc-ajax-form" data-epc-action="epc_save_data_settings" method="post" action="">
			<p>
				<label class="epc-toggle">
					<input type="checkbox" name="epc_delete_data_on_uninstall" value="1" <?php checked( EPC_Admin_Menu::delete_data_on_uninstall() ); ?> />
					<span class="epc-toggle__track" aria-hidden="true"></span>
					<span class="epc-toggle__label"><?php esc_html_e( 'Delete all EpassCard data when the plugin is deleted', 'epasscard' ); ?></span>
				</label>
			</p>
			<p class="description">
				<?php esc_html_e( 'Removed: connection settings and API key, integration settings and template mappings, issued pass records, API logs, and loyalty points, history, rewards and settings. Not removed: reward coupons already given to customers and WooCommerce order details, and passes on EpassCard\'s servers (manage those in your EpassCard account). Deactivating the plugin never deletes anything.', 'epasscard' ); ?>
			</p>
			<p style="margin-bottom:0;">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'epasscard' ); ?></button>
			</p>
		</form>
	</div>

<?php if ( $connected ) : ?>
<div class="epc-card">
	<h2><?php esc_html_e( 'Developer', 'epasscard' ); ?></h2>
		<details class="epc-developer-api">
			<summary><?php esc_html_e( 'Developer: X-Api-Key for custom endpoints', 'epasscard' ); ?></summary>
			<div class="epc-developer-api__body">
				<p class="description">
					<?php esc_html_e( 'Connection data is stored in wp_options. The API key is encrypted in the database; use epc_get_api_key() to get the decrypted X-Api-Key value.', 'epasscard' ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Option name:', 'epasscard' ); ?></strong>
					<code><?php echo esc_html( EPC_Connection::OPTION ); ?></code>
				</p>
				<div class="epc-code-block">
					<pre id="epc-developer-snippet" class="epc-code-block__pre"><?php echo esc_html( EPC_Connection::get_developer_snippet() ); ?></pre>
					<button type="button" class="button button-small epc-copy-snippet" data-copy-target="epc-developer-snippet">
						<?php esc_html_e( 'Copy code', 'epasscard' ); ?>
					</button>
				</div>
			</div>
		</details>
</div>
<?php endif; ?>

<div class="epc-card">
	<h2><?php esc_html_e( 'API log', 'epasscard' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Every call this site makes to EpassCard, with the answer. Useful when a card fails, or to send to support.', 'epasscard' ); ?></p>
	<p style="margin-bottom:0;"><a class="button button-secondary" href="<?php echo esc_url( EPC_Admin_App::url( 'api-log' ) ); ?>"><?php esc_html_e( 'Open the API log', 'epasscard' ); ?></a></p>
</div>

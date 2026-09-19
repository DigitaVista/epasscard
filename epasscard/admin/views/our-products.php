<?php
/**
 * Our Products — loads catalog HTML/CSS from WebCartisan Plugin Hub API.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$client_file = EPC_PLUGIN_DIR . 'includes/class-epc-catalog-client.php';
if ( file_exists( $client_file ) ) {
	require_once $client_file;
}

if ( class_exists( 'EPC_Catalog_Client' ) ) {
	EPC_Catalog_Client::render_tab(
		array(
			'host_slug' => 'epasscard',
			'api_url'   => (string) apply_filters( 'webcartisan_catalog_api_url', '' ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		)
	);
	return;
}

?>
<div class="notice notice-warning inline">
	<p><?php esc_html_e( 'WebCartisan catalog client is missing. Please update the plugin.', 'epasscard' ); ?></p>
</div>

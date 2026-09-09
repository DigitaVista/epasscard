<?php
/**
 * Plugin Name:       EpassCard (Google Wallet, Apple Wallet, and more)
 * Plugin URI:        https://webcartisan.com/plugins/epasscard
 * Description:       WordPress wallet pass plugin for Apple Wallet and Google Wallet. Auto-issue digital membership cards, pkpass passes, and subscription wallet passes.
 * Version:           1.0.6
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            WebCartisan
 * Author URI:        https://webcartisan.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       epasscard
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EPC_VERSION', '1.0.6' );
define( 'EPC_PLUGIN_FILE', __FILE__ );
define( 'EPC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EPC_DB_VERSION', '1.2.0' );

/**
 * Appsero project hash (from https://dashboard.appsero.com).
 * Leave empty until the EpassCard project is created in Appsero.
 */
if ( ! defined( 'EPC_APPSERO_HASH' ) ) {
	define( 'EPC_APPSERO_HASH', '' );
}

if ( ! class_exists( 'Appsero\Client' ) ) {
	$epc_appsero_client = EPC_PLUGIN_DIR . 'vendor/appsero/client/src/Client.php';
	if ( file_exists( $epc_appsero_client ) ) {
		require_once $epc_appsero_client;
	}
}

require_once EPC_PLUGIN_DIR . 'includes/class-epc-appsero.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-plugin.php';

/**
 * Plugin bootstrap.
 *
 * @return EPC_Plugin
 */
function epc_plugin() {
	return EPC_Plugin::instance();
}

register_activation_hook( __FILE__, array( 'EPC_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EPC_Activator', 'deactivate' ) );

EPC_Appsero::init();
epc_plugin()->init();

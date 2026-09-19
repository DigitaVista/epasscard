<?php
/**
 * Appsero usage insights integration.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize Appsero client for anonymous usage insights.
 */
class EPC_Appsero {

	/**
	 * Bootstrap Appsero insights tracker.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! self::load_client() ) {
			return;
		}

		$hash = self::get_project_hash();
		if ( '' === $hash ) {
			return;
		}

		$client = new Appsero\Client(
			$hash,
			'EpassCard (Google Wallet, Apple Wallet, and more)',
			EPC_PLUGIN_FILE
		);

		$client->insights()->init();
	}

	/**
	 * Load Appsero client class.
	 *
	 * @return bool
	 */
	private static function load_client() {
		if ( class_exists( 'Appsero\Client' ) ) {
			return true;
		}

		$vendor_client = EPC_PLUGIN_DIR . 'vendor/appsero/client/src/Client.php';
		if ( file_exists( $vendor_client ) ) {
			require_once $vendor_client;
		}

		if ( ! class_exists( 'Appsero\Client' ) ) {
			$fallback_client = EPC_PLUGIN_DIR . 'appsero/src/Client.php';
			if ( file_exists( $fallback_client ) ) {
				require_once $fallback_client;
			}
		}

		return class_exists( 'Appsero\Client' );
	}

	/**
	 * Appsero project hash from dashboard.appsero.com.
	 *
	 * @return string
	 */
	private static function get_project_hash() {
		$hash = 'd43e6289-9de5-4dbb-9cec-cf8cfb096395';

		/**
		 * Filter the Appsero project hash.
		 *
		 * @param string $hash Appsero project UUID.
		 */
		$hash = (string) apply_filters( 'epc_appsero_hash', $hash );

		$hash = strtolower( trim( $hash ) );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $hash ) ) {
			return '';
		}

		return $hash;
	}
}

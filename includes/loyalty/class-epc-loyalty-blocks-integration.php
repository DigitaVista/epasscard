<?php
/**
 * Cart and Checkout block integration for the loyalty points widget.
 *
 * Loaded from the WooCommerce Blocks registration hooks, when the interface exists.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Without WooCommerce Blocks the class is not declared, so class_exists() answers false
// instead of a fatal error (the class autoloader can load this file at any time).
if ( ! interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
	return;
}

/**
 * Registers the "Redeem loyalty points" widget with the Cart and Checkout blocks.
 *
 * The script renders a React component in the order summary (discounts slot), so it survives
 * every cart update instead of being rebuilt in the block's DOM.
 */
class EPC_Loyalty_Blocks_Integration implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {

	public const SCRIPT_HANDLE = 'epc-loyalty-checkout-blocks';
	public const STYLE_HANDLE  = 'epc-loyalty-checkout';

	/**
	 * Integration name. Script data is read in the browser as `<name>_data`.
	 *
	 * @return string
	 */
	public function get_name() {
		return EPC_Loyalty_Redemption_Service::STORE_NAMESPACE;
	}

	/**
	 * Register the script and style. They load only where a Cart or Checkout block renders.
	 *
	 * @return void
	 */
	public function initialize() {
		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::SCRIPT_HANDLE,
				EPC_PLUGIN_URL . 'assets/frontend/loyalty-checkout-blocks.js',
				array( 'wc-blocks-checkout', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-plugins' ),
				EPC_VERSION,
				true
			);
		}
		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_register_style( self::STYLE_HANDLE, EPC_PLUGIN_URL . 'assets/frontend/loyalty-checkout.css', array(), EPC_VERSION );
		}
		add_filter( 'render_block_woocommerce/cart', array( __CLASS__, 'enqueue_style' ) );
		add_filter( 'render_block_woocommerce/checkout', array( __CLASS__, 'enqueue_style' ) );
	}

	/**
	 * Load the widget style with a rendered Cart or Checkout block (also when a template holds it).
	 *
	 * @param string $content Block HTML.
	 * @return string
	 */
	public static function enqueue_style( $content ) {
		wp_enqueue_style( self::STYLE_HANDLE );
		return $content;
	}

	/**
	 * Front-end scripts (added as dependencies of the block's own script).
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( self::SCRIPT_HANDLE );
	}

	/**
	 * Editor scripts: none, the widget only shows to shoppers.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * Translated strings for the widget. The figures come from the cart's Store API data.
	 *
	 * @return array<string, mixed>
	 */
	public function get_script_data() {
		return array(
			'enabled'      => ! empty( EPC_Loyalty_Redemption_Service::get_settings()['enabled'] ),
			'couponPrefix' => EPC_Loyalty_Redemption_Service::COUPON_PREFIX,
			'i18n'         => self::strings(),
		);
	}

	/**
	 * Widget strings, translated on the server with the plugin's text domain.
	 *
	 * @return array<string, string>
	 */
	public static function strings() {
		return array(
			'title'       => __( 'Redeem loyalty points', 'epasscard' ),
			/* translators: %d: points the customer can still use. */
			'balance'     => __( 'Available points: %d', 'epasscard' ),
			/* translators: %d: points applied to the order. */
			'applied'     => __( '(%d applied to this order)', 'epasscard' ),
			'label'       => __( 'Points to redeem', 'epasscard' ),
			'apply'       => __( 'Apply points', 'epasscard' ),
			'remove'      => __( 'Remove', 'epasscard' ),
			/* translators: 1: points per currency unit, 2: currency code, 3: max points. */
			'hint'        => __( '%1$s points = 1 %2$s. Maximum for this order: %3$d points.', 'epasscard' ),
			'signIn'      => __( 'Sign in', 'epasscard' ),
			'guest'       => __( 'Sign in or create an account to redeem loyalty points.', 'epasscard' ),
			'done'        => __( 'Loyalty points updated.', 'epasscard' ),
			'error'       => __( 'Unable to update loyalty points.', 'epasscard' ),
			'couponLabel' => __( 'Loyalty points', 'epasscard' ),
		);
	}
}

<?php
/**
 * Integration test for v25: the loyalty widget registers with the Cart and Checkout blocks
 * (WooCommerce Blocks integration), with translated strings and its own script and style.
 * Needs WooCommerce with the loyalty integration on (skipped otherwise). The widget itself is
 * checked in a browser.
 *
 * Run: wp eval-file tests/integration/test-loyalty-blocks.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Loyalty_Redemption_Service' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-loyalty-blocks.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry' ) ) {
	echo "SKIP loyalty widget in the Cart and Checkout blocks (WooCommerce is not active here)\n";
	return;
}
if ( false === has_action( 'woocommerce_blocks_checkout_block_registration', array( 'EPC_Loyalty_Redemption_Service', 'register_blocks_integration' ) ) ) {
	echo "SKIP loyalty widget in the Cart and Checkout blocks (the loyalty integration is off here)\n";
	return;
}

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Loyalty_Blocks_Test_Counter {
	/**
	 * Checks passed.
	 *
	 * @var int
	 */
	public static $passed = 0;

	/**
	 * Checks failed.
	 *
	 * @var int
	 */
	public static $failed = 0;
}

/**
 * Record one check.
 *
 * @param string $label Check label.
 * @param bool   $ok    Result.
 * @param string $info  Extra detail on failure.
 * @return void
 */
function epc_lb_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Loyalty_Blocks_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Loyalty_Blocks_Test_Counter::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

$handle = 'epc-loyalty-checkout-blocks'; // EPC_Loyalty_Blocks_Integration::SCRIPT_HANDLE (the class loads with the first registry).

foreach ( array( 'cart', 'checkout' ) as $block ) {
	$registry = new \Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry();
	$registry->initialize( $block . '_block' );
	epc_lb_check( "$block block: the loyalty widget is registered", $registry->is_registered( 'epasscard-loyalty' ) );
	epc_lb_check( "$block block: its script loads with the block", in_array( $handle, $registry->get_all_registered_script_handles(), true ) );
	epc_lb_check( "$block block: nothing loads in the editor", array() === $registry->get_all_registered_editor_script_handles() );
	$data = $registry->get_all_registered_script_data();
	epc_lb_check( "$block block: settings reach the browser as epasscard-loyalty_data", isset( $data['epasscard-loyalty_data']['i18n']['title'] ) && 'epc-loyalty-' === $data['epasscard-loyalty_data']['couponPrefix'] );
}

$script = wp_scripts()->query( $handle, 'registered' );
epc_lb_check(
	'the script depends on the WooCommerce Blocks checkout API and wp.plugins, not jQuery',
	$script && in_array( 'wc-blocks-checkout', $script->deps, true ) && in_array( 'wp-plugins', $script->deps, true ) && ! in_array( 'jquery', $script->deps, true ),
	$script ? implode( ',', $script->deps ) : 'not registered'
);
epc_lb_check( 'the script file exists', is_readable( EPC_PLUGIN_DIR . 'assets/frontend/loyalty-checkout-blocks.js' ) );

// The strings are translated on the server with the plugin's text domain.
$translate = static function ( $translation, $text, $domain ) {
	return 'epasscard' === $domain && 'Redeem loyalty points' === $text ? 'Treuepunkte einlösen' : $translation;
};
add_filter( 'gettext', $translate, 10, 3 );
$strings = EPC_Loyalty_Blocks_Integration::strings();
remove_filter( 'gettext', $translate, 10 );
epc_lb_check( 'widget strings are translatable', 'Treuepunkte einlösen' === $strings['title'] );
$missing = array();
foreach ( array( 'title', 'balance', 'applied', 'label', 'apply', 'remove', 'hint', 'signIn', 'guest', 'done', 'error', 'couponLabel' ) as $key ) {
	if ( empty( $strings[ $key ] ) ) {
		$missing[] = $key;
	}
}
epc_lb_check( 'every string the script reads is provided', array() === $missing, implode( ',', $missing ) );

// The style loads with a rendered Cart or Checkout block, wherever the block sits.
wp_dequeue_style( EPC_Loyalty_Blocks_Integration::STYLE_HANDLE );
apply_filters( 'render_block_woocommerce/checkout', '<div></div>', array() );
epc_lb_check( 'a rendered Checkout block loads the widget style', wp_style_is( EPC_Loyalty_Blocks_Integration::STYLE_HANDLE, 'enqueued' ) );

// Registering twice in one registry is a no-op (no "already registered" notice).
$registry = new \Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry();
$registry->initialize( 'checkout_block' );
$notices = array();
$catch   = static function ( $function_name ) use ( &$notices ) {
	$notices[] = $function_name;
};
add_action( 'doing_it_wrong_run', $catch );
EPC_Loyalty_Redemption_Service::register_blocks_integration( $registry );
remove_action( 'doing_it_wrong_run', $catch );
epc_lb_check( 'a second registration is skipped quietly', array() === $notices, implode( ',', $notices ) );

echo EPC_Loyalty_Blocks_Test_Counter::$passed, ' passed, ', EPC_Loyalty_Blocks_Test_Counter::$failed, " failed\n";
if ( EPC_Loyalty_Blocks_Test_Counter::$failed > 0 ) {
	exit( 1 );
}

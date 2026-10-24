<?php
/**
 * Integration test for v14: a customer with no points can get their loyalty card from My Account.
 * Needs WooCommerce, the loyalty integration on, a loyalty card design and a connection (skipped
 * otherwise). The fake API from fixtures/test-integration.php answers the create call.
 *
 * Run: wp eval-file tests/integration/test-loyalty-get-card.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Loyalty_Frontend' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-loyalty-get-card.php (EpassCard active)\n" );
	exit( 1 );
}

require_once __DIR__ . '/fixtures/test-integration.php';

$epc_loyalty = function_exists( 'epc_plugin' ) ? epc_plugin()->get_module( 'woocommerce-loyalty' ) : null;
if ( ! function_exists( 'wc_get_account_endpoint_url' ) || ! $epc_loyalty instanceof EPC_Module || ! $epc_loyalty->is_available() || ! EPC_Module_Settings::is_enabled( 'woocommerce-loyalty' ) || empty( $epc_loyalty->get_mapping( 1 )['template_uid'] ) || ! EPC_Api_Client::is_configured() ) {
	echo "SKIP loyalty card without points (needs WooCommerce, the loyalty integration on, a design and a connection)\n";
	return;
}

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Get_Card_Test_Counter {
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
function epc_gc_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Get_Card_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Get_Card_Test_Counter::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * The customer's My Account loyalty page.
 *
 * @return string
 */
function epc_gc_wallet() {
	ob_start();
	EPC_Loyalty_Frontend::render_wallet();
	return (string) ob_get_clean();
}

$login    = 'epc_t_card_' . wp_generate_password( 8, false, false );
$customer = wp_insert_user(
	array(
		'user_login' => $login,
		'user_pass'  => wp_generate_password(),
		'user_email' => $login . '@example.test',
		'role'       => 'customer',
	)
);
wp_set_current_user( $customer );

$html = epc_gc_wallet();
epc_gc_check( 'a customer without points is offered the card', EPC_Loyalty_Frontend::can_get_card( $customer ) && false !== strpos( $html, 'name="action" value="epc_loyalty_get_card"' ) );

$_POST    = array( '_wpnonce' => wp_create_nonce( 'epc_loyalty_get_card' ) );
$_REQUEST = $_POST;
$to       = '';
$stop     = static function ( $location ) use ( &$to ) {
	$to = (string) $location;
	throw new RuntimeException( 'redirect' );
};
add_filter( 'wp_redirect', $stop, 1 );
try {
	EPC_Loyalty_Frontend::handle_get_card();
} catch ( RuntimeException $e ) {
	unset( $e ); // Expected: back to My Account.
}
remove_filter( 'wp_redirect', $stop, 1 );
$_POST    = array();
$_REQUEST = array();

$row = EPC_DB::get_pass( 'woocommerce-loyalty', $customer );
epc_gc_check( 'the card is created', $row && '' !== (string) $row->pass_uid && '' !== (string) $row->pass_link && 0 === (int) $row->is_test );
epc_gc_check( 'back to My Account with "ready"', false !== strpos( $to, 'epc_card=ready' ), $to );
$html = epc_gc_wallet();
epc_gc_check( 'the page now shows Add to wallet, not the button', false !== strpos( $html, 'Add loyalty pass to wallet' ) && false === strpos( $html, 'epc_loyalty_get_card' ) );
epc_gc_check( 'no second offer', ! EPC_Loyalty_Frontend::can_get_card( $customer ) );

add_filter( 'epc_loyalty_offer_card_without_points', '__return_false' );
$other = wp_insert_user(
	array(
		'user_login' => $login . 'b',
		'user_pass'  => wp_generate_password(),
		'user_email' => $login . 'b@example.test',
		'role'       => 'customer',
	)
);
epc_gc_check( 'the filter can turn the offer off', ! EPC_Loyalty_Frontend::can_get_card( $other ) );
remove_filter( 'epc_loyalty_offer_card_without_points', '__return_false' );
epc_gc_check( 'nobody signed in: no offer', ! EPC_Loyalty_Frontend::can_get_card( 0 ) );

// Clean up.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE module = %s AND source_id IN (%s, %s)', EPC_DB::table_name(), 'woocommerce-loyalty', (string) $customer, (string) $other ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id IN (%d, %d)', EPC_DB::loyalty_accounts_table_name(), $customer, $other ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $customer );
wp_delete_user( $other );

echo EPC_Get_Card_Test_Counter::$passed, ' passed, ', EPC_Get_Card_Test_Counter::$failed, " failed\n";
if ( EPC_Get_Card_Test_Counter::$failed > 0 ) {
	exit( 1 );
}

<?php
/**
 * Integration test: the points a customer can use at checkout are not lost to rounding.
 *
 * Before the fix, 115 points at the default 100 points = 1 allowed at most 114, and asking for
 * 115 applied 113: the balance was turned into money (rounded to cents) and back.
 *
 * Run: wp eval-file tests/integration/test-loyalty-redemption-rounding.php (needs WooCommerce).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Loyalty_Redemption_Service' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-loyalty-redemption-rounding.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! function_exists( 'wc_load_cart' ) ) {
	echo "SKIP loyalty redemption rounding (WooCommerce is not active here)\n";
	return;
}

/**
 * Results (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Redemption_Rounding_Test {
	/**
	 * Passed / failed.
	 *
	 * @var array<string, int>
	 */
	public static $result = array(
		'passed' => 0,
		'failed' => 0,
	);

	/**
	 * Record a check.
	 *
	 * @param string $label Label.
	 * @param bool   $ok    Result.
	 * @param string $info  Shown on failure.
	 * @return void
	 */
	public static function check( $label, $ok, $info = '' ) {
		echo $ok ? 'PASS ' : 'FAIL ', $label, $ok || '' === $info ? '' : ' -- ' . $info, "\n";
		++self::$result[ $ok ? 'passed' : 'failed' ];
	}

	/**
	 * Quote with these redemption settings.
	 *
	 * @param int                  $user_id   User.
	 * @param int                  $requested Points asked for.
	 * @param array<string, mixed> $settings  Settings to use.
	 * @return array<string, mixed>
	 */
	public static function quote( $user_id, $requested, array $settings ) {
		update_option(
			EPC_Loyalty_Redemption_Service::OPTION_KEY,
			array_merge(
				array(
					'enabled'           => 1,
					'minimum_balance'   => 0,
					'increment'         => 1,
					'max_order_percent' => 100,
				),
				$settings
			)
		);

		$quote = EPC_Loyalty_Redemption_Service::build_quote( $user_id, $requested );

		return is_array( $quote ) ? $quote : array();
	}
}

$saved_settings = get_option( EPC_Loyalty_Redemption_Service::OPTION_KEY, null );
$saved_user     = get_current_user_id();

$login   = 'epc_rq_' . wp_generate_password( 8, false, false );
$user_id = (int) wp_insert_user(
	array(
		'user_login' => $login,
		'user_pass'  => wp_generate_password(),
		'user_email' => $login . '@example.test',
		'role'       => 'customer',
	)
);
EPC_Loyalty_Ledger_Service::record( $user_id, 'epc-rq-seed:' . $user_id, 'manual_adjustment', 115, 115 );
wp_set_current_user( $user_id );

wc_load_cart();
$product = new WC_Product_Simple();
$product->set_name( 'Rounding test item' );
$product->set_regular_price( '10' );
$product->save();
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $product->get_id(), 1 );
WC()->cart->calculate_totals();

// Default rate (100 points = 1): all 115 points can be used, for 1.15 off.
$q = EPC_Redemption_Rounding_Test::quote( $user_id, 115, array() );
EPC_Redemption_Rounding_Test::check( '100 points = 1: maximum is the whole balance (115)', 115 === (int) ( $q['max_points'] ?? -1 ), wp_json_encode( $q ) );
EPC_Redemption_Rounding_Test::check( '100 points = 1: asking for 115 applies 115 for 1.15 off', 115 === (int) ( $q['applied_points'] ?? -1 ) && '1.15' === (string) ( $q['discount'] ?? '' ), wp_json_encode( $q ) );

// A rate where 115 points is not a whole number of cents.
$q = EPC_Redemption_Rounding_Test::quote( $user_id, 115, array( 'points_per_currency' => 700 ) );
EPC_Redemption_Rounding_Test::check( '700 points = 1: maximum is the whole balance (115)', 115 === (int) ( $q['max_points'] ?? -1 ), wp_json_encode( $q ) );
EPC_Redemption_Rounding_Test::check( '700 points = 1: 115 applied for 0.16 off', 115 === (int) ( $q['applied_points'] ?? -1 ) && '0.16' === (string) ( $q['discount'] ?? '' ), wp_json_encode( $q ) );

// The order limit still caps: 3 points = 1, 10.00 cart, 50% max -> 15 points for 5.00.
$q = EPC_Redemption_Rounding_Test::quote(
	$user_id,
	115,
	array(
		'points_per_currency' => 3,
		'max_order_percent'   => 50,
	)
);
EPC_Redemption_Rounding_Test::check( 'order limit: 50% of 10.00 at 3 points = 1 is 15 points', 15 === (int) ( $q['max_points'] ?? -1 ) && 15 === (int) ( $q['applied_points'] ?? -1 ) && '5.00' === (string) ( $q['discount'] ?? '' ), wp_json_encode( $q ) );

// The discount never passes the order limit, even when cents round up.
$q = EPC_Redemption_Rounding_Test::quote(
	$user_id,
	115,
	array(
		'points_per_currency' => 7,
		'max_order_percent'   => 33,
	)
);
EPC_Redemption_Rounding_Test::check( 'order limit: discount stays within 33% of 10.00', isset( $q['discount'] ) && (float) $q['discount'] <= 3.30 && 23 === (int) $q['applied_points'], wp_json_encode( $q ) );

// Increment still applies: steps of 10 -> 110 of 115.
$q = EPC_Redemption_Rounding_Test::quote( $user_id, 115, array( 'increment' => 10 ) );
EPC_Redemption_Rounding_Test::check( 'steps of 10: 110 of 115 points', 110 === (int) ( $q['max_points'] ?? -1 ) && 110 === (int) ( $q['applied_points'] ?? -1 ) && '1.10' === (string) ( $q['discount'] ?? '' ), wp_json_encode( $q ) );

// Asking for less applies exactly that.
$q = EPC_Redemption_Rounding_Test::quote( $user_id, 37, array() );
EPC_Redemption_Rounding_Test::check( 'asking for 37 applies 37 for 0.37 off', 37 === (int) ( $q['applied_points'] ?? -1 ) && '0.37' === (string) ( $q['discount'] ?? '' ), wp_json_encode( $q ) );

// Clean up.
WC()->cart->empty_cart();
wp_delete_post( $product->get_id(), true );
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
$wpdb->delete( EPC_DB::loyalty_ledger_table_name(), array( 'user_id' => $user_id ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
$wpdb->delete( EPC_DB::loyalty_accounts_table_name(), array( 'user_id' => $user_id ) );
// The balance change queued a loyalty card for this customer; it would fail once the user is gone.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'epc_loyalty_sync_pass', array( $user_id ), 'epasscard-loyalty' );
}
EPC_Pass_Issues::clear( 'woocommerce-loyalty', (string) $user_id );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $user_id );
wp_set_current_user( $saved_user );
if ( null === $saved_settings ) {
	delete_option( EPC_Loyalty_Redemption_Service::OPTION_KEY );
} else {
	update_option( EPC_Loyalty_Redemption_Service::OPTION_KEY, $saved_settings );
}

echo EPC_Redemption_Rounding_Test::$result['passed'], ' passed, ', EPC_Redemption_Rounding_Test::$result['failed'], " failed\n";
if ( EPC_Redemption_Rounding_Test::$result['failed'] > 0 ) {
	exit( 1 );
}

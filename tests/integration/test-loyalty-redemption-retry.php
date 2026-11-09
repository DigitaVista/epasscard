<?php
/**
 * Integration test: points paid at checkout stay paid when a payment fails and the customer
 * tries again, on the pay-for-order page or by checking out again (WooCommerce resumes the
 * same pending or failed order).
 *
 * Run: wp eval-file tests/integration/test-loyalty-redemption-retry.php (needs WooCommerce).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Loyalty_Redemption_Service' ) || ! function_exists( 'wc_create_order' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-loyalty-redemption-retry.php (EpassCard + WooCommerce active)\n" );
	exit( 1 );
}

/**
 * Results and the records to clean up (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Redemption_Retry_Test {
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
	 * Created users, orders, products.
	 *
	 * @var array<string, array<int, int>>
	 */
	public static $made = array(
		'users'    => array(),
		'orders'   => array(),
		'products' => array(),
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
	 * A customer with $points points.
	 *
	 * @param int $points Points.
	 * @return int
	 */
	public static function customer( $points ) {
		$login = 'epc_rr_' . wp_generate_password( 8, false, false );
		$id    = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password(),
				'user_email' => $login . '@example.test',
				'role'       => 'customer',
			)
		);
		self::$made['users'][] = (int) $id;
		EPC_Loyalty_Ledger_Service::record( (int) $id, 'epc-rr-seed:' . $id, 'manual_adjustment', $points, $points );
		return (int) $id;
	}

	/**
	 * Points the customer has, counting only the seed and redemptions (no order awards).
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function points( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test lookup.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points_delta), 0) FROM %i WHERE user_id = %d AND ( entry_type LIKE 'redemption%%' OR event_key LIKE 'epc-rr-seed:%%' )",
				EPC_DB::loyalty_ledger_table_name(),
				$user_id
			)
		);
	}

	/**
	 * A pending order for the customer.
	 *
	 * @param int $user_id User.
	 * @return WC_Order
	 */
	public static function order( $user_id ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Retry test item' );
		$product->set_regular_price( '10' );
		$product->save();
		self::$made['products'][] = $product->get_id();
		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();
		self::$made['orders'][] = $order->get_id();
		return $order;
	}

	/**
	 * What checkout does: the quote on the order (attach_order_meta), then the processed hook.
	 *
	 * @param int $order_id Order id.
	 * @param int $points   Points used at this checkout.
	 * @return void
	 */
	public static function checkout( $order_id, $points ) {
		$order = wc_get_order( $order_id );
		$order->update_meta_data( '_epc_loyalty_points_redeemed', $points );
		$order->update_meta_data( '_epc_loyalty_redemption_amount', number_format( $points / 100, 2, '.', '' ) );
		$order->update_meta_data( '_epc_loyalty_redemption_status', 'pending' );
		$order->save();
		EPC_Loyalty_Redemption_Service::reserve_points( wc_get_order( $order_id ) );
	}

	/**
	 * Fail the order's payment.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function fail( $order_id ) {
		wc_get_order( $order_id )->update_status( 'failed' );
	}

	/**
	 * The payment goes through.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function pay( $order_id ) {
		wc_get_order( $order_id )->payment_complete( 'epc-rr-' . $order_id );
	}

	/**
	 * Redemption state on the order.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	public static function state( $order_id ) {
		return (string) wc_get_order( $order_id )->get_meta( '_epc_loyalty_redemption_status', true );
	}
}

$t = 'EPC_Redemption_Retry_Test';

// A. Payment fails, the customer pays the same order again (pay-for-order page).
$u = $t::customer( 165 );
$o = $t::order( $u )->get_id();
$t::checkout( $o, 100 );
$t::check( 'A: 100 of 165 points used at checkout -> 65', 65 === $t::points( $u ), (string) $t::points( $u ) );
$t::fail( $o );
$t::check( 'A: payment failed -> points back, 165', 165 === $t::points( $u ), (string) $t::points( $u ) );
$t::pay( $o );
$t::check( 'A: paid on the retry -> 65, committed', 65 === $t::points( $u ) && 'committed' === $t::state( $o ), $t::points( $u ) . ' ' . $t::state( $o ) );

// B. Payment fails, the customer checks out again with the same points (the reported bug: 165).
$u = $t::customer( 165 );
$o = $t::order( $u )->get_id();
$t::checkout( $o, 100 );
$t::fail( $o );
$t::checkout( $o, 100 );
$t::check( 'B: checkout again on the failed order -> points taken again, 65', 65 === $t::points( $u ), (string) $t::points( $u ) );
$t::check( 'B: the order is pending payment again (a new failure is a status change)', wc_get_order( $o )->has_status( 'pending' ), wc_get_order( $o )->get_status() );
$t::pay( $o );
$t::check( 'B: paid -> still 65, committed', 65 === $t::points( $u ) && 'committed' === $t::state( $o ), $t::points( $u ) . ' ' . $t::state( $o ) );

// C. Checks out again with fewer points.
$u = $t::customer( 165 );
$o = $t::order( $u )->get_id();
$t::checkout( $o, 100 );
$t::fail( $o );
$t::checkout( $o, 50 );
$t::pay( $o );
$t::check( 'C: second checkout uses 50 -> 115', 115 === $t::points( $u ), (string) $t::points( $u ) );

// D. Checks out again with more points.
$u = $t::customer( 165 );
$o = $t::order( $u )->get_id();
$t::checkout( $o, 100 );
$t::fail( $o );
$t::checkout( $o, 150 );
$t::pay( $o );
$t::check( 'D: second checkout uses 150 -> 15', 15 === $t::points( $u ), (string) $t::points( $u ) );

// E. The payment did not go through (order still pending, points held); checkout again with
// fewer, then with none.
$u = $t::customer( 165 );
$o = $t::order( $u )->get_id();
$t::checkout( $o, 100 );
$t::checkout( $o, 40 );
$t::check( 'E: pending order, checkout again with 40 -> 125 (60 given back)', 125 === $t::points( $u ), (string) $t::points( $u ) );
EPC_Loyalty_Redemption_Service::attach_order_meta( wc_get_order( $o ) );
$t::check( 'E: checkout again without points -> all back, 165', 165 === $t::points( $u ) && 0 === EPC_Loyalty_Redemption_Service::get_held_points_for_order( $o ), (string) $t::points( $u ) );

// F. Fails twice, then paid.
$u = $t::customer( 165 );
$o = $t::order( $u )->get_id();
$t::checkout( $o, 100 );
$t::fail( $o );
$t::checkout( $o, 100 );
$t::fail( $o );
$t::check( 'F: failed twice -> 165', 165 === $t::points( $u ), (string) $t::points( $u ) );
$t::checkout( $o, 100 );
$t::pay( $o );
$t::check( 'F: third try paid -> 65', 65 === $t::points( $u ), (string) $t::points( $u ) );

// G. Not enough points left for the second checkout (spent elsewhere meanwhile): no free discount.
$u = $t::customer( 165 );
$o = $t::order( $u )->get_id();
$t::checkout( $o, 100 );
$t::fail( $o );
EPC_Loyalty_Ledger_Service::record( $u, 'epc-rr-seed:spend:' . $u, 'manual_adjustment', -120, 0 );
$o2 = wc_get_order( $o );
$o2->update_meta_data( '_epc_loyalty_points_redeemed', 100 );
$o2->update_meta_data( '_epc_loyalty_redemption_amount', '1.00' );
$o2->update_meta_data( '_epc_loyalty_redemption_status', 'pending' );
$o2->save();
$res = EPC_Loyalty_Redemption_Service::reserve_points( wc_get_order( $o ) );
$t::check( 'G: only 45 left -> checkout refused (error), nothing taken', is_wp_error( $res ) && 45 === $t::points( $u ), is_wp_error( $res ) ? (string) $t::points( $u ) : 'no error' );

// H. Cancelled after a resumed checkout: all held points back.
$u = $t::customer( 165 );
$o = $t::order( $u )->get_id();
$t::checkout( $o, 100 );
$t::fail( $o );
$t::checkout( $o, 150 );
wc_get_order( $o )->update_status( 'cancelled' );
$t::check( 'H: cancelled -> 165', 165 === $t::points( $u ) && 0 === EPC_Loyalty_Redemption_Service::get_held_points_for_order( $o ), (string) $t::points( $u ) );

// I. Paid normally, then fully refunded (policy "full"): points back once.
$u = $t::customer( 165 );
$o = $t::order( $u )->get_id();
$t::checkout( $o, 100 );
$ord = wc_get_order( $o );
$ord->update_meta_data( '_epc_loyalty_redemption_settings', array( 'points_per_currency' => 100, 'refund_restore_policy' => 'full' ) );
$ord->save();
$t::pay( $o );
$refund = wc_create_refund(
	array(
		'order_id' => $o,
		'amount'   => wc_get_order( $o )->get_total(),
	)
);
$t::check( 'I: full refund -> 165, nothing held', ! is_wp_error( $refund ) && 165 === $t::points( $u ) && 0 === EPC_Loyalty_Redemption_Service::get_held_points_for_order( $o ), is_wp_error( $refund ) ? $refund->get_error_message() : (string) $t::points( $u ) );

// Clean up.
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;
foreach ( $t::$made['orders'] as $id ) {
	$order = wc_get_order( $id );
	if ( $order ) {
		foreach ( $order->get_refunds() as $r ) {
			$r->delete( true );
		}
		$order->delete( true );
	}
}
foreach ( $t::$made['products'] as $id ) {
	wp_delete_post( $id, true );
}
foreach ( $t::$made['users'] as $id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
	$wpdb->delete( EPC_DB::loyalty_ledger_table_name(), array( 'user_id' => $id ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
	$wpdb->delete( EPC_DB::loyalty_accounts_table_name(), array( 'user_id' => $id ) );
	wp_delete_user( $id );
}

echo $t::$result['passed'], ' passed, ', $t::$result['failed'], " failed\n";
if ( $t::$result['failed'] > 0 ) {
	exit( 1 );
}

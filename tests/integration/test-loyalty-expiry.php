<?php
/**
 * Integration test: loyalty point expiry takes only what is left of the expiring award.
 *
 * Before the fix, when points that were already spent expired, the expiry took points earned
 * later; and a refund after the expiry took the order's points a second time (balance below 0).
 *
 * Run: wp eval-file tests/integration/test-loyalty-expiry.php
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Loyalty_Reward_Service' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-loyalty-expiry.php (EpassCard active)\n" );
	exit( 1 );
}

/**
 * Results and customers (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Loyalty_Expiry_Test {
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
	 * Customers made by this test.
	 *
	 * @var array<int, int>
	 */
	public static $users = array();

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
	 * A customer.
	 *
	 * @return int
	 */
	public static function customer() {
		$login = 'epc_ex_' . strtolower( wp_generate_password( 8, false, false ) );
		$id    = (int) wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password(),
				'user_email' => $login . '@example.test',
				'role'       => 'customer',
			)
		);
		self::$users[] = $id;
		return $id;
	}

	/**
	 * Add a ledger entry.
	 *
	 * @param int                  $user_id User.
	 * @param string               $type    Entry type.
	 * @param int                  $points  Points.
	 * @param array<string, mixed> $context Context.
	 * @return int Entry id.
	 */
	public static function entry( $user_id, $type, $points, array $context = array() ) {
		$result = EPC_Loyalty_Ledger_Service::record( $user_id, 'epc-ex:' . wp_generate_password( 12, false, false ), $type, $points, max( 0, $points ), $context );
		return is_array( $result ) ? (int) $result['entry']->id : 0;
	}

	/**
	 * Balance.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function balance( $user_id ) {
		$account = EPC_Loyalty_Account_Service::get( $user_id );
		return $account ? (int) $account->points_balance : 0;
	}
}

global $wpdb;
$t       = 'EPC_Loyalty_Expiry_Test';
$ledger  = EPC_DB::loyalty_ledger_table_name();
$past    = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
$future  = gmdate( 'Y-m-d H:i:s', time() + 300 * DAY_IN_SECONDS );
$expired = array( 'expires_at' => $past );

// 1. 100 earned (now expired), 100 spent, 100 earned later: nothing left to expire.
$a     = $t::customer();
$a_old = $t::entry( $a, 'order_award', 100, $expired );
$t::entry( $a, 'manual_adjustment', -100 );
$t::entry( $a, 'order_award', 100, array( 'expires_at' => $future ) );
$t::check( 'spent before it expired: nothing of it is left', 0 === EPC_Loyalty_Reward_Service::get_unspent_award_points( $a, $a_old ) );

// 2. 100 earned (now expired), 30 spent: 70 left to expire.
$b     = $t::customer();
$b_old = $t::entry( $b, 'order_award', 100, $expired );
$t::entry( $b, 'manual_adjustment', -30 );
$t::entry( $b, 'milestone_bonus', 50 );
$t::check( 'partly spent: 70 of 100 left', 70 === EPC_Loyalty_Reward_Service::get_unspent_award_points( $b, $b_old ) );

// 3. A checkout hold released (payment failed): the points are back on the award.
$c     = $t::customer();
$c_old = $t::entry( $c, 'order_award', 100, $expired );
$t::entry( $c, 'redemption_reservation', -100 );
$t::entry( $c, 'redemption_release', 100 );
$t::check( 'hold released: all 100 left', 100 === EPC_Loyalty_Reward_Service::get_unspent_award_points( $c, $c_old ) );

// 4. Spending uses the points that expire soonest: a later award with an earlier expiry goes first.
$d      = $t::customer();
$d_late = $t::entry( $d, 'order_award', 100, array( 'expires_at' => $future ) );
$d_soon = $t::entry( $d, 'order_award', 100, $expired );
$t::entry( $d, 'manual_adjustment', -100 );
$t::check( 'the soonest to expire is spent first', 0 === EPC_Loyalty_Reward_Service::get_unspent_award_points( $d, $d_soon ) && 100 === EPC_Loyalty_Reward_Service::get_unspent_award_points( $d, $d_late ) );

// 5. A deficit (balance below 0) is paid from the next points earned.
$e     = $t::customer();
$t::entry( $e, 'manual_adjustment', 20 );
$t::entry( $e, 'refund_reversal', -50 );
$e_new = $t::entry( $e, 'order_award', 100, $expired );
$t::check( 'a deficit of 30 is paid from the next award: 70 left', 70 === EPC_Loyalty_Reward_Service::get_unspent_award_points( $e, $e_new ) );

// The daily job, for these customers only when no other customer has points waiting to expire.
$ids = implode( ',', array_map( 'absint', $t::$users ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test: ids are integers.
$others = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i l LEFT JOIN %i x ON x.event_key = CONCAT('point_expiry:', l.id) WHERE l.points_delta > 0 AND l.expires_at IS NOT NULL AND l.expires_at <= %s AND x.id IS NULL AND l.user_id NOT IN ($ids)", $ledger, $ledger, current_time( 'mysql', true ) ) );
if ( $others > 0 ) {
	echo "SKIP the daily expiry job (other customers on this site have points waiting to expire)\n";
} else {
	EPC_Loyalty_Reward_Service::process_expiry();
	$t::check( 'job: spent points expire as 0, the later 100 stay', 100 === $t::balance( $a ), (string) $t::balance( $a ) );
	$t::check( 'job: partly spent, 70 expire (120 -> 50)', 50 === $t::balance( $b ), (string) $t::balance( $b ) );
	$t::check( 'job: released hold, all 100 expire', 0 === $t::balance( $c ), (string) $t::balance( $c ) );

	// A refund after the points expired does not take them again.
	if ( function_exists( 'wc_create_order' ) ) {
		$f       = $t::customer();
		$product = new WC_Product_Simple();
		$product->set_name( 'Expiry test item' );
		$product->set_regular_price( '10' );
		$product->save();
		$order = wc_create_order( array( 'customer_id' => $f ) );
		$item  = $order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order->save();
		EPC_Loyalty_Ledger_Service::record(
			$f,
			'order_award:' . $order->get_id(),
			'order_award',
			100,
			100,
			array(
				'order_id'   => $order->get_id(),
				'amount'     => 10,
				'expires_at' => $past,
			)
		);
		EPC_Loyalty_Reward_Service::process_expiry();
		$t::check( 'order points expired: balance 0', 0 === $t::balance( $f ), (string) $t::balance( $f ) );
		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => 10,
				'line_items' => array(
					$item => array(
						'qty'          => 1,
						'refund_total' => 10,
					),
				),
			)
		);
		EPC_Loyalty_Order_Service::on_order_refunded( $order->get_id(), $refund->get_id() );
		$t::check( 'refund after the expiry: balance stays 0, not -100', 0 === $t::balance( $f ), (string) $t::balance( $f ) );
		foreach ( wc_get_order( $order->get_id() )->get_refunds() as $r ) {
			$r->delete( true );
		}
		wc_get_order( $order->get_id() )->delete( true );
		wp_delete_post( $product->get_id(), true );
	}
}

// Clean up.
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $t::$users as $user_id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
	$wpdb->delete( $ledger, array( 'user_id' => $user_id ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
	$wpdb->delete( EPC_DB::loyalty_accounts_table_name(), array( 'user_id' => $user_id ) );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'epc_loyalty_sync_pass', array( $user_id ), 'epasscard-loyalty' );
	}
	EPC_Pass_Issues::clear( 'woocommerce-loyalty', (string) $user_id );
	wp_delete_user( $user_id );
}

echo $t::$result['passed'], ' passed, ', $t::$result['failed'], " failed\n";
if ( $t::$result['failed'] > 0 ) {
	exit( 1 );
}

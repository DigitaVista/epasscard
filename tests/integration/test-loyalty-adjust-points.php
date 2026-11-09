<?php
/**
 * Integration test: a manual points adjustment cannot take a customer's balance below zero.
 *
 * Before the fix, deducting 435 from a customer with 100 points left them at -335.
 *
 * Run: wp eval-file tests/integration/test-loyalty-adjust-points.php
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Loyalty_Customer_Service' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-loyalty-adjust-points.php (EpassCard active)\n" );
	exit( 1 );
}

/**
 * Results (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Adjust_Points_Test {
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
	 * Current balance.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function balance( $user_id ) {
		$account = EPC_Loyalty_Account_Service::get( $user_id );

		return $account ? (int) $account->points_balance : 0;
	}
}

$saved_user = get_current_user_id();
$admins     = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( $admins ? (int) $admins[0] : 0 );

$login   = 'epc_ap_' . wp_generate_password( 8, false, false );
$user_id = (int) wp_insert_user(
	array(
		'user_login' => $login,
		'user_pass'  => wp_generate_password(),
		'user_email' => $login . '@example.test',
		'role'       => 'customer',
	)
);

$t = 'EPC_Adjust_Points_Test';

$added = EPC_Loyalty_Customer_Service::adjust_points( $user_id, 100, 'Welcome gift' );
$t::check( 'adding 100 works', ! is_wp_error( $added ) && 100 === $t::balance( $user_id ), is_wp_error( $added ) ? $added->get_error_message() : (string) $t::balance( $user_id ) );

$too_much = EPC_Loyalty_Customer_Service::adjust_points( $user_id, -435, 'Correction' );
$t::check( 'deducting 435 from 100 is refused', is_wp_error( $too_much ) && 'epc_loyalty_adjust_more_than_balance' === $too_much->get_error_code(), is_wp_error( $too_much ) ? $too_much->get_error_code() : 'balance now ' . $t::balance( $user_id ) );
$t::check( 'the message says how many can be deducted', is_wp_error( $too_much ) && false !== strpos( $too_much->get_error_message(), '100' ), is_wp_error( $too_much ) ? $too_much->get_error_message() : '' );
$t::check( 'the balance stays 100', 100 === $t::balance( $user_id ), (string) $t::balance( $user_id ) );

$partial = EPC_Loyalty_Customer_Service::adjust_points( $user_id, -40, 'Correction' );
$t::check( 'deducting 40 works: 60 left', ! is_wp_error( $partial ) && 60 === $t::balance( $user_id ), (string) $t::balance( $user_id ) );

$all = EPC_Loyalty_Customer_Service::adjust_points( $user_id, -60, 'Correction' );
$t::check( 'deducting the whole balance works: 0 left', ! is_wp_error( $all ) && 0 === $t::balance( $user_id ), (string) $t::balance( $user_id ) );

$below = EPC_Loyalty_Customer_Service::adjust_points( $user_id, -1, 'Correction' );
$t::check( 'deducting from 0 is refused', is_wp_error( $below ) && 0 === $t::balance( $user_id ), (string) $t::balance( $user_id ) );

// Clean up.
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

echo $t::$result['passed'], ' passed, ', $t::$result['failed'], " failed\n";
if ( $t::$result['failed'] > 0 ) {
	exit( 1 );
}

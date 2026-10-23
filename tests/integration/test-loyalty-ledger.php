<?php
/**
 * Integration tests for EPC_Loyalty_Ledger_Service::record() on a real WordPress + MySQL.
 *
 * Run: wp eval-file tests/integration/test-loyalty-ledger.php
 * (tests/integration/run.sh does this). Prints PASS/FAIL lines; exits 1 on any failure.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Loyalty_Ledger_Service' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-loyalty-ledger.php (EpassCard active)\n" );
	exit( 1 );
}

/**
 * Pass/fail counter. A class, because wp eval-file runs this file inside a function,
 * so plain globals declared here would not be global.
 */
final class EPC_Ledger_Test_Counter {
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
function epc_test_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Ledger_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Ledger_Test_Counter::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * New customer for one test.
 *
 * @return int User id.
 */
function epc_test_user() {
	$login = 'epc_ledger_' . wp_generate_password( 8, false, false );
	$id    = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password(),
			'user_email' => $login . '@example.test',
			'role'       => 'subscriber',
		)
	);
	if ( is_wp_error( $id ) ) {
		echo 'FAIL could not create test user: ', esc_html( $id->get_error_message() ), "\n";
		exit( 1 );
	}
	return (int) $id;
}

/**
 * Current balance and lifetime points.
 *
 * @param int $user_id User id.
 * @return array{0: int, 1: int}
 */
function epc_test_balance( $user_id ) {
	$account = EPC_Loyalty_Account_Service::get( $user_id );
	return $account ? array( (int) $account->points_balance, (int) $account->lifetime_points ) : array( 0, 0 );
}

/**
 * Number of ledger rows for a user.
 *
 * @param int $user_id User id.
 * @return int
 */
function epc_test_rows( $user_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', EPC_DB::loyalty_ledger_table_name(), $user_id ) );
}

$epc_events = 0;
add_action(
	'epc_loyalty_balance_changed',
	static function () use ( &$epc_events ) {
		++$epc_events;
	}
);
$epc_run = 'run' . wp_generate_password( 6, false, false ) . ':';
// Reversal totals are summed per order id across customers: use order ids unique to this run.
$epc_o = wp_rand( 100000, 900000000 );

// 1. Award updates balance and lifetime, and fires the hook once.
$u = epc_test_user();
$r = EPC_Loyalty_Ledger_Service::record( $u, $epc_run . 'award:1', 'order_award', 100, 100, array( 'order_id' => $epc_o + 1 ) );
epc_test_check( 'award: created', is_array( $r ) && true === $r['created'] );
epc_test_check( 'award: balance 100, lifetime 100', array( 100, 100 ) === epc_test_balance( $u ), wp_json_encode( epc_test_balance( $u ) ) );
epc_test_check( 'award: balance_changed fired once', 1 === $epc_events, (string) $epc_events );

// 2. Same event key again: nothing changes.
$r2 = EPC_Loyalty_Ledger_Service::record( $u, $epc_run . 'award:1', 'order_award', 100, 100, array( 'order_id' => $epc_o + 1 ) );
epc_test_check( 'idempotent: created=false', is_array( $r2 ) && false === $r2['created'] );
epc_test_check( 'idempotent: balance still 100', array( 100, 100 ) === epc_test_balance( $u ) );
epc_test_check( 'idempotent: still one ledger row', 1 === epc_test_rows( $u ) );
epc_test_check( 'idempotent: hook not fired again', 1 === $epc_events );

// 3. Event key owned by another customer is refused.
$other = epc_test_user();
$r3    = EPC_Loyalty_Ledger_Service::record( $other, $epc_run . 'award:1', 'order_award', 50, 50 );
epc_test_check( 'conflict: other customer refused', is_wp_error( $r3 ) && 'epc_loyalty_event_conflict' === $r3->get_error_code() );
epc_test_check( 'conflict: other customer has no rows', 0 === epc_test_rows( $other ) );

// 4. Invalid input.
$bad = array(
	EPC_Loyalty_Ledger_Service::record( 0, $epc_run . 'x', 'order_award', 1, 1 ),
	EPC_Loyalty_Ledger_Service::record( $u, '', 'order_award', 1, 1 ),
	EPC_Loyalty_Ledger_Service::record( $u, $epc_run . 'y', '', 1, 1 ),
);
epc_test_check(
	'invalid: user 0 / empty key / empty type refused',
	3 === count(
		array_filter(
			$bad,
			static function ( $e ) {
				return is_wp_error( $e ) && 'epc_loyalty_invalid_entry' === $e->get_error_code();
			}
		)
	)
);

// 5. Redemption needing more points than available is refused and changes nothing.
$r5 = EPC_Loyalty_Ledger_Service::record( $u, $epc_run . 'redeem:1', 'redemption_reservation', -150, 0, array( 'require_available_balance' => true ) );
epc_test_check( 'insufficient: refused', is_wp_error( $r5 ) && 'epc_loyalty_insufficient_points' === $r5->get_error_code() );
epc_test_check( 'insufficient: balance unchanged, no row', array( 100, 100 ) === epc_test_balance( $u ) && 1 === epc_test_rows( $u ) );
$r5b = EPC_Loyalty_Ledger_Service::record( $u, $epc_run . 'redeem:2', 'redemption_reservation', -60, 0, array( 'require_available_balance' => true ) );
epc_test_check( 'sufficient: redemption of 60 recorded, balance 40, lifetime 100', is_array( $r5b ) && array( 40, 100 ) === epc_test_balance( $u ), wp_json_encode( epc_test_balance( $u ) ) );

// 6. Order reversals are capped at what the order awarded.
$v = epc_test_user();
EPC_Loyalty_Ledger_Service::record( $v, $epc_run . 'award:77', 'order_award', 100, 100, array( 'order_id' => $epc_o + 77 ) );
EPC_Loyalty_Ledger_Service::record( $v, $epc_run . 'rev:77:1', 'order_reversal', -80, -80, array( 'order_id' => $epc_o + 77, 'maximum_order_reversal' => 100 ) );
$r6 = EPC_Loyalty_Ledger_Service::record( $v, $epc_run . 'rev:77:2', 'order_reversal', -50, -50, array( 'order_id' => $epc_o + 77, 'maximum_order_reversal' => 100 ) );
epc_test_check( 'reversal cap: second reversal capped to 20', is_array( $r6 ) && -20 === (int) $r6['entry']->points_delta, is_array( $r6 ) ? (string) $r6['entry']->points_delta : 'error' );
epc_test_check( 'reversal cap: balance 0, lifetime 0', array( 0, 0 ) === epc_test_balance( $v ), wp_json_encode( epc_test_balance( $v ) ) );
$r6c = EPC_Loyalty_Ledger_Service::record( $v, $epc_run . 'rev:77:3', 'order_reversal', -10, -10, array( 'order_id' => $epc_o + 77, 'maximum_order_reversal' => 100 ) );
epc_test_check( 'reversal cap: nothing left to reverse records 0', is_array( $r6c ) && 0 === (int) $r6c['entry']->points_delta );

// 7. Reinstatement is capped at the outstanding cancellation reversal.
$w = epc_test_user();
EPC_Loyalty_Ledger_Service::record( $w, $epc_run . 'award:88', 'order_award', 100, 100, array( 'order_id' => $epc_o + 88 ) );
EPC_Loyalty_Ledger_Service::record( $w, $epc_run . 'rev:88', 'order_reversal', -30, -30, array( 'order_id' => $epc_o + 88, 'maximum_order_reversal' => 100 ) );
epc_test_check( 'outstanding reversal is 30', 30 === EPC_Loyalty_Ledger_Service::get_outstanding_order_reversal( $epc_o + 88 ) );
$r7 = EPC_Loyalty_Ledger_Service::record(
	$w,
	$epc_run . 'reinstate:88',
	'order_reinstatement',
	500,
	500,
	array(
		'order_id'                          => $epc_o + 88,
		'cap_to_outstanding_order_reversal' => true,
		'amount'                            => 50.0,
	)
);
epc_test_check( 'reinstatement capped to 30', is_array( $r7 ) && 30 === (int) $r7['entry']->points_delta );
epc_test_check( 'reinstatement: amount scaled to 30/500 of 50 = 3.00', is_array( $r7 ) && abs( (float) $r7['entry']->amount - 3.0 ) < 0.001, is_array( $r7 ) ? (string) $r7['entry']->amount : '' );
epc_test_check( 'reinstatement: balance back to 100', array( 100, 100 ) === epc_test_balance( $w ), wp_json_encode( epc_test_balance( $w ) ) );

// 8. Lifetime points never go below zero; balance may.
$z = epc_test_user();
EPC_Loyalty_Ledger_Service::record( $z, $epc_run . 'adj:1', 'manual_adjustment', -25, -25 );
epc_test_check( 'negative adjustment: balance -25, lifetime 0', array( -25, 0 ) === epc_test_balance( $z ), wp_json_encode( epc_test_balance( $z ) ) );

// 9. Rolled-back failures leave no partial state (unknown user).
$r9 = EPC_Loyalty_Ledger_Service::record( 999999999, $epc_run . 'ghost', 'order_award', 10, 10 );
epc_test_check( 'unknown user refused', is_wp_error( $r9 ) && 'epc_loyalty_invalid_user' === $r9->get_error_code() );
epc_test_check( 'unknown user: no ledger row written', null === EPC_Loyalty_Ledger_Service::get_by_event_key( $epc_run . 'ghost' ) );

printf( "%d passed, %d failed\n", (int) EPC_Ledger_Test_Counter::$passed, (int) EPC_Ledger_Test_Counter::$failed );
// No checks recorded means the test itself broke: never report that as success.
exit( ( EPC_Ledger_Test_Counter::$failed > 0 || 0 === EPC_Ledger_Test_Counter::$passed ) ? 1 : 0 );

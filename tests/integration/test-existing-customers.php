<?php
/**
 * Integration tests for giving cards to existing customers (v32): the check, the run, pause,
 * stop, one email per card, and the stops for repeated failures and a turned-off integration.
 *
 * Run: wp eval-file tests/integration/test-existing-customers.php (tests/integration/run.sh does this).
 * Uses the test integration and fake API from fixtures/test-integration.php and removes what it
 * created. Prints PASS/FAIL lines; exits 1 on any failure.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Existing_Customers' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-existing-customers.php (EpassCard active)\n" );
	exit( 1 );
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Pass/fail counter and captured mail (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Existing_Test_State {
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

	/**
	 * Recipients of the mail sent so far.
	 *
	 * @var array<int, string>
	 */
	public static $mail = array();
}

/**
 * Record one check.
 *
 * @param string $label Check label.
 * @param bool   $ok    Result.
 * @param string $info  Extra detail on failure.
 * @return void
 */
function epc_ec_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Existing_Test_State::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Existing_Test_State::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * Run batches until the run is no longer running (at most 20).
 *
 * @return array<string, mixed> The run.
 */
function epc_ec_run() {
	for ( $i = 0; $i < 20 && 'running' === EPC_Existing_Customers::get_job()['status']; $i++ ) {
		EPC_Existing_Customers::process_batch();
	}
	return EPC_Existing_Customers::get_job();
}

/**
 * Check the test integration and keep the check for the current admin, as the screen does.
 *
 * @param EPC_Module $module Test integration.
 * @return array<string, mixed>
 */
function epc_ec_new_check( $module ) {
	$check = EPC_Existing_Customers::check( $module );
	set_transient( EPC_Existing_Customers::CHECK_PREFIX . get_current_user_id(), $check, HOUR_IN_SECONDS );
	return $check;
}

/**
 * Whether a record has a card.
 *
 * @param string $slug Module slug.
 * @param int    $id   Record id.
 * @return bool
 */
function epc_ec_has_card( $slug, $id ) {
	$row = EPC_DB::get_pass( $slug, $id );
	return $row && '' !== (string) $row->pass_uid;
}

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) $admins[0] );

$slug = epc_test_module_slug();
epc_test_fixture_setup();
$module = EPC_Module_Loader::get_module_instance( $slug );

// A second item without a design.
add_filter(
	'epc_mappable_entities',
	static function ( $entities, $for ) use ( $slug ) {
		if ( $for === $slug ) {
			$entities[] = array(
				'id'    => 2,
				'label' => 'No-card plan',
			);
		}
		return $entities;
	},
	10,
	2
);

// Mail: record each recipient (the fixture then accepts the mail without sending it).
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		EPC_Existing_Test_State::$mail[] = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'];
		return $short;
	},
	1,
	2
);

// The automatic card email is on, as by default: a run must still send one email per card.
$email_settings = get_option( EPC_Pass_Email::OPTION, null );
update_option( EPC_Pass_Email::OPTION, array_merge( EPC_Pass_Email::get_settings(), array( 'auto_on_create' => 1 ) ), false );
EPC_Existing_Customers::control( 'stop' );
EPC_Existing_Customers::control( 'dismiss' );

$u = array();
for ( $i = 1; $i <= 7; $i++ ) {
	$u[ $i ] = epc_test_fixture_user();
}
// u1 has a card already; u2 has no email address; u7 is on the item without a design.
$module->sync_by_source_id( $u[1], 'sync' );
EPC_Existing_Test_State::$mail = array();
global $wpdb;
$wpdb->update( $wpdb->users, array( 'user_email' => '' ), array( 'ID' => $u[2] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
clean_user_cache( $u[2] );
$GLOBALS['epc_test_backfill'] = array(
	1 => array( $u[1], $u[2], $u[3], $u[4], $u[5], $u[6] ),
	2 => array( $u[7] ),
);

// ---- Check.
$check = epc_ec_new_check( $module );
$newest = array( (string) $u[6], (string) $u[5], (string) $u[4], (string) $u[3] );
epc_ec_check( 'check: will get a card, newest first', $newest === $check['will'], wp_json_encode( $check['will'] ) );
epc_ec_check( 'check: one already has a card', 1 === $check['have'], (string) $check['have'] );
epc_ec_check( 'check: one is on an item without a design', 1 === $check['excluded'] && array( 'No-card plan' => 1 ) === $check['excluded_items'], wp_json_encode( $check['excluded_items'] ) );
epc_ec_check( 'check: one has no email address', 1 === $check['no_email'] && (string) $u[2] === (string) $check['no_email_ids'][0][0] );
$sample = EPC_Existing_Customers::sample_email( $check );
epc_ec_check(
	'sample email goes to the newest customer, with a placeholder link',
	null !== $sample && get_userdata( $u[6] )->user_email === $sample['to'] && false !== strpos( $sample['body'], '[link to their card]' ),
	wp_json_encode( $sample )
);
epc_ec_check( 'the check sent no mail and made no card', array() === EPC_Existing_Test_State::$mail && ! epc_ec_has_card( $slug, $u[3] ) );

// ---- Start with the 2 newest; pause; resume.
$started = EPC_Existing_Customers::start( $slug, 2 );
epc_ec_check( 'start: the 2 newest are queued', ! is_wp_error( $started ) && 2 === $started['total'], is_wp_error( $started ) ? $started->get_error_message() : '' );
epc_ec_check( 'start: the check is used up', null === EPC_Existing_Customers::get_check() );
$again = EPC_Existing_Customers::start( $slug, 0 );
epc_ec_check( 'a second start is refused', is_wp_error( $again ) );

EPC_Existing_Customers::control( 'pause' );
EPC_Existing_Customers::process_batch();
epc_ec_check( 'paused: a batch does nothing', 0 === EPC_Existing_Customers::get_job()['position'] && 'paused' === EPC_Existing_Customers::get_job()['status'] );
EPC_Existing_Customers::control( 'resume' );
$job = epc_ec_run();
epc_ec_check( 'run: done, 2 emailed', 'done' === $job['status'] && 2 === $job['emailed'] && 2 === $job['position'], wp_json_encode( $job ) );
epc_ec_check( 'run: the 2 newest have a card, the others not', epc_ec_has_card( $slug, $u[6] ) && epc_ec_has_card( $slug, $u[5] ) && ! epc_ec_has_card( $slug, $u[4] ) && ! epc_ec_has_card( $slug, $u[3] ) );
$expected = array( get_userdata( $u[6] )->user_email, get_userdata( $u[5] )->user_email );
epc_ec_check( 'run: exactly one email per card (the automatic email did not also go out)', $expected === EPC_Existing_Test_State::$mail, wp_json_encode( EPC_Existing_Test_State::$mail ) );
$row = EPC_DB::get_pass( $slug, $u[6] );
epc_ec_check( 'run: the card is marked as emailed', ! empty( EPC_DB::get_pass_meta( $row )['emailed_at'] ) );
$results = EPC_Existing_Customers::get_results();
epc_ec_check( 'run: a result per record', 2 === count( $results ) && 'emailed' === $results[0][1] && (string) $u[6] === (string) $results[0][0], wp_json_encode( $results ) );

// ---- A card made between the check and the run: skipped, no second email.
$check = epc_ec_new_check( $module );
epc_ec_check( 'second check: the 2 left', array( (string) $u[4], (string) $u[3] ) === $check['will'], wp_json_encode( $check['will'] ) );
EPC_Existing_Test_State::$mail = array();
$module->sync_by_source_id( $u[3], 'sync' );
epc_ec_check( 'a live card outside a run still gets the automatic email', array( get_userdata( $u[3] )->user_email ) === EPC_Existing_Test_State::$mail, wp_json_encode( EPC_Existing_Test_State::$mail ) );
EPC_Existing_Test_State::$mail = array();
EPC_Existing_Customers::start( $slug, 0 );
$job = epc_ec_run();
epc_ec_check( 'run: the record that got a card meanwhile is skipped', 1 === $job['had'] && 1 === $job['emailed'], wp_json_encode( $job ) );
epc_ec_check( 'run: only the new card was emailed', array( get_userdata( $u[4] )->user_email ) === EPC_Existing_Test_State::$mail, wp_json_encode( EPC_Existing_Test_State::$mail ) );

// ---- Stop.
$more = array();
for ( $i = 0; $i < 6; $i++ ) {
	$more[] = epc_test_fixture_user();
}
$GLOBALS['epc_test_backfill'][1] = array_merge( $GLOBALS['epc_test_backfill'][1], $more );
epc_ec_new_check( $module );
EPC_Existing_Customers::start( $slug, 0 );
EPC_Existing_Customers::control( 'stop' );
EPC_Existing_Customers::process_batch();
$job = EPC_Existing_Customers::get_job();
epc_ec_check( 'stopped: nothing more is done', 'cancelled' === $job['status'] && 0 === $job['position'] );

// ---- Repeated failures stop the run.
epc_ec_new_check( $module );
EPC_Existing_Customers::start( $slug, 0 );
putenv( 'EPC_FAKE_FAIL_CREATE=1' );
$job = epc_ec_run();
putenv( 'EPC_FAKE_FAIL_CREATE' );
epc_ec_check(
	'five failures in a row with the same error stop the run',
	'stopped' === $job['status'] && 5 === $job['failed'] && 5 === $job['position'] && false !== strpos( (string) $job['stop_reason'], 'Pass limit reached' ),
	wp_json_encode( $job )
);
epc_ec_check( 'failed records got no card', ! epc_ec_has_card( $slug, $more[5] ) && ! epc_ec_has_card( $slug, $more[0] ) );

// ---- Integration turned off during a run.
epc_ec_new_check( $module );
EPC_Existing_Customers::start( $slug, 0 );
$enabled = get_option( 'epc_enabled_modules', array() );
update_option( 'epc_enabled_modules', array_values( array_diff( (array) $enabled, array( $slug ) ) ) );
$job = epc_ec_run();
update_option( 'epc_enabled_modules', $enabled );
epc_ec_check( 'integration turned off: the run stops with the reason', 'stopped' === $job['status'] && 1 === $job['position'] && '' !== (string) $job['stop_reason'], wp_json_encode( $job ) );

// ---- Who may do this.
epc_ec_check( 'admin gets the link to Cards', false !== strpos( EPC_Admin_Cards::existing_customers_url( $module ), 'epc_existing_module=' . $slug ) );
wp_set_current_user( $u[3] );
epc_ec_check( 'a customer gets no link', '' === EPC_Admin_Cards::existing_customers_url( $module ) );
wp_set_current_user( (int) $admins[0] );

// ---- Loyalty: customers who earned points.
$loyalty = EPC_Module_Loader::get_module_instance( 'woocommerce-loyalty' );
if ( $loyalty instanceof EPC_Module ) {
	$with    = epc_test_fixture_user();
	$without = epc_test_fixture_user();
	EPC_Loyalty_Account_Service::get_or_create( $with );
	EPC_Loyalty_Account_Service::get_or_create( $without );
	$wpdb->update( EPC_DB::loyalty_accounts_table_name(), array( 'lifetime_points' => 25, 'points_balance' => 25 ), array( 'user_id' => $with ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
	$ids = array();
	for ( $offset = 0; ; $offset += 500 ) {
		$page = $loyalty->get_backfill_source_ids( 1, 500, $offset );
		$ids  = array_merge( $ids, array_map( 'intval', $page ) );
		if ( count( $page ) < 500 ) {
			break;
		}
	}
	epc_ec_check( 'loyalty: a customer with points is listed, one without is not', in_array( $with, $ids, true ) && ! in_array( $without, $ids, true ) );
	epc_ec_check( 'loyalty: the record is the customer', $with === $loyalty->get_backfill_user_id( $with ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id IN (%d, %d)', EPC_DB::loyalty_accounts_table_name(), $with, $without ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
}

// Clean up.
EPC_Existing_Customers::control( 'stop' );
EPC_Existing_Customers::control( 'dismiss' );
delete_transient( EPC_Existing_Customers::CHECK_PREFIX . get_current_user_id() );
if ( null === $email_settings ) {
	delete_option( EPC_Pass_Email::OPTION );
} else {
	update_option( EPC_Pass_Email::OPTION, $email_settings, false );
}
epc_test_fixture_teardown();

echo EPC_Existing_Test_State::$passed, ' passed, ', EPC_Existing_Test_State::$failed, " failed\n";
if ( EPC_Existing_Test_State::$failed > 0 ) {
	exit( 1 );
}

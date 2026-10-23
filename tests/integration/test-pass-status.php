<?php
/**
 * Integration tests for card statuses, test cards and the first-real-card event (v46).
 *
 * Run: wp eval-file tests/integration/test-pass-status.php (tests/integration/run.sh does this).
 * Uses its own test integration and fake API (fixtures/test-integration.php) and removes what
 * it created. Prints PASS/FAIL lines; exits 1 on any failure.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Pass_Status' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-pass-status.php (EpassCard active)\n" );
	exit( 1 );
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Status_Test_Counter {
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
	 * Went-live events seen: [job, module, row id].
	 *
	 * @var array<int, array{0: string, 1: string, 2: int}>
	 */
	public static $events = array();
}

/**
 * Record one check.
 *
 * @param string $label Check label.
 * @param bool   $ok    Result.
 * @param string $info  Extra detail on failure.
 * @return void
 */
function epc_status_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Status_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Status_Test_Counter::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

add_action(
	'epc_job_went_live',
	static function ( $job, $module, $row ) {
		EPC_Status_Test_Counter::$events[] = array( (string) $job, (string) $module, (int) $row->id );
	},
	10,
	3
);

epc_test_fixture_setup();
$epc_first_was   = get_option( 'epc_first_real_pass_at', false );
$epc_email_was   = get_option( EPC_Pass_Email::OPTION, null );
$epc_slug        = epc_test_module_slug();
$epc_m           = EPC_Module_Loader::get_module_instance( $epc_slug );
$epc_created_ids = array();

try {
	// 1. A test card: flagged, left out of counts, no event.
	$admin = epc_test_fixture_user();
	$r     = EPC_Pass_Status::run_as_test(
		static function () use ( $epc_m, $admin ) {
			return $epc_m->sync_by_source_id( $admin, 'sync' );
		}
	);
	$row   = EPC_DB::get_pass( $epc_slug, $admin );
	epc_status_check( 'test card created', true === $r && $row && '' !== (string) $row->pass_uid );
	epc_status_check( 'test card stored with is_test = 1', $row && 1 === (int) $row->is_test );
	epc_status_check( 'test card fires no went-live event', 0 === count( EPC_Status_Test_Counter::$events ) );
	epc_status_check( 'test context closed afterwards', false === EPC_Pass_Status::is_test_context() );
	epc_status_check( 'test card not counted', 0 === EPC_DB::count_real_passes( $epc_slug ) );
	epc_status_check( 'status cell shows the Test badge', false !== strpos( EPC_Pass_Status::render_status_html( $row ), 'epc-pass-test-badge' ) );

	// 2. An ordinary update of the test card keeps the flag.
	epc_status_check( 'ordinary update of the test card succeeds', true === $epc_m->sync_by_source_id( $admin, 'update' ) );
	epc_status_check( 'flag kept after an ordinary update', 1 === (int) EPC_DB::get_pass( $epc_slug, $admin )->is_test );

	// 3. The test context closes even when the callback throws.
	try {
		EPC_Pass_Status::run_as_test(
			static function () {
				throw new RuntimeException( 'expected' );
			}
		);
	} catch ( RuntimeException $e ) {
		unset( $e );
	}
	epc_status_check( 'test context closed after an exception', false === EPC_Pass_Status::is_test_context() );

	// 4. First real card fires the event once; the next one does not.
	$u1 = epc_test_fixture_user();
	$epc_m->sync_by_source_id( $u1, 'sync' );
	$row1 = EPC_DB::get_pass( $epc_slug, $u1 );
	epc_status_check( 'real card stored with is_test = 0', $row1 && 0 === (int) $row1->is_test );
	epc_status_check(
		'first real card fires epc_job_went_live once, with job and module',
		1 === count( EPC_Status_Test_Counter::$events ) && 'epc-test-job' === EPC_Status_Test_Counter::$events[0][0] && $epc_slug === EPC_Status_Test_Counter::$events[0][1] && (int) $row1->id === EPC_Status_Test_Counter::$events[0][2],
		wp_json_encode( EPC_Status_Test_Counter::$events )
	);
	if ( false === $epc_first_was ) {
		epc_status_check( 'epc_first_real_pass_at recorded', false !== get_option( 'epc_first_real_pass_at', false ) );
	}
	$u2 = epc_test_fixture_user();
	$epc_m->sync_by_source_id( $u2, 'sync' );
	epc_status_check( 'second real card fires nothing', 1 === count( EPC_Status_Test_Counter::$events ) );
	epc_status_check( 'counts: 2 real cards, test card left out', 2 === EPC_DB::count_real_passes( $epc_slug ) && 2 === EPC_DB::count_real_passes( $epc_slug, 'active' ) );

	// 5. Setting and clearing the flag.
	epc_status_check( 'set_pass_test_flag sets', EPC_DB::set_pass_test_flag( $epc_slug, $u2, true ) && 1 === (int) EPC_DB::get_pass( $epc_slug, $u2 )->is_test );
	EPC_DB::set_pass_test_flag( $epc_slug, $u2, false );
	epc_status_check( 'set_pass_test_flag clears', 0 === (int) EPC_DB::get_pass( $epc_slug, $u2 )->is_test );

	// 6. Emailed: auto-email on create (default on), manual sends counted, other meta kept.
	$es                   = is_array( $epc_email_was ) ? $epc_email_was : array();
	$es['auto_on_create'] = true;
	update_option( EPC_Pass_Email::OPTION, $es );
	$u3 = epc_test_fixture_user();
	$epc_m->sync_by_source_id( $u3, 'sync' );
	$meta = EPC_DB::get_pass_meta( EPC_DB::get_pass( $epc_slug, $u3 ) );
	epc_status_check( 'auto-emailed card: emailed_at stored, count 1', ! empty( $meta['emailed_at'] ) && 1 === (int) ( $meta['emailed_count'] ?? 0 ), wp_json_encode( $meta ) );
	$r3               = EPC_DB::get_pass( $epc_slug, $u3 );
	$meta['keep_me'] = 7;
	EPC_DB::update_pass_meta( $r3, $meta );
	epc_status_check( 'manual email sent', true === EPC_Pass_Email::send_for_source( $epc_slug, $u3 ) );
	$meta = EPC_DB::get_pass_meta( EPC_DB::get_pass( $epc_slug, $u3 ) );
	epc_status_check( 'manual email counted (2), other meta kept', 2 === (int) ( $meta['emailed_count'] ?? 0 ) && 7 === (int) ( $meta['keep_me'] ?? 0 ), wp_json_encode( $meta ) );
	$s = EPC_Pass_Status::get_display_status( EPC_DB::get_pass( $epc_slug, $u3 ) );
	epc_status_check( 'shows Emailed with a detail line', 'emailed' === $s['key'] && '' !== $s['detail'] );

	$es['auto_on_create'] = false;
	update_option( EPC_Pass_Email::OPTION, $es );
	$u4 = epc_test_fixture_user();
	$epc_m->sync_by_source_id( $u4, 'sync' );
	$s = EPC_Pass_Status::get_display_status( EPC_DB::get_pass( $epc_slug, $u4 ) );
	epc_status_check( 'auto-email off: new card shows Created', 'created' === $s['key'], $s['key'] );

	// 7. Failures and revoke states.
	EPC_Pass_Issues::record( $epc_slug, $u4, 'update', new WP_Error( 'epc_update_failed', 'Template rejected <b>Points</b>' ) );
	$s = EPC_Pass_Status::get_display_status( EPC_DB::get_pass( $epc_slug, $u4 ) );
	epc_status_check( 'recorded update failure shows Update failed with the reason', 'update_failed' === $s['key'] && false !== strpos( $s['detail'], 'Points' ) );
	epc_status_check( 'status cell has no raw HTML from the reason', false === strpos( EPC_Pass_Status::render_status_html( EPC_DB::get_pass( $epc_slug, $u4 ) ), '<b>' ) );
	EPC_Pass_Issues::clear( $epc_slug, $u4 );
	EPC_Pass_Issues::record( $epc_slug, $u4, 'expire', new WP_Error( 'epc_expire_failed', 'x' ) );
	epc_status_check( 'expire failure is not shown as Update failed', 'created' === EPC_Pass_Status::get_display_status( EPC_DB::get_pass( $epc_slug, $u4 ) )['key'] );
	EPC_Pass_Issues::clear( $epc_slug, $u4 );

	$r2              = EPC_DB::get_pass( $epc_slug, $u2 );
	$m2              = EPC_DB::get_pass_meta( $r2 );
	$m2['revoke_pending'] = 1;
	EPC_DB::update_pass_meta( $r2, $m2 );
	epc_status_check( 'revoke_pending shows Revoke pending', 'revoke_pending' === EPC_Pass_Status::get_display_status( EPC_DB::get_pass( $epc_slug, $u2 ) )['key'] );
	$GLOBALS['wpdb']->update( EPC_DB::table_name(), array( 'status' => 'revoked' ), array( 'id' => (int) $r2->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test setup.
	epc_status_check( 'revoked shows Revoked', 'revoked' === EPC_Pass_Status::get_display_status( EPC_DB::get_pass( $epc_slug, $u2 ) )['key'] );
	epc_status_check( 'counts after a revoke: 4 real, 3 active', 4 === EPC_DB::count_real_passes( $epc_slug ) && 3 === EPC_DB::count_real_passes( $epc_slug, 'active' ) );

	// 8. A failed create writes no row and records the reason.
	putenv( 'EPC_FAKE_FAIL_CREATE=1' );
	$u5  = epc_test_fixture_user();
	$res = $epc_m->sync_by_source_id( $u5, 'sync' );
	putenv( 'EPC_FAKE_FAIL_CREATE' );
	$iss = EPC_Pass_Issues::get( $epc_slug, $u5 );
	epc_status_check( 'failed create: error, no row', is_wp_error( $res ) && null === EPC_DB::get_pass( $epc_slug, $u5 ) );
	epc_status_check( 'failed create: reason recorded', is_array( $iss ) && 'Pass limit reached for your plan' === $iss['message'] );

	// 9. Jobs.
	epc_status_check( 'job of memberpress is membership', 'membership' === EPC_Jobs::get_job_for_module( 'memberpress' ) );
	epc_status_check( 'job of woocommerce-loyalty is loyalty', 'loyalty' === EPC_Jobs::get_job_for_module( 'woocommerce-loyalty' ) );
	epc_status_check( 'unknown module has no job', '' === EPC_Jobs::get_job_for_module( 'no-such-module' ) );
} finally {
	epc_test_fixture_teardown();
	if ( null === $epc_email_was ) {
		delete_option( EPC_Pass_Email::OPTION );
	} else {
		update_option( EPC_Pass_Email::OPTION, $epc_email_was );
	}
	if ( false === $epc_first_was ) {
		delete_option( 'epc_first_real_pass_at' );
	}
}

printf( "%d passed, %d failed\n", (int) EPC_Status_Test_Counter::$passed, (int) EPC_Status_Test_Counter::$failed );
exit( ( EPC_Status_Test_Counter::$failed > 0 || 0 === EPC_Status_Test_Counter::$passed ) ? 1 : 0 );

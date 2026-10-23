<?php
/**
 * Integration tests for "Retry all failed cards" (v44).
 *
 * Run: wp eval-file tests/integration/test-retry-failed.php (tests/integration/run.sh does this).
 * Uses the test integration and fake API from fixtures/test-integration.php and removes what it
 * created. Prints PASS/FAIL lines; exits 1 on any failure.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Retry_Failed' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-retry-failed.php (EpassCard active)\n" );
	exit( 1 );
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Retry_Test_Counter {
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
function epc_retry_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Retry_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Retry_Test_Counter::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * Create a card that fails, leaving a recorded failure.
 *
 * @param EPC_Module $module Test integration.
 * @return int User id.
 */
function epc_retry_failed_create( $module ) {
	$u = epc_test_fixture_user();
	putenv( 'EPC_FAKE_FAIL_CREATE=1' );
	$module->sync_by_source_id( $u, 'sync' );
	putenv( 'EPC_FAKE_FAIL_CREATE' );
	return $u;
}

/**
 * Forget the failures EPC_Pass_Service remembers for this request.
 *
 * sync_pass() does not call the API again for a sync that already failed in the same request.
 * Real retries run in a later request (cron or Action Scheduler); this test runs everything
 * in one, so it clears that memory first.
 *
 * @return void
 */
function epc_retry_forget_failures() {
	$prop = new ReflectionProperty( 'EPC_Pass_Service', 'failed_in_request' );
	if ( PHP_VERSION_ID < 80100 ) {
		$prop->setAccessible( true );
	}
	$prop->setValue( null, array() );
}

/**
 * Run batches until the job stops running (at most 50), as a later request would.
 *
 * @return array<string, mixed> Final job.
 */
function epc_retry_run_all() {
	epc_retry_forget_failures();
	for ( $i = 0; $i < 50; $i++ ) {
		EPC_Retry_Failed::process_batch();
		$job = EPC_Retry_Failed::get_job();
		if ( 'running' !== $job['status'] ) {
			return $job;
		}
	}
	return EPC_Retry_Failed::get_job();
}

/**
 * Number of the test integration's cards.
 *
 * @return int
 */
function epc_retry_card_count() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test read.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE module = %s AND pass_uid <> ''", EPC_DB::table_name(), epc_test_module_slug() ) );
}

epc_test_fixture_setup();
$epc_job_was = get_option( EPC_Retry_Failed::OPTION, null );
$epc_slug    = epc_test_module_slug();
$epc_m       = EPC_Module_Loader::get_module_instance( $epc_slug );
delete_option( EPC_Retry_Failed::OPTION );

try {
	// 1. Five failed creates, plus three records that must be skipped.
	$failed = array();
	for ( $i = 0; $i < 5; $i++ ) {
		$failed[] = epc_retry_failed_create( $epc_m );
	}
	epc_retry_check( 'five failures recorded, no cards', 5 === count( EPC_Retry_Failed::get_retryable( $epc_slug ) ) && 0 === epc_retry_card_count() );

	$revoked = epc_test_fixture_user();
	$epc_m->sync_by_source_id( $revoked, 'sync' );
	$GLOBALS['wpdb']->update( EPC_DB::table_name(), array( 'status' => 'revoked' ), array( 'module' => $epc_slug, 'source_id' => (string) $revoked ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test setup.
	EPC_Pass_Issues::record( $epc_slug, $revoked, 'update', new WP_Error( 'epc_update_failed', 'old failure' ) );

	$pending = epc_test_fixture_user();
	$epc_m->sync_by_source_id( $pending, 'sync' );
	$prow                   = EPC_DB::get_pass( $epc_slug, $pending );
	$pmeta                  = EPC_DB::get_pass_meta( $prow );
	$pmeta['revoke_pending'] = 1;
	EPC_DB::update_pass_meta( $prow, $pmeta );
	EPC_Pass_Issues::record( $epc_slug, $pending, 'update', new WP_Error( 'epc_update_failed', 'old failure' ) );

	EPC_Pass_Issues::record( 'epc-not-enabled', 999, 'create', new WP_Error( 'epc_create_failed', 'x' ) );
	EPC_Pass_Issues::record( $epc_slug, 424242, 'expire', new WP_Error( 'epc_expire_failed', 'x' ) );

	epc_retry_check( 'expire failures are not offered for bulk retry', ! in_array( '424242', wp_list_pluck( EPC_Retry_Failed::get_retryable(), 'source_id' ), true ) );

	// 2. Controls offer the button.
	$html = EPC_Retry_Failed::render_controls( $epc_slug );
	epc_retry_check( 'module panel offers "Retry all 7 failed cards"', false !== strpos( $html, 'Retry all 7 failed cards' ), wp_strip_all_tags( $html ) );

	// 3. Run it.
	$job = EPC_Retry_Failed::start();
	epc_retry_check( 'job starts with 8 records (all integrations)', is_array( $job ) && 'running' === $job['status'] && 8 === (int) $job['total'], is_wp_error( $job ) ? $job->get_error_message() : wp_json_encode( $job['total'] ?? null ) );
	epc_retry_check( 'second start refused while running', is_wp_error( EPC_Retry_Failed::start() ) );
	epc_retry_check( 'controls show progress while running', false !== strpos( EPC_Retry_Failed::render_controls(), 'Retrying failed cards in the background' ) );
	$job = epc_retry_run_all();
	epc_retry_check( 'job done: 5 fixed, 0 failed, 3 skipped', 'done' === $job['status'] && 5 === (int) $job['fixed'] && 0 === (int) $job['failed'] && 3 === (int) $job['skipped'], wp_json_encode( array_intersect_key( $job, array_flip( array( 'status', 'fixed', 'failed', 'skipped', 'processed', 'reasons' ) ) ) ) );
	epc_retry_check( 'five cards created', 7 === epc_retry_card_count() );
	$left = array_filter(
		$failed,
		static function ( $u ) use ( $epc_slug ) {
			return null !== EPC_Pass_Issues::get( $epc_slug, $u );
		}
	);
	epc_retry_check( 'their failures cleared', 0 === count( $left ) );
	epc_retry_check( 'revoked card left revoked', 'revoked' === (string) EPC_DB::get_pass( $epc_slug, $revoked )->status );
	epc_retry_check( 'skip reasons recorded', isset( $job['reasons']['epc_retry_revoked'], $job['reasons']['epc_retry_revoke_pending'], $job['reasons']['epc_retry_module_off'] ), wp_json_encode( array_keys( (array) $job['reasons'] ) ) );
	epc_retry_check( 'pending revoke kept', ! empty( EPC_DB::get_pass_meta( EPC_DB::get_pass( $epc_slug, $pending ) )['revoke_pending'] ) );
	epc_retry_check( 'summary line mentions fixed and skipped', false !== strpos( EPC_Retry_Failed::summary( $job ), '5 cards fixed' ) && false !== strpos( EPC_Retry_Failed::summary( $job ), '3 skipped' ) );
	EPC_Pass_Issues::clear( 'epc-not-enabled', 999 );
	EPC_Pass_Issues::clear( $epc_slug, 424242 );
	EPC_Pass_Issues::clear( $epc_slug, $revoked );
	EPC_Pass_Issues::clear( $epc_slug, $pending );

	// 4. Still failing: the reason is counted and the failures stay listed.
	$a = epc_retry_failed_create( $epc_m );
	$b = epc_retry_failed_create( $epc_m );
	EPC_Retry_Failed::start( $epc_slug );
	putenv( 'EPC_FAKE_FAIL_CREATE=1' );
	$job = epc_retry_run_all();
	putenv( 'EPC_FAKE_FAIL_CREATE' );
	epc_retry_check( 'still failing: 2 failed, reason counted', 2 === (int) $job['failed'] && 2 === (int) ( $job['reasons']['epc_create_failed']['count'] ?? 0 ) && 'Pass limit reached for your plan' === ( $job['reasons']['epc_create_failed']['message'] ?? '' ), wp_json_encode( $job['reasons'] ) );
	epc_retry_check( 'still failing: failures stay listed', null !== EPC_Pass_Issues::get( $epc_slug, $a ) && null !== EPC_Pass_Issues::get( $epc_slug, $b ) );

	// 5. Batch size and the next batch being queued.
	add_filter(
		'epc_retry_failed_batch_size',
		static function () {
			return 1;
		}
	);
	EPC_Retry_Failed::start( $epc_slug );
	EPC_Retry_Failed::process_batch();
	$job    = EPC_Retry_Failed::get_job();
	$queued = function_exists( 'as_next_scheduled_action' ) ? false !== as_next_scheduled_action( EPC_Retry_Failed::HOOK, array(), EPC_Retry_Failed::GROUP ) : false !== wp_next_scheduled( EPC_Retry_Failed::HOOK );
	epc_retry_check( 'batch of 1: one processed, next batch queued', 'running' === $job['status'] && 1 === (int) $job['processed'] && $queued );

	// 6. Cancel.
	EPC_Retry_Failed::cancel();
	$job    = EPC_Retry_Failed::get_job();
	$queued = function_exists( 'as_next_scheduled_action' ) ? false !== as_next_scheduled_action( EPC_Retry_Failed::HOOK, array(), EPC_Retry_Failed::GROUP ) : false !== wp_next_scheduled( EPC_Retry_Failed::HOOK );
	epc_retry_check( 'cancel: status cancelled, nothing queued', 'cancelled' === $job['status'] && empty( $job['queue'] ) && ! $queued );
	EPC_Retry_Failed::process_batch();
	epc_retry_check( 'cancel: a late batch does nothing', 1 === (int) EPC_Retry_Failed::get_job()['processed'] );

	// 6b. A cancel from another request while a record is being retried is not lost.
	EPC_Pass_Issues::record( $epc_slug, $a, 'create', new WP_Error( 'epc_create_failed', 'x' ) );
	EPC_Pass_Issues::record( $epc_slug, $b, 'create', new WP_Error( 'epc_create_failed', 'x' ) );
	EPC_Retry_Failed::start( $epc_slug );
	$epc_cancel_once = static function () {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		// Simulate the other request: write the option behind this request's cache.
		global $wpdb;
		$other           = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", EPC_Retry_Failed::OPTION ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test.
		$other['status'] = 'cancelled';
		$other['queue']  = array();
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $other ) ), array( 'option_name' => EPC_Retry_Failed::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test.
	};
	add_action( 'epc_before_sync_pass', $epc_cancel_once );
	epc_retry_forget_failures();
	EPC_Retry_Failed::process_batch();
	remove_action( 'epc_before_sync_pass', $epc_cancel_once );
	$job = EPC_Retry_Failed::get_job();
	epc_retry_check( 'cancel during a batch is kept, batch stops after that record', 'cancelled' === $job['status'] && 1 === (int) $job['processed'], wp_json_encode( array( $job['status'], $job['processed'] ) ) );

	// 7. Nothing to retry.
	EPC_Pass_Issues::clear( $epc_slug, $a );
	EPC_Pass_Issues::clear( $epc_slug, $b );
	$r = EPC_Retry_Failed::start( $epc_slug );
	epc_retry_check( 'nothing to retry: refused', is_wp_error( $r ) && 'epc_retry_nothing' === $r->get_error_code() );

	// 8. A revoke for a record with no card clears its failed create.
	$c = epc_retry_failed_create( $epc_m );
	EPC_Pass_Service::revoke_pass( $epc_slug, $c );
	epc_retry_check( 'revoke with no card clears the moot failure', null === EPC_Pass_Issues::get( $epc_slug, $c ) );
} finally {
	EPC_Retry_Failed::cancel();
	if ( null === $epc_job_was ) {
		delete_option( EPC_Retry_Failed::OPTION );
	} else {
		update_option( EPC_Retry_Failed::OPTION, $epc_job_was, false );
	}
	epc_test_fixture_teardown();
}

printf( "%d passed, %d failed\n", (int) EPC_Retry_Test_Counter::$passed, (int) EPC_Retry_Test_Counter::$failed );
exit( ( EPC_Retry_Test_Counter::$failed > 0 || 0 === EPC_Retry_Test_Counter::$passed ) ? 1 : 0 );

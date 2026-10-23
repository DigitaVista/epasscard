<?php
/**
 * Steps of the parallel bulk-retry test (run.sh runs them). EPC_RETRY_STEP:
 *   setup  - 10 failed creates for the test integration, then start the job
 *   work   - wait for EPC_RACE_AT, then run batches until the job is no longer running
 *   check  - print "<cards> <processed> <fixed> <status>"
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Retry_Failed' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/fixtures/test-integration.php';

$epc_step = (string) getenv( 'EPC_RETRY_STEP' );
$epc_slug = epc_test_module_slug();

if ( 'setup' === $epc_step ) {
	epc_test_fixture_setup();
	$epc_m = EPC_Module_Loader::get_module_instance( $epc_slug );
	putenv( 'EPC_FAKE_FAIL_CREATE=1' );
	for ( $epc_i = 0; $epc_i < 10; $epc_i++ ) {
		$epc_m->sync_by_source_id( epc_test_fixture_user(), 'sync' );
	}
	putenv( 'EPC_FAKE_FAIL_CREATE' );
	$epc_job = EPC_Retry_Failed::start( $epc_slug );
	exit( is_wp_error( $epc_job ) ? 1 : 0 );
}

if ( 'work' === $epc_step ) {
	add_filter(
		'epc_retry_failed_batch_size',
		static function () {
			return 3;
		}
	);
	$epc_at = (float) getenv( 'EPC_RACE_AT' );
	while ( microtime( true ) < $epc_at ) {
		usleep( 500 );
	}
	for ( $epc_i = 0; $epc_i < 40 && 'running' === EPC_Retry_Failed::get_job()['status']; $epc_i++ ) {
		EPC_Retry_Failed::process_batch();
		wp_cache_delete( EPC_Retry_Failed::OPTION, 'options' );
		usleep( 100000 );
	}
	exit( 0 );
}

if ( 'check' === $epc_step ) {
	global $wpdb;
	$epc_job = EPC_Retry_Failed::get_job();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test read.
	$epc_cards = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE module = %s AND pass_uid <> ''", EPC_DB::table_name(), $epc_slug ) );
	echo $epc_cards, ' ', (int) $epc_job['processed'], ' ', (int) $epc_job['fixed'], ' ', esc_html( (string) $epc_job['status'] ), "\n";
	exit( 0 );
}

exit( 1 );

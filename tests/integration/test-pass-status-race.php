<?php
/**
 * One worker of the first-real-card race (run.sh starts several at once).
 *
 * Waits for EPC_RACE_AT (unix time), creates one real card for the test integration named in
 * EPC_TEST_MODULE, and appends a line to EPC_RACE_LOG for every went-live event it fires.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Pass_Status' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/fixtures/test-integration.php';

add_action(
	'epc_job_went_live',
	static function ( $job, $module, $row ) {
		file_put_contents( (string) getenv( 'EPC_RACE_LOG' ), getmypid() . ' ' . $job . ' ' . (int) $row->id . "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test log.
	},
	10,
	3
);

$epc_user = epc_test_fixture_user();
$epc_at   = (float) getenv( 'EPC_RACE_AT' );
while ( microtime( true ) < $epc_at ) {
	usleep( 500 );
}
$epc_result = EPC_Module_Loader::get_module_instance( epc_test_module_slug() )->sync_by_source_id( $epc_user, 'sync' );
exit( true === $epc_result ? 0 : 1 );

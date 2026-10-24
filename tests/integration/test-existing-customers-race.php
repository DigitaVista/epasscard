<?php
/**
 * Three runners of "give cards to existing customers" at once: each record is handled and
 * emailed once. Steps (EPC_EXISTING_STEP): setup, work (run in parallel), check.
 *
 * Run by tests/integration/run.sh.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Existing_Customers' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/fixtures/test-integration.php';

$epc_step = (string) getenv( 'EPC_EXISTING_STEP' );
$epc_slug = epc_test_module_slug();
$epc_log  = (string) getenv( 'EPC_RACE_LOG' );

// The records live in an option so every process sees the same list.
$GLOBALS['epc_test_backfill'] = array( 1 => (array) get_option( 'epc_test_existing_ids', array() ) );

// Each email is one line in the log.
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) use ( $epc_log ) {
		if ( '' !== $epc_log ) {
			file_put_contents( $epc_log, (string) $atts['to'] . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test log.
		}
		return $short;
	},
	1,
	2
);

$epc_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) $epc_admins[0] );

if ( 'setup' === $epc_step ) {
	epc_test_fixture_setup();
	EPC_Existing_Customers::control( 'stop' );
	EPC_Existing_Customers::control( 'dismiss' );
	$epc_ids = array();
	for ( $epc_i = 0; $epc_i < 10; $epc_i++ ) {
		$epc_ids[] = epc_test_fixture_user();
	}
	update_option( 'epc_test_existing_ids', $epc_ids, false );
	$GLOBALS['epc_test_backfill'] = array( 1 => $epc_ids );
	$epc_check = EPC_Existing_Customers::check( EPC_Module_Loader::get_module_instance( $epc_slug ) );
	set_transient( EPC_Existing_Customers::CHECK_PREFIX . get_current_user_id(), $epc_check, HOUR_IN_SECONDS );
	$epc_job = EPC_Existing_Customers::start( $epc_slug, 0 );
	exit( is_wp_error( $epc_job ) ? 1 : 0 );
}

if ( 'work' === $epc_step ) {
	add_filter(
		'epc_existing_customers_batch_size',
		static function () {
			return 3;
		}
	);
	$epc_at = (float) getenv( 'EPC_RACE_AT' );
	while ( microtime( true ) < $epc_at ) {
		usleep( 500 );
	}
	for ( $epc_i = 0; $epc_i < 40 && 'running' === EPC_Existing_Customers::get_job()['status']; $epc_i++ ) {
		EPC_Existing_Customers::process_batch();
		wp_cache_delete( EPC_Existing_Customers::OPTION, 'options' );
		usleep( 100000 );
	}
	exit( 0 );
}

if ( 'check' === $epc_step ) {
	global $wpdb;
	$epc_job = EPC_Existing_Customers::get_job();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test read.
	$epc_cards = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE module = %s AND pass_uid <> ''", EPC_DB::table_name(), $epc_slug ) );
	echo $epc_cards, ' ', (int) $epc_job['position'], ' ', (int) $epc_job['emailed'], ' ', count( EPC_Existing_Customers::get_results() ), ' ', esc_html( (string) $epc_job['status'] ), "\n";
	exit( 0 );
}

if ( 'teardown' === $epc_step ) {
	EPC_Existing_Customers::control( 'stop' );
	EPC_Existing_Customers::control( 'dismiss' );
	delete_option( 'epc_test_existing_ids' );
	epc_test_fixture_teardown();
	exit( 0 );
}

exit( 1 );

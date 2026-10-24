<?php
/**
 * Integration test for v51: a Cancel of the loyalty past-order sync saved by another request
 * while a batch runs stops the sync. Needs WooCommerce (skipped without it).
 *
 * The other request is simulated by writing the cancelled job straight to the database, past
 * this request's option cache, which is what a second PHP request does.
 *
 * Run: wp eval-file tests/integration/test-loyalty-sync-cancel.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Loyalty_Order_Sync_Service' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-loyalty-sync-cancel.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! function_exists( 'wc_create_order' ) ) {
	echo "SKIP loyalty past-order sync cancel (WooCommerce is not active here)\n";
	return;
}

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Sync_Cancel_Test_Counter {
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
function epc_sc_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Sync_Cancel_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Sync_Cancel_Test_Counter::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * Save a job state straight to the database, as another request would.
 *
 * @param array<string, mixed> $job Job.
 * @return void
 */
function epc_sc_write_elsewhere( array $job ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulates another request.
	$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $job ) ), array( 'option_name' => EPC_Loyalty_Order_Sync_Service::OPTION_KEY ) );
}

/**
 * Job as saved in the database.
 *
 * @return array<string, mixed>
 */
function epc_sc_job() {
	wp_cache_delete( EPC_Loyalty_Order_Sync_Service::OPTION_KEY, 'options' );
	return EPC_Loyalty_Order_Sync_Service::get_job();
}

$saved_job = get_option( EPC_Loyalty_Order_Sync_Service::OPTION_KEY, null );

// A customer with two orders the sync would scan.
$login    = 'epc_sync_' . wp_generate_password( 8, false, false );
$customer = wp_insert_user(
	array(
		'user_login' => $login,
		'user_pass'  => wp_generate_password(),
		'user_email' => $login . '@example.test',
		'role'       => 'customer',
	)
);
$orders   = array();
for ( $i = 0; $i < 2; $i++ ) {
	$order = wc_create_order( array( 'customer_id' => $customer ) );
	$order->set_status( 'completed' );
	$order->save();
	$orders[] = $order->get_id();
}

$running = array(
	'status'     => 'running',
	'from'       => '',
	'to'         => '',
	'cursor_id'  => min( $orders ) - 1,
	'found'      => 2,
	'processed'  => 0,
	'started_at' => gmdate( 'Y-m-d H:i:s' ),
);
$cancelled = array_merge(
	$running,
	array(
		'status'      => 'cancelled',
		'finished_at' => gmdate( 'Y-m-d H:i:s' ),
	)
);

// 1. Cancelled before the batch starts, after this request read the job.
update_option( EPC_Loyalty_Order_Sync_Service::OPTION_KEY, $running, false );
EPC_Loyalty_Order_Sync_Service::get_job(); // This request now holds the running job in its cache.
epc_sc_write_elsewhere( $cancelled );
EPC_Loyalty_Order_Sync_Service::process_batch();
$job = epc_sc_job();
epc_sc_check( 'a cancel saved before the batch stops it before any order', 'cancelled' === $job['status'] && 0 === (int) $job['processed'], wp_json_encode( $job ) );

// 2. Cancelled while the batch runs (when it reads the orders).
update_option( EPC_Loyalty_Order_Sync_Service::OPTION_KEY, $running, false );
$fired  = false;
$cancel = static function ( $query ) use ( &$fired, $cancelled ) {
	if ( ! $fired && false !== strpos( (string) $query, "'shop_order'" ) && false !== strpos( (string) $query, 'ORDER BY' ) ) {
		$fired = true;
		epc_sc_write_elsewhere( $cancelled );
	}
	return $query;
};
add_filter( 'query', $cancel );
EPC_Loyalty_Order_Sync_Service::process_batch();
remove_filter( 'query', $cancel );
$job = epc_sc_job();
epc_sc_check( 'the batch read the orders', $fired );
epc_sc_check( 'a cancel saved during the batch is kept', 'cancelled' === $job['status'], wp_json_encode( $job ) );
$queued = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( EPC_Loyalty_Order_Sync_Service::CRON_HOOK, array(), 'epasscard-loyalty' ) : wp_next_scheduled( EPC_Loyalty_Order_Sync_Service::CRON_HOOK );
epc_sc_check( 'no next batch is queued after a cancel', false === $queued, wp_json_encode( $queued ) );

// Clean up.
EPC_Loyalty_Order_Sync_Service::clear_cron();
foreach ( $orders as $order_id ) {
	$order = wc_get_order( $order_id );
	if ( $order ) {
		$order->delete( true );
	}
}
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', EPC_DB::loyalty_ledger_table_name(), $customer ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', EPC_DB::loyalty_accounts_table_name(), $customer ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $customer );
if ( null === $saved_job ) {
	delete_option( EPC_Loyalty_Order_Sync_Service::OPTION_KEY );
} else {
	update_option( EPC_Loyalty_Order_Sync_Service::OPTION_KEY, $saved_job, false );
}

echo EPC_Sync_Cancel_Test_Counter::$passed, ' passed, ', EPC_Sync_Cancel_Test_Counter::$failed, " failed\n";
if ( EPC_Sync_Cancel_Test_Counter::$failed > 0 ) {
	exit( 1 );
}

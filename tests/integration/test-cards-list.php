<?php
/**
 * Integration tests for the Cards list (v38): one list of every integration's cards.
 *
 * Run: wp eval-file tests/integration/test-cards-list.php (tests/integration/run.sh does this).
 * Uses the test integration and fake API from fixtures/test-integration.php and removes what it
 * created. Prints PASS/FAIL lines; exits 1 on any failure.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! method_exists( 'EPC_DB', 'query_all_passes' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-cards-list.php (EpassCard active)\n" );
	exit( 1 );
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Cards_List_Test_Counter {
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
function epc_cl_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Cards_List_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Cards_List_Test_Counter::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * Source ids of a query result.
 *
 * @param array<string, mixed> $args Query args.
 * @return array<int, string>
 */
function epc_cl_ids( array $args ) {
	$result = EPC_DB::query_all_passes( $args );
	$ids    = array_map( 'strval', wp_list_pluck( $result['items'], 'source_id' ) );
	sort( $ids );
	return $ids;
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

// Three customers: a real card, a test card, a revoked card.
$real    = epc_test_fixture_user();
$test    = epc_test_fixture_user();
$revoked = epc_test_fixture_user();
wp_update_user(
	array(
		'ID'           => $real,
		'display_name' => 'Brew Customer Unique',
	)
);
$module->sync_by_source_id( $real, 'sync' );
EPC_Pass_Status::run_as_test(
	static function () use ( $module, $test ) {
		return $module->sync_by_source_id( $test, 'sync' );
	}
);
$module->sync_by_source_id( $revoked, 'sync' );
$row = EPC_DB::get_pass( $slug, $revoked );
global $wpdb;
$wpdb->update( EPC_DB::table_name(), array( 'status' => 'revoked' ), array( 'id' => (int) $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.

$base = array(
	'modules'  => array( $slug ),
	'per_page' => 50,
);
$ids  = array( (string) $real, (string) $revoked );
sort( $ids );
epc_cl_check( 'all: real and revoked cards, no test card', $ids === epc_cl_ids( $base ), wp_json_encode( epc_cl_ids( $base ) ) );
epc_cl_check( 'active: only the real card', array( (string) $real ) === epc_cl_ids( array_merge( $base, array( 'view' => 'active' ) ) ) );
epc_cl_check( 'revoked: only the revoked card', array( (string) $revoked ) === epc_cl_ids( array_merge( $base, array( 'view' => 'revoked' ) ) ), wp_json_encode( epc_cl_ids( array_merge( $base, array( 'view' => 'revoked' ) ) ) ) );
epc_cl_check( 'test: only the test card', array( (string) $test ) === epc_cl_ids( array_merge( $base, array( 'view' => 'test' ) ) ) );
epc_cl_check( 'search by card id', array( (string) $revoked ) === epc_cl_ids( array_merge( $base, array( 'search' => substr( (string) $row->pass_uid, 0, 13 ) ) ) ) );
epc_cl_check(
	'search by customer (user ids from a name search)',
	array( (string) $real ) === epc_cl_ids(
		array_merge(
			$base,
			array(
				'search'   => 'Brew Customer Unique',
				'user_ids' => array( $real ),
			)
		)
	)
);
epc_cl_check(
	'name search with no matching user and no matching id: nothing',
	array() === epc_cl_ids(
		array_merge(
			$base,
			array(
				'search'   => 'zz-nobody-zz',
				'user_ids' => array(),
			)
		)
	)
);
epc_cl_check( 'no integration given: nothing', 0 === EPC_DB::query_all_passes( array( 'modules' => array() ) )['total'] );
epc_cl_check( 'paging: total stays 2 with one row per page', 2 === EPC_DB::query_all_passes( array_merge( $base, array( 'per_page' => 1 ) ) )['total'] && 1 === count( EPC_DB::query_all_passes( array_merge( $base, array( 'per_page' => 1 ) ) )['items'] ) );

// Emailed count follows the emailed_at meta of real active cards.
$before = EPC_DB::count_emailed_real_passes();
$fresh  = EPC_DB::get_pass( $slug, $real );
$meta   = EPC_DB::get_pass_meta( $fresh );
unset( $meta['emailed_at'] );
EPC_DB::update_pass_meta( $fresh, $meta );
$without = EPC_DB::count_emailed_real_passes();
EPC_Pass_Status::record_email_sent( 'x@example.test', $fresh );
epc_cl_check( 'emailed count goes up by one when the card is emailed', $without + 1 === EPC_DB::count_emailed_real_passes(), $before . ' / ' . $without . ' / ' . EPC_DB::count_emailed_real_passes() );

// The list table renders the customer, the job and the status.
require_once EPC_PLUGIN_DIR . 'includes/admin/class-epc-cards-list-table.php';
$_GET['page'] = EPC_Admin_App::PAGE_CARDS;
// WP_List_Table builds its paging links from the request.
$_SERVER['HTTP_HOST']   = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test request.
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=' . EPC_Admin_App::PAGE_CARDS;
$table                  = new EPC_Cards_List_Table(
	array( $slug => $module ),
	array(
		'module' => '',
		'view'   => 'all',
		'search' => '',
	)
);
$table->prepare_items();
ob_start();
$table->display();
$html = ob_get_clean();
unset( $_GET['page'] );
epc_cl_check( 'table shows the customer name', false !== strpos( $html, 'Brew Customer Unique' ) );
epc_cl_check( 'table shows the revoked status', false !== strpos( $html, 'epc-pass-status--revoked' ) );
epc_cl_check( 'table row carries the integration for the pass actions', false !== strpos( $html, 'data-epc-module="' . $slug . '"' ) && false !== strpos( $html, 'data-module="' . $slug . '"' ) );
epc_cl_check( 'table leaves the test card out', false === strpos( $html, 'data-epc-source-id="' . $test . '"' ) );

// Clean up.
epc_test_fixture_teardown();

echo EPC_Cards_List_Test_Counter::$passed, ' passed, ', EPC_Cards_List_Test_Counter::$failed, " failed\n";
if ( EPC_Cards_List_Test_Counter::$failed > 0 ) {
	exit( 1 );
}

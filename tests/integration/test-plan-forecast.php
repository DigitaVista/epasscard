<?php
/**
 * Integration test for m07: the plan forecast on Settings → Account & plan
 * ("4 cards left, about 2 days at this rate").
 *
 * Run: wp eval-file tests/integration/test-plan-forecast.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! method_exists( 'EPC_Plan', 'forecast_text' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-plan-forecast.php (EpassCard active)\n" );
	exit( 1 );
}

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Plan_Forecast_Test_Counter {
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
 * @param string $label    Check label.
 * @param string $got      Value.
 * @param string $expected Expected value.
 * @return void
 */
function epc_pf_same( $label, $got, $expected ) {
	if ( $got === $expected ) {
		++EPC_Plan_Forecast_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Plan_Forecast_Test_Counter::$failed;
	echo 'FAIL ', $label, ' -- got "', $got, '", expected "', $expected, "\"\n";
}

// The sentence (left, cards created in the window, window in days).
epc_pf_same( 'no cards made lately: only what is left', EPC_Plan::forecast_text( 12, 0, 30 ), '12 cards left' );
epc_pf_same( 'one card left', EPC_Plan::forecast_text( 1, 0, 30 ), '1 card left' );
epc_pf_same( 'mockup case: 4 left at 2 a day', EPC_Plan::forecast_text( 4, 60, 30 ), '4 cards left, about 2 days at this rate.' );
epc_pf_same( 'one day', EPC_Plan::forecast_text( 3, 90, 30 ), '3 cards left, about 1 day at this rate.' );
epc_pf_same( 'under a day', EPC_Plan::forecast_text( 2, 300, 30 ), '2 cards left, less than a day at this rate.' );
epc_pf_same( 'weeks from 14 days', EPC_Plan::forecast_text( 20, 30, 30 ), '20 cards left, about 3 weeks at this rate.' );
epc_pf_same( 'far away', EPC_Plan::forecast_text( 40, 3, 30 ), '40 cards left, more than two months at this rate.' );
epc_pf_same( 'a short window counts as days, not 30', EPC_Plan::forecast_text( 10, 5, 5 ), '10 cards left, about 10 days at this rate.' );
epc_pf_same( 'none left', EPC_Plan::forecast_text( 0, 60, 30 ), 'No cards left. New cards are not created until you change the plan.' );

// The card ages come from the passes table (database clock); test cards never count.
global $wpdb;
$table   = EPC_DB::table_name();
$prefix  = 'pf-' . wp_generate_password( 6, false, false ) . '-';
$backup  = $wpdb->get_results( $wpdb->prepare( "SELECT id, is_test FROM %i WHERE is_test = 0 AND pass_uid <> ''", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
$hidden  = wp_list_pluck( $backup, 'id' );
$restore = static function () use ( $wpdb, $table, $prefix, $hidden ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source_id LIKE %s', $table, $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
	if ( $hidden ) {
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET is_test = 0 WHERE is_test = 2 AND id IN (' . implode( ',', array_fill( 0, count( $hidden ), '%d' ) ) . ')', array_merge( array( $table ), array_map( 'intval', $hidden ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Test cleanup.
	}
};
register_shutdown_function( $restore ); // Also on a fatal error part way.
if ( $hidden ) {
	// Hide the site's own real cards for the duration of the test (restored by $restore).
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET is_test = 2 WHERE id IN (' . implode( ',', array_fill( 0, count( $hidden ), '%d' ) ) . ')', array_merge( array( $table ), array_map( 'intval', $hidden ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Test setup.
}
epc_pf_same( 'no real card yet: no age', var_export( EPC_DB::first_real_pass_age_days(), true ), 'NULL' );

$insert = static function ( $n, $days_ago, $is_test ) use ( $wpdb, $table, $prefix ) {
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test setup.
		$table,
		array(
			'module'       => 'test-forecast',
			'source_id'    => $prefix . $n,
			'entity_id'    => 1,
			'user_id'      => 0,
			'template_uid' => '11111111-2222-4333-8444-555555555555',
			'pass_uid'     => wp_generate_uuid4(),
			'pass_link'    => 'https://pass.example.test/' . $n,
			'status'       => 'active',
			'is_test'      => $is_test,
		)
	);
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = NOW() - INTERVAL %d DAY WHERE id = %d', $table, $days_ago, $wpdb->insert_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
};
$insert( 1, 4, 0 );
$insert( 2, 2, 0 );
$insert( 3, 0, 0 );
$insert( 4, 90, 1 ); // A test card long ago: not the first card.
epc_pf_same( 'age of the first real card, test cards ignored', (string) EPC_DB::first_real_pass_age_days(), '4' );
epc_pf_same( 'cards in that window', (string) EPC_DB::count_real_passes_last_days( 4 ), '3' );
$insert( 5, 45, 0 );
epc_pf_same( 'an older real card makes the age older', (string) EPC_DB::first_real_pass_age_days(), '45' );
epc_pf_same( 'the 30-day window leaves it out', (string) EPC_DB::count_real_passes_last_days( 30 ), '3' );

// Clean up.
$restore();

echo EPC_Plan_Forecast_Test_Counter::$passed, ' passed, ', EPC_Plan_Forecast_Test_Counter::$failed, " failed\n";
if ( EPC_Plan_Forecast_Test_Counter::$failed > 0 ) {
	exit( 1 );
}

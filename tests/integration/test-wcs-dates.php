<?php
/**
 * Integration test: WooCommerce Subscriptions, a status change and a date change in the same
 * request both reach the card.
 *
 * Before the fix, the status change left a flag that made the next save of that subscription
 * in the request (the new end date) be ignored, so the card kept the old date.
 *
 * Run: wp eval-file tests/integration/test-wcs-dates.php (needs WooCommerce Subscriptions).
 * Uses the fake API of fixtures/test-integration.php.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Module_Loader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-wcs-dates.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! function_exists( 'wcs_create_subscription' ) ) {
	echo "SKIP WooCommerce Subscriptions dates (WooCommerce Subscriptions is not active here)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Results (a class: wp eval-file runs this file inside a function).
 */
final class EPC_WCS_Dates_Test {
	/**
	 * Passed / failed.
	 *
	 * @var array<string, int>
	 */
	public static $result = array(
		'passed' => 0,
		'failed' => 0,
	);

	/**
	 * Field values sent to the API, last call last.
	 *
	 * @var array<int, string>
	 */
	public static $sent = array();

	/**
	 * Record a check.
	 *
	 * @param string $label Label.
	 * @param bool   $ok    Result.
	 * @param string $info  Shown on failure.
	 * @return void
	 */
	public static function check( $label, $ok, $info = '' ) {
		echo $ok ? 'PASS ' : 'FAIL ', $label, $ok || '' === $info ? '' : ' -- ' . $info, "\n";
		++self::$result[ $ok ? 'passed' : 'failed' ];
	}
}

$t = 'EPC_WCS_Dates_Test';
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false !== strpos( (string) $url, 'single-pass' ) ) {
			$body = json_decode( (string) ( $args['body'] ?? '' ), true );
			foreach ( (array) ( $body['fields'] ?? ( $body['additionalFieldsValue'] ?? array() ) ) as $field ) {
				if ( is_array( $field ) && 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' === ( $field['uid'] ?? '' ) ) {
					EPC_WCS_Dates_Test::$sent[] = (string) ( $field['fieldValue'] ?? '' );
				}
			}
		}
		return $pre;
	},
	1,
	3
);

$slug          = 'woocommerce-subscriptions';
$saved_enabled = get_option( 'epc_enabled_modules', null );
epc_test_fixture_setup();
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), array( $slug ) ) ) ) );
$module = EPC_Module_Loader::get_module_instance( $slug );
$module->init();
$saved_maps  = $module->get_mappings();
$saved_rules = get_option( $module->get_status_rules_option_key(), null );
delete_option( $module->get_status_rules_option_key() );

$product = new WC_Product_Simple();
$product->set_name( 'EPC test plan' );
$product->set_regular_price( '9' );
$product->save();
$module->save_mapping(
	$product->get_id(),
	array(
		'template_uid'  => '11111111-2222-4333-8444-555555555555',
		'field_mapping' => array(
			'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' => array(
				'type'   => 'source',
				'source' => 'end_date',
			),
		),
		'pass_fields'   => array(),
	)
);

$customer     = epc_test_fixture_user();
$subscription = wcs_create_subscription(
	array(
		'customer_id'      => $customer,
		'billing_period'   => 'month',
		'billing_interval' => 1,
		'start_date'       => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
	)
);
$subscription->add_product( $product, 1 );
$subscription->calculate_totals();
$subscription->save();
$id = (int) $subscription->get_id();
$t::check( 'pending: no card', null === EPC_DB::get_pass( $slug, (string) $id ) || '' === (string) EPC_DB::get_pass( $slug, (string) $id )->pass_uid );

// One admin save: the status becomes Active, then the schedule (end date) is saved.
$t::$sent     = array();
$subscription = wcs_get_subscription( $id );
$subscription->update_status( 'active' );
$row = EPC_DB::get_pass( $slug, (string) $id );
$t::check( 'active: card made', $row && 'active' === (string) $row->status );
$t::check( 'one call for it (the save and the status hook do not both send)', 1 === count( $t::$sent ), wp_json_encode( $t::$sent ) );

$end          = gmdate( 'Y-m-d H:i:s', strtotime( '+200 days' ) );
$subscription = wcs_get_subscription( $id );
$subscription->update_dates( array( 'end' => $end ) );
$subscription->save();
$expected = epc_format_pass_expiry_datetime( $end );
$t::check( 'the end date saved in the same request reaches the card', '' !== $expected && $expected === (string) end( $t::$sent ), $expected . ' / ' . wp_json_encode( $t::$sent ) );

// A later status change still applies its rule (On hold: update).
$t::$sent     = array();
$subscription = wcs_get_subscription( $id );
$subscription->update_status( 'on-hold' );
$t::check( 'on hold: the card is kept', 'active' === (string) EPC_DB::get_pass( $slug, (string) $id )->status );
$subscription = wcs_get_subscription( $id );
$subscription->update_status( 'cancelled' );
$t::check( 'cancelled: the card is voided', 'revoked' === (string) EPC_DB::get_pass( $slug, (string) $id )->status );

// Clean up.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
$wpdb->delete(
	EPC_DB::table_name(),
	array(
		'module'    => $slug,
		'source_id' => (string) $id,
	)
);
EPC_Pass_Issues::clear( $slug, (string) $id );
wcs_get_subscription( $id )->delete( true );
wp_delete_post( $product->get_id(), true );
update_option( $module->get_mappings_option_key(), $saved_maps );
if ( null === $saved_rules ) {
	delete_option( $module->get_status_rules_option_key() );
} else {
	update_option( $module->get_status_rules_option_key(), $saved_rules );
}
if ( null === $saved_enabled ) {
	delete_option( 'epc_enabled_modules' );
} else {
	update_option( 'epc_enabled_modules', $saved_enabled );
}
epc_test_fixture_teardown();

echo $t::$result['passed'], ' passed, ', $t::$result['failed'], " failed\n";
if ( $t::$result['failed'] > 0 ) {
	exit( 1 );
}

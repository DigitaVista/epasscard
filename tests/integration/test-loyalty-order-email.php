<?php
/**
 * Integration test: a customer's first loyalty order. The Processing email WooCommerce sends
 * already carries the loyalty card, because the points are awarded before that email.
 * Needs WooCommerce (skipped otherwise). Uses the fake API of fixtures/test-integration.php.
 *
 * Run: wp eval-file tests/integration/test-loyalty-order-email.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Loyalty_Order_Service' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-loyalty-order-email.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Emails' ) ) {
	echo "SKIP loyalty first-order email (WooCommerce is not active here)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Pass/fail counter and captured mail (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Loyalty_Email_Test_State {
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
	 * Mail sent: to, subject, message.
	 *
	 * @var array<int, array{to: string, subject: string, message: string}>
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
function epc_le_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Loyalty_Email_Test_State::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Loyalty_Email_Test_State::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

$saved_enabled = get_option( 'epc_enabled_modules', null );
epc_test_fixture_setup();
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), array( 'woocommerce-loyalty' ) ) ) ) );
$module = EPC_Module_Loader::get_module_instance( 'woocommerce-loyalty' );
$module->init();
$saved_maps = $module->get_mappings();
$module->save_mapping(
	1,
	array(
		'template_uid'  => '11111111-2222-4333-8444-555555555555',
		'field_mapping' => array(
			'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' => array(
				'type'   => 'source',
				'source' => 'points_balance',
			),
		),
		'pass_fields'   => array(),
	)
);

add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		EPC_Loyalty_Email_Test_State::$mail[] = array(
			'to'      => is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'],
			'subject' => (string) $atts['subject'],
			'message' => (string) $atts['message'],
		);
		return true;
	},
	1,
	2
);

$settings = EPC_Loyalty_Order_Service::get_settings();
$email    = EPC_Loyalty_Customer_Service::get_email_settings();
if ( ! in_array( 'processing', $settings['qualifying_statuses'], true ) || empty( $email['include_pass_on_order_emails'] ) || ! in_array( 'processing', $email['order_email_statuses'], true ) ) {
	echo "SKIP loyalty first-order email (this site does not earn points or show the card on Processing)\n";
} else {
	WC()->mailer();
	$customer = epc_test_fixture_user();
	$product  = new WC_Product_Simple();
	$product->set_name( 'Test beans' );
	$product->set_regular_price( '120' );
	$product->set_status( 'publish' );
	$product->save();

	$order = wc_create_order( array( 'customer_id' => $customer ) );
	$order->add_product( $product, 1 );
	$order->set_billing_email( get_userdata( $customer )->user_email );
	$order->set_billing_first_name( 'Mira' );
	$order->calculate_totals();
	$order->save();
	epc_le_check( 'a new customer has no loyalty card yet', null === EPC_DB::get_pass( 'woocommerce-loyalty', $customer ) );

	$order->update_status( 'processing' );
	$pass       = EPC_DB::get_pass( 'woocommerce-loyalty', $customer );
	$processing = array_values(
		array_filter(
			EPC_Loyalty_Email_Test_State::$mail,
			static function ( $mail ) use ( $customer ) {
				// WooCommerce's default subject: "Your {site_title} order has been received!".
				return get_userdata( $customer )->user_email === $mail['to'] && false !== stripos( $mail['subject'], 'order has been received' );
			}
		)
	);
	epc_le_check( 'paid: points awarded and the card made', $pass && '' !== (string) $pass->pass_link && null !== EPC_Loyalty_Ledger_Service::get_by_event_key( 'order_award:' . $order->get_id() ) );
	epc_le_check( 'WooCommerce sent the customer its Processing email', 1 === count( $processing ), wp_json_encode( wp_list_pluck( EPC_Loyalty_Email_Test_State::$mail, 'subject' ) ) );
	epc_le_check( 'that first email already has the loyalty card link', $pass && isset( $processing[0] ) && false !== strpos( $processing[0]['message'], (string) $pass->pass_link ) );
	$order->update_status( 'completed' );
	$awards = EPC_Loyalty_Ledger_Service::get_by_event_key( 'order_award:' . $order->get_id() );
	epc_le_check( 'completed later: the points are not awarded twice', $awards && (int) EPC_Loyalty_Account_Service::get( $customer )->lifetime_points === (int) $awards->points_delta );

	// Clean up.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE module = %s AND source_id = %s', EPC_DB::table_name(), 'woocommerce-loyalty', (string) $customer ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', EPC_DB::loyalty_accounts_table_name(), $customer ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', EPC_DB::loyalty_ledger_table_name(), $customer ) );
	$order->delete( true );
	$product->delete( true );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $customer );
}

update_option( $module->get_mappings_option_key(), $saved_maps );
if ( null === $saved_enabled ) {
	delete_option( 'epc_enabled_modules' );
} else {
	update_option( 'epc_enabled_modules', $saved_enabled );
}
epc_test_fixture_teardown();

echo EPC_Loyalty_Email_Test_State::$passed, ' passed, ', EPC_Loyalty_Email_Test_State::$failed, " failed\n";
if ( EPC_Loyalty_Email_Test_State::$failed > 0 ) {
	exit( 1 );
}

<?php
/**
 * Integration test for v17: WooCommerce product → wallet card (tickets, vouchers, product cards).
 * Needs WooCommerce (skipped otherwise). Uses the fake API of fixtures/test-integration.php.
 *
 * Run: wp eval-file tests/integration/test-product-cards.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_WC_Product_Cards' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-product-cards.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! function_exists( 'wc_create_order' ) ) {
	echo "SKIP product cards (WooCommerce is not active here)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Pass/fail counter and captured mail (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Product_Cards_Test_State {
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
	 * Card emails: recipient => first line.
	 *
	 * @var array<int, array{to: string, subject: string, first: string}>
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
function epc_pc_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Product_Cards_Test_State::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Product_Cards_Test_State::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * A simple product with wallet card settings.
 *
 * @param string               $name  Name.
 * @param string               $price Price.
 * @param array<string, string> $meta Meta.
 * @return int
 */
function epc_pc_product( $name, $price, array $meta ) {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( $price );
	$product->set_status( 'publish' );
	foreach ( $meta as $key => $value ) {
		$product->update_meta_data( $key, $value );
	}
	$product->save();
	return (int) $product->get_id();
}

/**
 * Card rows of an order, by module.
 *
 * @param EPC_Module_WC_Product_Cards $module Module.
 * @param WC_Order                    $order  Order.
 * @return array<string, object|null>
 */
function epc_pc_rows( $module, $order ) {
	$out = array();
	foreach ( $module->order_source_ids( wc_get_order( $order->get_id() ) ) as $source_id ) {
		$out[ $source_id ] = EPC_DB::get_pass( $module->get_slug(), $source_id );
	}
	return $out;
}

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) $admins[0] );

$slugs   = array( 'woocommerce-tickets', 'woocommerce-vouchers', 'woocommerce-product-cards' );
$saved   = array(
	'enabled' => get_option( 'epc_enabled_modules', null ),
	'email'   => get_option( EPC_Pass_Email::OPTION, null ),
);
epc_test_fixture_setup();
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), $slugs ) ) ) );
update_option( EPC_Pass_Email::OPTION, array_merge( EPC_Pass_Email::get_settings(), array( 'auto_on_create' => 1 ) ), false );
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		if ( false !== strpos( (string) $atts['subject'], 'wallet pass' ) ) {
			EPC_Product_Cards_Test_State::$mail[] = array(
				'to'      => (string) $atts['to'],
				'subject' => (string) $atts['subject'],
				'first'   => (string) strtok( (string) $atts['message'], "\n" ),
			);
		}
		return true;
	},
	1,
	2
);

$modules = array();
foreach ( $slugs as $slug ) {
	$modules[ $slug ] = EPC_Module_Loader::get_module_instance( $slug );
	$modules[ $slug ]->init();
}
$tickets  = $modules['woocommerce-tickets'];
$vouchers = $modules['woocommerce-vouchers'];
$cards    = $modules['woocommerce-product-cards'];

// Products.
$ticket_id  = epc_pc_product(
	'Test ticket',
	'18',
	array(
		EPC_WC_Product_Cards::META_TYPE        => 'ticket',
		EPC_WC_Product_Cards::META_EVENT_START => '2031-05-01 19:30',
		EPC_WC_Product_Cards::META_VENUE       => 'Back room',
	)
);
$voucher_id = epc_pc_product(
	'Test voucher',
	'40',
	array(
		EPC_WC_Product_Cards::META_TYPE        => 'voucher',
		EPC_WC_Product_Cards::META_VALID_UNTIL => '2031-03-31',
	)
);
$card_id    = epc_pc_product( 'Test pass', '9', array( EPC_WC_Product_Cards::META_TYPE => 'card' ) );
$plain_id   = epc_pc_product( 'Test coffee', '5', array() );
$mapping    = array(
	'template_uid'  => '11111111-2222-4333-8444-555555555555',
	'field_mapping' => array(
		'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' => array(
			'type'   => 'source',
			'source' => 'card_code',
		),
	),
	'pass_fields'   => array(),
);
$tickets->save_mapping( $ticket_id, $mapping );
$vouchers->save_mapping( $voucher_id, $mapping );
$cards->save_mapping( $card_id, $mapping );

$ticket_list  = wp_list_pluck( $tickets->get_mappable_entities(), 'id' );
$voucher_list = wp_list_pluck( $vouchers->get_mappable_entities(), 'id' );
epc_pc_check( 'each kind lists only its products', in_array( $ticket_id, $ticket_list, true ) && ! in_array( $voucher_id, $ticket_list, true ) && ! in_array( $plain_id, $ticket_list, true ) && in_array( $voucher_id, $voucher_list, true ) && ! in_array( $ticket_id, $voucher_list, true ) );
epc_pc_check( 'record ids are valid card record ids', 'i12_3' === EPC_DB::sanitize_source_id( EPC_WC_Product_Cards::source_id( 12, 3 ) ) && array( 12, 3 ) === EPC_WC_Product_Cards::parse_source_id( 'i12_3' ) && null === EPC_WC_Product_Cards::parse_source_id( 'x12_3' ) );
epc_pc_check( 'date and time input is checked', '2031-05-01 19:30' === EPC_WC_Product_Cards::sanitize_datetime( '2031-05-01T19:30' ) && '' === EPC_WC_Product_Cards::sanitize_datetime( '2031-02-30T10:00' ) && '' === EPC_WC_Product_Cards::sanitize_date( '31/03/2031' ) );

// A customer buys 2 tickets, a pass and a plain product.
$buyer = epc_test_fixture_user();
$order = wc_create_order( array( 'customer_id' => $buyer ) );
$order->add_product( wc_get_product( $ticket_id ), 2 );
$order->add_product( wc_get_product( $card_id ), 1 );
$order->add_product( wc_get_product( $plain_id ), 1 );
$order->set_billing_first_name( 'Bea' );
$order->set_billing_last_name( 'Stone' );
$order->set_billing_email( get_userdata( $buyer )->user_email );
$order->calculate_totals();
$order->save();
epc_pc_check( 'pending: no card yet', array( null, null ) === array_values( epc_pc_rows( $tickets, $order ) ) );
$order->update_status( 'processing' );
$ticket_rows = epc_pc_rows( $tickets, $order );
epc_pc_check( 'paid: one ticket per unit bought', 2 === count( $ticket_rows ) && 2 === count( array_filter( $ticket_rows ) ) && 'active' === reset( $ticket_rows )->status, wp_json_encode( array_keys( $ticket_rows ) ) );
epc_pc_check( 'the cards belong to the customer and the product', $buyer === (int) reset( $ticket_rows )->user_id && $ticket_id === (int) reset( $ticket_rows )->entity_id );
epc_pc_check( 'the pass card of the same order', 1 === count( array_filter( epc_pc_rows( $cards, $order ) ) ) );
epc_pc_check( 'no card for a product without a wallet card', 3 === count( $tickets->order_source_ids( $order ) ) + count( $cards->order_source_ids( $order ) ) );
epc_pc_check( 'card emails went to the customer, one per card', 3 === count( EPC_Product_Cards_Test_State::$mail ) && get_userdata( $buyer )->user_email === EPC_Product_Cards_Test_State::$mail[0]['to'], wp_json_encode( EPC_Product_Cards_Test_State::$mail ) );

$ids    = array_keys( $ticket_rows );
$item   = EPC_WC_Product_Cards::get_item( EPC_WC_Product_Cards::parse_source_id( $ids[0] )[0] );
$codes  = $item->get_meta( EPC_WC_Product_Cards::ITEM_CODES, true );
epc_pc_check( 'each ticket has its own code, kept on the order line', is_array( $codes ) && 2 === count( array_unique( $codes ) ) && 1 === preg_match( '/^[A-HJ-NP-Z2-9]{10}$/', (string) $codes[1] ) );
$tickets->sync_by_source_id( $ids[0], 'update' );
$item2 = EPC_WC_Product_Cards::get_item( EPC_WC_Product_Cards::parse_source_id( $ids[0] )[0] );
epc_pc_check( 'an update keeps the code', $codes === $item2->get_meta( EPC_WC_Product_Cards::ITEM_CODES, true ) );
$record = ( new ReflectionMethod( $tickets, 'resolve' ) );
$record->setAccessible( true );
$build = new ReflectionMethod( $tickets, 'build_values' );
$build->setAccessible( true );
$values = $build->invoke( $tickets, $record->invoke( $tickets, $ids[1] ) );
epc_pc_check( 'ticket values: unit, venue, event time, buyer', '2 of 2' === $values['unit_label'] && 'Back room' === $values['venue'] && '' !== $values['event_start'] && 'Bea Stone' === $values['user_full_name'] && $codes[2] === $values['card_code'], wp_json_encode( $values ) );

$order->update_status( 'completed' );
epc_pc_check( 'completed: still the same two tickets', 2 === count( array_filter( epc_pc_rows( $tickets, $order ) ) ) && 3 === count( EPC_Product_Cards_Test_State::$mail ) );
$order->update_status( 'refunded' );
$rows = epc_pc_rows( $tickets, $order );
epc_pc_check( 'refunded: the tickets are voided', 'revoked' === reset( $rows )->status && 'revoked' === end( $rows )->status );

// A guest buys a voucher.
EPC_Product_Cards_Test_State::$mail = array();
$guest = wc_create_order();
$guest->add_product( wc_get_product( $voucher_id ), 1 );
$guest->set_billing_first_name( 'Noah' );
$guest->set_billing_last_name( 'Guest' );
$guest->set_billing_email( 'noah.guest@example.test' );
$guest->calculate_totals();
$guest->save();
$guest->update_status( 'on-hold' );
epc_pc_check( 'on hold: nothing yet', array( null ) === array_values( epc_pc_rows( $vouchers, $guest ) ) );
$guest->update_status( 'processing' );
$vrows = epc_pc_rows( $vouchers, $guest );
$vrow  = reset( $vrows );
epc_pc_check( 'guest: the voucher card exists without an account', $vrow && 'active' === $vrow->status && 0 === (int) $vrow->user_id );
epc_pc_check( 'guest: the card email goes to the billing email', 1 === count( EPC_Product_Cards_Test_State::$mail ) && 'noah.guest@example.test' === EPC_Product_Cards_Test_State::$mail[0]['to'] && 0 === strpos( EPC_Product_Cards_Test_State::$mail[0]['first'], 'Hi Noah' ), wp_json_encode( EPC_Product_Cards_Test_State::$mail ) );
epc_pc_check( 'guest: the Cards list can show the buyer', array( 'name' => 'Noah Guest', 'email' => 'noah.guest@example.test' ) === $vouchers->get_record_customer( key( $vrows ) ) );
$vvalues = $build->invoke( $vouchers, $record->invoke( $vouchers, key( $vrows ) ) );
epc_pc_check( 'voucher: value is the price paid, expiry from the product', false !== strpos( $vvalues['voucher_value'], '40' ) && '' !== $vvalues['valid_until'] && 0 === strpos( (string) $vvalues['pass_expire_mysql'], '2031-03-31' ), wp_json_encode( $vvalues ) );
$listed = EPC_Pass_Email::get_passes_for_order( wc_get_order( $guest->get_id() ) );
epc_pc_check( 'the order email lists the voucher card', 1 === count( $listed ) && (int) $vrow->id === (int) $listed[0]->id );
$guest->update_status( 'cancelled' );
epc_pc_check( 'cancelled: the voucher is voided', 'revoked' === EPC_DB::get_pass( 'woocommerce-vouchers', key( $vrows ) )->status );

// Existing customers: units of paid orders bought before cards were turned on.
global $wpdb;
$old = wc_create_order( array( 'customer_id' => $buyer ) );
$old->add_product( wc_get_product( $ticket_id ), 3 );
$old->calculate_totals();
$old->save();
$old->update_status( 'completed' );
$old_ids = $tickets->order_source_ids( wc_get_order( $old->get_id() ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test: as if the cards were never made.
$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE module = 'woocommerce-tickets' AND source_id IN (%s, %s, %s)", EPC_DB::table_name(), $old_ids[0], $old_ids[1], $old_ids[2] ) );
$all = $tickets->get_backfill_source_ids( $ticket_id, 100, 0 );
epc_pc_check( 'existing customers: units of paid orders only (the refunded order is left out)', $old_ids === array_values( array_intersect( $all, $old_ids ) ) && array() === array_intersect( $all, $ids ), wp_json_encode( $all ) );
epc_pc_check( 'existing customers: paging counts cards', array( $old_ids[1] ) === $tickets->get_backfill_source_ids( $ticket_id, 1, array_search( $old_ids[1], $all, true ) ) );
epc_pc_check( 'existing customers: newest order line first', $tickets->get_backfill_sort_key( $old_ids[0] ) > $tickets->get_backfill_sort_key( $ids[1] ) );

// Product settings are saved from the product form.
$_POST = array(
	'epc_product_card_nonce' => wp_create_nonce( 'epc_product_card' ),
	'epc_wallet_card_on'     => 'yes',
	'epc_wallet_card_type'   => 'voucher',
	'epc_voucher_valid_until' => '2032-01-31',
);
$product = wc_get_product( $plain_id );
EPC_WC_Product_Cards::save_product_fields( $product );
$product->save();
epc_pc_check( 'product form: "Wallet card: Voucher" is saved', 'voucher' === EPC_WC_Product_Cards::get_type( $plain_id ) && '2032-01-31' === get_post_meta( $plain_id, EPC_WC_Product_Cards::META_VALID_UNTIL, true ) );
$_POST = array( 'epc_product_card_nonce' => wp_create_nonce( 'epc_product_card' ) );
$product = wc_get_product( $plain_id );
EPC_WC_Product_Cards::save_product_fields( $product );
$product->save();
epc_pc_check( 'product form: unticking turns it off', '' === EPC_WC_Product_Cards::get_type( $plain_id ) );
$_POST = array();

// Clean up: this test's cards only (the site may have its own).
$test_cards = array();
foreach ( array( $order, $guest, $old ) as $o ) {
	foreach ( $modules as $slug => $module ) {
		foreach ( $module->order_source_ids( wc_get_order( $o->get_id() ) ) as $source_id ) {
			$test_cards[] = array( $slug, $source_id );
		}
	}
}
foreach ( $test_cards as $card ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
	$wpdb->delete(
		EPC_DB::table_name(),
		array(
			'module'    => $card[0],
			'source_id' => $card[1],
		)
	);
	EPC_Pass_Issues::clear( $card[0], $card[1] );
}
foreach ( array( $order, $guest, $old ) as $o ) {
	$o->delete( true );
}
foreach ( array( $ticket_id, $voucher_id, $card_id, $plain_id ) as $pid ) {
	wp_delete_post( $pid, true );
}
foreach ( $slugs as $slug ) {
	$all_maps = $modules[ $slug ]->get_mappings();
	foreach ( array( $ticket_id, $voucher_id, $card_id ) as $pid ) {
		unset( $all_maps[ (string) $pid ] );
	}
	update_option( $modules[ $slug ]->get_mappings_option_key(), $all_maps );
}
foreach ( array( 'enabled' => 'epc_enabled_modules', 'email' => EPC_Pass_Email::OPTION ) as $key => $option ) {
	if ( null === $saved[ $key ] ) {
		delete_option( $option );
	} else {
		update_option( $option, $saved[ $key ] );
	}
}
epc_test_fixture_teardown();

echo EPC_Product_Cards_Test_State::$passed, ' passed, ', EPC_Product_Cards_Test_State::$failed, " failed\n";
if ( EPC_Product_Cards_Test_State::$failed > 0 ) {
	exit( 1 );
}

<?php
/**
 * Integration test: YITH gift card codes made by hand (no gift card product) get a card.
 * Needs WooCommerce and YITH Gift Cards (skipped otherwise). Uses the fake API of
 * fixtures/test-integration.php.
 *
 * Run: wp eval-file tests/integration/test-yith-gift-cards.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Module_Loader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-yith-gift-cards.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! class_exists( 'YITH_YWGC_Gift_Card' ) || ! class_exists( 'WC_Product_Gift_Card' ) || ! function_exists( 'wc_get_products' ) ) {
	echo "SKIP YITH gift cards (needs WooCommerce and YITH Gift Cards)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_YITH_Test_Counter {
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
function epc_yith_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_YITH_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_YITH_Test_Counter::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * A gift card code made by hand in YITH (Create code): no product, no order.
 *
 * @param float $amount Amount.
 * @return YITH_YWGC_Gift_Card
 */
function epc_yith_code_by_hand( $amount ) {
	$card                   = new YITH_YWGC_Gift_Card();
	$card->product_id       = 0;
	$card->gift_card_number = strtoupper( wp_generate_password( 12, false ) );
	$card->recipient        = 'hand.' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
	$card->recipient_name   = 'Hana';
	$card->update_amount( $amount );
	$card->update_balance( $amount );
	$card->save();
	return $card;
}

/**
 * Card row of a gift card, or null.
 *
 * @param int $card_id Card id.
 * @return object|null
 */
function epc_yith_row( $card_id ) {
	$row = EPC_DB::get_pass( 'yith-gift-cards', (string) $card_id );
	return $row && ! empty( $row->pass_uid ) ? $row : null;
}

$saved_enabled = get_option( 'epc_enabled_modules', null );
epc_test_fixture_setup();
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), array( 'yith-gift-cards' ) ) ) ) );
$module = EPC_Module_Loader::get_module_instance( 'yith-gift-cards' );
$module->init();
$saved_maps = $module->get_mappings();
$cache      = new ReflectionProperty( $module, 'sole_product' );
$cache->setAccessible( true );

$mapping = array(
	'template_uid'  => '11111111-2222-4333-8444-555555555555',
	'field_mapping' => array(
		'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' => array(
			'type'   => 'source',
			'source' => 'balance_formatted',
		),
	),
	'pass_fields'   => array(),
);

/**
 * A YITH gift card product.
 *
 * @param string $name Name.
 * @return int
 */
function epc_yith_product( $name ) {
	$product = new WC_Product_Gift_Card( 0 );
	$product->set_name( $name );
	$product->set_status( 'publish' );
	$product->save();
	return (int) $product->get_id();
}

$products = array();
$cards    = array();
$existing = wp_list_pluck( $module->get_mappable_entities(), 'id' );
if ( empty( $existing ) ) {
	$products[] = epc_yith_product( 'Test gift card' );
	$existing   = wp_list_pluck( $module->get_mappable_entities(), 'id' );
}

if ( 1 === count( $existing ) ) {
	$only = (int) $existing[0];
	$module->save_mapping( $only, $mapping );
	$cache->setValue( $module, null );

	// The shop has one gift card product: a code made by hand gets its design.
	$card    = epc_yith_code_by_hand( 30 );
	$cards[] = $card->ID;
	$module->process_deferred_cards();
	$row = epc_yith_row( $card->ID );
	epc_yith_check( 'one gift card product: a code made by hand gets a card', null !== $row && 'active' === $row->status );
	epc_yith_check( 'the card is listed under the gift card product', null !== $row && $only === (int) $row->entity_id );
	$mail = null !== $row ? EPC_Pass_Email::compose( $row ) : null;
	epc_yith_check( 'no account: the card email goes to the code\'s recipient', is_array( $mail ) && $card->recipient === $mail['to'], is_wp_error( $mail ) ? $mail->get_error_message() : wp_json_encode( $mail ) );
	epc_yith_check( 'Cards shows who it is for', array( 'name' => 'Hana', 'email' => (string) $card->recipient ) === $module->get_record_customer( $card->ID ) );

	// Give cards to existing customers finds codes made by hand too.
	$old     = epc_yith_code_by_hand( 20 );
	$cards[] = $old->ID;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test: as if the card were made before EpassCard.
	$GLOBALS['wpdb']->delete( EPC_DB::table_name(), array( 'module' => 'yith-gift-cards', 'source_id' => (string) $old->ID ) );
	$listed = array_filter( (array) $module->get_backfill_source_ids( $only, 1000, 0 ) );
	epc_yith_check( 'existing customers: a code made by hand is included', in_array( (int) $old->ID, array_map( 'intval', $listed ), true ) );

	// A second gift card product: a new code made by hand cannot be matched to a design.
	$products[] = epc_yith_product( 'Test gift card 2' );
	$cache->setValue( $module, null );
	$other   = epc_yith_code_by_hand( 15 );
	$cards[] = $other->ID;
	$module->process_deferred_cards();
	$issue = EPC_Pass_Issues::get( 'yith-gift-cards', (string) $other->ID );
	epc_yith_check( 'several products: no card, and the reason is under Need attention', null === epc_yith_row( $other->ID ) && is_array( $issue ) && 'epc_gift_card_no_product' === $issue['code'], wp_json_encode( $issue ) );
} else {
	echo "SKIP one gift card product (this site has several)\n";
}

// Clean up.
foreach ( $cards as $card_id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
	$GLOBALS['wpdb']->delete( EPC_DB::table_name(), array( 'module' => 'yith-gift-cards', 'source_id' => (string) $card_id ) );
	EPC_Pass_Issues::clear( 'yith-gift-cards', (string) $card_id );
	wp_delete_post( $card_id, true );
}
foreach ( $products as $product_id ) {
	wp_delete_post( $product_id, true );
}
update_option( $module->get_mappings_option_key(), $saved_maps );
if ( null === $saved_enabled ) {
	delete_option( 'epc_enabled_modules' );
} else {
	update_option( 'epc_enabled_modules', $saved_enabled );
}
epc_test_fixture_teardown();

echo EPC_YITH_Test_Counter::$passed, ' passed, ', EPC_YITH_Test_Counter::$failed, " failed\n";
if ( EPC_YITH_Test_Counter::$failed > 0 ) {
	exit( 1 );
}

<?php
/**
 * Integration test: WooCommerce ticket cards follow the units still bought.
 *
 * - A partial refund voids the cards of the refunded units.
 * - A lower quantity voids the cards of the units taken off.
 * - A line removed from the order voids its cards.
 * - Cancelling an order voids every card it ever had, also of units taken off without a hook.
 *
 * Run: wp eval-file tests/integration/test-product-cards-units.php (needs WooCommerce).
 * Uses the fake API of fixtures/test-integration.php.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Module_Loader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-product-cards-units.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! function_exists( 'wc_create_order' ) ) {
	echo "SKIP product card units (WooCommerce is not active here)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Results (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Product_Units_Test {
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
	 * Card record ids made by this test.
	 *
	 * @var array<int, string>
	 */
	public static $cards = array();

	/**
	 * Orders made by this test.
	 *
	 * @var array<int, int>
	 */
	public static $orders = array();

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

	/**
	 * A paid order of $qty tickets.
	 *
	 * @param int $buyer      Customer.
	 * @param int $product_id Ticket product.
	 * @param int $qty        Quantity.
	 * @return array{0: WC_Order, 1: int} Order and its line id.
	 */
	public static function paid_order( $buyer, $product_id, $qty ) {
		$order   = wc_create_order( array( 'customer_id' => $buyer ) );
		$item_id = (int) $order->add_product( wc_get_product( $product_id ), $qty );
		$order->set_billing_email( get_userdata( $buyer )->user_email );
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'processing' );
		self::$orders[] = $order->get_id();
		for ( $unit = 1; $unit <= $qty; $unit++ ) {
			self::$cards[] = EPC_WC_Product_Cards::source_id( $item_id, $unit );
		}
		return array( wc_get_order( $order->get_id() ), $item_id );
	}

	/**
	 * Card states of a line's units: "active", "revoked" or "none" per unit.
	 *
	 * @param int $item_id Line.
	 * @param int $units   Units to read.
	 * @return string e.g. "active,active,revoked"
	 */
	public static function states( $item_id, $units ) {
		$out = array();
		for ( $unit = 1; $unit <= $units; $unit++ ) {
			$row   = EPC_DB::get_pass( 'woocommerce-tickets', EPC_WC_Product_Cards::source_id( $item_id, $unit ) );
			$out[] = ! $row || '' === (string) $row->pass_uid ? 'none' : ( 'revoked' === (string) $row->status ? 'revoked' : 'active' );
		}
		return implode( ',', $out );
	}
}

$t      = 'EPC_Product_Units_Test';
$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) $admins[0] );

$saved_enabled = get_option( 'epc_enabled_modules', null );
epc_test_fixture_setup();
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), array( 'woocommerce-tickets' ) ) ) ) );
add_filter( 'pre_wp_mail', '__return_true', 1 );
$tickets = EPC_Module_Loader::get_module_instance( 'woocommerce-tickets' );
$tickets->init();

$product = new WC_Product_Simple();
$product->set_name( 'Units test ticket' );
$product->set_regular_price( '18' );
$product->set_status( 'publish' );
$product->update_meta_data( EPC_WC_Product_Cards::META_TYPE, 'ticket' );
$product->update_meta_data( EPC_WC_Product_Cards::META_EVENT_START, '2031-05-01 19:30' );
$product->save();
$ticket_id = (int) $product->get_id();
$tickets->save_mapping(
	$ticket_id,
	array(
		'template_uid'  => '11111111-2222-4333-8444-555555555555',
		'field_mapping' => array(
			'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' => array(
				'type'   => 'source',
				'source' => 'card_code',
			),
		),
		'pass_fields'   => array(),
	)
);
$buyer = epc_test_fixture_user();

// A. Partial refund of 1 of 3 tickets: the third card is voided, the order stays paid.
list( $a, $a_item ) = $t::paid_order( $buyer, $ticket_id, 3 );
$t::check( 'paid: 3 tickets, 3 cards', 'active,active,active' === $t::states( $a_item, 3 ), $t::states( $a_item, 3 ) );
$refund = wc_create_refund(
	array(
		'order_id'   => $a->get_id(),
		'amount'     => 18,
		'reason'     => 'One guest cannot come',
		'line_items' => array(
			$a_item => array(
				'qty'          => 1,
				'refund_total' => 18,
			),
		),
	)
);
$t::check( 'the partial refund is made', ! is_wp_error( $refund ), is_wp_error( $refund ) ? $refund->get_error_message() : '' );
$t::check( 'partial refund: the refunded ticket\'s card is voided', 'active,active,revoked' === $t::states( $a_item, 3 ), $t::states( $a_item, 3 ) );
$t::check( 'partial refund: the order still lists 2 cards', 2 === count( $tickets->order_source_ids( wc_get_order( $a->get_id() ) ) ) );
wc_get_order( $a->get_id() )->update_status( 'completed' );
$t::check( 'completed later: the refunded ticket stays voided', 'active,active,revoked' === $t::states( $a_item, 3 ), $t::states( $a_item, 3 ) );

// B. Admin lowers the quantity from 3 to 1 and saves the order items.
list( $b, $b_item ) = $t::paid_order( $buyer, $ticket_id, 3 );
require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
wc_save_order_items(
	$b->get_id(),
	array(
		'order_item_id'  => array( $b_item ),
		'order_item_qty' => array( $b_item => 1 ),
	)
);
$t::check( 'quantity lowered to 1: the 2 extra cards are voided', 'active,revoked,revoked' === $t::states( $b_item, 3 ), $t::states( $b_item, 3 ) );

// C. The quantity is lowered without the admin screen (no hook), then the order is cancelled.
list( $c, $c_item ) = $t::paid_order( $buyer, $ticket_id, 3 );
$line = $c->get_item( $c_item );
$line->set_quantity( 1 );
$line->save();
wc_get_order( $c->get_id() )->update_status( 'cancelled' );
$t::check( 'quantity lowered then cancelled: all 3 cards are voided', 'revoked,revoked,revoked' === $t::states( $c_item, 3 ), $t::states( $c_item, 3 ) );

// D. The ticket line is removed from the order.
list( $d, $d_item ) = $t::paid_order( $buyer, $ticket_id, 2 );
wc_delete_order_item( $d_item );
$t::check( 'line removed: its cards are voided', 'revoked,revoked' === $t::states( $d_item, 2 ), $t::states( $d_item, 2 ) );

// E. Full refund of every ticket.
list( $e, $e_item ) = $t::paid_order( $buyer, $ticket_id, 2 );
wc_create_refund(
	array(
		'order_id'   => $e->get_id(),
		'amount'     => 36,
		'line_items' => array(
			$e_item => array(
				'qty'          => 2,
				'refund_total' => 36,
			),
		),
	)
);
$t::check( 'full refund: the order is refunded and both cards are voided', 'refunded' === wc_get_order( $e->get_id() )->get_status() && 'revoked,revoked' === $t::states( $e_item, 2 ), wc_get_order( $e->get_id() )->get_status() . ' ' . $t::states( $e_item, 2 ) );

// Clean up.
global $wpdb;
foreach ( array_unique( $t::$cards ) as $source_id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
	$wpdb->delete(
		EPC_DB::table_name(),
		array(
			'module'    => 'woocommerce-tickets',
			'source_id' => $source_id,
		)
	);
	EPC_Pass_Issues::clear( 'woocommerce-tickets', $source_id );
}
foreach ( $t::$orders as $order_id ) {
	$order = wc_get_order( $order_id );
	if ( $order ) {
		foreach ( $order->get_refunds() as $r ) {
			$r->delete( true );
		}
		$order->delete( true );
	}
}
wp_delete_post( $ticket_id, true );
$maps = $tickets->get_mappings();
unset( $maps[ (string) $ticket_id ] );
update_option( $tickets->get_mappings_option_key(), $maps );
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

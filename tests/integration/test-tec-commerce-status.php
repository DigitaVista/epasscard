<?php
/**
 * Integration test: The Events Calendar + Tickets Commerce order and RSVP statuses.
 *
 * Uses the real Event Tickets order flow (Order::create() and Order::modify_status()):
 * - an unpaid (pending) order gets no card; the card comes when the order is completed;
 * - a failed (denied) payment, an abandoned order and a refund void the card;
 * - an RSVP V2 "Not going" reply gets no card, and changing it voids or issues the card.
 *
 * Needs The Events Calendar and Event Tickets with Tickets Commerce (skipped otherwise).
 * Uses the fake API of fixtures/test-integration.php.
 *
 * Run: wp eval-file tests/integration/test-tec-commerce-status.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Module_Loader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-tec-commerce-status.php (EpassCard active)\n" );
	exit( 1 );
}
if (
	! function_exists( 'tribe_create_event' )
	|| ! function_exists( 'tec_tickets_commerce_is_enabled' )
	|| ! tec_tickets_commerce_is_enabled()
	|| ! class_exists( 'TEC\\Tickets\\Commerce\\Order' )
	|| ! class_exists( 'TEC\\Tickets\\Commerce\\Gateways\\Stripe\\Gateway' )
) {
	echo "SKIP Tickets Commerce statuses (needs The Events Calendar and Event Tickets with Tickets Commerce)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_TEC_Status_Test_Counter {
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
function epc_ts_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_TEC_Status_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_TEC_Status_Test_Counter::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * Card state of an attendee: none, active or revoked.
 *
 * @param int $attendee_id Attendee id.
 * @return string
 */
function epc_ts_card( $attendee_id ) {
	$row = EPC_DB::get_pass( 'the-events-calendar', (string) $attendee_id );
	if ( ! $row || '' === (string) $row->pass_uid ) {
		return 'none';
	}

	return 'revoked' === (string) $row->status ? 'revoked' : 'active';
}

/**
 * Create a Tickets Commerce order for one ticket.
 *
 * @param int    $event_id  Event id.
 * @param int    $ticket_id Ticket id.
 * @param string $price     Price.
 * @param string $type      Item type.
 * @param array  $extra     Item extra data.
 * @return int Order id.
 */
function epc_ts_order( $event_id, $ticket_id, $price, $type = 'ticket', $extra = array() ) {
	$email = 'epc_t_' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test';
	$order = tribe( 'TEC\\Tickets\\Commerce\\Order' )->create(
		tribe( 'TEC\\Tickets\\Commerce\\Gateways\\Stripe\\Gateway' ),
		array(
			'title'                => 'EPC test order ' . wp_generate_password( 6, false, false ),
			'total_value'          => $price,
			'subtotal'             => $price,
			'items'                => array(
				array(
					'event_id'          => $event_id,
					'extra'             => $extra,
					'price'             => $price,
					'quantity'          => 1,
					'regular_price'     => $price,
					'regular_sub_total' => $price,
					'sub_total'         => $price,
					'ticket_id'         => $ticket_id,
					'type'              => $type,
				),
			),
			'gateway'              => 'stripe',
			'hash'                 => '',
			'currency'             => 'USD',
			'purchaser_user_id'    => 0,
			'purchaser_full_name'  => 'Rui Mendes',
			'purchaser_first_name' => 'Rui',
			'purchaser_last_name'  => 'Mendes',
			'purchaser_email'      => $email,
		)
	);

	return $order ? (int) $order->ID : 0;
}

/**
 * Move an order to a status, then run the queued attendee handling as at the end of a request.
 *
 * @param object $module   Module.
 * @param int    $order_id Order id.
 * @param string $slug     Status slug.
 * @return bool
 */
function epc_ts_status( $module, $order_id, $slug ) {
	$ok = tribe( 'TEC\\Tickets\\Commerce\\Order' )->modify_status( $order_id, $slug );
	clean_post_cache( $order_id );
	$module->process_queued_attendees();

	return $ok;
}

/**
 * Attendee ids of an order, including archived (trashed) ones.
 *
 * @param int $order_id Order id.
 * @return array<int, int>
 */
function epc_ts_attendees( $order_id ) {
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => 'tec_tc_attendee',
				'post_parent'    => $order_id,
				'post_status'    => 'any,trash',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		)
	);
}

$saved_enabled = get_option( 'epc_enabled_modules', null );
epc_test_fixture_setup();
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), array( 'the-events-calendar' ) ) ) ) );
$module = EPC_Module_Loader::get_module_instance( 'the-events-calendar' );
$module->init();
$saved_maps  = $module->get_mappings();
$saved_rules = get_option( $module->get_status_rules_option_key(), null );
delete_option( $module->get_status_rules_option_key() );

$event_id = tribe_create_event(
	array(
		'post_title'       => 'EPC test: tasting night',
		'post_status'      => 'publish',
		'EventStartDate'   => gmdate( 'Y-m-d', strtotime( '+9 days' ) ),
		'EventEndDate'     => gmdate( 'Y-m-d', strtotime( '+9 days' ) ),
		'EventStartHour'   => '18',
		'EventStartMinute' => '00',
		'EventEndHour'     => '21',
		'EventEndMinute'   => '00',
	)
);
$commerce  = tribe( 'TEC\\Tickets\\Commerce\\Module' );
$ticket_id = $commerce->ticket_add(
	$event_id,
	array(
		'ticket_name'        => 'Entry',
		'ticket_description' => '',
		'ticket_price'       => '12',
		'tribe-ticket'       => array(
			'mode'     => 'own',
			'capacity' => 100,
		),
	)
);
$rsvp_id   = $commerce->ticket_add(
	$event_id,
	array(
		'ticket_name'        => 'Free seat',
		'ticket_description' => '',
		'ticket_price'       => '0',
		'tribe-ticket'       => array(
			'mode'     => 'own',
			'capacity' => 100,
		),
	)
);
update_post_meta( $rsvp_id, '_type', 'tc-rsvp' );
$module->save_mapping(
	$event_id,
	array(
		'template_uid'  => '11111111-2222-4333-8444-555555555555',
		'field_mapping' => array(
			'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' => array(
				'type'   => 'source',
				'source' => 'event_title',
			),
		),
		'pass_fields'   => array(),
	)
);

$orders = array();

// A. Unpaid order: attendee is generated at Pending (Event Tickets default), but no card yet.
$order_a  = epc_ts_order( $event_id, $ticket_id, '12' );
$orders[] = $order_a;
epc_ts_status( $module, $order_a, 'pending' );
$att_a = epc_ts_attendees( $order_a );
epc_ts_check( 'pending order: Event Tickets made its attendee', 1 === count( $att_a ), wp_json_encode( $att_a ) );
$att_a = $att_a ? $att_a[0] : 0;
epc_ts_check( 'pending order: no card before payment', 'none' === epc_ts_card( $att_a ), epc_ts_card( $att_a ) );

// B. The payment completes: the card is issued.
epc_ts_status( $module, $order_a, 'completed' );
epc_ts_check( 'payment completed: card issued', 'active' === epc_ts_card( $att_a ), epc_ts_card( $att_a ) );

// C. Refund: the attendee is archived by Event Tickets and the card is voided.
epc_ts_status( $module, $order_a, 'refunded' );
epc_ts_check( 'refunded: card voided', 'revoked' === epc_ts_card( $att_a ), epc_ts_card( $att_a ) );

// D. Payment fails (denied) after Pending: no card is ever issued.
$order_d  = epc_ts_order( $event_id, $ticket_id, '12' );
$orders[] = $order_d;
epc_ts_status( $module, $order_d, 'pending' );
$att_d = epc_ts_attendees( $order_d );
$att_d = $att_d ? $att_d[0] : 0;
epc_ts_status( $module, $order_d, 'denied' );
epc_ts_check( 'payment denied: no card', 'none' === epc_ts_card( $att_d ), epc_ts_card( $att_d ) );

// E. A card issued before the payment failed is voided (sites whose "Pending" rule says issue).
update_option( $module->get_status_rules_option_key(), array_merge( $module->get_default_status_rules(), array( 'pending' => 'sync' ) ) );
$order_e  = epc_ts_order( $event_id, $ticket_id, '12' );
$orders[] = $order_e;
epc_ts_status( $module, $order_e, 'pending' );
$att_e = epc_ts_attendees( $order_e );
$att_e = $att_e ? $att_e[0] : 0;
epc_ts_check( 'rule set to issue while pending: card issued', 'active' === epc_ts_card( $att_e ), epc_ts_card( $att_e ) );
epc_ts_status( $module, $order_e, 'denied' );
epc_ts_check( 'then payment denied: card voided', 'revoked' === epc_ts_card( $att_e ), epc_ts_card( $att_e ) );

// F. Same, but the order is abandoned (Not completed): Event Tickets archives the attendee first.
$order_f  = epc_ts_order( $event_id, $ticket_id, '12' );
$orders[] = $order_f;
epc_ts_status( $module, $order_f, 'pending' );
$att_f = epc_ts_attendees( $order_f );
$att_f = $att_f ? $att_f[0] : 0;
epc_ts_status( $module, $order_f, 'not-completed' );
epc_ts_check( 'then order abandoned: card voided', 'revoked' === epc_ts_card( $att_f ) && 'trash' === get_post_status( $att_f ), epc_ts_card( $att_f ) . ' / ' . get_post_status( $att_f ) );
delete_option( $module->get_status_rules_option_key() );

// G. RSVP V2 "Not going": no card.
$order_g  = epc_ts_order( $event_id, $rsvp_id, '0', 'tc-rsvp', array( 'order_status' => 'no' ) );
$orders[] = $order_g;
epc_ts_status( $module, $order_g, 'pending' );
epc_ts_status( $module, $order_g, 'completed' );
$att_g = epc_ts_attendees( $order_g );
$att_g = $att_g ? $att_g[0] : 0;
epc_ts_check( 'RSVP V2: the reply is stored as "no"', 'no' === get_post_meta( $att_g, '_tec_tickets_commerce_rsvp_status', true ), (string) get_post_meta( $att_g, '_tec_tickets_commerce_rsvp_status', true ) );
epc_ts_check( 'RSVP V2 not going: no card', 'none' === epc_ts_card( $att_g ), epc_ts_card( $att_g ) );

// H. Changed to "Going": card issued. Changed back to "Not going": card voided.
update_post_meta( $att_g, '_tec_tickets_commerce_rsvp_status', 'yes' );
do_action( 'event_tickets_attendee_update', array( 'order_status' => 'yes' ), $att_g, $event_id );
$module->process_queued_attendees();
epc_ts_check( 'RSVP V2 changed to going: card issued', 'active' === epc_ts_card( $att_g ), epc_ts_card( $att_g ) );
update_post_meta( $att_g, '_tec_tickets_commerce_rsvp_status', 'no' );
do_action( 'event_tickets_attendee_update', array( 'order_status' => 'no' ), $att_g, $event_id );
$module->process_queued_attendees();
epc_ts_check( 'RSVP V2 changed to not going: card voided', 'revoked' === epc_ts_card( $att_g ), epc_ts_card( $att_g ) );

// I. RSVP V2 "Going": card issued.
$order_i  = epc_ts_order( $event_id, $rsvp_id, '0', 'tc-rsvp', array( 'order_status' => 'yes' ) );
$orders[] = $order_i;
epc_ts_status( $module, $order_i, 'pending' );
epc_ts_status( $module, $order_i, 'completed' );
$att_i = epc_ts_attendees( $order_i );
$att_i = $att_i ? $att_i[0] : 0;
epc_ts_check( 'RSVP V2 going: card issued', 'active' === epc_ts_card( $att_i ), epc_ts_card( $att_i ) );

// J. The Pending row is on the settings screen with "No card yet" as its default.
$statuses = $module->get_configurable_attendee_statuses();
epc_ts_check( 'settings list the Pending payment status', isset( $statuses['pending'] ) );
epc_ts_check( 'Pending payment default: no card', 'none' === $module->get_status_rule( 'pending' ), $module->get_status_rule( 'pending' ) );

// K. Rules saved before 1.2.x (no "pending" key) still give no card while pending.
update_option( $module->get_status_rules_option_key(), array( 'yes' => 'sync', 'no' => 'revoke', 'active' => 'sync', 'deleted' => 'revoke' ) );
epc_ts_check( 'older saved rules: Pending still no card', 'none' === $module->get_status_rule( 'pending' ), $module->get_status_rule( 'pending' ) );

// Clean up.
global $wpdb;
$attendees = array();
foreach ( $orders as $order_id ) {
	$attendees = array_merge( $attendees, epc_ts_attendees( $order_id ) );
}
foreach ( $attendees as $attendee_id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
	$wpdb->delete(
		EPC_DB::table_name(),
		array(
			'module'    => 'the-events-calendar',
			'source_id' => (string) $attendee_id,
		)
	);
	EPC_Pass_Issues::clear( 'the-events-calendar', (string) $attendee_id );
	wp_delete_post( $attendee_id, true );
}
foreach ( array_merge( $orders, array( $ticket_id, $rsvp_id, $event_id ) ) as $post_id ) {
	wp_delete_post( $post_id, true );
}
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

echo EPC_TEC_Status_Test_Counter::$passed, ' passed, ', EPC_TEC_Status_Test_Counter::$failed, " failed\n";
if ( EPC_TEC_Status_Test_Counter::$failed > 0 ) {
	exit( 1 );
}

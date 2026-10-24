<?php
/**
 * Integration test for v22: Tickera event tickets. Needs Tickera (skipped otherwise). Events,
 * ticket types, orders and tickets are saved as the WordPress posts and meta Tickera writes
 * (same keys and formats as Tickera 3.6's checkout and admin), so the integration sees them
 * through the same WordPress hooks. Uses the fake API of fixtures/test-integration.php.
 *
 * Run: wp eval-file tests/integration/test-tickera.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Module_Loader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-tickera.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! class_exists( '\\Tickera\\TC' ) ) {
	echo "SKIP Tickera tickets (Tickera is not active here)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Counters and what the cards were sent with (a class: wp eval-file runs this file inside a function).
 */
final class EPC_TK_Test_State {
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
	 * Values of the last sync per record.
	 *
	 * @var array<string, array<string, string>>
	 */
	public static $sent = array();

	/**
	 * Syncs per record.
	 *
	 * @var array<string, int>
	 */
	public static $syncs = array();

	/**
	 * Posts this test created.
	 *
	 * @var array<int, int>
	 */
	public static $posts = array();
}

/**
 * Record one check.
 *
 * @param string $label Check label.
 * @param bool   $ok    Result.
 * @param string $info  Extra detail on failure.
 * @return void
 */
function epc_tk_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_TK_Test_State::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_TK_Test_State::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * Card status of a ticket, or 'none'.
 *
 * @param int $ticket_id Ticket id.
 * @return string
 */
function epc_tk_card( $ticket_id ) {
	$pass = EPC_DB::get_pass( 'tickera', (string) $ticket_id );
	return $pass && ! empty( $pass->pass_uid ) ? (string) $pass->status : 'none';
}

/**
 * Insert a post with meta, remembered for cleanup.
 *
 * @param array<string, mixed> $post Post data.
 * @param array<string, mixed> $meta Meta.
 * @return int
 */
function epc_tk_post( array $post, array $meta = array() ) {
	$id                           = (int) wp_insert_post( $post );
	EPC_TK_Test_State::$posts[] = $id;
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
}

/**
 * An order with tickets, as Tickera's create_order() saves it.
 *
 * @param string                           $status  Order status.
 * @param int                              $type_id Ticket type id.
 * @param int                              $event   Event id.
 * @param array<int, array<string,string>> $owners  Attendees: first_name, last_name, owner_email.
 * @param string                           $buyer   Buyer email.
 * @return array{0: int, 1: array<int, int>} Order id and ticket ids.
 */
function epc_tk_order( $status, $type_id, $event, array $owners, $buyer ) {
	$number = strtoupper( wp_generate_password( 10, false, false ) );
	$order  = epc_tk_post(
		array(
			'post_title'  => $number,
			'post_name'   => $number,
			'post_status' => $status,
			'post_type'   => 'tc_orders',
		),
		array(
			'tc_cart_info' => array(
				'buyer_data' => array(
					'first_name_post_meta' => 'Rafi',
					'last_name_post_meta'  => 'Karim',
					'email_post_meta'      => $buyer,
				),
			),
		)
	);
	$tickets = array();
	foreach ( array_values( $owners ) as $n => $owner ) {
		$tickets[] = epc_tk_post(
			array(
				'post_parent' => $order,
				'post_status' => 'publish',
				'post_type'   => 'tc_tickets_instances',
			),
			array_merge(
				$owner,
				array(
					'ticket_type_id' => $type_id,
					'ticket_total'   => '15',
					'ticket_code'    => $number . '-' . ( $n + 1 ),
					'event_id'       => $event,
				)
			)
		);
	}
	return array( $order, $tickets );
}

$saved_tz      = get_option( 'timezone_string' );
$saved_offset  = get_option( 'gmt_offset' );
$saved_enabled = get_option( 'epc_enabled_modules', null );
update_option( 'timezone_string', 'Asia/Dhaka' ); // UTC+6: event times are the site's local time.
epc_test_fixture_setup();
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), array( 'tickera' ) ) ) ) );
$module = EPC_Module_Loader::get_module_instance( 'tickera' );
$module->init();
add_action(
	'epc_before_sync_pass',
	static function ( $slug, $source_id, $entity_id, $user_id, $mapping, $values ) {
		unset( $entity_id, $user_id, $mapping );
		EPC_TK_Test_State::$sent[ $slug . '|' . $source_id ]  = $values;
		EPC_TK_Test_State::$syncs[ $slug . '|' . $source_id ] = ( EPC_TK_Test_State::$syncs[ $slug . '|' . $source_id ] ?? 0 ) + 1;
	},
	10,
	6
);
$saved_maps    = $module->get_mappings();
$saved_default = get_option( EPC_Default_Design::OPTION_PREFIX . 'tickera', null );
$saved_rules   = get_option( $module->get_status_rules_option_key(), null );
delete_option( EPC_Default_Design::OPTION_PREFIX . 'tickera' );
delete_option( $module->get_status_rules_option_key() );
$design = array(
	'template_uid'  => '11111111-2222-4333-8444-555555555555',
	'field_mapping' => array(
		'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' => array(
			'type'   => 'source',
			'source' => 'ticket_code',
		),
	),
	'pass_fields'   => array(),
);
$with_check_in                  = $design;
$with_check_in['field_mapping'] += array(
	'aaaaaaaa-bbbb-4ccc-8ddd-ffffffffffff' => array(
		'type'   => 'source',
		'source' => 'checked_in',
	),
);

// An event in 5 days, 18:00-22:00 site time, with a ticket type.
$day   = wp_date( 'Y-m-d', time() + 5 * DAY_IN_SECONDS );
$event = epc_tk_post(
	array(
		'post_title'  => 'EPC test: latte art night',
		'post_status' => 'publish',
		'post_type'   => 'tc_events',
	),
	array(
		'event_date_time'     => $day . ' 18:00',
		'event_end_date_time' => $day . ' 22:00',
		'event_location'      => 'Brew &amp; Butter, 12 Harbour Street',
	)
);
$type  = epc_tk_post(
	array(
		'post_title'  => 'Standard',
		'post_status' => 'publish',
		'post_type'   => 'tc_tickets',
	),
	array(
		'event_name'       => $event,
		'price_per_ticket' => '15',
	)
);
$module->save_mapping( $event, $design );
epc_tk_check( 'events are the items', in_array( $event, wp_list_pluck( $module->get_mappable_entities(), 'id' ), true ) );
epc_tk_check( 'default rules: paid makes the card, received waits, cancelled/refunded/fraud void', array( 'order_paid' => 'sync', 'order_received' => 'none', 'order_cancelled' => 'revoke', 'order_refunded' => 'revoke', 'order_fraud' => 'revoke' ) === $module->get_status_rules() );

// A guest order waiting for an offline payment: no card yet.
$member                    = epc_test_fixture_user();
$member_email              = (string) get_userdata( $member )->user_email;
list( $order, $tickets )   = epc_tk_order(
	'order_received',
	$type,
	$event,
	array(
		array(
			'first_name'  => 'Leila',
			'last_name'   => 'Ahmed',
			'owner_email' => 'epc_t_leila@example.test',
		),
		array(
			'first_name'  => 'Jamie',
			'last_name'   => 'Lee',
			'owner_email' => $member_email,
		),
		array(
			'first_name' => 'Omar',
			'last_name'  => 'Haque',
		),
	),
	'epc_t_rafi@example.test'
);
list( $t1, $t2, $t3 ) = $tickets;
epc_tk_check( 'the change waits for the end of the request', 'none' === epc_tk_card( $t1 ) );
$module->flush();
epc_tk_check( 'order received (waiting for payment): no card', 'none' === epc_tk_card( $t1 ) && 'none' === epc_tk_card( $t3 ) );

// Paid (Tickera saves the new status with wp_update_post()).
wp_update_post(
	array(
		'ID'          => $order,
		'post_status' => 'order_paid',
	)
);
$module->flush();
epc_tk_check( 'order paid: one card per ticket', 'active' === epc_tk_card( $t1 ) && 'active' === epc_tk_card( $t2 ) && 'active' === epc_tk_card( $t3 ) );
$v     = EPC_TK_Test_State::$sent[ 'tickera|' . $t1 ] ?? array();
$start = ( new DateTimeImmutable( $day . ' 18:00', new DateTimeZone( 'Asia/Dhaka' ) ) )->getTimestamp();
$fmt   = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );
epc_tk_check( 'card values: attendee, event, local start time, location, ticket type, code', 'Leila Ahmed' === ( $v['user_full_name'] ?? '' ) && 'EPC test: latte art night' === ( $v['event_name'] ?? '' ) && wp_date( $fmt, $start ) === ( $v['event_start'] ?? '' ) && 'Brew & Butter, 12 Harbour Street' === ( $v['event_location'] ?? '' ) && 'Standard' === ( $v['ticket_type'] ?? '' ) && get_post_meta( $t1, 'ticket_code', true ) === ( $v['ticket_code'] ?? '' ) && 'Paid' === ( $v['order_status'] ?? '' ), wp_json_encode( $v ) );
epc_tk_check( 'event start is read as the site\'s local time (18:00 in Dhaka = 12:00 UTC)', gmdate( 'H:i', EPC_Module_Tickera::local_timestamp( $day . ' 18:00' ) ) === '12:00' );
$r1 = $module->get_guest_recipient( (string) $t1 );
epc_tk_check( 'attendee email without an account: the card goes to the attendee', is_array( $r1 ) && 'epc_t_leila@example.test' === $r1['email'] && 0 === (int) EPC_DB::get_pass( 'tickera', (string) $t1 )->user_id );
epc_tk_check( 'attendee email of an account: the card is linked to that account', $member === (int) EPC_DB::get_pass( 'tickera', (string) $t2 )->user_id && null === $module->get_guest_recipient( (string) $t2 ) );
$r3 = $module->get_guest_recipient( (string) $t3 );
epc_tk_check( 'no attendee email: the card goes to the buyer', is_array( $r3 ) && 'epc_t_rafi@example.test' === $r3['email'] && 'epc_t_rafi@example.test' === ( EPC_TK_Test_State::$sent[ 'tickera|' . $t3 ]['user_email'] ?? '' ) );

// wp-admin saves the order: its author becomes the admin. The buyer stays the guest.
wp_update_post(
	array(
		'ID'          => $order,
		'post_author' => 1,
	)
);
$module->flush();
epc_tk_check( 'order saved in wp-admin: the buyer\'s card is not moved to the admin', 0 === (int) EPC_DB::get_pass( 'tickera', (string) $t3 )->user_id );

// Check-in: only a design that shows it gets an update.
$before = EPC_TK_Test_State::$syncs[ 'tickera|' . $t1 ] ?? 0;
$local  = strtotime( wp_date( 'Y-m-d H:i:s' ) ); // Tickera's tickera_timestamp_to_local().
update_post_meta(
	$t1,
	'tc_checkins',
	array(
		array(
			'date_checked' => $local,
			'status'       => 'Pass',
			'api_key_id'   => 1,
			'direction'    => 'in',
		),
	)
);
$module->flush();
epc_tk_check( 'check-in, design without the field: no update', ( EPC_TK_Test_State::$syncs[ 'tickera|' . $t1 ] ?? 0 ) === $before );
$module->save_mapping( $event, $with_check_in );
update_post_meta(
	$t1,
	'tc_checkins',
	array(
		array(
			'date_checked' => $local,
			'status'       => 'Pass',
			'api_key_id'   => 1,
			'direction'    => 'in',
		),
		array(
			'date_checked' => $local + 60,
			'status'       => 'Fail',
			'api_key_id'   => 1,
			'direction'    => 'in',
		),
	)
);
$module->flush();
epc_tk_check( 'check-in, design with the field: updated with the last check-in that passed, as Tickera shows it', date_i18n( $fmt, $local ) === ( EPC_TK_Test_State::$sent[ 'tickera|' . $t1 ]['checked_in'] ?? '' ) && ( EPC_TK_Test_State::$syncs[ 'tickera|' . $t1 ] ?? 0 ) === $before + 1, (string) ( EPC_TK_Test_State::$sent[ 'tickera|' . $t1 ]['checked_in'] ?? '' ) );
delete_post_meta( $t1, 'tc_checkins' );
$module->flush();
epc_tk_check( 'check-in deleted: the card says "Not yet"', 'Not yet' === ( EPC_TK_Test_State::$sent[ 'tickera|' . $t1 ]['checked_in'] ?? '' ) );
epc_tk_check( 'a ticket without a card is not made by a check-in', ( function () use ( $module, $t3 ) {
	global $wpdb;
	$wpdb->delete( EPC_DB::table_name(), array( 'module' => 'tickera', 'source_id' => (string) $t3 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test: a ticket without a card.
	update_post_meta( $t3, 'tc_checkins', array( array( 'date_checked' => time(), 'status' => 'Pass', 'direction' => 'in' ) ) );
	$module->flush();
	$none = 'none' === epc_tk_card( $t3 );
	$module->handle_ticket( $t3 );
	return $none && 'active' === epc_tk_card( $t3 );
} )() );

// The attendee renamed in Tickera.
update_post_meta( $t1, 'first_name', 'Layla' );
$module->flush();
epc_tk_check( 'attendee renamed: the card shows the new name', 'Layla Ahmed' === ( EPC_TK_Test_State::$sent[ 'tickera|' . $t1 ]['user_full_name'] ?? '' ) );

// Cancelled, then paid again.
$uid = (string) EPC_DB::get_pass( 'tickera', (string) $t1 )->pass_uid;
wp_update_post(
	array(
		'ID'          => $order,
		'post_status' => 'order_cancelled',
	)
);
$module->flush();
epc_tk_check( 'order cancelled: its cards are voided', 'revoked' === epc_tk_card( $t1 ) && 'revoked' === epc_tk_card( $t2 ) );
epc_tk_check( 'the existing-tickets list leaves cancelled orders out', ! in_array( (string) $t1, $module->get_backfill_source_ids( $event, 100, 0 ), true ) );
wp_update_post(
	array(
		'ID'          => $order,
		'post_status' => 'order_paid',
	)
);
$module->flush();
epc_tk_check( 'paid again: the same card is live', 'active' === epc_tk_card( $t1 ) && $uid === (string) EPC_DB::get_pass( 'tickera', (string) $t1 )->pass_uid );
epc_tk_check( 'the existing-tickets list has the paid tickets', in_array( (string) $t1, $module->get_backfill_source_ids( $event, 100, 0 ), true ) );

// One ticket trashed (Tickera's attendee list).
wp_trash_post( $t2 );
$module->flush();
epc_tk_check( 'ticket trashed: its card is voided', 'revoked' === epc_tk_card( $t2 ) && 'active' === epc_tk_card( $t1 ) );

// The event moved: its cards are refreshed in the background.
wp_clear_scheduled_hook( EPC_Module_Tickera::REFRESH_HOOK, array( $event, 0 ) );
$later = wp_date( 'Y-m-d', time() + 9 * DAY_IN_SECONDS );
update_post_meta( $event, 'event_date_time', $later . ' 19:00' );
epc_tk_check( 'event moved: a refresh is scheduled', false !== wp_next_scheduled( EPC_Module_Tickera::REFRESH_HOOK, array( $event, 0 ) ) );
$module->refresh_event( $event, 0 );
$moved = ( new DateTimeImmutable( $later . ' 19:00', new DateTimeZone( 'Asia/Dhaka' ) ) )->getTimestamp();
epc_tk_check( 'refresh: live cards show the new start', wp_date( $fmt, $moved ) === ( EPC_TK_Test_State::$sent[ 'tickera|' . $t1 ]['event_start'] ?? '' ) );
wp_clear_scheduled_hook( EPC_Module_Tickera::REFRESH_HOOK, array( $event, 0 ) );

// Tickera's order page: a Wallet column with the card link.
$columns = apply_filters( 'tickera_front_ticket_table_columns', array( array( 'id' => 'ticket_code' ) ) );
ob_start();
$module->render_wallet_cell( 'ticket_code', '', $t1 );
$cell = (string) ob_get_clean();
epc_tk_check( 'order page: a Wallet column links the card', 'epasscard_wallet' === ( end( $columns )['id'] ?? '' ) && false !== strpos( $cell, (string) EPC_DB::get_pass( 'tickera', (string) $t1 )->pass_link ) );

// After the event: expired by the daily check, and no card comes back.
$past = wp_date( 'Y-m-d', time() - 3 * DAY_IN_SECONDS );
update_post_meta( $event, 'event_date_time', $past . ' 18:00' );
update_post_meta( $event, 'event_end_date_time', $past . ' 22:00' );
$module->process_scheduled_notification_for_pass( EPC_DB::get_pass( 'tickera', (string) $t1 ), array() );
epc_tk_check( 'daily check: the ticket of a finished event is expired', 'revoked' === epc_tk_card( $t1 ) );
$module->handle_ticket( $t1 );
epc_tk_check( 'a finished event\'s ticket does not get its card back', 'revoked' === epc_tk_card( $t1 ) );
epc_tk_check( 'the existing-tickets list leaves finished events out', array() === $module->get_backfill_source_ids( $event, 100, 0 ) );
update_post_meta( $event, 'event_date_time', $day . ' 18:00' );
update_post_meta( $event, 'event_end_date_time', $day . ' 22:00' );

// The order deleted for good in wp-admin (its tickets left behind).
$module->handle_ticket( $t3 );
wp_delete_post( $order, true );
$module->flush();
epc_tk_check( 'order deleted: the cards of its tickets are voided', 'revoked' === epc_tk_card( $t3 ) );

// A ticket whose order is not a Tickera order (e.g. Bridge for WooCommerce) is left alone.
$other  = epc_tk_post(
	array(
		'post_title'  => 'Not a Tickera order',
		'post_status' => 'publish',
		'post_type'   => 'post',
	)
);
$orphan = epc_tk_post(
	array(
		'post_parent' => $other,
		'post_status' => 'publish',
		'post_type'   => 'tc_tickets_instances',
	),
	array(
		'ticket_type_id' => $type,
		'event_id'       => $event,
	)
);
$module->flush();
epc_tk_check( 'ticket of a non-Tickera order: no card', null === $module->resolve_ticket( $orphan ) && 'none' === epc_tk_card( $orphan ) );

// Clean up.
global $wpdb;
foreach ( EPC_TK_Test_State::$posts as $id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup: only this test's cards.
	$wpdb->delete( EPC_DB::table_name(), array( 'module' => 'tickera', 'source_id' => (string) $id ) );
	EPC_Pass_Issues::clear( 'tickera', (string) $id );
	wp_delete_post( $id, true );
}
$module->flush();
wp_clear_scheduled_hook( EPC_Module_Tickera::REFRESH_HOOK, array( $event, 0 ) );
update_option( $module->get_mappings_option_key(), $saved_maps );
foreach ( array( EPC_Default_Design::OPTION_PREFIX . 'tickera' => $saved_default, $module->get_status_rules_option_key() => $saved_rules ) as $option => $value ) {
	if ( null === $value ) {
		delete_option( $option );
	} else {
		update_option( $option, $value, false );
	}
}
if ( null === $saved_enabled ) {
	delete_option( 'epc_enabled_modules' );
} else {
	update_option( 'epc_enabled_modules', $saved_enabled );
}
update_option( 'timezone_string', $saved_tz );
update_option( 'gmt_offset', $saved_offset );
epc_test_fixture_teardown();

echo EPC_TK_Test_State::$passed, ' passed, ', EPC_TK_Test_State::$failed, " failed\n";
if ( EPC_TK_Test_State::$failed > 0 ) {
	exit( 1 );
}

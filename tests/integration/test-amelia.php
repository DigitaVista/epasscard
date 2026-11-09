<?php
/**
 * Integration test for v20: Amelia appointment and event cards. Needs Amelia (skipped
 * otherwise). Bookings are written to Amelia's tables and Amelia's own hooks are fired with
 * the arrays Amelia passes (booking, appointment and event toArray() keys). Uses the fake API
 * of fixtures/test-integration.php.
 *
 * Run: wp eval-file tests/integration/test-amelia.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Module_Loader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-amelia.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! defined( 'AMELIA_VERSION' ) ) {
	echo "SKIP Amelia cards (Amelia is not active here)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Counters and what the cards were sent with (a class: wp eval-file runs this file inside a function).
 */
final class EPC_AM_Test_State {
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
	 * Rows this test wrote, per Amelia table.
	 *
	 * @var array<string, array<int, int>>
	 */
	public static $rows = array();
}

/**
 * Record one check.
 *
 * @param string $label Check label.
 * @param bool   $ok    Result.
 * @param string $info  Extra detail on failure.
 * @return void
 */
function epc_am_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_AM_Test_State::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_AM_Test_State::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * Insert a row into an Amelia table and remember it for cleanup.
 *
 * @param string               $table Table name without the amelia_ prefix.
 * @param array<string, mixed> $data  Row.
 * @return int Row id.
 */
function epc_am_insert( $table, array $data ) {
	global $wpdb;
	$wpdb->insert( $wpdb->prefix . 'amelia_' . $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test data.
	$id                                    = (int) $wpdb->insert_id;
	EPC_AM_Test_State::$rows[ $table ][] = $id;
	return $id;
}

/**
 * Update an Amelia row.
 *
 * @param string               $table Table name without the amelia_ prefix.
 * @param int                  $id    Row id.
 * @param array<string, mixed> $data  Changes.
 * @return void
 */
function epc_am_update( $table, $id, array $data ) {
	global $wpdb;
	$wpdb->update( $wpdb->prefix . 'amelia_' . $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test data.
}

/**
 * Card status of a record, or 'none'.
 *
 * @param string     $slug      Module slug.
 * @param string|int $source_id Record id.
 * @return string
 */
function epc_am_card( $slug, $source_id ) {
	$pass = EPC_DB::get_pass( $slug, (string) $source_id );
	return $pass && ! empty( $pass->pass_uid ) ? (string) $pass->status : 'none';
}

/**
 * A booking as Amelia's toArray() gives it (the keys this integration reads).
 *
 * @param int $id Booking id.
 * @return array<string, mixed>
 */
function epc_am_booking_array( $id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test read.
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, appointmentId, customerId, status, persons FROM %i WHERE id = %d', $wpdb->prefix . 'amelia_customer_bookings', $id ), ARRAY_A );
	return array_map(
		static function ( $v ) {
			return is_numeric( $v ) ? (int) $v : $v;
		},
		(array) $row
	);
}

$saved_enabled = get_option( 'epc_enabled_modules', null );
epc_test_fixture_setup();
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), array( 'amelia-appointments', 'amelia-events' ) ) ) ) );
$appointments = EPC_Module_Loader::get_module_instance( 'amelia-appointments' );
$events       = EPC_Module_Loader::get_module_instance( 'amelia-events' );
$appointments->init();
$events->init();
add_action(
	'epc_before_sync_pass',
	static function ( $module, $source_id, $entity_id, $user_id, $mapping, $values ) {
		unset( $entity_id, $user_id, $mapping );
		EPC_AM_Test_State::$sent[ $module . '|' . $source_id ] = $values;
	},
	10,
	6
);

$saved = array();
foreach ( array( $appointments, $events ) as $m ) {
	$saved[ $m->get_slug() ] = array(
		'maps'     => $m->get_mappings(),
		'default'  => get_option( EPC_Default_Design::OPTION_PREFIX . $m->get_slug(), null ),
		'per'      => get_option( $m->get_cards_per_option_key(), null ),
		'rules'    => get_option( $m->get_status_rules_option_key(), null ),
	);
	delete_option( EPC_Default_Design::OPTION_PREFIX . $m->get_slug() );
	delete_option( $m->get_cards_per_option_key() );
	delete_option( $m->get_status_rules_option_key() );
}
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

// Amelia data: an employee, a service, a customer with an account and a guest customer.
$wp_user  = epc_test_fixture_user();
$provider = epc_am_insert( 'users', array( 'type' => 'provider', 'status' => 'visible', 'firstName' => 'Test', 'lastName' => 'Employee', 'email' => 'epc_t_emp@example.test' ) );
$client   = epc_am_insert( 'users', array( 'type' => 'customer', 'status' => 'visible', 'firstName' => 'Kim', 'lastName' => 'Park', 'email' => (string) get_userdata( $wp_user )->user_email, 'externalId' => $wp_user ) );
$guest    = epc_am_insert( 'users', array( 'type' => 'customer', 'status' => 'visible', 'firstName' => 'Ana', 'lastName' => 'Lima', 'email' => 'epc_t_ana@example.test' ) );
$service  = epc_am_insert( 'services', array( 'name' => 'EPC test: tasting', 'status' => 'visible', 'categoryId' => 1, 'price' => 0, 'duration' => 3600, 'minCapacity' => 1, 'maxCapacity' => 5 ) );
$appointments->save_mapping( $service, $design );

$start = gmdate( 'Y-m-d H:i:s', strtotime( '+3 days 10:00 UTC' ) );
$end   = gmdate( 'Y-m-d H:i:s', strtotime( '+3 days 11:00 UTC' ) );
$appt  = epc_am_insert( 'appointments', array( 'status' => 'approved', 'bookingStart' => $start, 'bookingEnd' => $end, 'serviceId' => $service, 'providerId' => $provider ) );
$b1    = epc_am_insert( 'customer_bookings', array( 'appointmentId' => $appt, 'customerId' => $guest, 'status' => 'approved', 'persons' => 1, 'price' => 0 ) );
$appt_array = array(
	'id'           => $appt,
	'serviceId'    => $service,
	'bookingStart' => $start,
	'bookingEnd'   => $end,
	'status'       => 'approved',
	'bookings'     => array( epc_am_booking_array( $b1 ) ),
);

// A guest books online.
do_action( 'amelia_after_appointment_booking_saved', epc_am_booking_array( $b1 ), array( 'id' => $service ), $appt_array );
epc_am_check( 'the change waits for the end of the request', 'none' === epc_am_card( 'amelia-appointments', $b1 ) );
$appointments->flush();
epc_am_check( 'approved booking: card created', 'active' === epc_am_card( 'amelia-appointments', $b1 ) );
$values = EPC_AM_Test_State::$sent[ 'amelia-appointments|' . $b1 ] ?? array();
$local  = wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) strtotime( $start . ' UTC' ) );
epc_am_check( 'card values: service, employee, start in the site time zone', 'EPC test: tasting' === ( $values['service_name'] ?? '' ) && 'Test Employee' === ( $values['provider_name'] ?? '' ) && $local === ( $values['booking_start'] ?? '' ), wp_json_encode( $values ) );
$recipient = $appointments->get_guest_recipient( (string) $b1 );
epc_am_check( 'guest booking: the card email goes to the customer', is_array( $recipient ) && 'epc_t_ana@example.test' === $recipient['email'] );

// Canceled and approved again (booking status hooks).
epc_am_update( 'customer_bookings', $b1, array( 'status' => 'canceled' ) );
do_action( 'amelia_after_booking_canceled', array( 'booking' => epc_am_booking_array( $b1 ) ) );
$appointments->flush();
epc_am_check( 'booking canceled: card voided', 'revoked' === epc_am_card( 'amelia-appointments', $b1 ) );
$uid = (string) EPC_DB::get_pass( 'amelia-appointments', (string) $b1 )->pass_uid;
epc_am_update( 'customer_bookings', $b1, array( 'status' => 'approved' ) );
do_action( 'amelia_after_booking_approved', array( 'booking' => epc_am_booking_array( $b1 ) ) );
$appointments->flush();
epc_am_check( 'approved again: the same card is live', 'active' === epc_am_card( 'amelia-appointments', $b1 ) && $uid === (string) EPC_DB::get_pass( 'amelia-appointments', (string) $b1 )->pass_uid );

// Rescheduled: the card gets the new time.
$new_start = gmdate( 'Y-m-d H:i:s', strtotime( '+4 days 14:00 UTC' ) );
epc_am_update( 'appointments', $appt, array( 'bookingStart' => $new_start, 'bookingEnd' => gmdate( 'Y-m-d H:i:s', strtotime( '+4 days 15:00 UTC' ) ) ) );
unset( EPC_AM_Test_State::$sent[ 'amelia-appointments|' . $b1 ] );
do_action( 'amelia_after_booking_rescheduled', $appt_array, null, $new_start );
$appointments->flush();
$local = wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) strtotime( $new_start . ' UTC' ) );
epc_am_check( 'rescheduled: the card shows the new time', $local === ( EPC_AM_Test_State::$sent[ 'amelia-appointments|' . $b1 ]['booking_start'] ?? '' ) );

// The whole appointment canceled.
epc_am_update( 'appointments', $appt, array( 'status' => 'canceled' ) );
do_action( 'amelia_after_appointment_status_updated', $appt_array, 'canceled' );
$appointments->flush();
epc_am_check( 'appointment canceled: its bookings\' cards are voided', 'revoked' === epc_am_card( 'amelia-appointments', $b1 ) );
epc_am_update( 'appointments', $appt, array( 'status' => 'approved' ) );

// Pending waits.
$b2 = epc_am_insert( 'customer_bookings', array( 'appointmentId' => $appt, 'customerId' => $client, 'status' => 'pending', 'persons' => 1, 'price' => 0 ) );
do_action( 'amelia_after_appointment_booking_saved', epc_am_booking_array( $b2 ), array( 'id' => $service ), $appt_array );
$appointments->flush();
epc_am_check( 'pending booking: no card yet', 'none' === epc_am_card( 'amelia-appointments', $b2 ) );
epc_am_update( 'customer_bookings', $b2, array( 'status' => 'approved' ) );
do_action( 'amelia_after_booking_approved', array( 'booking' => epc_am_booking_array( $b2 ) ) );
$appointments->flush();
$pass = EPC_DB::get_pass( 'amelia-appointments', (string) $b2 );
epc_am_check( 'approved later: card, linked to the customer\'s account', 'active' === epc_am_card( 'amelia-appointments', $b2 ) && $wp_user === (int) $pass->user_id );

// One card per person (setting on the integration page, saved with the status rules).
$_POST = array( 'epc_cards_per' => 'person' );
$saved_ok = ( function () use ( $appointments ) {
	$method = new ReflectionMethod( $appointments, 'save_status_rules_extra' );
	$method->setAccessible( true );
	$method->invoke( $appointments );
	return 'person' === $appointments->cards_per();
} )();
$_POST = array();
epc_am_check( 'setting: one card per person can be chosen', $saved_ok );
$b3 = epc_am_insert( 'customer_bookings', array( 'appointmentId' => $appt, 'customerId' => $guest, 'status' => 'approved', 'persons' => 3, 'price' => 0 ) );
do_action( 'amelia_after_appointment_booking_saved', epc_am_booking_array( $b3 ), array( 'id' => $service ), $appt_array );
$appointments->flush();
epc_am_check( 'one card per person: 3 people, 3 cards', 'active' === epc_am_card( 'amelia-appointments', 'p' . $b3 . '_1' ) && 'active' === epc_am_card( 'amelia-appointments', 'p' . $b3 . '_2' ) && 'active' === epc_am_card( 'amelia-appointments', 'p' . $b3 . '_3' ) && 'none' === epc_am_card( 'amelia-appointments', $b3 ) );
epc_am_check( 'one card per person: "2 of 3" and its own code', '2 of 3' === ( EPC_AM_Test_State::$sent[ 'amelia-appointments|' . 'p' . $b3 . '_2' ]['unit_label'] ?? '' ) && $b3 . '-2' === ( EPC_AM_Test_State::$sent[ 'amelia-appointments|' . 'p' . $b3 . '_2' ]['ticket_code'] ?? '' ) );
epc_am_update( 'customer_bookings', $b3, array( 'persons' => 2 ) );
update_option( $appointments->get_cards_per_option_key(), 'booking', false );
do_action( 'amelia_after_appointment_updated', $appt_array, $appt_array, null, null, null );
$appointments->flush();
epc_am_check( 'one person less: that card is voided; the booking keeps cards per person', 'revoked' === epc_am_card( 'amelia-appointments', 'p' . $b3 . '_3' ) && 'active' === epc_am_card( 'amelia-appointments', 'p' . $b3 . '_2' ) && 'none' === epc_am_card( 'amelia-appointments', $b3 ) );
epc_am_check( 'the existing-bookings list has a card per person', in_array( 'p' . $b3 . '_1', $appointments->get_backfill_source_ids( $service, 100, 0 ), true ) && in_array( (string) $b1, $appointments->get_backfill_source_ids( $service, 100, 0 ), true ) );

// The day after it ends: expired by the daily check; a past booking gets no new card.
$past     = epc_am_insert( 'appointments', array( 'status' => 'approved', 'bookingStart' => gmdate( 'Y-m-d H:i:s', strtotime( '-3 days 10:00 UTC' ) ), 'bookingEnd' => gmdate( 'Y-m-d H:i:s', strtotime( '-3 days 11:00 UTC' ) ), 'serviceId' => $service, 'providerId' => $provider ) );
epc_am_update( 'customer_bookings', $b2, array( 'appointmentId' => $past ) );
$appointments->process_scheduled_notification_for_pass( EPC_DB::get_pass( 'amelia-appointments', (string) $b2 ), array() );
epc_am_check( 'daily check: finished booking\'s card expired', 'revoked' === epc_am_card( 'amelia-appointments', $b2 ) );
do_action( 'amelia_after_booking_approved', array( 'booking' => epc_am_booking_array( $b2 ) ) );
$appointments->flush();
epc_am_check( 'a finished booking does not get its card back', 'revoked' === epc_am_card( 'amelia-appointments', $b2 ) );
epc_am_check( 'the existing-bookings list leaves finished bookings out', ! in_array( (string) $b2, $appointments->get_backfill_source_ids( $service, 100, 0 ), true ) );

// The appointment deleted (Amelia deletes its bookings).
global $wpdb;
$wpdb->delete( $wpdb->prefix . 'amelia_customer_bookings', array( 'id' => $b1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test data.
do_action( 'amelia_after_appointment_deleted', array( 'id' => $appt, 'serviceId' => $service, 'bookingStart' => $start, 'bookings' => array() ) );
$appointments->flush();
epc_am_check( 'booking deleted: its card is voided', 'revoked' === epc_am_card( 'amelia-appointments', $b1 ) );

// Events: a booking, then the event canceled.
$event  = epc_am_insert( 'events', array( 'name' => 'EPC test: cupping', 'status' => 'approved', 'bookingOpens' => null, 'bookingCloses' => null, 'maxCapacity' => 10, 'price' => 0, 'show' => 1 ) );
$period = epc_am_insert( 'events_periods', array( 'eventId' => $event, 'periodStart' => gmdate( 'Y-m-d H:i:s', strtotime( '+5 days 18:00 UTC' ) ), 'periodEnd' => gmdate( 'Y-m-d H:i:s', strtotime( '+5 days 21:00 UTC' ) ) ) );
$eb     = epc_am_insert( 'customer_bookings', array( 'appointmentId' => null, 'customerId' => $guest, 'status' => 'approved', 'persons' => 2, 'price' => 0 ) );
epc_am_insert( 'customer_bookings_to_events_periods', array( 'customerBookingId' => $eb, 'eventPeriodId' => $period ) );
$events->save_mapping( $event, $design );
epc_am_check( 'events are the items', in_array( $event, wp_list_pluck( $events->get_mappable_entities(), 'id' ), true ) );
do_action( 'amelia_after_event_booking_saved', epc_am_booking_array( $eb ), array( 'id' => $event, 'periods' => array(), 'bookingOpens' => null ) );
$appointments->flush();
$events->flush();
epc_am_check( 'event booking: one ticket for the booking (default)', 'active' === epc_am_card( 'amelia-events', $eb ) && 'none' === epc_am_card( 'amelia-appointments', $eb ) );
epc_am_check( 'event ticket values: event name and people', 'EPC test: cupping' === ( EPC_AM_Test_State::$sent[ 'amelia-events|' . $eb ]['event_name'] ?? '' ) && '2' === ( EPC_AM_Test_State::$sent[ 'amelia-events|' . $eb ]['persons'] ?? '' ) );

// A second booking of the event: only that booking gets a card; the first is not sent again
// (Amelia passes the whole event, with all its bookings, to the hook).
$eb2 = epc_am_insert( 'customer_bookings', array( 'appointmentId' => null, 'customerId' => $client, 'status' => 'approved', 'persons' => 1, 'price' => 0 ) );
epc_am_insert( 'customer_bookings_to_events_periods', array( 'customerBookingId' => $eb2, 'eventPeriodId' => $period ) );
EPC_AM_Test_State::$sent = array();
do_action(
	'amelia_after_event_booking_saved',
	epc_am_booking_array( $eb2 ),
	array(
		'id'           => $event,
		'periods'      => array(),
		'bookingOpens' => null,
		'bookings'     => array( epc_am_booking_array( $eb ), epc_am_booking_array( $eb2 ) ),
	)
);
$events->flush();
epc_am_check( 'second event booking: its own card', 'active' === epc_am_card( 'amelia-events', $eb2 ) );
epc_am_check( 'second event booking: the first booking\'s card is not sent again', array( 'amelia-events|' . $eb2 ) === array_keys( EPC_AM_Test_State::$sent ), wp_json_encode( array_keys( EPC_AM_Test_State::$sent ) ) );

epc_am_update( 'events', $event, array( 'status' => 'canceled' ) );
do_action( 'amelia_after_event_status_updated', array( 'id' => $event, 'periods' => array(), 'bookingOpens' => null, 'status' => 'canceled' ), 'canceled', false );
$events->flush();
epc_am_check( 'event canceled: its tickets are voided', 'revoked' === epc_am_card( 'amelia-events', $eb ) );

// Clean up.
foreach ( array( 'amelia-appointments', 'amelia-events' ) as $slug ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup: only this test's cards.
	$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT source_id FROM %i WHERE module = %s', EPC_DB::table_name(), $slug ) );
	foreach ( (array) $ids as $sid ) {
		$parsed = EPC_Module_Amelia_Bookings::parse_source_id( (string) $sid );
		if ( null !== $parsed && in_array( $parsed[0], EPC_AM_Test_State::$rows['customer_bookings'] ?? array(), true ) ) {
			$wpdb->delete( EPC_DB::table_name(), array( 'module' => $slug, 'source_id' => (string) $sid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
			EPC_Pass_Issues::clear( $slug, (string) $sid );
		}
	}
}
foreach ( EPC_AM_Test_State::$rows as $table => $ids ) {
	foreach ( $ids as $id ) {
		$wpdb->delete( $wpdb->prefix . 'amelia_' . $table, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
	}
}
foreach ( array( $appointments, $events ) as $m ) {
	$s = $saved[ $m->get_slug() ];
	update_option( $m->get_mappings_option_key(), $s['maps'] );
	foreach ( array( EPC_Default_Design::OPTION_PREFIX . $m->get_slug() => $s['default'], $m->get_cards_per_option_key() => $s['per'], $m->get_status_rules_option_key() => $s['rules'] ) as $option => $value ) {
		if ( null === $value ) {
			delete_option( $option );
		} else {
			update_option( $option, $value, false );
		}
	}
}
if ( null === $saved_enabled ) {
	delete_option( 'epc_enabled_modules' );
} else {
	update_option( 'epc_enabled_modules', $saved_enabled );
}
epc_test_fixture_teardown();

echo EPC_AM_Test_State::$passed, ' passed, ', EPC_AM_Test_State::$failed, " failed\n";
if ( EPC_AM_Test_State::$failed > 0 ) {
	exit( 1 );
}

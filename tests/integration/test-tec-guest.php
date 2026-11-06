<?php
/**
 * Integration test: The Events Calendar + Event Tickets. A guest's RSVP gets a card, and the
 * card email goes to the attendee's email. Needs The Events Calendar and Event Tickets (skipped
 * otherwise). Uses the fake API of fixtures/test-integration.php.
 *
 * Run: wp eval-file tests/integration/test-tec-guest.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Module_Loader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-tec-guest.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! function_exists( 'tribe_create_event' ) || ! function_exists( 'tribe_tickets_get_ticket_provider' ) ) {
	echo "SKIP The Events Calendar guests (needs The Events Calendar and Event Tickets)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_TEC_Guest_Test_Counter {
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
function epc_tg_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_TEC_Guest_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_TEC_Guest_Test_Counter::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

$saved_enabled = get_option( 'epc_enabled_modules', null );
epc_test_fixture_setup();
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), array( 'the-events-calendar' ) ) ) ) );
$module = EPC_Module_Loader::get_module_instance( 'the-events-calendar' );
$module->init();
$saved_maps = $module->get_mappings();

$event_id  = tribe_create_event(
	array(
		'post_title'       => 'EPC test: cupping',
		'post_status'      => 'publish',
		'EventStartDate'   => gmdate( 'Y-m-d', strtotime( '+6 days' ) ),
		'EventEndDate'     => gmdate( 'Y-m-d', strtotime( '+6 days' ) ),
		'EventStartHour'   => '10',
		'EventStartMinute' => '00',
		'EventEndHour'     => '12',
		'EventEndMinute'   => '00',
	)
);
$ticket_id = tribe( 'tickets.rsvp' )->ticket_add(
	$event_id,
	array(
		'ticket_name'        => 'Seat',
		'ticket_description' => '',
	)
);
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

$guest    = 'epc_t_guest_' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test';
$provider = tribe_tickets_get_ticket_provider( $ticket_id );
$attendee = $provider->create_attendee(
	$ticket_id,
	array(
		'full_name' => 'Ines Duarte',
		'email'     => $guest,
	)
);
$attendee_id = is_object( $attendee ) ? (int) $attendee->ID : (int) $attendee;
$module->process_queued_attendees();
$row = EPC_DB::get_pass( 'the-events-calendar', (string) $attendee_id );
epc_tg_check( 'a guest RSVP gets a card', $row && '' !== (string) $row->pass_uid && 0 === (int) $row->user_id, wp_json_encode( $row ) );
$mail = $row ? EPC_Pass_Email::compose( $row ) : null;
epc_tg_check( 'its card email goes to the attendee\'s email', is_array( $mail ) && $guest === $mail['to'] && 0 === strpos( $mail['body'], 'Hi Ines' ), is_wp_error( $mail ) ? $mail->get_error_message() : wp_json_encode( $mail ) );
epc_tg_check( 'Cards shows who it is for', array( 'name' => 'Ines Duarte', 'email' => $guest ) === $module->get_record_customer( (string) $attendee_id ) );

// Clean up.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
$wpdb->delete(
	EPC_DB::table_name(),
	array(
		'module'    => 'the-events-calendar',
		'source_id' => (string) $attendee_id,
	)
);
EPC_Pass_Issues::clear( 'the-events-calendar', (string) $attendee_id );
foreach ( array( $attendee_id, $ticket_id, $event_id ) as $post_id ) {
	wp_delete_post( $post_id, true );
}
update_option( $module->get_mappings_option_key(), $saved_maps );
if ( null === $saved_enabled ) {
	delete_option( 'epc_enabled_modules' );
} else {
	update_option( 'epc_enabled_modules', $saved_enabled );
}
epc_test_fixture_teardown();

echo EPC_TEC_Guest_Test_Counter::$passed, ' passed, ', EPC_TEC_Guest_Test_Counter::$failed, " failed\n";
if ( EPC_TEC_Guest_Test_Counter::$failed > 0 ) {
	exit( 1 );
}

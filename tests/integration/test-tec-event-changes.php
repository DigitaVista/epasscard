<?php
/**
 * Integration test: The Events Calendar, the event's date changes and its cards are updated
 * (in the background).
 *
 * Needs The Events Calendar and Event Tickets (skipped otherwise). Uses the fake API of
 * fixtures/test-integration.php.
 *
 * Run: wp eval-file tests/integration/test-tec-event-changes.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Module_Loader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-tec-event-changes.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! function_exists( 'tribe_create_event' ) || ! function_exists( 'tribe_update_event' ) || ! function_exists( 'tribe_tickets_get_ticket_provider' ) ) {
	echo "SKIP The Events Calendar event changes (needs The Events Calendar and Event Tickets)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Results (a class: wp eval-file runs this file inside a function).
 */
final class EPC_TEC_Changes_Test {
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
	 * Bodies of the pass updates sent.
	 *
	 * @var array<int, string>
	 */
	public static $updates = array();

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

$t = 'EPC_TEC_Changes_Test';
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false !== strpos( (string) $url, 'update-single-pass' ) ) {
			EPC_TEC_Changes_Test::$updates[] = (string) ( $args['body'] ?? '' );
		}
		return $pre;
	},
	1,
	3
);

$saved_enabled = get_option( 'epc_enabled_modules', null );
epc_test_fixture_setup();
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), array( 'the-events-calendar' ) ) ) ) );
$module = EPC_Module_Loader::get_module_instance( 'the-events-calendar' );
$module->init();
$saved_maps = $module->get_mappings();

$day      = gmdate( 'Y-m-d', strtotime( '+8 days' ) );
$event_id = tribe_create_event(
	array(
		'post_title'       => 'EPC test: tasting',
		'post_status'      => 'publish',
		'EventStartDate'   => $day,
		'EventEndDate'     => $day,
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
				'source' => 'event_start',
			),
		),
		'pass_fields'   => array(),
	)
);
$attendee    = tribe_tickets_get_ticket_provider( $ticket_id )->create_attendee(
	$ticket_id,
	array(
		'full_name' => 'Lena Ortiz',
		'email'     => 'epc_t_' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test',
	)
);
$attendee_id = is_object( $attendee ) ? (int) $attendee->ID : (int) $attendee;
$module->process_queued_attendees();
$row = EPC_DB::get_pass( 'the-events-calendar', (string) $attendee_id );
$t::check( 'the attendee has a card', $row && 'active' === (string) $row->status );

// The event moves to another day.
$new_day = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
tribe_update_event(
	$event_id,
	array(
		'EventStartDate'   => $new_day,
		'EventEndDate'     => $new_day,
		'EventStartHour'   => '10',
		'EventStartMinute' => '00',
		'EventEndHour'     => '12',
		'EventEndMinute'   => '00',
	)
);
$job = array( $event_id, 0 );
$t::check( 'date changed: the cards\' update is queued once', false !== wp_next_scheduled( EPC_Module_The_Events_Calendar::REFRESH_HOOK, $job ) );
// The job runs in a later request; drop this request's memo of the event dates (tribe_get_var).
if ( function_exists( 'tribe_set_var' ) ) {
	tribe_set_var( 'tribe_get_start_date', array() );
	tribe_set_var( 'tribe_get_end_date', array() );
}
$t::$updates = array();
do_action_ref_array( EPC_Module_The_Events_Calendar::REFRESH_HOOK, $job );
$format = new ReflectionMethod( $module, 'format_event_datetime' );
$format->setAccessible( true );
$new_start = (string) $format->invoke( $module, $event_id, 'start' );
$sent      = 1 === count( $t::$updates ) ? json_decode( $t::$updates[0], true ) : array();
$t::check( 'the job updates the card with the new date', '' !== $new_start && false !== strpos( (string) $new_start, (string) (int) gmdate( 'j', strtotime( $new_day ) ) ) && $new_start === (string) ( $sent['fields'][0]['fieldValue'] ?? '' ), $new_start . ' / ' . wp_json_encode( $t::$updates ) );
$t::check( 'the card stays live', 'active' === (string) EPC_DB::get_pass( 'the-events-calendar', (string) $attendee_id )->status );

// Clean up.
$next = wp_next_scheduled( EPC_Module_The_Events_Calendar::REFRESH_HOOK, $job );
if ( $next ) {
	wp_unschedule_event( $next, EPC_Module_The_Events_Calendar::REFRESH_HOOK, $job );
}
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

echo $t::$result['passed'], ' passed, ', $t::$result['failed'], " failed\n";
if ( $t::$result['failed'] > 0 ) {
	exit( 1 );
}

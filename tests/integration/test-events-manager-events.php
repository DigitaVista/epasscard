<?php
/**
 * Integration test: Events Manager, the event changes and its booking cards follow.
 *
 * - Bookings turned off for the event: the cards are voided.
 * - The event moved to the trash: the cards are voided; restored: live again.
 * - The event deleted for good: the cards are voided.
 * - The event's date, location or name changes: its cards are updated (in the background).
 *
 * Run: wp eval-file tests/integration/test-events-manager-events.php (needs Events Manager).
 * Uses the fake API of fixtures/test-integration.php.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Module_Loader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-events-manager-events.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! class_exists( 'EM_Event' ) || ! defined( 'EM_BOOKINGS_TABLE' ) ) {
	echo "SKIP Events Manager event changes (Events Manager is not active here)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Results (a class: wp eval-file runs this file inside a function).
 */
final class EPC_EM_Events_Test {
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
	 * Booking ids made by this test.
	 *
	 * @var array<int, int>
	 */
	public static $bookings = array();

	/**
	 * Event post ids made by this test.
	 *
	 * @var array<int, int>
	 */
	public static $posts = array();

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

	/**
	 * An event with bookings on, its design, and $count approved bookings with cards.
	 *
	 * @param object $module Module.
	 * @param string $name   Event name.
	 * @param int    $count  Bookings.
	 * @return array{0: EM_Event, 1: array<int, int>} Event and booking ids.
	 */
	public static function event( $module, $name, $count ) {
		global $wpdb;
		$event                   = new EM_Event();
		$event->event_name       = $name;
		$event->post_content     = '';
		$event->event_start_date = gmdate( 'Y-m-d', strtotime( '+10 days' ) );
		$event->event_end_date   = $event->event_start_date;
		$event->event_start_time = '18:00:00';
		$event->event_end_time   = '20:00:00';
		$event->event_timezone   = 'UTC';
		$event->event_rsvp       = 1;
		$event->force_status     = 'publish';
		$event->save();
		self::$posts[] = (int) $event->post_id;
		$module->save_mapping(
			(int) $event->event_id,
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
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test booking, as Events Manager stores one.
			$wpdb->insert(
				EM_BOOKINGS_TABLE,
				array(
					'event_id'       => (int) $event->event_id,
					'person_id'      => get_current_user_id(),
					'booking_spaces' => 1,
					'booking_status' => 1,
					'booking_date'   => current_time( 'mysql' ),
					'booking_price'  => 0,
				)
			);
			$id               = (int) $wpdb->insert_id;
			$ids[]            = $id;
			self::$bookings[] = $id;
			$module->sync_by_source_id( $id, 'sync' );
		}
		return array( $event, $ids );
	}

	/**
	 * Card states of bookings, joined: "active,active".
	 *
	 * @param array<int, int> $ids Booking ids.
	 * @return string
	 */
	public static function cards( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$pass  = EPC_DB::get_pass( 'events-manager', (string) $id );
			$out[] = $pass && ! empty( $pass->pass_uid ) ? (string) $pass->status : 'none';
		}
		return implode( ',', $out );
	}
}

global $wpdb;
$t      = 'EPC_EM_Events_Test';
$slug   = 'events-manager';
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
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), array( $slug ) ) ) ) );
$module = EPC_Module_Loader::get_module_instance( $slug );
$module->init();
$saved_maps = $module->get_mappings();

// Bookings turned off: Events Manager deletes the event's bookings (EM_Bookings::delete()).
list( $off, $off_ids ) = $t::event( $module, 'EPC test: bookings off', 2 );
$t::check( 'two bookings, two live cards', 'active,active' === $t::cards( $off_ids ), $t::cards( $off_ids ) );
$off->get_bookings()->delete();
$t::check( 'bookings turned off: both cards voided', 'revoked,revoked' === $t::cards( $off_ids ), $t::cards( $off_ids ) );

// Moved to the trash, then restored.
list( $trash, $trash_ids ) = $t::event( $module, 'EPC test: trashed', 2 );
wp_trash_post( (int) $trash->post_id );
$t::check( 'event in the trash: both cards voided', 'revoked,revoked' === $t::cards( $trash_ids ), $t::cards( $trash_ids ) );
wp_untrash_post( (int) $trash->post_id );
$t::check( 'event restored: both cards live again', 'active,active' === $t::cards( $trash_ids ), $t::cards( $trash_ids ) );

// Deleted for good, the way Events Manager does it (it deletes the bookings too).
list( $gone, $gone_ids ) = $t::event( $module, 'EPC test: deleted', 2 );
$gone->delete( true );
$t::check( 'event deleted: both cards voided', 'revoked,revoked' === $t::cards( $gone_ids ), $t::cards( $gone_ids ) );

// Deleted for good without Events Manager's admin code (e.g. WP-CLI): the bookings stay, the cards go.
list( $bare, $bare_ids ) = $t::event( $module, 'EPC test: post deleted', 2 );
wp_delete_post( (int) $bare->post_id, true );
$t::check( 'event post deleted: both cards voided', 'revoked,revoked' === $t::cards( $bare_ids ), $t::cards( $bare_ids ) );

// One booking deleted (as before).
list( , $one_ids ) = $t::event( $module, 'EPC test: one booking deleted', 2 );
em_get_booking( $one_ids[0] )->delete();
$t::check( 'one booking deleted: only its card voided', 'revoked,active' === $t::cards( $one_ids ), $t::cards( $one_ids ) );

// The date and the name change: the card is updated by the background job.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false !== strpos( (string) $url, 'update-single-pass' ) ) {
			EPC_EM_Events_Test::$updates[] = (string) ( $args['body'] ?? '' );
		}
		return $pre;
	},
	1,
	3
);
list( $moved, $moved_ids ) = $t::event( $module, 'EPC test: moved', 1 );
$moved                    = em_get_event( (int) $moved->event_id );
$moved->event_start_date  = gmdate( 'Y-m-d', strtotime( '+40 days' ) );
$moved->event_end_date    = $moved->event_start_date;
$moved->event_name        = 'EPC test: moved (new date)';
$moved->save();
$job = array( (int) $moved->event_id, 0, 'cards' );
$t::check( 'date changed: the cards\' update is queued once', false !== wp_next_scheduled( EPC_Module_Events_Manager::REFRESH_HOOK, $job ) );
$t::$updates = array();
do_action_ref_array( EPC_Module_Events_Manager::REFRESH_HOOK, $job );
$format = new ReflectionMethod( $module, 'format_em_event_datetime' );
$format->setAccessible( true );
$new_start = (string) $format->invoke( $module, em_get_event( (int) $moved->event_id ), 'start' );
$sent      = 1 === count( $t::$updates ) ? json_decode( $t::$updates[0], true ) : array();
$t::check( 'the job updates the card with the new date', '' !== $new_start && $new_start === (string) ( $sent['fields'][0]['fieldValue'] ?? '' ), $new_start . ' / ' . wp_json_encode( $t::$updates ) );
$t::check( 'the card stays live', 'active' === $t::cards( $moved_ids ), $t::cards( $moved_ids ) );
wp_unschedule_event( (int) wp_next_scheduled( EPC_Module_Events_Manager::REFRESH_HOOK, $job ), EPC_Module_Events_Manager::REFRESH_HOOK, $job );

// Clean up.
foreach ( $t::$bookings as $id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test cleanup.
	$wpdb->delete( EM_BOOKINGS_TABLE, array( 'booking_id' => $id ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test cleanup.
	$wpdb->delete(
		EPC_DB::table_name(),
		array(
			'module'    => $slug,
			'source_id' => (string) $id,
		)
	);
	EPC_Pass_Issues::clear( $slug, (string) $id );
}
foreach ( $t::$posts as $post_id ) {
	if ( get_post( $post_id ) ) {
		wp_delete_post( $post_id, true );
	}
}
wp_clear_scheduled_hook( EPC_Module_Events_Manager::REFRESH_HOOK );
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

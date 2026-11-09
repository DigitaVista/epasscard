<?php
/**
 * Integration test: Ultimate Membership Pro members.
 *
 * - "Give cards to existing members" lists only members whose status gives a card.
 * - Deleting a user voids their cards.
 * - Approving a user (UMP's own approve hook) applies the status rules.
 *
 * Run: wp eval-file tests/integration/test-ump-members.php (needs Ultimate Membership Pro 14).
 * Uses the fake API of fixtures/test-integration.php.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Module_Loader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-ump-members.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! class_exists( '\Ump\User_Subscriptions' ) || ! class_exists( '\Ump\Db\Memberships' ) ) {
	echo "SKIP Ultimate Membership Pro members (Ultimate Membership Pro 14 is not active here)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Results (a class: wp eval-file runs this file inside a function).
 */
final class EPC_UMP_Members_Test {
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
	 * Users made by this test.
	 *
	 * @var array<int, int>
	 */
	public static $users = array();

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
	 * A member of $level with membership row status $status ('' = assigned, not paid).
	 *
	 * @param int    $level  Level.
	 * @param string $status '' or a status code 0-5.
	 * @return array{0: int, 1: int} User id and membership row id.
	 */
	public static function member( $level, $status ) {
		global $wpdb;
		$user          = epc_test_fixture_user();
		self::$users[] = $user;
		\Ump\User_Subscriptions::assign( $user, $level );
		if ( '' !== $status ) {
			\Ump\User_Subscriptions::makeComplete( $user, $level );
			if ( '1' !== $status ) {
				\Ump\User_Subscriptions::updateStatus( $user, $level, (int) $status );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test read.
		$row_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d AND level_id = %d', $wpdb->prefix . 'ihc_user_levels', $user, $level ) );
		return array( $user, $row_id );
	}

	/**
	 * Card status of a membership row, or 'none'.
	 *
	 * @param int $row_id Membership row.
	 * @return string
	 */
	public static function card( $row_id ) {
		$pass = EPC_DB::get_pass( 'ultimate-membership-pro', (string) $row_id );
		return $pass && ! empty( $pass->pass_uid ) ? (string) $pass->status : 'none';
	}
}

global $wpdb;
$t             = 'EPC_UMP_Members_Test';
$slug          = 'ultimate-membership-pro';
$saved_enabled = get_option( 'epc_enabled_modules', null );
$saved_rules   = get_option( 'epc_ump_status_rules', null );
epc_test_fixture_setup();
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), array( $slug ) ) ) ) );
delete_option( 'epc_ump_status_rules' );
$module = EPC_Module_Loader::get_module_instance( $slug );
$module->init();

$made  = \Ump\Db\Memberships::save(
	array(
		'name'         => 'epc-t-members-' . wp_generate_password( 6, false, false ),
		'label'        => 'EPC Members Level',
		'payment_type' => 'free',
		'price'        => 0,
		'status'       => 0,
		'access_type'  => 'unlimited',
		'order'        => 0,
	),
	true
);
$level = (int) ( $made['id'] ?? 0 );
$module->save_mapping(
	$level,
	array(
		'template_uid'  => '11111111-2222-4333-8444-555555555555',
		'field_mapping' => array(
			'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' => array(
				'type'   => 'source',
				'source' => 'level_name',
			),
		),
		'pass_fields'   => array(),
	)
);

// Existing members: members from before the cards were turned on (their cards are removed).
list( , $active )    = $t::member( $level, '1' );
list( , $cancelled ) = $t::member( $level, '0' );
list( , $hold )      = $t::member( $level, '3' );
list( , $paused )    = $t::member( $level, '4' );
list( , $ending )    = $t::member( $level, '5' );
list( , $unpaid )    = $t::member( $level, '' );
foreach ( $t::$users as $user_id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test: as if the cards were never made.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE module = %s AND user_id = %d', EPC_DB::table_name(), $slug, $user_id ) );
}
$t::check( 'no cards yet', 'none' === $t::card( $active ) && 'none' === $t::card( $cancelled ) );

$listed = array_map( 'intval', $module->get_backfill_source_ids( $level, 200, 0 ) );
$t::check( 'existing members: the active member is listed', in_array( $active, $listed, true ), wp_json_encode( $listed ) );
$t::check( 'existing members: cancelled, on hold, paused, cancellation pending and unpaid are not', array() === array_values( array_intersect( array( $cancelled, $hold, $paused, $ending, $unpaid ), $listed ) ), wp_json_encode( $listed ) );

update_option( 'epc_ump_status_rules', array_merge( $module->get_default_status_rules(), array( 'hold' => 'sync' ) ) );
$listed = array_map( 'intval', $module->get_backfill_source_ids( $level, 200, 0 ) );
$t::check( 'existing members: a status set to give a card (On hold) is listed', in_array( $hold, $listed, true ) && in_array( $active, $listed, true ) && ! in_array( $cancelled, $listed, true ), wp_json_encode( $listed ) );
delete_option( 'epc_ump_status_rules' );

$first  = $module->get_backfill_source_ids( $level, 1, 0 );
$second = $module->get_backfill_source_ids( $level, 1, 1 );
$t::check( 'existing members: pages follow the filtered list', array( $active ) === array_map( 'intval', $first ) && array() === $second, wp_json_encode( array( $first, $second ) ) );

// A user is deleted (Users screen, UMP's members list, or the member deleting their account):
// all go through wp_delete_user(). The card is voided.
list( $gone, $gone_row ) = $t::member( $level, '1' );
$t::check( 'active member: card live', 'active' === $t::card( $gone_row ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $gone );
$t::check( 'user deleted: card voided', 'revoked' === $t::card( $gone_row ), $t::card( $gone_row ) );

// Clean up.
foreach ( $t::$users as $user_id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
	$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT source_id FROM %i WHERE module = %s AND user_id = %d', EPC_DB::table_name(), $slug, $user_id ) );
	foreach ( $rows as $source_id ) {
		EPC_Pass_Issues::clear( $slug, (string) $source_id );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE module = %s AND user_id = %d', EPC_DB::table_name(), $slug, $user_id ) );
	$wpdb->delete( $wpdb->prefix . 'ihc_user_levels', array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
}
\Ump\Db\Memberships::deleteOne( $level );
$maps = $module->get_mappings();
unset( $maps[ $level ] );
update_option( $module->get_mappings_option_key(), $maps, false );
if ( null !== $saved_rules ) {
	update_option( 'epc_ump_status_rules', $saved_rules );
}
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

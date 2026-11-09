<?php
/**
 * Integration test: Simple Membership, a deleted member keeps no card.
 *
 * - The member deletes their own account (Simple Membership's SwpmAuth::delete(): the member
 *   row, then the WordPress user; no Simple Membership hook).
 * - An admin deletes the user on the Users screen.
 * - An admin deletes the member in Simple Membership (its own hook).
 *
 * Run: wp eval-file tests/integration/test-simple-membership-delete.php (needs Simple Membership).
 * Uses the fake API of fixtures/test-integration.php.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Module_Loader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-simple-membership-delete.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! class_exists( 'SwpmMembers' ) || ! method_exists( 'SwpmMembers', 'delete_swpm_user_by_id' ) ) {
	echo "SKIP Simple Membership deleted members (Simple Membership is not active here)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Results (a class: wp eval-file runs this file inside a function).
 */
final class EPC_SWPM_Delete_Test {
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
	 * Member rows made by this test.
	 *
	 * @var array<int, int>
	 */
	public static $members = array();

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
	 * An active member with a WordPress user and a card.
	 *
	 * @param object $module Module.
	 * @param int    $level  Level.
	 * @return array{0: int, 1: string, 2: int} Member id, user name, WordPress user id.
	 */
	public static function member( $module, $level ) {
		global $wpdb;
		$login = 'epc_swd_' . strtolower( wp_generate_password( 8, false, false ) );
		$user  = (int) wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password(),
				'user_email' => $login . '@example.test',
				'role'       => 'subscriber',
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test setup, as Simple Membership stores a member.
		$wpdb->insert(
			$wpdb->prefix . 'swpm_members_tbl',
			array(
				'user_name'           => $login,
				'first_name'          => 'Noor',
				'last_name'           => 'Haddad',
				'password'            => '',
				'member_since'        => gmdate( 'Y-m-d' ),
				'subscription_starts' => gmdate( 'Y-m-d' ),
				'membership_level'    => $level,
				'account_state'       => 'active',
				'email'               => $login . '@example.test',
			)
		);
		$member_id       = (int) $wpdb->insert_id;
		self::$members[] = $member_id;
		$module->sync_by_source_id( $member_id, 'sync' );
		return array( $member_id, $login, $user );
	}

	/**
	 * Card status of a member, or 'none'.
	 *
	 * @param int $member_id Member.
	 * @return string
	 */
	public static function card( $member_id ) {
		$pass = EPC_DB::get_pass( 'simple-membership', (string) $member_id );
		return $pass && ! empty( $pass->pass_uid ) ? (string) $pass->status : 'none';
	}
}

global $wpdb;
$t             = 'EPC_SWPM_Delete_Test';
$slug          = 'simple-membership';
$saved_enabled = get_option( 'epc_enabled_modules', null );
epc_test_fixture_setup();
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), array( $slug ) ) ) ) );
$module = EPC_Module_Loader::get_module_instance( $slug );
$module->init();
$saved_maps = $module->get_mappings();

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test level, as Simple Membership stores one.
$wpdb->insert(
	$wpdb->prefix . 'swpm_membership_tbl',
	array(
		'alias'                      => 'EPC delete test level',
		'role'                       => 'subscriber',
		'permissions'                => 0,
		'subscription_period'        => '',
		'subscription_duration_type' => 0,
		'protect_older_posts'        => 0,
		'campaign_name'              => '',
	)
);
$level = (int) $wpdb->insert_id;
$module->save_mapping(
	$level,
	array(
		'template_uid'  => '11111111-2222-4333-8444-555555555555',
		'field_mapping' => array(
			'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' => array(
				'type'   => 'source',
				'source' => 'member_id',
			),
		),
		'pass_fields'   => array(),
	)
);
require_once ABSPATH . 'wp-admin/includes/user.php';

// The member deletes their own account: what SwpmAuth::delete() does.
list( $own, $own_login ) = $t::member( $module, $level );
$t::check( 'member: card live', 'active' === $t::card( $own ), $t::card( $own ) );
SwpmMembers::delete_swpm_user_by_id( $own );
SwpmMembers::delete_wp_user( $own_login );
$t::check( 'member deleted their own account: card voided', 'revoked' === $t::card( $own ), $t::card( $own ) );

// An admin deletes the WordPress user on the Users screen.
list( $by_wp, , $by_wp_user ) = $t::member( $module, $level );
wp_delete_user( $by_wp_user );
$t::check( 'user deleted on the Users screen: card voided', 'revoked' === $t::card( $by_wp ), $t::card( $by_wp ) );

// An admin deletes the member in Simple Membership (its own hook, as before).
list( $by_admin ) = $t::member( $module, $level );
SwpmMembers::delete_user_by_id( $by_admin );
$t::check( 'member deleted in Simple Membership: card voided', 'revoked' === $t::card( $by_admin ), $t::card( $by_admin ) );

// Clean up.
foreach ( $t::$members as $member_id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test cleanup.
	$wpdb->delete( $wpdb->prefix . 'swpm_members_tbl', array( 'member_id' => $member_id ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test cleanup.
	$wpdb->delete(
		EPC_DB::table_name(),
		array(
			'module'    => $slug,
			'source_id' => (string) $member_id,
		)
	);
	EPC_Pass_Issues::clear( $slug, (string) $member_id );
}
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test cleanup.
$wpdb->delete( $wpdb->prefix . 'swpm_membership_tbl', array( 'id' => $level ) );
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

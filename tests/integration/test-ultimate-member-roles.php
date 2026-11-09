<?php
/**
 * Integration test: Ultimate Member, a member's role changes.
 *
 * - New role, same design: the same card shows the new role.
 * - New role, another design: the old card is voided and a card on the new design is issued.
 * - New role without a design: the card is voided.
 *
 * Run: wp eval-file tests/integration/test-ultimate-member-roles.php (needs Ultimate Member).
 * Uses the fake API of fixtures/test-integration.php.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Module_Loader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-ultimate-member-roles.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! function_exists( 'UM' ) || ! method_exists( UM()->common()->users(), 'set_status' ) ) {
	echo "SKIP Ultimate Member role changes (Ultimate Member is not active here)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Results and the API calls seen (a class: wp eval-file runs this file inside a function).
 */
final class EPC_UM_Roles_Test {
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
	 * API calls: "create", "update <uid>", "expire <uid>" with the values sent.
	 *
	 * @var array<int, array{call: string, body: string}>
	 */
	public static $calls = array();

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
	 * The user's card row.
	 *
	 * @param int $user_id User.
	 * @return object|null
	 */
	public static function card( $user_id ) {
		$row = EPC_DB::get_pass( 'ultimate-member', (string) $user_id );
		return $row && '' !== (string) $row->pass_uid ? $row : null;
	}

	/**
	 * Calls whose name starts with $prefix.
	 *
	 * @param string $prefix e.g. "expire".
	 * @return array<int, array{call: string, body: string}>
	 */
	public static function calls( $prefix ) {
		return array_values(
			array_filter(
				self::$calls,
				static function ( $c ) use ( $prefix ) {
					return 0 === strpos( $c['call'], $prefix );
				}
			)
		);
	}
}

$t = 'EPC_UM_Roles_Test';
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$url = (string) $url;
		if ( false !== strpos( $url, 'epasscard' ) ) {
			$name = false !== strpos( $url, 'create-single-pass' ) ? 'create' : ( false !== stripos( $url, 'expire' ) ? 'expire' : 'update' );
			EPC_UM_Roles_Test::$calls[] = array(
				'call' => $name . ' ' . $url,
				'body' => is_string( $args['body'] ?? null ) ? $args['body'] : (string) wp_json_encode( $args['body'] ?? '' ),
			);
		}
		return $pre;
	},
	1,
	3
);

$saved_enabled = get_option( 'epc_enabled_modules', null );
epc_test_fixture_setup();
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), array( 'ultimate-member' ) ) ) ) );
$module = EPC_Module_Loader::get_module_instance( 'ultimate-member' );
$module->init();
$saved_maps    = $module->get_mappings();
$saved_default = get_option( EPC_Default_Design::OPTION_PREFIX . 'ultimate-member', null );
delete_option( EPC_Default_Design::OPTION_PREFIX . 'ultimate-member' );

add_role( 'um_epc-gold', 'Gold member', array( 'read' => true ) );
add_role( 'um_epc-silver', 'Silver member', array( 'read' => true ) );
add_role( 'um_epc-bronze', 'Bronze member', array( 'read' => true ) );
add_role( 'epc_test_role_shopper', 'Role test shopper', array( 'read' => true ) );
$design = static function ( $template_uid ) {
	return array(
		'template_uid'  => $template_uid,
		'field_mapping' => array(
			'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' => array(
				'type'   => 'source',
				'source' => 'role_name',
			),
		),
		'pass_fields'   => array(),
	);
};
$gold_item   = $module->role_id( 'um_epc-gold' );
$silver_item = $module->role_id( 'um_epc-silver' );
$bronze_item = $module->role_id( 'um_epc-bronze' );
$module->save_mapping( $gold_item, $design( '11111111-2222-4333-8444-555555555555' ) );
$module->save_mapping( $silver_item, $design( '22222222-3333-4444-8555-666666666666' ) );
$module->save_mapping( $bronze_item, $design( '22222222-3333-4444-8555-666666666666' ) );

$user = epc_test_fixture_user();
( new WP_User( $user ) )->set_role( 'um_epc-gold' );
UM()->common()->users()->set_status( $user, 'approved' );
$gold_card = $t::card( $user );
$t::check( 'approved gold member: card on the gold design', $gold_card && 'active' === $gold_card->status && $gold_item === (int) $gold_card->entity_id && '11111111-2222-4333-8444-555555555555' === $gold_card->template_uid, wp_json_encode( $gold_card ) );

// New role on another design: void the old card, issue one on the new design.
$t::$calls = array();
( new WP_User( $user ) )->set_role( 'um_epc-silver' );
$silver_card = $t::card( $user );
$expired     = $t::calls( 'expire' );
$t::check( 'silver (another design): the gold card is voided', 1 === count( $expired ) && false !== strpos( $expired[0]['call'] . $expired[0]['body'], (string) $gold_card->pass_uid ), wp_json_encode( $t::$calls ) );
$t::check( 'silver: a new card on the silver design', $silver_card && 'active' === $silver_card->status && (string) $gold_card->pass_uid !== (string) $silver_card->pass_uid && '22222222-3333-4444-8555-666666666666' === $silver_card->template_uid && $silver_item === (int) $silver_card->entity_id, wp_json_encode( $silver_card ) );
$t::check( 'silver: the new card shows the silver role', 1 === count( $t::calls( 'create' ) ) && false !== strpos( $t::calls( 'create' )[0]['body'], 'Silver member' ), wp_json_encode( $t::calls( 'create' ) ) );

// New role on the same design: the same card, updated.
$t::$calls = array();
( new WP_User( $user ) )->set_role( 'um_epc-bronze' );
$bronze_card = $t::card( $user );
$t::check( 'bronze (same design): the same card stays', $bronze_card && 'active' === $bronze_card->status && (string) $silver_card->pass_uid === (string) $bronze_card->pass_uid && $bronze_item === (int) $bronze_card->entity_id, wp_json_encode( $bronze_card ) );
$t::check( 'bronze: the card now shows the bronze role, nothing voided', array() === $t::calls( 'expire' ) && array() === $t::calls( 'create' ) && 1 === count( $t::calls( 'update' ) ) && false !== strpos( $t::calls( 'update' )[0]['body'], 'Bronze member' ), wp_json_encode( $t::$calls ) );

// New role without a design: the card is voided.
$t::$calls = array();
( new WP_User( $user ) )->set_role( 'epc_test_role_shopper' );
$row = EPC_DB::get_pass( 'ultimate-member', (string) $user );
$t::check( 'a role without a design: the card is voided', $row && 'revoked' === (string) $row->status && 1 === count( $t::calls( 'expire' ) ), wp_json_encode( $row ) . ' ' . wp_json_encode( $t::$calls ) );

// Back to bronze: the same card is live again.
( new WP_User( $user ) )->set_role( 'um_epc-bronze' );
$again = $t::card( $user );
$t::check( 'back to bronze: the card is live again', $again && 'active' === $again->status && (string) $bronze_card->pass_uid === (string) $again->pass_uid, wp_json_encode( $again ) );

// Clean up.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
$wpdb->delete(
	EPC_DB::table_name(),
	array(
		'module'    => 'ultimate-member',
		'source_id' => (string) $user,
	)
);
EPC_Pass_Issues::clear( 'ultimate-member', (string) $user );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $user );
foreach ( array( 'um_epc-gold', 'um_epc-silver', 'um_epc-bronze', 'epc_test_role_shopper' ) as $role ) {
	remove_role( $role );
}
update_option( $module->get_mappings_option_key(), $saved_maps );
if ( null !== $saved_default ) {
	update_option( EPC_Default_Design::OPTION_PREFIX . 'ultimate-member', $saved_default );
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

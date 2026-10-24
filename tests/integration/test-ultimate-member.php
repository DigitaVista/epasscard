<?php
/**
 * Integration test for v21: Ultimate Member member cards. Needs Ultimate Member (skipped
 * otherwise). Uses the fake API of fixtures/test-integration.php.
 *
 * Run: wp eval-file tests/integration/test-ultimate-member.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Module_Loader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-ultimate-member.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! function_exists( 'UM' ) ) {
	echo "SKIP Ultimate Member cards (Ultimate Member is not active here)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_UM_Test_Counter {
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
function epc_um_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_UM_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_UM_Test_Counter::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * Card status of a user, or 'none'.
 *
 * @param int $user_id User.
 * @return string
 */
function epc_um_card( $user_id ) {
	$pass = EPC_DB::get_pass( 'ultimate-member', (string) $user_id );
	return $pass && ! empty( $pass->pass_uid ) ? (string) $pass->status : 'none';
}

$saved_enabled = get_option( 'epc_enabled_modules', null );
epc_test_fixture_setup();
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), array( 'ultimate-member' ) ) ) ) );
$module = EPC_Module_Loader::get_module_instance( 'ultimate-member' );
$module->init();

$mapping = array(
	'template_uid'  => '11111111-2222-4333-8444-555555555555',
	'field_mapping' => array(
		'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' => array(
			'type'   => 'source',
			'source' => 'member_id',
		),
	),
	'pass_fields'   => array(),
);
$subscriber_item = $module->role_id( 'subscriber' );
$saved_maps      = $module->get_mappings();
$saved_default   = get_option( EPC_Default_Design::OPTION_PREFIX . 'ultimate-member', null );
delete_option( EPC_Default_Design::OPTION_PREFIX . 'ultimate-member' ); // Only the subscriber role has a design here.
$module->save_mapping( $subscriber_item, $mapping );

epc_um_check( 'roles are items with stable numbers', $subscriber_item > 0 && $subscriber_item === $module->role_id( 'subscriber' ) && 'subscriber' === $module->role_for_id( $subscriber_item ) );
epc_um_check( 'administrators are not an item', ! in_array( 'Administrator', wp_list_pluck( $module->get_mappable_entities(), 'label' ), true ) );

$users = UM()->common()->users();

// A new user: nothing until Ultimate Member sets the status.
$user = epc_test_fixture_user();
epc_um_check( 'new user without a status: no card yet', 'none' === epc_um_card( $user ) );
$users->set_status( $user, 'awaiting_admin_review' );
epc_um_check( 'pending review: no card', 'none' === epc_um_card( $user ) );
$users->set_status( $user, 'approved' );
epc_um_check( 'approved: card created', 'active' === epc_um_card( $user ) );
$pass = EPC_DB::get_pass( 'ultimate-member', (string) $user );
epc_um_check( 'the card belongs to the user and the role', $user === (int) $pass->user_id && $subscriber_item === (int) $pass->entity_id );

$users->set_status( $user, 'inactive' );
epc_um_check( 'deactivated: card voided', 'revoked' === epc_um_card( $user ) );
$users->set_status( $user, 'approved' );
epc_um_check( 'approved again: the same card is live again', 'active' === epc_um_card( $user ) && (string) $pass->pass_uid === (string) EPC_DB::get_pass( 'ultimate-member', (string) $user )->pass_uid );

// Account page: a "Wallet card" tab with the card link, empty (so hidden) without a card.
$tabs = apply_filters( 'um_account_page_default_tabs_hook', array() );
epc_um_check( 'account page: the Wallet card tab is added', isset( $tabs[150]['epasscard'] ) && false === $tabs[150]['epasscard']['show_button'] && true === $tabs[150]['epasscard']['custom'] );
wp_set_current_user( $user );
$html = (string) apply_filters( 'um_account_content_hook_epasscard', '', array() );
epc_um_check( 'account page: the tab links the member\'s card', false !== strpos( $html, esc_url( EPC_DB::get_pass( 'ultimate-member', (string) $user )->pass_link ) ) );
$other = epc_test_fixture_user();
wp_set_current_user( $other );
epc_um_check( 'account page: no card, no tab content', '' === (string) apply_filters( 'um_account_content_hook_epasscard', '', array() ) );
wp_set_current_user( 0 );

// Role with no design: the card is not made; an administrator gets none.
$editor_user = epc_test_fixture_user();
( new WP_User( $editor_user ) )->set_role( 'editor' );
$users->set_status( $editor_user, 'approved' );
epc_um_check( 'a role without a design gets no card', 'none' === epc_um_card( $editor_user ) );
( new WP_User( $user ) )->set_role( 'administrator' );
epc_um_check( 'becoming an administrator voids the card', 'revoked' === epc_um_card( $user ) );
( new WP_User( $user ) )->set_role( 'subscriber' );
epc_um_check( 'back to a member role: card live', 'active' === epc_um_card( $user ) );

// Existing members.
$old = epc_test_fixture_user();
update_user_meta( $old, 'account_status', 'approved' );
$pending = epc_test_fixture_user();
update_user_meta( $pending, 'account_status', 'awaiting_email_confirmation' );
$no_status = epc_test_fixture_user();
delete_user_meta( $no_status, 'account_status' );
$listed = array_filter( (array) $module->get_backfill_source_ids( $subscriber_item, 1000, 0 ) );
epc_um_check( 'existing members: approved ones, and users without a status', in_array( (string) $old, $listed, true ) && in_array( (string) $no_status, $listed, true ) && ! in_array( (string) $pending, $listed, true ), wp_json_encode( array_values( $listed ) ) );
epc_um_check( 'existing members: their card goes to their own account', $old === $module->get_backfill_user_id( (string) $old ) );

// Deleting the user voids the card.
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $user );
epc_um_check( 'user deleted: card voided', 'revoked' === epc_um_card( $user ) );

// Clean up.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE module = %s AND source_id IN (%s, %s, %s, %s, %s)', EPC_DB::table_name(), 'ultimate-member', (string) $user, (string) $editor_user, (string) $old, (string) $pending, (string) $no_status ) );
foreach ( array( $user, $editor_user, $old, $pending, $no_status ) as $id ) {
	EPC_Pass_Issues::clear( 'ultimate-member', (string) $id );
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

echo EPC_UM_Test_Counter::$passed, ' passed, ', EPC_UM_Test_Counter::$failed, " failed\n";
if ( EPC_UM_Test_Counter::$failed > 0 ) {
	exit( 1 );
}

<?php
/**
 * Integration tests for v12: "Add to wallet" on the Paid Memberships Pro account page and in
 * the Simple Membership logged-in box. Needs Paid Memberships Pro; the Simple Membership part
 * also needs that plugin (each skipped otherwise).
 *
 * Run: wp eval-file tests/integration/test-account-links.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! method_exists( 'EPC_DB', 'find_active_card' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-account-links.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! defined( 'PMPRO_VERSION' ) ) {
	echo "SKIP account page links (Paid Memberships Pro is not active here)\n";
	return;
}

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Account_Links_Test_Counter {
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
function epc_al_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Account_Links_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Account_Links_Test_Counter::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * Insert a card row.
 *
 * @param string $module    Module.
 * @param string $source_id Record.
 * @param int    $entity_id Entity.
 * @param int    $user_id   User.
 * @param int    $is_test   Test card.
 * @param string $status    Status.
 * @return string Pass link.
 */
function epc_al_card( $module, $source_id, $entity_id, $user_id, $is_test = 0, $status = 'active' ) {
	global $wpdb;
	$uid  = wp_generate_uuid4();
	$link = 'https://pass.example.test/' . $uid;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test setup.
	$wpdb->insert(
		EPC_DB::table_name(),
		array(
			'module'       => $module,
			'source_id'    => $source_id,
			'entity_id'    => $entity_id,
			'user_id'      => $user_id,
			'template_uid' => '11111111-2222-4333-8444-555555555555',
			'pass_uid'     => $uid,
			'pass_link'    => $link,
			'status'       => $status,
			'is_test'      => $is_test,
		)
	);
	return $link;
}

global $wpdb;
$saved   = get_option( 'epc_enabled_modules', null );
$enabled = (array) get_option( 'epc_enabled_modules', array() );
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( $enabled, array( 'paid-memberships-pro', 'simple-membership' ) ) ) ) );

$login  = 'epc_t_acct_' . wp_generate_password( 6, false, false );
$member = wp_insert_user(
	array(
		'user_login' => $login,
		'user_pass'  => wp_generate_password(),
		'user_email' => $login . '@example.test',
		'role'       => 'subscriber',
	)
);
$source = 'acct-' . $member;
$link   = epc_al_card( 'paid-memberships-pro', $source, 901, $member );
epc_al_card( 'paid-memberships-pro', $source . '-test', 902, $member, 1 );
epc_al_card( 'paid-memberships-pro', $source . '-revoked', 903, $member, 0, 'revoked' );

wp_set_current_user( $member );
$links = EPC_Frontend::pmpro_member_action_links( array( 'cancel' => '<span>Cancel</span>' ), 901 );
epc_al_check( 'PMPro: "Add to wallet" first on the level with a card', 'epasscard' === array_keys( $links )[0] && false !== strpos( $links['epasscard'], esc_url( $link ) ) && isset( $links['cancel'] ) );
epc_al_check( 'PMPro: nothing for a level whose only card is a test card', array( 'cancel' ) === array_keys( EPC_Frontend::pmpro_member_action_links( array( 'cancel' => '' ), 902 ) ) );
epc_al_check( 'PMPro: nothing for a revoked card', array( 'cancel' ) === array_keys( EPC_Frontend::pmpro_member_action_links( array( 'cancel' => '' ), 903 ) ) );
wp_set_current_user( 0 );
epc_al_check( 'PMPro: nothing when signed out', array( 'cancel' ) === array_keys( EPC_Frontend::pmpro_member_action_links( array( 'cancel' => '' ), 901 ) ) );
wp_set_current_user( $member );
update_option( 'epc_enabled_modules', array_values( array_diff( (array) get_option( 'epc_enabled_modules' ), array( 'paid-memberships-pro' ) ) ) );
epc_al_check( 'PMPro: nothing when the integration is off', array( 'cancel' ) === array_keys( EPC_Frontend::pmpro_member_action_links( array( 'cancel' => '' ), 901 ) ) );
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules' ), array( 'paid-memberships-pro' ) ) ) ) );

if ( class_exists( 'SwpmAuth' ) && class_exists( 'SwpmMemberUtils' ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test setup.
	$wpdb->insert(
		$wpdb->prefix . 'swpm_members_tbl',
		array(
			'user_name'        => $login,
			'first_name'       => 'Test',
			'password'         => '',
			'member_since'     => gmdate( 'Y-m-d' ),
			'membership_level' => 2,
			'account_state'    => 'active',
			'email'            => $login . '@example.test',
		)
	);
	$swpm_member = (int) $wpdb->insert_id;
	$swpm_link   = epc_al_card( 'simple-membership', (string) $swpm_member, 2, $member );
	@SwpmAuth::get_instance()->login_to_swpm_using_wp_user( get_userdata( $member ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Sets a cookie; headers are already sent under WP-CLI.
	$html = do_shortcode( '[swpm_login_form]' );
	epc_al_check( 'Simple Membership: the logged-in box links the member\'s card', false !== strpos( $html, esc_url( $swpm_link ) ) && false !== strpos( $html, 'epc-swpm-card' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test cleanup.
	$wpdb->delete( $wpdb->prefix . 'swpm_members_tbl', array( 'member_id' => $swpm_member ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test cleanup.
	$wpdb->delete( EPC_DB::table_name(), array( 'module' => 'simple-membership', 'source_id' => (string) $swpm_member ) );
} else {
	echo "SKIP Simple Membership logged-in box (Simple Membership is not active here)\n";
}

// Clean up.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE module = %s AND source_id LIKE %s', EPC_DB::table_name(), 'paid-memberships-pro', $wpdb->esc_like( $source ) . '%' ) );
if ( null === $saved ) {
	delete_option( 'epc_enabled_modules' );
} else {
	update_option( 'epc_enabled_modules', $saved );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $member );

echo EPC_Account_Links_Test_Counter::$passed, ' passed, ', EPC_Account_Links_Test_Counter::$failed, " failed\n";
if ( EPC_Account_Links_Test_Counter::$failed > 0 ) {
	exit( 1 );
}

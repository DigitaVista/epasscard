<?php
/**
 * Integration test for v18: WPLoyalty loyalty cards. Needs WPLoyalty (skipped otherwise).
 * Points are changed through WPLoyalty's own code, so its real hooks fire. Uses the fake API of
 * fixtures/test-integration.php.
 *
 * Run: wp eval-file tests/integration/test-wployalty.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Module_Loader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-wployalty.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! class_exists( 'Wlr\\App\\Helpers\\Base' ) || ! class_exists( 'Wlr\\App\\Models\\Users' ) ) {
	echo "SKIP WPLoyalty cards (WPLoyalty is not active here)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Pass/fail counter and captured mail (a class: wp eval-file runs this file inside a function).
 */
final class EPC_WLR_Test_State {
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
	 * Card emails sent.
	 *
	 * @var array<int, string>
	 */
	public static $mail_to = array();
}

/**
 * Record one check.
 *
 * @param string $label Check label.
 * @param bool   $ok    Result.
 * @param string $info  Extra detail on failure.
 * @return void
 */
function epc_wlr_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_WLR_Test_State::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_WLR_Test_State::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * Change a customer's points the way WPLoyalty's admin screen does.
 *
 * @param string $email  Customer email.
 * @param int    $points Points.
 * @param string $type   credit|debit.
 * @return void
 */
function epc_wlr_points( $email, $points, $type = 'credit' ) {
	$action_data = array(
		'user_email'          => $email,
		'action_type'         => 'admin_change',
		'action_process_type' => 'credit' === $type ? 'earn_point' : 'reduce_point',
		'points'              => $points,
		'note'                => 'EpassCard test',
		'customer_note'       => 'EpassCard test',
	);
	( new \Wlr\App\Helpers\Base() )->addExtraPointAction( 'admin_change', $points, $action_data, $type );
}

/**
 * Card of a WPLoyalty customer row: its status, or 'none'.
 *
 * @param int $row_id Row id.
 * @return string
 */
function epc_wlr_card( $row_id ) {
	$pass = EPC_DB::get_pass( 'wployalty', (string) $row_id );
	return $pass && ! empty( $pass->pass_uid ) ? (string) $pass->status : 'none';
}

/**
 * Run the sync WPLoyalty's change queued, as Action Scheduler would.
 *
 * @param EPC_Module_WPLoyalty $module Module.
 * @param int                  $row_id Row id.
 * @return bool Whether a sync was queued.
 */
function epc_wlr_run_queue( $module, $row_id ) {
	$args   = array( (int) $row_id );
	$queued = as_has_scheduled_action( EPC_Module_WPLoyalty::SYNC_HOOK, $args, EPC_Module_WPLoyalty::SYNC_GROUP );
	as_unschedule_all_actions( EPC_Module_WPLoyalty::SYNC_HOOK, $args, EPC_Module_WPLoyalty::SYNC_GROUP );
	if ( $queued ) {
		do_action( EPC_Module_WPLoyalty::SYNC_HOOK, (int) $row_id );
	}
	return (bool) $queued;
}

$saved_enabled = get_option( 'epc_enabled_modules', null );
$saved_email   = get_option( EPC_Pass_Email::OPTION, null );
epc_test_fixture_setup();
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), array( 'wployalty' ) ) ) ) );
update_option( EPC_Pass_Email::OPTION, array_merge( EPC_Pass_Email::get_settings(), array( 'auto_on_create' => 1 ) ), false );
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		unset( $short );
		if ( false !== strpos( (string) $atts['subject'], 'wallet pass' ) ) {
			EPC_WLR_Test_State::$mail_to[] = (string) ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'] );
		}
		return true; // No real mail (WPLoyalty sends its own emails too).
	},
	1,
	2
);

$module = EPC_Module_Loader::get_module_instance( 'wployalty' );
$module->init();
$saved_maps = $module->get_mappings();
$module->save_mapping(
	EPC_Module_WPLoyalty::PROGRAM_ENTITY_ID,
	array(
		'template_uid'  => '11111111-2222-4333-8444-555555555555',
		'field_mapping' => array(
			'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' => array(
				'type'   => 'source',
				'source' => 'points_balance',
			),
		),
		'pass_fields'   => array(),
	)
);

epc_wlr_check( 'the integration is available with WPLoyalty active', $module->is_available() && epc_is_wployalty_active() );

// A customer with an account earns points: a card, made after the request (queued).
$user_id = epc_test_fixture_user();
$email   = (string) get_userdata( $user_id )->user_email;
epc_wlr_points( $email, 120 );
$row_id = $module->customer_id_by_email( $email );
epc_wlr_check( 'WPLoyalty made the customer row', $row_id > 0 );
epc_wlr_check( 'the balance change is queued, not sent in the request', 'none' === epc_wlr_card( $row_id ) && epc_wlr_run_queue( $module, $row_id ) );
epc_wlr_check( 'first points: card created', 'active' === epc_wlr_card( $row_id ) );
$pass = EPC_DB::get_pass( 'wployalty', (string) $row_id );
epc_wlr_check( 'the card belongs to the customer\'s account and the program', $user_id === (int) $pass->user_id && 1 === (int) $pass->entity_id );
epc_wlr_check( 'the card email went to the customer', in_array( $email, EPC_WLR_Test_State::$mail_to, true ), wp_json_encode( EPC_WLR_Test_State::$mail_to ) );

$values = $module->build_values( $module->get_customer( $row_id ) );
epc_wlr_check( 'card values: balance, lifetime, used, email', '120' === $values['points_balance'] && '120' === $values['lifetime_points'] && '0' === $values['used_points'] && $email === $values['user_email'], wp_json_encode( $values ) );
epc_wlr_check( 'card values: referral code and WPLoyalty\'s referral link', '' !== $values['referral_code'] && ( new \Wlr\App\Helpers\Base() )->getReferralUrl( $values['referral_code'] ) === $values['referral_url'], wp_json_encode( $values ) );
epc_wlr_check( 'card values: points label and member since', 'points' === $values['points_label'] && '' !== $values['member_since'] );

// Redeeming takes points away: the same card is updated.
epc_wlr_points( $email, 20, 'debit' );
epc_wlr_run_queue( $module, $row_id );
$values = $module->build_values( $module->get_customer( $row_id ) );
epc_wlr_check( 'points taken away: the same card, new balance', 'active' === epc_wlr_card( $row_id ) && (string) $pass->pass_uid === (string) EPC_DB::get_pass( 'wployalty', (string) $row_id )->pass_uid && '100' === $values['points_balance'] );
epc_wlr_check( 'one card email only (none on updates)', 1 === count( array_keys( EPC_WLR_Test_State::$mail_to, $email, true ) ) );

// Rewards page in My Account: the Wallet card block.
wp_set_current_user( $user_id );
ob_start();
do_action( 'wlr_after_customer_reward_page_content' );
$html = (string) ob_get_clean();
epc_wlr_check( 'rewards page: the Wallet card block links the card', false !== strpos( $html, esc_url( (string) $pass->pass_link ) ) && false !== strpos( $html, 'wlr-heading' ) );
$other = epc_test_fixture_user();
wp_set_current_user( $other );
ob_start();
do_action( 'wlr_after_customer_reward_page_content' );
epc_wlr_check( 'rewards page: no card, no block', '' === (string) ob_get_clean() );
wp_set_current_user( 0 );

// Ban and unban, through WPLoyalty's own save in its ban request.
$module->watch_ban_toggle();
( new \Wlr\App\Models\Users() )->insertOrUpdate( array( 'is_banned_user' => 1 ), $row_id );
epc_wlr_check( 'banned in WPLoyalty: card voided', 'revoked' === epc_wlr_card( $row_id ) );
epc_wlr_points( $email, 5 );
epc_wlr_run_queue( $module, $row_id );
epc_wlr_check( 'banned: a points change does not bring the card back', 'revoked' === epc_wlr_card( $row_id ) );
( new \Wlr\App\Models\Users() )->insertOrUpdate( array( 'is_banned_user' => 0 ), $row_id );
epc_wlr_check( 'ban lifted: the same card is live again', 'active' === epc_wlr_card( $row_id ) && (string) $pass->pass_uid === (string) EPC_DB::get_pass( 'wployalty', (string) $row_id )->pass_uid );
remove_filter( 'wlr_after_user_updated', array( $module, 'on_ban_saved' ), 20 );

// A card voided on the Cards page stays voided when points change.
EPC_Pass_Service::revoke_pass( 'wployalty', (string) $row_id );
epc_wlr_points( $email, 5 );
epc_wlr_run_queue( $module, $row_id );
epc_wlr_check( 'voided on Cards: points do not bring it back', 'revoked' === epc_wlr_card( $row_id ) );
$module->sync_by_source_id( (string) $row_id, 'sync' );

// Email changed on the account: WPLoyalty moves its row to the new email; the same card follows.
$new_email = 'epc_t_new_' . wp_generate_password( 6, false, false ) . '@example.test';
wp_set_current_user( $user_id );
wp_update_user(
	array(
		'ID'         => $user_id,
		'user_email' => $new_email,
	)
);
epc_wlr_check( 'email changed: WPLoyalty kept the row and queued the card update', $row_id === $module->customer_id_by_email( $new_email ) && epc_wlr_run_queue( $module, $row_id ) );
epc_wlr_check( 'email changed: the same card, the new email, the same account', 'active' === epc_wlr_card( $row_id ) && $new_email === $module->build_values( $module->get_customer( $row_id ) )['user_email'] && $user_id === (int) EPC_DB::get_pass( 'wployalty', (string) $row_id )->user_id );
// Put the old email back (directly: WPLoyalty caches emails for the rest of this request).
wp_update_user(
	array(
		'ID'         => $user_id,
		'user_email' => $email,
	)
);
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test: restore the row's email.
$wpdb->update( EPC_Module_WPLoyalty::customers_table(), array( 'user_email' => $email ), array( 'id' => $row_id ) );
as_unschedule_all_actions( EPC_Module_WPLoyalty::SYNC_HOOK, array( $row_id ), EPC_Module_WPLoyalty::SYNC_GROUP );
wp_set_current_user( 0 );

// A guest (no account): the card goes to the WPLoyalty email.
$guest     = 'epc_t_guest_' . wp_generate_password( 6, false, false ) . '@example.test';
epc_wlr_points( $guest, 40 );
$guest_row = $module->customer_id_by_email( $guest );
epc_wlr_run_queue( $module, $guest_row );
$guest_pass = EPC_DB::get_pass( 'wployalty', (string) $guest_row );
epc_wlr_check( 'guest: card made without an account', 'active' === epc_wlr_card( $guest_row ) && 0 === (int) $guest_pass->user_id );
epc_wlr_check( 'guest: the card email went to the WPLoyalty email', in_array( $guest, EPC_WLR_Test_State::$mail_to, true ), wp_json_encode( EPC_WLR_Test_State::$mail_to ) );
epc_wlr_check( 'guest: the Cards list shows the email', array( 'name' => '', 'email' => $guest ) === $module->get_record_customer( (string) $guest_row ) );

// Existing customers: those with points who are not banned.
$zero = 'epc_t_zero_' . wp_generate_password( 6, false, false ) . '@example.test';
( new \Wlr\App\Models\Users() )->insertOrUpdate(
	array(
		'user_email'   => $zero,
		'refer_code'   => 'EPCZERO' . wp_rand( 100, 999 ),
		'created_date' => time(),
	)
);
$banned = 'epc_t_ban_' . wp_generate_password( 6, false, false ) . '@example.test';
epc_wlr_points( $banned, 30 );
$banned_row = $module->customer_id_by_email( $banned );
as_unschedule_all_actions( EPC_Module_WPLoyalty::SYNC_HOOK, array( $banned_row ), EPC_Module_WPLoyalty::SYNC_GROUP );
( new \Wlr\App\Models\Users() )->insertOrUpdate( array( 'is_banned_user' => 1 ), $banned_row );
$listed = (array) $module->get_backfill_source_ids( 1, 100000, 0 );
epc_wlr_check( 'existing customers: with points yes; no points or banned no', in_array( (string) $row_id, $listed, true ) && in_array( (string) $guest_row, $listed, true ) && ! in_array( (string) $module->customer_id_by_email( $zero ), $listed, true ) && ! in_array( (string) $banned_row, $listed, true ) );
epc_wlr_check( 'existing customers: the card goes to the account, or to no account for a guest', $user_id === $module->get_backfill_user_id( (string) $row_id ) && 0 === $module->get_backfill_user_id( (string) $guest_row ) );
epc_wlr_check( 'a banned customer cannot be given a card', is_wp_error( $module->sync_by_source_id( (string) $banned_row ) ) );

// Deleting the customer in WPLoyalty voids the card.
\Wlr\App\Controllers\Admin\Customers::deleteCustomers( $module->get_customer( $guest_row ) );
epc_wlr_check( 'customer deleted in WPLoyalty: card voided', null === $module->get_customer( $guest_row ) && 'revoked' === epc_wlr_card( $guest_row ) );
epc_wlr_check( 'other cards are left alone by a delete', 'active' === epc_wlr_card( $row_id ) );

// Clean up.
$rows = array( $row_id, $guest_row, $banned_row, $module->customer_id_by_email( $zero ) );
foreach ( $rows as $id ) {
	as_unschedule_all_actions( EPC_Module_WPLoyalty::SYNC_HOOK, array( (int) $id ), EPC_Module_WPLoyalty::SYNC_GROUP );
	EPC_Pass_Issues::clear( 'wployalty', (string) $id );
}
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup: only this test's cards.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE module = %s AND source_id IN (%s, %s, %s, %s)', EPC_DB::table_name(), 'wployalty', (string) $rows[0], (string) $rows[1], (string) $rows[2], (string) $rows[3] ) );
foreach ( array( $email, $banned, $zero ) as $address ) {
	$row = ( new \Wlr\App\Helpers\Base() )->getPointUserByEmail( $address );
	if ( is_object( $row ) ) {
		\Wlr\App\Controllers\Admin\Customers::deleteCustomers( $row );
	}
}
// WPLoyalty keeps a points ledger after a delete, and the email change moved history to the
// new email; remove this test's entries.
foreach ( array( $email, $new_email, $guest, $banned, $zero ) as $address ) {
	foreach ( array( 'wlr_points_ledger', 'wlr_logs', 'wlr_earn_campaign_transaction' ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
		$wpdb->delete( $wpdb->prefix . $table, array( 'user_email' => $address ) );
	}
}
update_option( $module->get_mappings_option_key(), $saved_maps );
if ( null === $saved_email ) {
	delete_option( EPC_Pass_Email::OPTION );
} else {
	update_option( EPC_Pass_Email::OPTION, $saved_email, false );
}
if ( null === $saved_enabled ) {
	delete_option( 'epc_enabled_modules' );
} else {
	update_option( 'epc_enabled_modules', $saved_enabled );
}
epc_test_fixture_teardown();

echo EPC_WLR_Test_State::$passed, ' passed, ', EPC_WLR_Test_State::$failed, " failed\n";
if ( EPC_WLR_Test_State::$failed > 0 ) {
	exit( 1 );
}

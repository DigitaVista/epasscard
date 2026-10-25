<?php
/**
 * Integration test: Ultimate Membership Pro cards on a current UMP (checked with 14.2.1).
 * Needs Ultimate Membership Pro (skipped otherwise). Uses the fake API of
 * fixtures/test-integration.php.
 *
 * Covers: levels from UMP's memberships table, the card through a member's life (assigned,
 * activated, cancelled, expired, removed), the latest transaction id from UMP's orders, the
 * EpassCard column of the members list (DataTables, UMP 12.5+) and the "Wallet passes" tab of
 * the account page (v12).
 *
 * Run: wp eval-file tests/integration/test-ultimate-membership-pro.php (run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Module_Loader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-ultimate-membership-pro.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! class_exists( '\Ump\User_Subscriptions' ) || ! class_exists( '\Ump\Db\Memberships' ) ) {
	echo "SKIP Ultimate Membership Pro cards (Ultimate Membership Pro 14 is not active here)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Counters and what was sent to the API (a class: wp eval-file runs this file inside a function).
 */
final class EPC_UMP_Test_State {
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
	 * Fields of the last create or update call, uid => value.
	 *
	 * @var array<string, string>
	 */
	public static $fields = array();
}

/**
 * Record one check.
 *
 * @param string $label Check label.
 * @param bool   $ok    Result.
 * @param string $info  Extra detail on failure.
 * @return void
 */
function epc_ump_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_UMP_Test_State::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_UMP_Test_State::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * Card status of a member's level, or 'none'.
 *
 * @param int $user_id  User.
 * @param int $level_id Level.
 * @return string
 */
function epc_ump_card( $user_id, $level_id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test read.
	$row_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d AND level_id = %d ORDER BY id DESC LIMIT 1', $wpdb->prefix . 'ihc_user_levels', $user_id, $level_id ) );
	$pass   = $row_id > 0 ? EPC_DB::get_pass( 'ultimate-membership-pro', (string) $row_id ) : null;
	return $pass && ! empty( $pass->pass_uid ) ? (string) $pass->status : 'none';
}

// Record what is sent to EpassCard (the fixture's fake API answers).
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false !== strpos( (string) $url, 'single-pass' ) ) {
			$body = json_decode( (string) ( $args['body'] ?? '' ), true );
			$list = is_array( $body ) ? ( $body['fields'] ?? ( $body['additionalFieldsValue'] ?? array() ) ) : array();
			EPC_UMP_Test_State::$fields = array();
			foreach ( (array) $list as $field ) {
				if ( is_array( $field ) && isset( $field['uid'] ) ) {
					EPC_UMP_Test_State::$fields[ (string) $field['uid'] ] = (string) ( $field['fieldValue'] ?? '' );
				}
			}
		}
		return $pre;
	},
	5,
	3
);

global $wpdb;
$slug          = 'ultimate-membership-pro';
$saved_enabled = get_option( 'epc_enabled_modules', null );
$saved_rules   = get_option( 'epc_ump_status_rules', null );
epc_test_fixture_setup();
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), array( $slug ) ) ) ) );
delete_option( 'epc_ump_status_rules' ); // Default rules: active = card, cancelled / expired = void.
$module = EPC_Module_Loader::get_module_instance( $slug );
$module->init();

// A level made in UMP 14 (its memberships table; the old ihc_levels option is not used).
$made = \Ump\Db\Memberships::save(
	array(
		'name'         => 'epc-t-level-' . wp_generate_password( 6, false, false ),
		'label'        => 'EPC Test Level',
		'payment_type' => 'free',
		'price'        => 0,
		'status'       => 0,
		'access_type'  => 'unlimited',
		'order'        => 0,
	),
	true // As an import: not counted against the trial limit of 3 levels.
);
$level = (int) ( $made['id'] ?? 0 );
epc_ump_check( 'test level created in UMP', $level > 0, wp_json_encode( $made ) );
$labels = wp_list_pluck( $module->get_mappable_entities(), 'label', 'id' );
epc_ump_check( 'EpassCard lists the levels from UMP\'s memberships table', 'EPC Test Level' === ( $labels[ $level ] ?? '' ), wp_json_encode( $labels ) );
epc_ump_check( 'the level name shows on cards and screens', 'EPC Test Level' === $module->get_entity_label( $level ) );

$name_uid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
$txn_uid  = 'aaaaaaaa-bbbb-4ccc-8ddd-555555555555';
$module->save_mapping(
	$level,
	array(
		'template_uid'  => '11111111-2222-4333-8444-555555555555',
		'field_mapping' => array(
			$name_uid => array(
				'type'   => 'source',
				'source' => 'level_name',
			),
			$txn_uid  => array(
				'type'   => 'source',
				'source' => 'transaction_id',
			),
		),
		'pass_fields'   => array(),
	)
);

// A member gets the level the way UMP's checkout and admin do.
$user = epc_test_fixture_user();
\Ump\User_Subscriptions::assign( $user, $level );
epc_ump_check( 'level assigned, not yet paid or activated: no card', 'none' === epc_ump_card( $user, $level ) );
\Ump\User_Subscriptions::makeComplete( $user, $level );
epc_ump_check( 'level activated: card created', 'active' === epc_ump_card( $user, $level ) );
epc_ump_check( 'the card shows the level name', 'EPC Test Level' === ( EPC_UMP_Test_State::$fields[ $name_uid ] ?? '' ), wp_json_encode( EPC_UMP_Test_State::$fields ) );

// Latest transaction id: UMP 14 keeps it in the order meta.
$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test order, as UMP stores it.
	$wpdb->prefix . 'ihc_orders',
	array(
		'uid'          => $user,
		'lid'          => $level,
		'amount_type'  => 'USD',
		'amount_value' => 12,
		'status'       => 'Completed',
	)
);
$order = (int) $wpdb->insert_id;
$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test order meta, as UMP stores it.
	$wpdb->prefix . 'ihc_orders_meta',
	array(
		'order_id'   => $order,
		'meta_key'   => 'transaction_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- Test row.
		'meta_value' => 'ch_epc_test_42', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value -- Test row.
	)
);
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test read.
$row_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d AND level_id = %d', $wpdb->prefix . 'ihc_user_levels', $user, $level ) );
$module->sync_by_source_id( (string) $row_id, 'update' );
epc_ump_check( 'the card shows the transaction id of the latest order', 'ch_epc_test_42' === ( EPC_UMP_Test_State::$fields[ $txn_uid ] ?? '' ), wp_json_encode( EPC_UMP_Test_State::$fields ) );

// Members list (DataTables, UMP 12.5+): the column comes right after Total Spend and the header
// cell is printed only then.
$admin = (int) ( get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
)[0] ?? 0 );
wp_set_current_user( $admin );
$columns = $module->ump_datatable_columns(
	array(
		array( 'data' => 'memberships' ),
		array( 'data' => 'total_spend' ),
		array( 'data' => 'wp_role' ),
	)
);
epc_ump_check( 'members list: EpassCard column after Total Spend', array( 'memberships', 'total_spend', 'epasscard', 'wp_role' ) === wp_list_pluck( $columns, 'data' ) );
ob_start();
$module->ump_users_list_column();
epc_ump_check( 'members list: its header cell is printed', false !== strpos( (string) ob_get_clean(), 'EpassCard' ) );
$row = $module->ump_datatable_row( array( 'uid' => $user ), $user );
epc_ump_check( 'members list: the row has the member\'s card actions', isset( $row['epasscard'] ) && false !== strpos( (string) $row['epasscard'], 'epasscard-pass-actions' ), (string) ( $row['epasscard'] ?? '' ) );
$module->ump_datatable_columns( array( array( 'data' => 'uid' ) ) );
ob_start();
$module->ump_users_list_column();
epc_ump_check( 'members list: no Total Spend column, so no column and no header cell', '' === (string) ob_get_clean() );
wp_set_current_user( 0 );

// Account page: the "Wallet passes" tab (before Log out) with the member's card.
wp_set_current_user( $user );
$menu = EPC_Frontend::ump_account_tab(
	array(
		'overview' => array(
			'title' => 'Dashboard',
			'class' => 'ihc-ap-menu-item',
			'url'   => 'x',
		),
		'logout'   => array(
			'title' => 'LogOut',
			'class' => 'ihc-ap-menu-item',
			'url'   => 'y',
		),
	)
);
epc_ump_check( 'account page: Wallet passes tab before Log out', array( 'overview', EPC_Frontend::UMP_TAB, 'logout' ) === array_keys( $menu ) && false !== strpos( (string) $menu[ EPC_Frontend::UMP_TAB ]['url'], 'ihc_ap_menu=' . EPC_Frontend::UMP_TAB ) );
$html = (string) EPC_Frontend::ump_account_tab_content( '', EPC_Frontend::UMP_TAB );
$pass = EPC_DB::get_pass( $slug, (string) $row_id );
epc_ump_check( 'account page: the tab links the member\'s card', false !== strpos( $html, esc_url( (string) $pass->pass_link ) ) );
epc_ump_check( 'account page: other tabs are left to UMP', 'kept' === EPC_Frontend::ump_account_tab_content( 'kept', 'orders' ) );
wp_set_current_user( 0 );
epc_ump_check( 'account page: nothing for visitors', array( 'a' => 1 ) === EPC_Frontend::ump_account_tab( array( 'a' => 1 ) ) );

// Cancelled, expired and removed, the way UMP does them.
// Cancel (UMP's Cancel_Subscription::modifyStatusInDb()): status 0, then the hook.
\Ump\User_Subscriptions::updateStatus( $user, $level, 0 );
do_action( 'ihc_action_after_cancel_subscription', $user, $level );
epc_ump_check( 'cancelled: card voided', 'revoked' === epc_ump_card( $user, $level ) );
// Buying again (checkout, then the payment gateway): assign() sets the status back to active.
\Ump\User_Subscriptions::assign( $user, $level );
\Ump\User_Subscriptions::makeComplete( $user, $level );
do_action( 'ihc_payment_completed', $user, $level, \Ump\Db\Memberships::getOne( $level ), $order );
epc_ump_check( 'bought again after cancelling: the same card is live again', 'active' === epc_ump_card( $user, $level ) && (string) $pass->pass_uid === (string) EPC_DB::get_pass( $slug, (string) $row_id )->pass_uid );
// Expiry (UMP's daily job fires the hook once the end date has passed).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Expire the level, as time would.
$wpdb->update( $wpdb->prefix . 'ihc_user_levels', array( 'expire_time' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ), array( 'id' => $row_id ) );
do_action( 'ihc_action_subscription_expired', $user, $level, array() );
epc_ump_check( 'expired (UMP daily job): card voided', 'revoked' === epc_ump_card( $user, $level ) );
\Ump\User_Subscriptions::assign( $user, $level );
\Ump\User_Subscriptions::makeComplete( $user, $level );
epc_ump_check( 'renewed after expiry: card live again', 'active' === epc_ump_card( $user, $level ) );
\Ump\User_Subscriptions::deleteOne( $user, $level );
$after = EPC_DB::get_pass( $slug, (string) $row_id );
epc_ump_check( 'level removed from the member: card voided', $after && 'revoked' === (string) $after->status );

// Clean up.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE module = %s AND user_id = %d', EPC_DB::table_name(), $slug, $user ) );
EPC_Pass_Issues::clear( $slug, (string) $row_id );
$wpdb->delete( $wpdb->prefix . 'ihc_orders_meta', array( 'order_id' => $order ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
$wpdb->delete( $wpdb->prefix . 'ihc_orders', array( 'id' => $order ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
$wpdb->delete( $wpdb->prefix . 'ihc_user_levels', array( 'user_id' => $user ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
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

echo EPC_UMP_Test_State::$passed, ' passed, ', EPC_UMP_Test_State::$failed, " failed\n";
if ( EPC_UMP_Test_State::$failed > 0 ) {
	exit( 1 );
}

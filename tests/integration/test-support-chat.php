<?php
/**
 * Integration test for v42: "Chat with us" (EpassCard support on Crisp).
 *
 * Run: wp eval-file tests/integration/test-support-chat.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Support_Chat' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-support-chat.php (EpassCard active)\n" );
	exit( 1 );
}

/**
 * Counters (a class: wp eval-file runs this file inside a function).
 */
final class EPC_SC_Test_State {
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
function epc_sc_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_SC_Test_State::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_SC_Test_State::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

$at = static function ( $utc ) {
	return EPC_Support_Chat::is_online( (int) strtotime( $utc . ' UTC' ) );
};
// Asia/Dhaka is UTC+6; 2026-10-24 is a Saturday, 2026-10-23 a Friday.
epc_sc_check( 'online from 10:00 Dhaka (Saturday)', $at( '2026-10-24 04:00' ) && ! $at( '2026-10-24 03:59' ) );
epc_sc_check( 'offline from 19:00 Dhaka', $at( '2026-10-24 12:59' ) && ! $at( '2026-10-24 13:00' ) );
epc_sc_check( 'offline all Friday, online Thursday', ! $at( '2026-10-23 06:00' ) && $at( '2026-10-22 06:00' ) );
$hours = static function () {
	return array(
		'timezone' => 'UTC',
		'days'     => array( 5 ),
		'open'     => '00:00',
		'close'    => '23:59',
	);
};
add_filter( 'epc_support_chat_hours', $hours );
epc_sc_check( 'hours are filterable', $at( '2026-10-23 06:00' ) && ! $at( '2026-10-24 06:00' ) );
remove_filter( 'epc_support_chat_hours', $hours );

epc_sc_check( 'emails on reserved test domains are not given to Crisp', '' === EPC_Support_Chat::chat_email( 'admin@site.test' ) && '' === EPC_Support_Chat::chat_email( 'a@b.example' ) && 'me@site.com' === EPC_Support_Chat::chat_email( 'me@site.com' ) );

// What is sent: never the API key.
$saved = get_option( 'epc_connection_settings', null );
if ( '' === EPC_Connection::get_api_key() ) {
	$settings                      = EPC_Connection::get_settings();
	$settings['api_key_encrypted'] = EPC_Encryption::encrypt( 'epc-test-secret-key-123' );
	update_option( 'epc_connection_settings', $settings );
}
$key  = EPC_Connection::get_api_key();
$sent = implode( ' | ', wp_list_pluck( EPC_Support_Chat::context(), 'value' ) );
epc_sc_check( 'the details sent hold the site, versions and plan, not the API key', false !== strpos( $sent, home_url( '/' ) ) && false !== strpos( $sent, EPC_VERSION ) && false === strpos( $sent, $key ), $sent );

// The last failed call: path and error, not the query string or the body.
global $wpdb;
$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test row.
	EPC_Api_Log::table_name(),
	array(
		'method'        => 'POST',
		'endpoint_url'  => 'https://api.epasscard.com/api/public/v1/create-single-pass/abc?apiKey=' . $key,
		'request_body'  => '{"x":"' . $key . '"}',
		'response_body' => '{"status":402,"message":"Pass limit reached for your plan"}',
		'http_status'   => 200,
		'is_success'    => 0,
		'error_code'    => 'epc_api_error',
		'context'       => 'tickera:create_pass',
		'duration_ms'   => 5,
		'created_at'    => current_time( 'mysql', true ),
	)
);
$log_id = (int) $wpdb->insert_id;
$last   = EPC_Support_Chat::last_failed_call();
epc_sc_check( 'last failed call: endpoint, error and message, never the key', false !== strpos( $last, '/api/public/v1/create-single-pass/abc' ) && false !== strpos( $last, 'Pass limit reached' ) && false !== strpos( $last, 'tickera:create_pass' ) && false === strpos( $last, $key ), $last );
$wpdb->delete( EPC_Api_Log::table_name(), array( 'id' => $log_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
if ( null === $saved ) {
	delete_option( 'epc_connection_settings' );
} else {
	update_option( 'epc_connection_settings', $saved );
}

// Who sees the button.
$admin = (int) ( get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
)[0] ?? 0 );
wp_set_current_user( $admin );
$was_hidden = get_user_meta( $admin, EPC_Support_Chat::META_HIDDEN, true );
delete_user_meta( $admin, EPC_Support_Chat::META_HIDDEN );
ob_start();
EPC_Support_Chat::render_button();
$html = (string) ob_get_clean();
epc_sc_check( 'an admin sees the button, and no Crisp script is in the page', EPC_Support_Chat::is_visible() && false !== strpos( $html, 'Chat with us' ) && false === strpos( $html, 'client.crisp.chat' ) );
update_user_meta( $admin, EPC_Support_Chat::META_HIDDEN, '1' );
ob_start();
EPC_Support_Chat::render_button();
epc_sc_check( 'hidden for this user (Settings → Advanced)', '' === (string) ob_get_clean() && ! EPC_Support_Chat::is_visible() );
delete_user_meta( $admin, EPC_Support_Chat::META_HIDDEN );
add_filter( 'epc_support_chat_enabled', '__return_false' );
epc_sc_check( 'off for the whole site with the filter', ! EPC_Support_Chat::is_visible() );
remove_filter( 'epc_support_chat_enabled', '__return_false' );
if ( '' !== (string) $was_hidden ) {
	update_user_meta( $admin, EPC_Support_Chat::META_HIDDEN, $was_hidden );
}
wp_set_current_user( 0 );
epc_sc_check( 'not for visitors without admin rights', ! EPC_Support_Chat::is_visible() );

echo EPC_SC_Test_State::$passed, ' passed, ', EPC_SC_Test_State::$failed, " failed\n";
if ( EPC_SC_Test_State::$failed > 0 ) {
	exit( 1 );
}

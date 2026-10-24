<?php
/**
 * Integration test for v13: the "User profile field" mapping mode (any user meta on a card).
 * Uses the test integration and fake API of fixtures/test-integration.php.
 *
 * Run: wp eval-file tests/integration/test-user-fields.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_User_Fields' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-user-fields.php (EpassCard active)\n" );
	exit( 1 );
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Counters and the fields sent to the API (a class: wp eval-file runs this file inside a function).
 */
final class EPC_UF_Test_State {
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
function epc_uf_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_UF_Test_State::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_UF_Test_State::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

// Record what is sent to EpassCard (the fixture's fake API answers).
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false !== strpos( (string) $url, 'single-pass' ) ) {
			$body = json_decode( (string) ( $args['body'] ?? '' ), true );
			$list = is_array( $body ) ? ( $body['fields'] ?? ( $body['additionalFieldsValue'] ?? array() ) ) : array();
			EPC_UF_Test_State::$fields = array();
			foreach ( (array) $list as $field ) {
				if ( is_array( $field ) && isset( $field['uid'] ) ) {
					EPC_UF_Test_State::$fields[ (string) $field['uid'] ] = (string) ( $field['fieldValue'] ?? '' );
				}
			}
		}
		return $pre;
	},
	5,
	3
);

epc_test_fixture_setup();
$slug   = epc_test_module_slug();
$module = EPC_Module_Loader::get_module_instance( $slug );
$member = 'aaaaaaaa-bbbb-4ccc-8ddd-111111111111';
$email  = 'aaaaaaaa-bbbb-4ccc-8ddd-222222222222';
$secret = 'aaaaaaaa-bbbb-4ccc-8ddd-333333333333';
$map    = array(
	'template_uid'  => '11111111-2222-4333-8444-555555555555',
	'field_mapping' => array(
		'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' => array(
			'type'   => 'source',
			'source' => 'name',
		),
		$member                                => array(
			'type'  => 'user_meta',
			'value' => 'epc_t_member_id',
		),
		$email                                 => array(
			'type'  => 'user_meta',
			'value' => 'user_email',
		),
	),
	'pass_fields'   => array(),
);

epc_uf_check( 'the mapping offers "User profile field"', isset( $module->get_mapping_modes()['user_meta'] ) );
epc_uf_check( 'secret fields are refused', EPC_User_Fields::is_blocked( 'session_tokens' ) && EPC_User_Fields::is_blocked( 'wp_capabilities' ) && EPC_User_Fields::is_blocked( 'my_api_key' ) && EPC_User_Fields::is_blocked( 'reset_token' ) && ! EPC_User_Fields::is_blocked( 'member_id' ) && ! EPC_User_Fields::is_blocked( 'footprint' ) && ! EPC_User_Fields::is_blocked( 'author_bio' ) );
epc_uf_check( 'a mapping to a secret field or to no field is dropped when saved', null === EPC_Pass_Service::normalize_mapping_entry( array( 'type' => 'user_meta', 'value' => 'session_tokens' ) ) && null === EPC_Pass_Service::normalize_mapping_entry( array( 'type' => 'user_meta', 'value' => ' ' ) ) && array( 'type' => 'user_meta', 'value' => 'member_id' ) === EPC_Pass_Service::normalize_mapping_entry( array( 'type' => 'user_meta', 'value' => 'member_id' ) ) );
epc_uf_check( 'the field names of this site are suggested, secrets left out', in_array( 'user_email', EPC_User_Fields::suggestions(), true ) && in_array( 'first_name', EPC_User_Fields::suggestions(), true ) && ! in_array( 'session_tokens', EPC_User_Fields::suggestions(), true ) );

$module->save_mapping( 1, $map );
$user = epc_test_fixture_user();
update_user_meta( $user, 'epc_t_member_id', 'D4-00042' );
$result = $module->sync_by_source_id( (string) $user );
epc_uf_check( 'card made with the member ID and email from the profile', true === $result && 'D4-00042' === ( EPC_UF_Test_State::$fields[ $member ] ?? '' ) && (string) get_userdata( $user )->user_email === ( EPC_UF_Test_State::$fields[ $email ] ?? '' ), wp_json_encode( EPC_UF_Test_State::$fields ) );

// The member ID changes in the profile: the card is refreshed shortly.
wp_clear_scheduled_hook( EPC_User_Pass_Sync::CRON_HOOK, array( $user ) );
EPC_User_Fields::reset_cache();
update_user_meta( $user, 'epc_t_member_id', 'D4-00043' );
epc_uf_check( 'changing a mapped profile field schedules the update', false !== wp_next_scheduled( EPC_User_Pass_Sync::CRON_HOOK, array( $user ) ) );
EPC_User_Pass_Sync::sync_user_passes( $user );
epc_uf_check( 'the card shows the new member ID', 'D4-00043' === ( EPC_UF_Test_State::$fields[ $member ] ?? '' ) );
wp_clear_scheduled_hook( EPC_User_Pass_Sync::CRON_HOOK, array( $user ) );
update_user_meta( $user, 'epc_t_not_on_card', 'x' );
epc_uf_check( 'a profile field no design uses does not', false === wp_next_scheduled( EPC_User_Pass_Sync::CRON_HOOK, array( $user ) ) );

// Values: lists, account fields, no account.
update_user_meta( $user, 'epc_t_list', array( 'Gold', 'VIP' ) );
epc_uf_check( 'a list value is joined; account fields work', 'Gold, VIP' === EPC_User_Fields::value( $user, 'epc_t_list' ) && (string) $user === EPC_User_Fields::value( $user, 'user_id' ) && '' === EPC_User_Fields::value( $user, 'session_tokens' ) );
EPC_UF_Test_State::$fields = array();
$guest = EPC_Pass_Service::build_create_fields( $map, EPC_User_Fields::attach( $map, array( 'name' => 'Guest' ), 0 ), $slug );
epc_uf_check( 'a card without an account leaves the profile fields out', 1 === count( $guest ) );
$map['field_mapping'][ $secret ] = array(
	'type'  => 'user_meta',
	'value' => 'session_tokens',
);
$sent = EPC_Pass_Service::build_create_fields( $map, EPC_User_Fields::attach( $map, array( 'name' => 'X' ), $user ), $slug );
epc_uf_check( 'a secret field never reaches a card, even if saved directly', ! in_array( $secret, wp_list_pluck( $sent, 'uid' ), true ) );

wp_clear_scheduled_hook( EPC_User_Pass_Sync::CRON_HOOK, array( $user ) );
epc_test_fixture_teardown();

echo EPC_UF_Test_State::$passed, ' passed, ', EPC_UF_Test_State::$failed, " failed\n";
if ( EPC_UF_Test_State::$failed > 0 ) {
	exit( 1 );
}

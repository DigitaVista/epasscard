<?php
/**
 * Integration tests for the developer API (v24): epc_issue_pass(), epc_update_pass(),
 * epc_void_pass(), epc_get_pass() and the REST routes under epasscard/v1/passes.
 *
 * Run: wp eval-file tests/integration/test-developer-api.php (tests/integration/run.sh does this).
 * Uses the test integration and fake API from fixtures/test-integration.php and removes what it
 * created. Prints PASS/FAIL lines; exits 1 on any failure.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'epc_issue_pass' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-developer-api.php (EpassCard active)\n" );
	exit( 1 );
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Pass/fail counter and captured mail (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Dev_Api_Test_State {
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
	 * Mail recipients.
	 *
	 * @var array<int, string>
	 */
	public static $mail = array();
}

/**
 * Record one check.
 *
 * @param string $label Check label.
 * @param bool   $ok    Result.
 * @param string $info  Extra detail on failure.
 * @return void
 */
function epc_da_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Dev_Api_Test_State::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Dev_Api_Test_State::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * Error code of a result, or ''.
 *
 * @param mixed $result Result.
 * @return string
 */
function epc_da_code( $result ) {
	return is_wp_error( $result ) ? (string) $result->get_error_code() : '';
}

/**
 * Run a REST request.
 *
 * @param string               $method Method.
 * @param string               $route  Route.
 * @param array<string, mixed> $params Body params.
 * @return WP_REST_Response
 */
function epc_da_rest( $method, $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_get_server()->dispatch( $request );
}

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) $admins[0] );

$slug = epc_test_module_slug();
epc_test_fixture_setup();
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		EPC_Dev_Api_Test_State::$mail[] = (string) $atts['to'];
		return $short;
	},
	1,
	2
);
$email_settings = get_option( EPC_Pass_Email::OPTION, null );
update_option( EPC_Pass_Email::OPTION, array_merge( EPC_Pass_Email::get_settings(), array( 'auto_on_create' => 1 ) ), false );

$user = epc_test_fixture_user();

// Checks before anything exists.
epc_da_check( 'an unknown integration is refused', 'epc_unknown_integration' === epc_da_code( epc_issue_pass( 'no-such-plugin', $user ) ) );
epc_da_check( 'a bad record id is refused', 'epc_invalid_source_id' === epc_da_code( epc_issue_pass( $slug, 'abc def' ) ) );
epc_da_check( 'get: no card yet', 'epc_pass_missing' === epc_da_code( epc_get_pass( $slug, $user ) ) );
epc_da_check( 'update: no card yet', 'epc_pass_missing' === epc_da_code( epc_update_pass( $slug, $user ) ) );

// Issue, with the email asked for: one email, even though the automatic email is on.
EPC_Dev_Api_Test_State::$mail = array();
$card = epc_issue_pass( $slug, $user, array( 'email' => true ) );
epc_da_check( 'issue creates the card', is_array( $card ) && true === $card['created'] && 'active' === $card['status'] && '' !== $card['pass_link'], wp_json_encode( $card ) );
epc_da_check( 'issue with email sends exactly one email', is_array( $card ) && true === $card['emailed'] && array( get_userdata( $user )->user_email ) === EPC_Dev_Api_Test_State::$mail, wp_json_encode( EPC_Dev_Api_Test_State::$mail ) );

$again = epc_issue_pass( $slug, $user );
epc_da_check( 'issue again updates the same card', is_array( $again ) && false === $again['created'] && $card['pass_uid'] === $again['pass_uid'] );
$read = epc_get_pass( $slug, $user );
epc_da_check( 'get returns the card', is_array( $read ) && $card['pass_uid'] === $read['pass_uid'] && $user === $read['user_id'] && false === $read['is_test'] );

// Void, then bring back.
$void = epc_void_pass( $slug, $user );
epc_da_check( 'void revokes the card', is_array( $void ) && 'revoked' === $void['status'], wp_json_encode( $void ) );
epc_da_check( 'void twice is fine', is_array( epc_void_pass( $slug, $user ) ) );
$back = epc_update_pass( $slug, $user );
epc_da_check( 'update makes a voided card live again', is_array( $back ) && 'active' === $back['status'] && $card['pass_uid'] === $back['pass_uid'], wp_json_encode( $back ) );

// REST.
$other    = epc_test_fixture_user();
$response = epc_da_rest(
	'POST',
	'/epasscard/v1/passes',
	array(
		'module'    => $slug,
		'source_id' => (string) $other,
	)
);
epc_da_check( 'REST POST creates: 201', 201 === $response->get_status() && 'active' === $response->get_data()['status'], $response->get_status() . ' ' . wp_json_encode( $response->get_data() ) );
$response = epc_da_rest(
	'POST',
	'/epasscard/v1/passes',
	array(
		'module'    => $slug,
		'source_id' => (string) $other,
	)
);
epc_da_check( 'REST POST again: 200', 200 === $response->get_status() );
$response = epc_da_rest( 'GET', '/epasscard/v1/passes/' . $slug . '/' . $other );
epc_da_check( 'REST GET: 200 with the card', 200 === $response->get_status() && (string) $other === $response->get_data()['source_id'] );
$response = epc_da_rest( 'DELETE', '/epasscard/v1/passes/' . $slug . '/' . $other );
epc_da_check( 'REST DELETE voids: 200 revoked', 200 === $response->get_status() && 'revoked' === $response->get_data()['status'] );
$response = epc_da_rest( 'PUT', '/epasscard/v1/passes/' . $slug . '/' . $other );
epc_da_check( 'REST PUT updates: 200 active', 200 === $response->get_status() && 'active' === $response->get_data()['status'] );
$response = epc_da_rest( 'GET', '/epasscard/v1/passes/no-such-plugin/' . $other );
epc_da_check( 'REST unknown integration: 404', 404 === $response->get_status() );
$response = epc_da_rest( 'GET', '/epasscard/v1/passes/' . $slug . '/' . $user . '9999' );
epc_da_check( 'REST no card: 404', 404 === $response->get_status() );

wp_set_current_user( $user );
$response = epc_da_rest( 'GET', '/epasscard/v1/passes/' . $slug . '/' . $other );
epc_da_check( 'REST: a customer is refused', in_array( $response->get_status(), array( 401, 403 ), true ), (string) $response->get_status() );
wp_set_current_user( 0 );
$response = epc_da_rest(
	'POST',
	'/epasscard/v1/passes',
	array(
		'module'    => $slug,
		'source_id' => (string) $other,
	)
);
epc_da_check( 'REST: signed out is refused', 401 === $response->get_status(), (string) $response->get_status() );
wp_set_current_user( (int) $admins[0] );

// Clean up.
if ( null === $email_settings ) {
	delete_option( EPC_Pass_Email::OPTION );
} else {
	update_option( EPC_Pass_Email::OPTION, $email_settings, false );
}
epc_test_fixture_teardown();

echo EPC_Dev_Api_Test_State::$passed, ' passed, ', EPC_Dev_Api_Test_State::$failed, " failed\n";
if ( EPC_Dev_Api_Test_State::$failed > 0 ) {
	exit( 1 );
}

<?php
/**
 * Integration test: every way of connecting (sign up, sign in, API key) sends
 * reference = wp-plugin, so EpassCard knows the account came from this plugin.
 *
 * Run: wp eval-file tests/integration/test-connect-reference.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Api_Client' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-connect-reference.php (EpassCard active)\n" );
	exit( 1 );
}

/**
 * What each connection call sent (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Ref_Test_State {
	/**
	 * Reference per endpoint.
	 *
	 * @var array<string, string>
	 */
	public static $seen = array();
}

$epc_ref_filter = static function ( $pre, $args, $url ) {
	if ( preg_match( '#/(validate-api-key|generate-api-key|sign-up)$#', (string) $url, $m ) ) {
		$body = json_decode( (string) $args['body'], true );
		EPC_Ref_Test_State::$seen[ $m[1] ] = is_array( $body ) && isset( $body['reference'] ) ? (string) $body['reference'] : '(missing)';
		// Answer with an error so nothing is stored.
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'status'  => 400,
					'message' => 'test',
				)
			),
			'response' => array(
				'code'    => 400,
				'message' => 'Bad Request',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
	return $pre;
};
add_filter( 'pre_http_request', $epc_ref_filter, 1, 3 );

EPC_Api_Client::validate_api_key( 'epc-test-key' );
EPC_Api_Client::generate_api_key( 'owner@example.test', 'not-a-real-password' );
EPC_Api_Client::sign_up( 'Test Shop', 'owner@example.test' );
remove_filter( 'pre_http_request', $epc_ref_filter, 1 );

$failed = 0;
foreach ( array( 'validate-api-key' => 'API key', 'generate-api-key' => 'sign in', 'sign-up' => 'sign up' ) as $endpoint => $label ) {
	$ok = 'wp-plugin' === ( EPC_Ref_Test_State::$seen[ $endpoint ] ?? '' );
	echo $ok ? 'PASS ' : 'FAIL ', 'connect by ', $label, ': reference = wp-plugin', $ok ? '' : ' -- ' . wp_json_encode( EPC_Ref_Test_State::$seen ), "\n";
	$failed += $ok ? 0 : 1;
}
echo 3 - $failed, ' passed, ', $failed, " failed\n";
if ( $failed > 0 ) {
	exit( 1 );
}

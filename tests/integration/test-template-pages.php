<?php
/**
 * Integration test: the template picker gets every template, not only the API's first page
 * (get-pass-templates returns 10 a page).
 *
 * Run: wp eval-file tests/integration/test-template-pages.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Api_Client' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-template-pages.php (EpassCard active)\n" );
	exit( 1 );
}

/**
 * The fake API (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Tpl_Pages_Test {
	/**
	 * Templates on the account.
	 *
	 * @var int
	 */
	public static $count = 23;

	/**
	 * Send total_templates.
	 *
	 * @var bool
	 */
	public static $send_total = true;

	/**
	 * Page that answers with an error (0 = none).
	 *
	 * @var int
	 */
	public static $fail_page = 0;

	/**
	 * Answer every page with page 1 (an API that ignores ?page).
	 *
	 * @var bool
	 */
	public static $ignore_page = false;

	/**
	 * Pages asked for.
	 *
	 * @var array<int, int>
	 */
	public static $asked = array();

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
	 * Reset between cases.
	 *
	 * @param int  $count       Templates.
	 * @param bool $send_total  Send the total.
	 * @param int  $fail_page   Failing page.
	 * @param bool $ignore_page Ignore ?page.
	 * @return void
	 */
	public static function setup( $count, $send_total = true, $fail_page = 0, $ignore_page = false ) {
		self::$count       = $count;
		self::$send_total  = $send_total;
		self::$fail_page   = $fail_page;
		self::$ignore_page = $ignore_page;
		self::$asked       = array();
	}
}

$epc_tpl_response = static function ( $code, array $body ) {
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( $body ),
		'response' => array(
			'code'    => $code,
			'message' => 200 === $code ? 'OK' : 'Error',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};

$epc_tpl_filter = static function ( $pre, $args, $url ) use ( $epc_tpl_response ) {
	if ( ! preg_match( '#/get-pass-templates\?page=(\d+)$#', (string) $url, $m ) ) {
		return $pre;
	}
	$page                        = (int) $m[1];
	EPC_Tpl_Pages_Test::$asked[] = $page;
	if ( EPC_Tpl_Pages_Test::$fail_page === $page ) {
		return $epc_tpl_response(
			500,
			array(
				'status'  => 500,
				'message' => 'test failure',
			)
		);
	}
	$from      = ( ( EPC_Tpl_Pages_Test::$ignore_page ? 1 : $page ) - 1 ) * 10;
	$templates = array();
	for ( $i = $from; $i < min( $from + 10, EPC_Tpl_Pages_Test::$count ); $i++ ) {
		$templates[] = array(
			'id'   => 1000 + $i,
			'uid'  => sprintf( '00000000-0000-4000-8000-%012d', $i ),
			'name' => 'Template ' . ( $i + 1 ),
		);
	}
	$body = array( 'templates' => $templates );
	if ( EPC_Tpl_Pages_Test::$send_total ) {
		$body['total'] = array( 'total_templates' => EPC_Tpl_Pages_Test::$count );
	}
	return $epc_tpl_response( 200, $body );
};

// The client needs an API key; the fake API answers whatever it is.
$epc_tpl_key = static function ( $value ) {
	$value                      = is_array( $value ) ? $value : array();
	$value['api_key_encrypted'] = EPC_Encryption::encrypt( 'epc-test-key' );
	return $value;
};
if ( ! EPC_Api_Client::is_configured() ) {
	add_filter( 'option_' . EPC_Connection::OPTION, $epc_tpl_key );
	add_filter( 'default_option_' . EPC_Connection::OPTION, $epc_tpl_key );
}
// Last, so it wins over any other fake API on the test site.
add_filter( 'pre_http_request', $epc_tpl_filter, PHP_INT_MAX, 3 );

$t = 'EPC_Tpl_Pages_Test';

// 23 templates: three pages.
$t::setup( 23 );
$all  = EPC_Api_Client::get_all_templates();
$uids = is_array( $all ) ? array_unique( wp_list_pluck( $all['templates'], 'uid' ) ) : array();
$t::check( '23 templates on 3 pages: all 23 come back', is_array( $all ) && 23 === count( $all['templates'] ) && 23 === count( $uids ), is_wp_error( $all ) ? $all->get_error_message() : wp_json_encode( $t::$asked ) );
$t::check( 'stops once the total is reached (pages 1-3, no 4th call)', array( 1, 2, 3 ) === $t::$asked, wp_json_encode( $t::$asked ) );
$t::check( 'total is 23', is_array( $all ) && 23 === $all['total_templates'] );
$t::check( 'order kept: first and last', is_array( $all ) && 'Template 1' === $all['templates'][0]['name'] && 'Template 23' === $all['templates'][22]['name'] );

// Exactly 20: two full pages.
$t::setup( 20 );
$all = EPC_Api_Client::get_all_templates();
$t::check( '20 templates: 2 calls, 20 back', is_array( $all ) && 20 === count( $all['templates'] ) && array( 1, 2 ) === $t::$asked, wp_json_encode( $t::$asked ) );

// Fewer than a page.
$t::setup( 4 );
$all = EPC_Api_Client::get_all_templates();
$t::check( '4 templates: 1 call', is_array( $all ) && 4 === count( $all['templates'] ) && array( 1 ) === $t::$asked, wp_json_encode( $t::$asked ) );

// No templates.
$t::setup( 0 );
$all = EPC_Api_Client::get_all_templates();
$t::check( 'no templates: empty list, 1 call', is_array( $all ) && array() === $all['templates'] && array( 1 ) === $t::$asked, wp_json_encode( $t::$asked ) );

// No total in the answer: read until an empty page.
$t::setup( 23, false );
$all = EPC_Api_Client::get_all_templates();
$t::check( 'without a total: reads until the empty page 4, gets 23', is_array( $all ) && 23 === count( $all['templates'] ) && array( 1, 2, 3, 4 ) === $t::$asked, wp_json_encode( $t::$asked ) );

// An API that ignores ?page: no endless loop, no duplicates.
$t::setup( 23, false, 0, true );
$all = EPC_Api_Client::get_all_templates();
$t::check( 'page ignored by the API: stops on the repeat, 10 unique', is_array( $all ) && 10 === count( $all['templates'] ) && array( 1, 2 ) === $t::$asked, wp_json_encode( $t::$asked ) );

// A later page fails: keep the earlier ones.
$t::setup( 23, true, 2 );
$all = EPC_Api_Client::get_all_templates();
$t::check( 'page 2 fails: the 10 from page 1 are kept', is_array( $all ) && 10 === count( $all['templates'] ), is_wp_error( $all ) ? $all->get_error_message() : '' );

// The first page fails: the error is passed on.
$t::setup( 23, true, 1 );
$all = EPC_Api_Client::get_all_templates();
$t::check( 'page 1 fails: an error', is_wp_error( $all ) );

// Page cap.
$t::setup( 1000, false );
$all = EPC_Api_Client::get_all_templates( 5 );
$t::check( 'stops at the page cap', is_array( $all ) && 50 === count( $all['templates'] ) && 5 === count( $t::$asked ), wp_json_encode( $t::$asked ) );

remove_filter( 'pre_http_request', $epc_tpl_filter, PHP_INT_MAX );
remove_filter( 'option_' . EPC_Connection::OPTION, $epc_tpl_key );
remove_filter( 'default_option_' . EPC_Connection::OPTION, $epc_tpl_key );

echo $t::$result['passed'], ' passed, ', $t::$result['failed'], " failed\n";
if ( $t::$result['failed'] > 0 ) {
	exit( 1 );
}

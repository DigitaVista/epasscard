<?php
/**
 * Integration tests for the 1.2 Home checklist and "Try it" (v37).
 *
 * Run: wp eval-file tests/integration/test-onboarding.php (tests/integration/run.sh does this).
 * Uses the test integration and fake API from fixtures/test-integration.php, saves and restores
 * the options it changes. Prints PASS/FAIL lines; exits 1 on any failure.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Onboarding' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-onboarding.php (EpassCard active)\n" );
	exit( 1 );
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Pass/fail counter and the API calls seen (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Onboarding_Test_State {
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
	 * EpassCard URLs requested.
	 *
	 * @var array<int, string>
	 */
	public static $calls = array();
}

/**
 * Record one check.
 *
 * @param string $label Check label.
 * @param bool   $ok    Result.
 * @param string $info  Extra detail on failure.
 * @return void
 */
function epc_onboarding_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Onboarding_Test_State::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Onboarding_Test_State::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * EpassCard calls whose URL matches a pattern.
 *
 * @param string $pattern Regular expression.
 * @return int
 */
function epc_onboarding_calls( $pattern ) {
	return count( preg_grep( $pattern, EPC_Onboarding_Test_State::$calls ) );
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false !== strpos( (string) $url, 'epasscard' ) ) {
			EPC_Onboarding_Test_State::$calls[] = (string) $url;
		}
		return $pre;
	},
	1,
	3
);

global $wpdb;

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) $admins[0] );

$slug   = epc_test_module_slug();
$saved  = array(
	'setup' => get_option( EPC_Onboarding::OPTION, null ),
	'cards' => get_option( EPC_Test_Cards::OPTION, null ),
);
$table  = EPC_DB::table_name();
$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

epc_test_fixture_setup();
$module = EPC_Module_Loader::get_module_instance( $slug );

// --- Checklist: the merchant's choice, then the steps read from the site.
EPC_Onboarding::update(
	array(
		'job'             => 'epc-test-job',
		'module'          => $slug,
		'rules_confirmed' => true,
		'try'             => '',
	)
);
$steps = wp_list_pluck( EPC_Onboarding::get_steps(), 'status' );
epc_onboarding_check( 'connected, job confirmed and design saved: Try it is the current step', array( 'done', 'done', 'done', 'current', 'todo' ) === array_values( $steps ), wp_json_encode( $steps ) );
epc_onboarding_check( 'checklist not complete before a real card', false === EPC_Onboarding::is_complete() );

EPC_Onboarding::update( array( 'try' => 'skipped' ) );
$steps = wp_list_pluck( EPC_Onboarding::get_steps(), 'status' );
epc_onboarding_check( 'skipping Try it makes Go live current', 'skipped' === $steps['try'] && 'current' === $steps['live'], wp_json_encode( $steps ) );

// --- Try it: a test card for a non-loyalty job lives in an option, not in the passes table.
EPC_Onboarding_Test_State::$calls = array();
$card = EPC_Test_Cards::issue( $slug );
epc_onboarding_check( 'test card created with a wallet link', is_array( $card ) && 0 === strpos( (string) $card['pass_link'], 'https://pass.example.test/' ), is_wp_error( $card ) ? $card->get_error_message() : '' );
epc_onboarding_check( 'one create call', 1 === epc_onboarding_calls( '/create-single-pass/' ) );
epc_onboarding_check( 'no row added to the passes table', $before === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
epc_onboarding_check( 'the test card does not complete the checklist', false === EPC_Onboarding::is_complete() );

EPC_Onboarding_Test_State::$calls = array();
$again = EPC_Test_Cards::issue( $slug );
epc_onboarding_check( 'same design: the card is reused without an API call', is_array( $again ) && $again['pass_uid'] === $card['pass_uid'] && empty( EPC_Onboarding_Test_State::$calls ) );

$mapping                 = $module->get_mapping( 1 );
$changed                 = $mapping;
$changed['template_uid'] = '99999999-2222-4333-8444-555555555555';
$module->save_mapping( 1, $changed );
EPC_Onboarding_Test_State::$calls = array();
$new = EPC_Test_Cards::issue( $slug );
epc_onboarding_check( 'new design: a new test card', is_array( $new ) && $new['pass_uid'] !== $card['pass_uid'] );
epc_onboarding_check( 'new design: the old test card is expired', 1 === epc_onboarding_calls( '#pass-expire/' . preg_quote( $card['pass_uid'], '#' ) . '#' ) );
$module->save_mapping( 1, $mapping );

ob_start();
EPC_Admin_Try::render();
$html = ob_get_clean();
epc_onboarding_check( 'Try page shows the QR code of the card', false !== strpos( $html, 'data-epc-qr="' . esc_attr( $new['pass_link'] ) . '"' ) );
epc_onboarding_check( 'Try page offers to delete a non-loyalty test card', false !== strpos( $html, 'epc_try_delete' ) );

EPC_Onboarding_Test_State::$calls = array();
epc_onboarding_check( 'delete returns true', true === EPC_Test_Cards::delete( $slug ) );
epc_onboarding_check( 'deleted card is forgotten', null === EPC_Test_Cards::get( $slug ) );
epc_onboarding_check( 'deleted card is expired', 1 === epc_onboarding_calls( '#pass-expire/' . preg_quote( $new['pass_uid'], '#' ) . '#' ) );

// --- First real card: the checklist is complete and Home shows the overview.
$user = epc_test_fixture_user();
$module->sync_by_source_id( $user, 'sync' );
$steps = wp_list_pluck( EPC_Onboarding::get_steps(), 'status' );
epc_onboarding_check( 'a real card completes the checklist', true === EPC_Onboarding::is_complete() && 'done' === $steps['live'], wp_json_encode( $steps ) );
ob_start();
EPC_Admin_Home::render();
$html = ob_get_clean();
epc_onboarding_check( 'Home shows the overview after the first real card', false !== strpos( $html, 'epc-home--live' ) );

// --- Sites from 1.1: the first enabled integration with a design is adopted.
delete_option( EPC_Onboarding::OPTION );
update_option( 'epc_enabled_modules', array( $slug ) );
$state = EPC_Onboarding::get_state();
epc_onboarding_check( 'existing setup adopted', 'epc-test-job' === $state['job'] && $slug === $state['module'] && true === $state['rules_confirmed'], wp_json_encode( $state ) );

// --- Setup's plain rules for an integration without status rules.
epc_onboarding_check( 'Setup describes an integration without status rules in one line', 1 === count( EPC_Admin_Setup::rule_lines( $module ) ) );

// Clean up.
epc_test_fixture_teardown();
foreach ( $saved as $key => $value ) {
	$option = 'setup' === $key ? EPC_Onboarding::OPTION : EPC_Test_Cards::OPTION;
	if ( null === $value ) {
		delete_option( $option );
	} else {
		update_option( $option, $value, false );
	}
}

echo EPC_Onboarding_Test_State::$passed, ' passed, ', EPC_Onboarding_Test_State::$failed, " failed\n";
if ( EPC_Onboarding_Test_State::$failed > 0 ) {
	exit( 1 );
}

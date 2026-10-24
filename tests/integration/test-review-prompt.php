<?php
/**
 * Integration tests for the review invitation (m03): only after setup, after a real card that
 * is 7 days old or 10 real cards, never for test cards, and each answer is kept per user.
 *
 * Run: wp eval-file tests/integration/test-review-prompt.php (tests/integration/run.sh does this).
 * Uses the test integration and fake API from fixtures/test-integration.php and removes what it
 * created. Prints PASS/FAIL lines; exits 1 on any failure.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Review_Prompt' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-review-prompt.php (EpassCard active)\n" );
	exit( 1 );
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Review_Test_Counter {
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
function epc_rp_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Review_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Review_Test_Counter::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * Home's HTML.
 *
 * @return string
 */
function epc_rp_home() {
	ob_start();
	EPC_Admin_Home::render();
	return (string) ob_get_clean();
}

global $wpdb;
$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$admin = (int) $admins[0];
wp_set_current_user( $admin );

if ( EPC_DB::has_real_pass_older_than_days( EPC_Review_Prompt::DAYS_LIVE ) || EPC_DB::count_all_real_passes() >= EPC_Review_Prompt::CARDS - 1 ) {
	echo "SKIP review invitation (this site already has old or many real cards)\n";
	return;
}

$slug  = epc_test_module_slug();
$saved = get_option( EPC_Onboarding::OPTION, null );
$meta  = get_user_meta( $admin, EPC_Review_Prompt::META, true );
delete_user_meta( $admin, EPC_Review_Prompt::META );
epc_test_fixture_setup();
$module = EPC_Module_Loader::get_module_instance( $slug );
EPC_Onboarding::update(
	array(
		'job'             => 'epc-test-job',
		'module'          => $slug,
		'rules_confirmed' => true,
		'try'             => 'skipped',
	)
);

// A test card never counts.
EPC_Pass_Status::run_as_test(
	static function () use ( $module ) {
		return $module->sync_by_source_id( epc_test_fixture_user(), 'sync' );
	}
);
epc_rp_check( 'no invitation before a real card', ! EPC_Review_Prompt::should_show() );

// A new real card: setup is complete, but the card is not 7 days old.
$first = epc_test_fixture_user();
$module->sync_by_source_id( $first, 'sync' );
epc_rp_check( 'setup is complete', EPC_Onboarding::is_complete() );
epc_rp_check( 'no invitation for a card made today', ! EPC_Review_Prompt::should_show() && false === strpos( epc_rp_home(), 'epc-review' ) );

// The same card 8 days later.
$row = EPC_DB::get_pass( $slug, $first );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = NOW() - INTERVAL 8 DAY WHERE id = %d', EPC_DB::table_name(), (int) $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
$html = epc_rp_home();
epc_rp_check( 'invitation once a real card is 7 days old', EPC_Review_Prompt::should_show() && false !== strpos( $html, 'data-epc-review' ) );
epc_rp_check( 'the invitation links to the review form and the support forum', false !== strpos( $html, 'name="answer" value="done"' ) && false !== strpos( $html, EPC_Review_Prompt::SUPPORT_URL ) );

// Not while another job is being set up.
$state = EPC_Onboarding::get_state();
EPC_Onboarding::update(
	array(
		'job'    => 'gift-cards',
		'module' => '',
	)
);
epc_rp_check( 'not during setup', ! EPC_Onboarding::is_complete() && ! EPC_Review_Prompt::should_show() );
EPC_Onboarding::update(
	array(
		'job'    => $state['job'],
		'module' => $state['module'],
	)
);
epc_rp_check( 'not for staff', ( static function () use ( $first, $admin ) {
	wp_set_current_user( $first );
	$show = EPC_Review_Prompt::should_show();
	wp_set_current_user( $admin );
	return ! $show;
} )() );

// Answers.
EPC_Review_Prompt::answer( $admin, 'later' );
epc_rp_check( 'Maybe later hides it', ! EPC_Review_Prompt::should_show() );
update_user_meta(
	$admin,
	EPC_Review_Prompt::META,
	array(
		'state' => 'later',
		'until' => time() - 1,
	)
);
epc_rp_check( 'it comes back when Maybe later is over', EPC_Review_Prompt::should_show() );
EPC_Review_Prompt::answer( $admin, 'never' );
epc_rp_check( 'Don\'t ask again hides it', ! EPC_Review_Prompt::should_show() );
delete_user_meta( $admin, EPC_Review_Prompt::META );

$_POST    = array(
	'answer'   => 'done',
	'_wpnonce' => wp_create_nonce( 'epc_review_prompt' ),
);
$_REQUEST = $_POST;
$to       = '';
$stop     = static function ( $location ) use ( &$to ) {
	$to = (string) $location;
	throw new RuntimeException( 'redirect' );
};
add_filter( 'wp_redirect', $stop, 1 );
try {
	EPC_Review_Prompt::handle();
} catch ( RuntimeException $e ) {
	unset( $e ); // Expected: the handler redirects.
}
remove_filter( 'wp_redirect', $stop, 1 );
$_POST    = array();
$_REQUEST = array();
epc_rp_check( 'Write a review goes to the WordPress.org review form', EPC_Review_Prompt::REVIEW_URL === $to, $to );
epc_rp_check( 'after writing a review it is not shown again', ! EPC_Review_Prompt::should_show() );
delete_user_meta( $admin, EPC_Review_Prompt::META );

// Ten real cards made today are enough on their own.
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = NOW() WHERE id = %d', EPC_DB::table_name(), (int) $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
while ( EPC_DB::count_all_real_passes() < EPC_Review_Prompt::CARDS - 1 ) {
	$module->sync_by_source_id( epc_test_fixture_user(), 'sync' );
}
epc_rp_check( 'nine real cards are not enough', ! EPC_Review_Prompt::should_show() );
$module->sync_by_source_id( epc_test_fixture_user(), 'sync' );
epc_rp_check( 'ten real cards are', EPC_Review_Prompt::should_show() );

// Clean up.
if ( is_array( $meta ) ) {
	update_user_meta( $admin, EPC_Review_Prompt::META, $meta );
} else {
	delete_user_meta( $admin, EPC_Review_Prompt::META );
}
if ( null === $saved ) {
	delete_option( EPC_Onboarding::OPTION );
} else {
	update_option( EPC_Onboarding::OPTION, $saved );
}
epc_test_fixture_teardown();

echo EPC_Review_Test_Counter::$passed, ' passed, ', EPC_Review_Test_Counter::$failed, " failed\n";
if ( EPC_Review_Test_Counter::$failed > 0 ) {
	exit( 1 );
}

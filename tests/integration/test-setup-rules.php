<?php
/**
 * Integration tests for editing the rules on Setup (v45): status rules saved on Confirm, the
 * starter loyalty values of a new program, their checks, and the plain-text starter names.
 *
 * Run: wp eval-file tests/integration/test-setup-rules.php (tests/integration/run.sh does this).
 * Prints PASS/FAIL lines; exits 1 on any failure.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Admin_Setup' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-setup-rules.php (EpassCard active)\n" );
	exit( 1 );
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Setup_Rules_Test_Counter {
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
function epc_sr_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Setup_Rules_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Setup_Rules_Test_Counter::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * Call a private static method of EPC_Admin_Setup.
 *
 * @param string            $name Method.
 * @param array<int, mixed> $args Arguments.
 * @return mixed
 */
function epc_sr_call( $name, array $args = array() ) {
	$method = new ReflectionMethod( 'EPC_Admin_Setup', $name );
	$method->setAccessible( true );
	return $method->invokeArgs( null, $args );
}

/**
 * Run a handler that ends in a redirect; return where it went.
 *
 * @param callable $handler Handler.
 * @return string
 */
function epc_sr_redirect( $handler ) {
	$to   = '';
	$stop = static function ( $location ) use ( &$to ) {
		$to = (string) $location;
		throw new RuntimeException( 'redirect' );
	};
	add_filter( 'wp_redirect', $stop, 1 );
	try {
		call_user_func( $handler );
	} catch ( RuntimeException $e ) {
		unset( $e ); // Expected: the handler redirects.
	}
	remove_filter( 'wp_redirect', $stop, 1 );
	return $to;
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
$module                        = EPC_Module_Loader::get_module_instance( $slug );
$GLOBALS['epc_test_statuses']  = array(
	'active'  => 'Active',
	'expired' => 'Expired',
);
$saved_state                   = get_option( EPC_Onboarding::OPTION, null );

// ---- Status rules on Setup.
$_GET['page'] = EPC_Admin_App::PAGE_SETUP;
$_GET['job']  = 'epc-test-job';
ob_start();
EPC_Admin_Setup::render();
$html = ob_get_clean();
unset( $_GET['page'], $_GET['job'] );
epc_sr_check( 'Setup shows a status rule field per status', false !== strpos( $html, 'name="epc_status_rule_active"' ) && false !== strpos( $html, 'name="epc_status_rule_expired"' ), substr( wp_strip_all_tags( $html ), 0, 300 ) );
epc_sr_check( 'each status field has a label', false !== strpos( $html, '<label for="epc-setup-rule-expired">Expired</label>' ) );

$_POST    = array(
	'job'                       => 'epc-test-job',
	'module'                    => $slug,
	'epc_status_rule_active'    => 'sync',
	'epc_status_rule_expired'   => 'revoke',
	'_wpnonce'                  => wp_create_nonce( 'epc_setup_confirm' ),
);
$_REQUEST = $_POST;
$to       = epc_sr_redirect( array( 'EPC_Admin_Setup', 'handle_confirm' ) );
epc_sr_check( 'Confirm saves the status rules', 'revoke' === $module->get_status_rule( 'expired' ) && 'sync' === $module->get_status_rule( 'active' ), wp_json_encode( $module->get_status_rules() ) );
epc_sr_check( 'Confirm goes on to Home', false !== strpos( $to, 'page=' . EPC_Admin_App::PAGE_HOME ), $to );

$_POST['epc_status_rule_expired'] = 'not-an-action';
$_REQUEST                         = $_POST;
epc_sr_redirect( array( 'EPC_Admin_Setup', 'handle_confirm' ) );
$stored = get_option( $module->get_status_rules_option_key(), array() );
epc_sr_check( 'an unknown action is not saved', is_array( $stored ) && ! isset( $stored['expired'] ) && 'sync' === $module->get_status_rule( 'expired' ), wp_json_encode( $stored ) );
$_POST    = array();
$_REQUEST = array();

// ---- Starter loyalty values (a new program).
$keep = array();
foreach ( array( EPC_Loyalty_Rule_Service::OPTION_KEY, EPC_Loyalty_Reward_Service::TIERS_OPTION, EPC_Loyalty_Reward_Service::MILESTONES_OPTION, 'epc_loyalty_program', EPC_Loyalty_Starter::OPTION_KEY ) as $option ) {
	$keep[ $option ] = get_option( $option, null );
}
update_option( EPC_Loyalty_Starter::OPTION_KEY, EPC_Loyalty_Starter::VERSION, false );
EPC_Loyalty_Rule_Service::save_rules( EPC_Loyalty_Starter::rules() );
EPC_Loyalty_Reward_Service::save_tiers( EPC_Loyalty_Starter::tiers() );
EPC_Loyalty_Reward_Service::save_milestones( EPC_Loyalty_Starter::milestones() );
delete_option( 'epc_loyalty_program' );

$_POST = array(
	'epc_spend_points'   => '0',
	'epc_welcome_points' => '100',
	'epc_welcome'        => '1',
);
epc_sr_check( 'points per amount spent must be above 0', is_wp_error( epc_sr_call( 'loyalty_input' ) ) );
$_POST = array(
	'epc_spend_points'   => '1',
	'epc_reward'         => '1',
	'epc_reward_points'  => '250',
	'epc_reward_amount'  => '0',
);
epc_sr_check( 'a reward needs a coupon value above 0', is_wp_error( epc_sr_call( 'loyalty_input' ) ) );
$_POST = array(
	'epc_spend_points'   => '1',
	'epc_welcome'        => '1',
	'epc_welcome_points' => '0',
);
epc_sr_check( 'a welcome bonus needs at least 1 point', is_wp_error( epc_sr_call( 'loyalty_input' ) ) );

$_POST = array(
	'epc_spend_points'   => '2',
	'epc_welcome_points' => '100',
	'epc_reward'         => '1',
	'epc_reward_points'  => '300',
	'epc_reward_amount'  => '7.5',
	'epc_tiers_shown'    => '1',
	'epc_tiers'          => '1',
	'epc_statuses'       => 'completed',
);
$in = epc_sr_call( 'loyalty_input' );
epc_sr_check( 'valid values pass the checks', is_array( $in ), is_wp_error( $in ) ? $in->get_error_message() : '' );
epc_sr_call( 'save_loyalty_input', array( $in ) );
$_POST = array();

$rules = array();
foreach ( EPC_Loyalty_Rule_Service::get_rules() as $rule ) {
	$rules[ $rule['id'] ] = $rule;
}
epc_sr_check( 'points per amount spent saved and the rule turned on', ! empty( $rules['points-per-spend']['active'] ) && 2.0 === (float) $rules['points-per-spend']['points'] );
epc_sr_check( 'the rule name follows the points', 'Earn 2 points for every $1 spent' === $rules['points-per-spend']['name'], $rules['points-per-spend']['name'] );
epc_sr_check( 'an unticked welcome bonus stays off', empty( $rules['welcome-first-order']['active'] ) );
epc_sr_check( 'other starter rules are untouched', empty( $rules['high-spend-bonus']['active'] ) );

$reward = null;
foreach ( EPC_Loyalty_Reward_Service::get_milestones() as $milestone ) {
	if ( 'five-off-coupon' === $milestone['id'] ) {
		$reward = $milestone;
	}
}
epc_sr_check( 'the reward is on with its points and value', $reward && ! empty( $reward['active'] ) && 300 === (int) $reward['threshold'] && 7.5 === (float) $reward['reward']['amount'], wp_json_encode( $reward ) );
epc_sr_check( 'the reward name follows the value', $reward && '$7.50 off your next order' === $reward['name'], $reward ? $reward['name'] : '' );

$active_tiers = array();
foreach ( EPC_Loyalty_Reward_Service::get_tiers() as $tier ) {
	if ( ! empty( $tier['active'] ) ) {
		$active_tiers[] = $tier['id'];
	}
}
epc_sr_check( 'the starter tiers are on', array( 'bronze', 'silver', 'gold' ) === $active_tiers, wp_json_encode( $active_tiers ) );
$settings = EPC_Loyalty_Order_Service::get_settings();
epc_sr_check( 'orders earn points when completed', array( 'completed' ) === $settings['qualifying_statuses'], wp_json_encode( $settings['qualifying_statuses'] ) );
epc_sr_check( 'a new program still leaves gift cards out', true === $settings['exclude_gift_cards'] );

// Unticked reward and tiers: nothing turned on.
EPC_Loyalty_Rule_Service::save_rules( EPC_Loyalty_Starter::rules() );
EPC_Loyalty_Reward_Service::save_tiers( EPC_Loyalty_Starter::tiers() );
EPC_Loyalty_Reward_Service::save_milestones( EPC_Loyalty_Starter::milestones() );
$_POST = array(
	'epc_spend_points'   => '1',
	'epc_welcome'        => '1',
	'epc_welcome_points' => '50',
	'epc_reward_points'  => '250',
	'epc_reward_amount'  => '5',
	'epc_tiers_shown'    => '1',
	'epc_statuses'       => 'keep',
);
epc_sr_call( 'save_loyalty_input', array( epc_sr_call( 'loyalty_input' ) ) );
$_POST  = array();
$any_on = false;
foreach ( EPC_Loyalty_Reward_Service::get_milestones() as $milestone ) {
	$any_on = $any_on || ! empty( $milestone['active'] );
}
foreach ( EPC_Loyalty_Reward_Service::get_tiers() as $tier ) {
	$any_on = $any_on || ! empty( $tier['active'] );
}
$welcome = null;
foreach ( EPC_Loyalty_Rule_Service::get_rules() as $rule ) {
	if ( 'welcome-first-order' === $rule['id'] ) {
		$welcome = $rule;
	}
}
epc_sr_check( 'unticked reward and tiers stay off', ! $any_on );
epc_sr_check( 'a ticked welcome bonus is on with its points', $welcome && ! empty( $welcome['active'] ) && 50 === (int) $welcome['points'] );

// ---- Starter names are plain text.
epc_sr_check( 'the starter currency symbol is plain text', false === strpos( EPC_Loyalty_Starter::currency_symbol(), '&' ) );
$encoded = EPC_Loyalty_Starter::rules();
foreach ( $encoded as &$rule ) {
	$rule['name'] = str_replace( '$', '&#36;', $rule['name'] );
}
unset( $rule );
EPC_Loyalty_Rule_Service::save_rules( $encoded );
update_option( EPC_Loyalty_Starter::OPTION_KEY, 3, false );
if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
	EPC_Loyalty_Starter::maybe_install();
	$names = wp_list_pluck( EPC_Loyalty_Rule_Service::get_rules(), 'name' );
	epc_sr_check( 'saved starter names are decoded once', in_array( 'Extra 50 points on orders of $100+', $names, true ) && 4 === (int) get_option( EPC_Loyalty_Starter::OPTION_KEY ), wp_json_encode( $names ) );
} else {
	echo "SKIP saved starter names are decoded once (WooCommerce is not active here)\n";
}

// Clean up.
foreach ( $keep as $option => $value ) {
	if ( null === $value ) {
		delete_option( $option );
	} else {
		update_option( $option, $value );
	}
}
if ( null === $saved_state ) {
	delete_option( EPC_Onboarding::OPTION );
} else {
	update_option( EPC_Onboarding::OPTION, $saved_state );
}
epc_test_fixture_teardown();

echo EPC_Setup_Rules_Test_Counter::$passed, ' passed, ', EPC_Setup_Rules_Test_Counter::$failed, " failed\n";
if ( EPC_Setup_Rules_Test_Counter::$failed > 0 ) {
	exit( 1 );
}

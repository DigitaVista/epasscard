<?php
/**
 * Integration tests for the default design per integration and Design → Applies to (v43).
 *
 * Run: wp eval-file tests/integration/test-default-design.php (tests/integration/run.sh does this).
 * Uses the test integration and fake API from fixtures/test-integration.php and removes what it
 * created. Prints PASS/FAIL lines; exits 1 on any failure.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Default_Design' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-default-design.php (EpassCard active)\n" );
	exit( 1 );
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Default_Design_Test_Counter {
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
function epc_dd_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Default_Design_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Default_Design_Test_Counter::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * Post "Applies to" through the real admin-post handler; the redirect is caught.
 *
 * @param string               $slug   Module slug.
 * @param array<int, string>   $rows   Entity id => this|none.
 * @param bool                 $use    "New items use this design".
 * @return void
 */
function epc_dd_post_applies( $slug, array $rows, $use ) {
	$_POST    = array(
		'action'   => 'epc_design_applies',
		'module'   => $slug,
		'applies'  => $rows,
		'_wpnonce' => wp_create_nonce( 'epc_design_applies_' . $slug ),
	);
	if ( $use ) {
		$_POST['use_default'] = '1';
	}
	$_REQUEST = $_POST;
	$stop     = static function () {
		throw new RuntimeException( 'redirect' );
	};
	add_filter( 'wp_redirect', $stop, 1 );
	try {
		EPC_Admin_Design::handle_applies();
	} catch ( RuntimeException $e ) {
		unset( $e ); // Expected: the handler redirects back to the Design page.
	}
	remove_filter( 'wp_redirect', $stop, 1 );
	$_POST    = array();
	$_REQUEST = array();
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
$module = EPC_Module_Loader::get_module_instance( $slug );
$own    = $module->get_mapping( 1 );

// Three plans; the fixture maps plan 1 only.
add_filter(
	'epc_mappable_entities',
	static function ( $entities, $module_slug ) use ( $slug ) {
		if ( $module_slug !== $slug ) {
			return $entities;
		}
		return array(
			array(
				'id'    => 1,
				'label' => 'Plan one',
			),
			array(
				'id'    => 2,
				'label' => 'Plan two',
			),
			array(
				'id'    => 3,
				'label' => 'Plan three',
			),
		);
	},
	10,
	2
);
// A ready-made design for the test integration, using the fixture's template.
add_filter(
	'epc_starter_template_preset',
	static function ( $preset, $module_slug ) use ( $slug ) {
		return $module_slug === $slug ? EPC_Template_Starter::get_preset( 'paid-memberships-pro' ) : $preset;
	},
	10,
	2
);
$record = array(
	'template_uid'  => (string) $own['template_uid'],
	'template_name' => 'Test design',
	'template_id'   => 0,
	'field_mapping' => (array) $own['field_mapping'],
	'pass_fields'   => array(),
	'design'        => array(),
);
update_option( EPC_Template_Starter::OPTION_PREFIX . $slug, $record, false );

// --- Without a default nothing changes (sites from 1.1).
epc_dd_check( 'no default: plan 2 has no mapping', empty( $module->get_mapping( 2 ) ) );
epc_dd_check( 'no default: plan 1 keeps its own mapping', $own === $module->get_mapping( 1 ) );

// --- With a default.
EPC_Default_Design::set( $slug, $record, array( 3 ) );
$two = $module->get_mapping( 2 );
epc_dd_check( 'default: plan 2 uses the default template', (string) $own['template_uid'] === (string) ( $two['template_uid'] ?? '' ) && ! empty( $two['is_default'] ) );
epc_dd_check( 'default: excluded plan 3 has no mapping', empty( $module->get_mapping( 3 ) ) );
epc_dd_check( 'default: plan 1 keeps its own mapping', $own === $module->get_mapping( 1 ) );
epc_dd_check( 'default: an item id of 0 never gets it', empty( $module->get_mapping( 0 ) ) );

// A card for a member of plan 2 is issued from the default.
$user   = epc_test_fixture_user();
$result = EPC_Pass_Service::sync_pass( $slug, $user, 2, $user, $module->get_mapping( 2 ), array( 'name' => 'Member ' . $user ), 'sync' );
$row    = EPC_DB::get_pass( $slug, $user );
epc_dd_check( 'card issued for plan 2 through the default', true === $result && $row && '' !== (string) $row->pass_uid && (string) $own['template_uid'] === (string) $row->template_uid, is_wp_error( $result ) ? $result->get_error_message() : '' );

// --- Applies to: rows and the handler.
$states = wp_list_pluck( EPC_Admin_Design::rows( $module ), 'state', 'id' );
epc_dd_check( 'rows: plan 1 this, plan 2 default, plan 3 none', array( 1 => 'this', 2 => 'default', 3 => 'none' ) === $states, wp_json_encode( $states ) );
$cards = wp_list_pluck( EPC_Admin_Design::rows( $module ), 'cards', 'id' );
epc_dd_check( 'rows: plan 2 shows its active card', 1 === $cards[2] && 0 === $cards[1], wp_json_encode( $cards ) );

epc_dd_post_applies(
	$slug,
	array(
		1 => 'none',
		2 => 'none',
		3 => 'this',
	),
	true
);
$stored = $module->get_mappings();
$saved  = EPC_Default_Design::get( $slug );
epc_dd_check( 'applies: plan 1 (no cards) set to No card loses its mapping', ! isset( $stored['1'] ) && ! isset( $stored[1] ) );
epc_dd_check( 'applies: plan 2 has a card, so it keeps the design (explicit mapping)', isset( $stored[2] ) && (string) $own['template_uid'] === (string) $stored[2]['template_uid'] );
epc_dd_check( 'applies: plan 3 gets the design', isset( $stored[3] ) && (string) $own['template_uid'] === (string) $stored[3]['template_uid'] );
epc_dd_check( 'applies: default kept, plan 1 excluded', null !== $saved && array( 1 ) === $saved['excluded'], wp_json_encode( $saved['excluded'] ?? null ) );
epc_dd_check( 'applies: excluded plan 1 now has no mapping at all', empty( $module->get_mapping( 1 ) ) );

epc_dd_post_applies( $slug, array( 1 => 'this' ), false );
epc_dd_check( 'applies: unticking "new items" removes the default', null === EPC_Default_Design::get( $slug ) );
epc_dd_check( 'applies: plan 1 back on the design', (string) $own['template_uid'] === (string) ( $module->get_mapping( 1 )['template_uid'] ?? '' ) );
epc_dd_check( 'applies: plans without their own mapping keep nothing once the default is gone', empty( $module->get_mapping( 9 ) ) );

// A value outside the list is ignored.
epc_dd_post_applies( $slug, array( 1 => 'evil' ), false );
epc_dd_check( 'applies: unknown value ignored', (string) $own['template_uid'] === (string) ( $module->get_mapping( 1 )['template_uid'] ?? '' ) );

// --- has_design counts a default.
delete_option( 'epc_mappings_' . $slug );
EPC_Default_Design::set( $slug, $record );
epc_dd_check( 'has_design: true with only a default', true === EPC_Jobs::has_design( $module ) );
EPC_Default_Design::delete( $slug );
epc_dd_check( 'has_design: false with neither', false === EPC_Jobs::has_design( $module ) );

// --- A design update with new field ids keeps the default in step.
EPC_Default_Design::set( $slug, $record, array( 3 ) );
$updated                  = $record;
$updated['field_mapping'] = array(
	'cccccccc-bbbb-4ccc-8ddd-eeeeeeeeeeee' => array(
		'type'   => 'source',
		'source' => 'name',
	),
);
do_action( 'epc_starter_template_updated', $updated, $module );
$after = EPC_Default_Design::get( $slug );
epc_dd_check( 'design update moves the default to the new field ids and keeps exclusions', isset( $after['field_mapping']['cccccccc-bbbb-4ccc-8ddd-eeeeeeeeeeee'] ) && array( 3 ) === $after['excluded'] );

// Clean up.
EPC_Default_Design::delete( $slug );
delete_option( EPC_Template_Starter::OPTION_PREFIX . $slug );
epc_test_fixture_teardown();

echo EPC_Default_Design_Test_Counter::$passed, ' passed, ', EPC_Default_Design_Test_Counter::$failed, " failed\n";
if ( EPC_Default_Design_Test_Counter::$failed > 0 ) {
	exit( 1 );
}

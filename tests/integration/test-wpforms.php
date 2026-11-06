<?php
/**
 * Integration test for v23: WPForms form cards. Needs WPForms (skipped otherwise). Submissions
 * reach the module through WPForms' own wpforms_process_complete hook, with fields shaped as
 * WPForms processes them. Uses the fake API of fixtures/test-integration.php.
 *
 * Run: wp eval-file tests/integration/test-wpforms.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Module_Loader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-wpforms.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! function_exists( 'wpforms' ) || ! function_exists( 'wpforms_encode' ) ) {
	echo "SKIP WPForms cards (WPForms is not active here)\n";
	return;
}

require_once __DIR__ . '/fixtures/test-integration.php';

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_WPF_Test_State {
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
function epc_wpf_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_WPF_Test_State::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_WPF_Test_State::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * A published form with Name, Email, Seats and a Password field.
 *
 * @param string $title Form name.
 * @return int Form id.
 */
function epc_wpf_form( $title ) {
	$id   = wp_insert_post(
		array(
			'post_type'   => 'wpforms',
			'post_status' => 'publish',
			'post_title'  => $title,
		)
	);
	$data = array(
		'id'       => $id,
		'field_id' => 5,
		'fields'   => array(
			1 => array(
				'id'     => 1,
				'type'   => 'name',
				'label'  => 'Name',
				'format' => 'first-last',
			),
			2 => array(
				'id'    => 2,
				'type'  => 'email',
				'label' => 'Email',
			),
			3 => array(
				'id'    => 3,
				'type'  => 'number',
				'label' => 'Seats',
			),
			4 => array(
				'id'    => 4,
				'type'  => 'password',
				'label' => 'Password',
			),
		),
		'settings' => array( 'form_title' => $title ),
	);
	wp_update_post(
		array(
			'ID'           => $id,
			'post_content' => wpforms_encode( $data ),
		)
	);
	return (int) $id;
}

/**
 * Submit a form the way WPForms reports a finished submission.
 *
 * @param int    $form_id Form id.
 * @param string $name    Name.
 * @param string $email   Email ('' for none).
 * @param string $seats   Seats.
 * @return void
 */
function epc_wpf_submit( $form_id, $name, $email, $seats = '2' ) {
	$fields = array(
		1 => array(
			'name'  => 'Name',
			'value' => $name,
			'id'    => 1,
			'type'  => 'name',
		),
		2 => array(
			'name'  => 'Email',
			'value' => $email,
			'id'    => 2,
			'type'  => 'email',
		),
		3 => array(
			'name'  => 'Seats',
			'value' => $seats,
			'id'    => 3,
			'type'  => 'number',
		),
		4 => array(
			'name'  => 'Password',
			'value' => 'secret-should-not-be-kept',
			'id'    => 4,
			'type'  => 'password',
		),
	);
	$form_data = wpforms_decode( get_post( $form_id )->post_content );
	do_action( 'wpforms_process_complete', $fields, array(), $form_data, 0 );
}

/**
 * Newest kept submission of a form.
 *
 * @param int $form_id Form id.
 * @return object|null
 */
function epc_wpf_last( $form_id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test read.
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE form_id = %d ORDER BY id DESC LIMIT 1', EPC_DB::form_submissions_table_name(), $form_id ) );
}

/**
 * Card status of a kept submission, or 'none'.
 *
 * @param int $row_id Row id.
 * @return string
 */
function epc_wpf_card( $row_id ) {
	$pass = EPC_DB::get_pass( 'wpforms', (string) $row_id );
	return $pass && ! empty( $pass->pass_uid ) ? (string) $pass->status : 'none';
}

$saved_enabled = get_option( 'epc_enabled_modules', null );
epc_test_fixture_setup();
update_option( 'epc_enabled_modules', array_values( array_unique( array_merge( (array) get_option( 'epc_enabled_modules', array() ), array( 'wpforms' ) ) ) ) );
$module = EPC_Module_Loader::get_module_instance( 'wpforms' );
$module->init();
$saved_maps    = $module->get_mappings();
$saved_default = get_option( EPC_Default_Design::OPTION_PREFIX . 'wpforms', null );
delete_option( EPC_Default_Design::OPTION_PREFIX . 'wpforms' ); // Only the first form has a design, until the default check below.

$with_design    = epc_wpf_form( 'EPC test: taster evening' );
$without_design = epc_wpf_form( 'EPC test: newsletter' );
$module->save_mapping(
	$with_design,
	array(
		'template_uid'  => '11111111-2222-4333-8444-555555555555',
		'field_mapping' => array(
			'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' => array(
				'type'   => 'source',
				'source' => EPC_Module_WPForms::field_key( $with_design, 3 ),
			),
			'aaaaaaaa-bbbb-4ccc-8ddd-ffffffffffff' => array(
				'type'   => 'source',
				'source' => 'card_code',
			),
		),
		'pass_fields'   => array(),
	)
);

$sources = $module->get_source_fields();
epc_wpf_check( 'each form\'s fields can go on the card, by name', isset( $sources[ EPC_Module_WPForms::field_key( $with_design, 3 ) ] ) && 'EPC test: taster evening: Seats' === $sources[ EPC_Module_WPForms::field_key( $with_design, 3 ) ] );
epc_wpf_check( 'password fields are never offered', ! isset( $sources[ EPC_Module_WPForms::field_key( $with_design, 4 ) ] ) );
epc_wpf_check( 'forms are the items', in_array( $with_design, wp_list_pluck( $module->get_mappable_entities(), 'id' ), true ) );

// A guest submits the form that has a design.
$email = 'epc_t_guest_' . wp_generate_password( 6, false, false ) . '@example.test';
epc_wpf_submit( $with_design, 'Sam Okafor', $email, '3' );
$row = epc_wpf_last( $with_design );
epc_wpf_check( 'submission kept with the address and a card code', $row && $email === (string) $row->email && 10 === strlen( (string) $row->card_code ) && 0 === (int) $row->user_id );
$kept = $row ? json_decode( (string) $row->field_values, true ) : array();
epc_wpf_check( 'only the values the card shows are kept (plus the name)', is_array( $kept ) && array( 'user_full_name', EPC_Module_WPForms::field_key( $with_design, 3 ) ) === array_keys( $kept ) && '3' === $kept[ EPC_Module_WPForms::field_key( $with_design, 3 ) ], wp_json_encode( $kept ) );
epc_wpf_check( 'a password is never kept', false === strpos( (string) $row->field_values, 'secret' ) );
epc_wpf_check( 'card created at submission', 'active' === epc_wpf_card( (int) $row->id ) );
$recipient = $module->get_guest_recipient( (string) $row->id );
epc_wpf_check( 'the card email goes to the form\'s Email field', is_array( $recipient ) && $email === $recipient['email'] && 'Sam Okafor' === $recipient['name'] );
$composed = EPC_Pass_Email::compose( EPC_DB::get_pass( 'wpforms', (string) $row->id ) );
epc_wpf_check( 'the composed card email is addressed to the guest', ! is_wp_error( $composed ) && $email === $composed['to'] );

// The confirmation message links the card.
$message = (string) apply_filters( 'wpforms_frontend_confirmation_message', '<p>Thanks!</p>', array( 'id' => $with_design ), array(), 0 );
epc_wpf_check( 'confirmation message: Add to wallet link', false !== strpos( $message, esc_url( (string) EPC_DB::get_pass( 'wpforms', (string) $row->id )->pass_link ) ) );
$other = (string) apply_filters( 'wpforms_frontend_confirmation_message', '<p>Thanks!</p>', array( 'id' => $without_design ), array(), 0 );
epc_wpf_check( 'confirmation message of another form is unchanged', '<p>Thanks!</p>' === $other );

// A form without a design: nothing kept, no card.
epc_wpf_submit( $without_design, 'Ana Lima', 'epc_t_other@example.test' );
epc_wpf_check( 'form without a design: nothing kept, no card', null === epc_wpf_last( $without_design ) );

// The ready-made design is the default for every form; a form turned off gets none.
update_option(
	EPC_Default_Design::OPTION_PREFIX . 'wpforms',
	array(
		'template_uid'  => '11111111-2222-4333-8444-555555555555',
		'field_mapping' => array(),
		'pass_fields'   => array(),
		'excluded'      => array( $without_design ),
	),
	false
);
epc_wpf_submit( $without_design, 'Ana Lima', 'epc_t_other@example.test' );
epc_wpf_check( 'default design: a form turned off on Design gets no card', null === epc_wpf_last( $without_design ) );
update_option(
	EPC_Default_Design::OPTION_PREFIX . 'wpforms',
	array(
		'template_uid'  => '11111111-2222-4333-8444-555555555555',
		'field_mapping' => array(
			'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' => array(
				'type'   => 'source',
				'source' => 'card_code',
			),
		),
		'pass_fields'   => array(),
		'excluded'      => array(),
	),
	false
);
epc_wpf_submit( $without_design, 'Ana Lima', 'epc_t_other@example.test' );
$by_default = epc_wpf_last( $without_design );
epc_wpf_check( 'default design: covers forms without their own design', $by_default && 'active' === epc_wpf_card( (int) $by_default->id ) );
delete_option( EPC_Default_Design::OPTION_PREFIX . 'wpforms' );

// A logged-in visitor with their own email: the card is linked to their account.
$user_id = epc_test_fixture_user();
wp_set_current_user( $user_id );
epc_wpf_submit( $with_design, 'Kim Park', strtoupper( get_userdata( $user_id )->user_email ) );
$logged = epc_wpf_last( $with_design );
$pass   = EPC_DB::get_pass( 'wpforms', (string) $logged->id );
epc_wpf_check( 'logged-in visitor: the card belongs to their account', $user_id === (int) $logged->user_id && $pass && $user_id === (int) $pass->user_id );
epc_wpf_check( 'logged-in visitor: their cards are listed for them', in_array( (int) $logged->id, (array) ( function () use ( $module, $user_id ) {
	$method = new ReflectionMethod( $module, 'get_pass_action_source_ids_for_user' );
	$method->setAccessible( true );
	return $method->invoke( $module, $user_id );
} )(), true ) );

// Logged in, but the form is for someone else (staff at the front desk): the card is that
// person's, and its email goes to the form's address, not to the logged-in account.
epc_wpf_submit( $with_design, 'Rosa Diaz', 'epc_t_rosa@example.test' );
$for_other = epc_wpf_last( $with_design );
$mail      = EPC_Pass_Email::compose( EPC_DB::get_pass( 'wpforms', (string) $for_other->id ) );
epc_wpf_check( 'filled in for someone else: the card is not the logged-in account\'s', 0 === (int) $for_other->user_id && 'active' === epc_wpf_card( (int) $for_other->id ) );
epc_wpf_check( 'filled in for someone else: the card email goes to the form\'s address', ! is_wp_error( $mail ) && 'epc_t_rosa@example.test' === $mail['to'] && false !== strpos( $mail['body'], 'Rosa' ), is_wp_error( $mail ) ? $mail->get_error_message() : $mail['to'] );
wp_set_current_user( 0 );

// A failed card is retried from the kept values.
putenv( 'EPC_FAKE_FAIL_CREATE=1' );
epc_wpf_submit( $with_design, 'Lee Chen', 'epc_t_lee@example.test', '5' );
putenv( 'EPC_FAKE_FAIL_CREATE' );
$failed = epc_wpf_last( $with_design );
epc_wpf_check( 'failed card: recorded on Cards', 'none' === epc_wpf_card( (int) $failed->id ) && null !== EPC_Pass_Issues::get( 'wpforms', (string) $failed->id ) );
// A retry is a new request: forget this request's failed calls.
( function () {
	self::$failed_in_request = array();
} )->bindTo( null, 'EPC_Pass_Service' )();
$retry = $module->sync_by_source_id( (string) $failed->id, 'sync' );
epc_wpf_check( 'failed card: retry makes it from the kept values', true === $retry && 'active' === epc_wpf_card( (int) $failed->id ) && '5' === $module->build_values( $failed )[ EPC_Module_WPForms::field_key( $with_design, 3 ) ] );

// Tools → Export / Erase Personal Data.
$export = $module->export_personal_data( $email );
$names  = array();
foreach ( $export['data'] as $item ) {
	$names = array_merge( $names, wp_list_pluck( $item['data'], 'value' ) );
}
epc_wpf_check( 'personal data export: the kept values and the card link', 1 === count( $export['data'] ) && in_array( 'Sam Okafor', $names, true ) && in_array( '3', $names, true ) );
$erased = $module->erase_personal_data( $email );
epc_wpf_check( 'personal data erase: values deleted, card voided', true === $erased['items_removed'] && null === $module->get_submission( (int) $row->id ) && 'revoked' === epc_wpf_card( (int) $row->id ) );
epc_wpf_check( 'personal data erase: other people are left alone', null !== $module->get_submission( (int) $failed->id ) && 'active' === epc_wpf_card( (int) $failed->id ) );

// Clean up.
global $wpdb;
$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE form_id IN (%d, %d)', EPC_DB::form_submissions_table_name(), $with_design, $without_design ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
foreach ( array_unique( array_merge( $ids, array( (int) $row->id ) ) ) as $id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup: only this test's cards.
	$wpdb->delete( EPC_DB::table_name(), array( 'module' => 'wpforms', 'source_id' => (string) $id ) );
	EPC_Pass_Issues::clear( 'wpforms', (string) $id );
}
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE form_id IN (%d, %d)', EPC_DB::form_submissions_table_name(), $with_design, $without_design ) );
wp_delete_post( $with_design, true );
wp_delete_post( $without_design, true );
update_option( $module->get_mappings_option_key(), $saved_maps );
if ( null !== $saved_default ) {
	update_option( EPC_Default_Design::OPTION_PREFIX . 'wpforms', $saved_default, false );
}
if ( null === $saved_enabled ) {
	delete_option( 'epc_enabled_modules' );
} else {
	update_option( 'epc_enabled_modules', $saved_enabled );
}
epc_test_fixture_teardown();

echo EPC_WPF_Test_State::$passed, ' passed, ', EPC_WPF_Test_State::$failed, " failed\n";
if ( EPC_WPF_Test_State::$failed > 0 ) {
	exit( 1 );
}

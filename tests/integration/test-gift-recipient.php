<?php
/**
 * Integration test for v15: the card email of a gift card goes to the gift recipient when the
 * store turns that on (Settings → Emails), and to the buyer as before when it is off.
 * Needs WooCommerce with PW Gift Cards and YITH Gift Cards (skipped otherwise).
 *
 * Run: wp eval-file tests/integration/test-gift-recipient.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Pass_Email' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-gift-recipient.php (EpassCard active)\n" );
	exit( 1 );
}
if ( ! class_exists( 'PW_Gift_Card' ) || ! class_exists( 'YITH_YWGC_Gift_Card' ) || ! function_exists( 'wc_create_order' ) ) {
	echo "SKIP gift recipient email (needs WooCommerce, PW Gift Cards and YITH Gift Cards)\n";
	return;
}

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Gift_Recipient_Test_Counter {
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
function epc_gr_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Gift_Recipient_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Gift_Recipient_Test_Counter::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * The card email for a gift card, composed as it would be sent (stand-in row: no card needed).
 *
 * @param string $module    Module slug.
 * @param int    $source_id Card id.
 * @param int    $user_id   User the card belongs to.
 * @return array<string, mixed>|\WP_Error
 */
function epc_gr_email( $module, $source_id, $user_id ) {
	return EPC_Pass_Email::compose(
		(object) array(
			'id'        => 0,
			'module'    => $module,
			'source_id' => (string) $source_id,
			'entity_id' => 0,
			'user_id'   => $user_id,
			'pass_uid'  => '',
			'pass_link' => 'https://pass.example.test/x',
		)
	);
}

/**
 * Turn the setting on or off.
 *
 * @param bool $on On.
 * @return void
 */
function epc_gr_setting( $on ) {
	update_option( EPC_Pass_Email::OPTION, array_merge( EPC_Pass_Email::get_settings(), array( 'gift_recipient' => $on ? 1 : 0 ) ), false );
}

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) $admins[0] );
$saved = get_option( EPC_Pass_Email::OPTION, null );

$suffix = wp_generate_password( 6, false, false );
$buyer  = wp_insert_user(
	array(
		'user_login' => 'epc_t_buyer_' . $suffix,
		'user_pass'  => wp_generate_password(),
		'user_email' => 'epc_t_buyer_' . $suffix . '@example.test',
		'role'       => 'customer',
		'first_name' => 'Bea',
	)
);
$friend = 'friend_' . $suffix . '@example.test';

// YITH: a card for a friend without an account, bought by $buyer.
$order = wc_create_order( array( 'customer_id' => $buyer ) );
$order->save();
$yith                   = new YITH_YWGC_Gift_Card();
$yith->product_id       = 0;
$yith->gift_card_number = strtoupper( wp_generate_password( 12, false ) );
$yith->recipient        = $friend;
$yith->recipient_name   = 'Nora';
$yith->order_id         = $order->get_id();
$yith->save();
update_post_meta( $yith->ID, YITH_YWGC_Gift_Card::META_ORDER_ID, $order->get_id() );

// PW: a card bought by $buyer for $friend (the order line holds the card number and "To").
$pw    = PW_Gift_Card::create_card( 'test' );
$item  = new WC_Order_Item_Product();
$item->set_name( 'Gift card' );
$item->set_quantity( 1 );
$order->add_item( $item );
$order->save();
wc_add_order_item_meta( $item->get_id(), 'pw_gift_card_number', $pw->get_number() );
wc_add_order_item_meta( $item->get_id(), 'pw_gift_card_to', $friend );
$pw = PW_Gift_Card::get_by_id( $pw->get_id() );

$yith_module = EPC_Module_Loader::get_module_instance( 'yith-gift-cards' );
$pw_module   = EPC_Module_Loader::get_module_instance( 'pw-gift-cards' );
$yith_user   = (int) $yith_module->get_backfill_user_id( $yith->ID );
$pw_user     = (int) $pw_module->get_backfill_user_id( $pw->get_id() );

epc_gr_check( 'both cards belong to the buyer (no account for the friend)', $buyer === $yith_user && $buyer === $pw_user, $yith_user . ' / ' . $pw_user );
epc_gr_check( 'the integrations know the recipient', array( 'email' => $friend, 'name' => 'Nora' ) === $yith_module->get_gift_recipient( $yith->ID ) && $friend === $pw_module->get_gift_recipient( $pw->get_id() )['email'] );

// Off (default): the buyer gets the email, as in 1.1.
epc_gr_setting( false );
$mail = epc_gr_email( 'yith-gift-cards', $yith->ID, $yith_user );
epc_gr_check( 'off: YITH card email goes to the buyer', ! is_wp_error( $mail ) && get_userdata( $buyer )->user_email === $mail['to'], is_wp_error( $mail ) ? $mail->get_error_message() : $mail['to'] );
epc_gr_check( 'off: it greets the buyer', ! is_wp_error( $mail ) && 0 === strpos( $mail['body'], 'Hi Bea,' ), is_wp_error( $mail ) ? '' : strtok( $mail['body'], "\n" ) );

// On: the friend gets it.
epc_gr_setting( true );
$mail = epc_gr_email( 'yith-gift-cards', $yith->ID, $yith_user );
epc_gr_check( 'on: YITH card email goes to the recipient', ! is_wp_error( $mail ) && $friend === $mail['to'] );
epc_gr_check( 'on: it greets the recipient by the name on the card', ! is_wp_error( $mail ) && 0 === strpos( $mail['body'], 'Hi Nora,' ), is_wp_error( $mail ) ? '' : strtok( $mail['body'], "\n" ) );
$mail = epc_gr_email( 'pw-gift-cards', $pw->get_id(), $pw_user );
epc_gr_check( 'on: PW card email goes to the recipient', ! is_wp_error( $mail ) && $friend === $mail['to'] );
epc_gr_check( 'on: no name on a PW card leaves a clean greeting', ! is_wp_error( $mail ) && 0 === strpos( $mail['body'], 'Hi,' ), is_wp_error( $mail ) ? '' : strtok( $mail['body'], "\n" ) );
$plain = epc_gr_email( 'paid-memberships-pro', 1, $buyer );
epc_gr_check( 'on: other integrations are unchanged', ! is_wp_error( $plain ) && get_userdata( $buyer )->user_email === $plain['to'] );

// The setting is saved from the Emails form.
$_POST = array(
	'epc_email_subject'        => 'S',
	'epc_email_body'           => 'B {pass_link}',
	'epc_email_gift_recipient' => '1',
);
EPC_Pass_Email::save_settings_from_request();
epc_gr_check( 'the Emails form saves it on', true === EPC_Pass_Email::get_settings()['gift_recipient'] );
unset( $_POST['epc_email_gift_recipient'] );
EPC_Pass_Email::save_settings_from_request();
epc_gr_check( 'and off', false === EPC_Pass_Email::get_settings()['gift_recipient'] );
$_POST = array();

// Clean up.
if ( null === $saved ) {
	delete_option( EPC_Pass_Email::OPTION );
} else {
	update_option( EPC_Pass_Email::OPTION, $saved, false );
}
wp_delete_post( $yith->ID, true );
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
$wpdb->delete( $wpdb->prefix . 'pimwick_gift_card_activity', array( 'pimwick_gift_card_id' => $pw->get_id() ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
$wpdb->delete( $wpdb->prefix . 'pimwick_gift_card', array( 'pimwick_gift_card_id' => $pw->get_id() ) );
$order->delete( true );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $buyer );

echo EPC_Gift_Recipient_Test_Counter::$passed, ' passed, ', EPC_Gift_Recipient_Test_Counter::$failed, " failed\n";
if ( EPC_Gift_Recipient_Test_Counter::$failed > 0 ) {
	exit( 1 );
}

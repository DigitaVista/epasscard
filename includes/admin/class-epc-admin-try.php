<?php
/**
 * Try it: put a test card on the admin's own phone.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Try-it screen (Home → step 4).
 */
class EPC_Admin_Try {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_epc_try_issue', array( __CLASS__, 'handle_issue' ) );
		add_action( 'admin_post_epc_try_email', array( __CLASS__, 'handle_email' ) );
		add_action( 'admin_post_epc_try_result', array( __CLASS__, 'handle_result' ) );
		add_action( 'admin_post_epc_try_delete', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Module the test card is for: the one chosen in Setup.
	 *
	 * @return string
	 */
	public static function module_slug() {
		return EPC_Onboarding::get_state()['module'];
	}

	/**
	 * URL of this screen with a one-time message.
	 *
	 * @param string $message Message key.
	 * @return string
	 */
	private static function url_with( $message ) {
		return EPC_Admin_App::url( 'try', array( 'epc_try' => $message ) );
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'epasscard' ) );
		}

		$slug   = self::module_slug();
		$module = '' !== $slug ? EPC_Module_Loader::get_module_instance( $slug ) : null;
		$ready  = $module instanceof EPC_Module && EPC_Connection::is_connected() && EPC_Module_Settings::is_enabled( $slug ) && EPC_Jobs::has_design( $module );
		$card   = $ready ? EPC_Test_Cards::get( $slug ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- One-time message key after a redirect.
		$notice = isset( $_GET['epc_try'] ) ? sanitize_key( wp_unslash( (string) $_GET['epc_try'] ) ) : '';
		$error  = get_transient( 'epc_try_error_' . get_current_user_id() );
		if ( is_string( $error ) && '' !== $error ) {
			delete_transient( 'epc_try_error_' . get_current_user_id() );
		} else {
			$error = '';
		}

		EPC_Admin_Shell::render_open(
			array(
				'context' => 'app',
				'section' => 'home',
				'title'   => __( 'Try your card', 'epasscard' ),
				'subnav'  => array(),
			)
		);

		include EPC_PLUGIN_DIR . 'admin/views/app/try.php';

		EPC_Admin_Shell::render_close();
	}

	/**
	 * Shared checks for the admin-post handlers.
	 *
	 * @param string $nonce Nonce action.
	 * @return void
	 */
	private static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'epasscard' ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Admin-post: create the test card.
	 *
	 * @return void
	 */
	public static function handle_issue() {
		self::guard( 'epc_try_issue' );

		$slug = self::module_slug();
		if ( '' === $slug || ! EPC_Connection::is_connected() ) {
			wp_safe_redirect( EPC_Admin_App::url( 'home' ) );
			exit;
		}

		$result = EPC_Test_Cards::issue( $slug );
		if ( is_wp_error( $result ) ) {
			set_transient( 'epc_try_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( self::url_with( 'failed' ) );
			exit;
		}

		wp_safe_redirect( self::url_with( 'created' ) );
		exit;
	}

	/**
	 * Admin-post: email the card link to the admin.
	 *
	 * @return void
	 */
	public static function handle_email() {
		self::guard( 'epc_try_email' );

		$card = EPC_Test_Cards::get( self::module_slug() );
		$user = wp_get_current_user();
		if ( ! $card || ! $user->exists() || ! is_email( $user->user_email ) ) {
			wp_safe_redirect( self::url_with( 'email_failed' ) );
			exit;
		}

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name */
		$subject = sprintf( __( 'Your test wallet card from %s', 'epasscard' ), $site );
		$body    = sprintf(
			/* translators: 1: wallet link, 2: site name */
			__( "Open this link on your phone to add your test card to Apple Wallet or Google Wallet:\n\n%1\$s\n\nThis is a test card made from the EpassCard plugin on %2\$s.", 'epasscard' ),
			$card['pass_link'],
			$site
		);
		$sent = wp_mail( (string) $user->user_email, $subject, $body );

		wp_safe_redirect( self::url_with( $sent ? 'emailed' : 'email_failed' ) );
		exit;
	}

	/**
	 * Admin-post: "It worked" or "Something went wrong".
	 *
	 * @return void
	 */
	public static function handle_result() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'epasscard' ) );
		}
		check_admin_referer( 'epc_try_result' );

		$result = isset( $_POST['result'] ) ? sanitize_key( wp_unslash( (string) $_POST['result'] ) ) : '';
		if ( 'worked' === $result ) {
			EPC_Onboarding::update( array( 'try' => 'done' ) );
			wp_safe_redirect( EPC_Admin_App::url( 'home' ) );
			exit;
		}

		wp_safe_redirect( self::url_with( 'help' ) . '#epc-try-help' );
		exit;
	}

	/**
	 * Admin-post: delete the test card (not loyalty: that card is the admin's own account).
	 *
	 * @return void
	 */
	public static function handle_delete() {
		self::guard( 'epc_try_delete' );

		$slug = self::module_slug();
		if ( 'woocommerce-loyalty' !== $slug ) {
			$result = EPC_Test_Cards::delete( $slug );
			if ( is_wp_error( $result ) ) {
				set_transient( 'epc_try_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
				wp_safe_redirect( self::url_with( 'delete_failed' ) );
				exit;
			}
		}

		wp_safe_redirect( self::url_with( 'deleted' ) );
		exit;
	}
}

<?php
/**
 * Admin menu and Connection page.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers top-level EpassCard menu.
 */
class EPC_Admin_Menu {

	/**
	 * Hook admin.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 15 );
		add_action( 'wp_ajax_epc_connect_api_key', array( __CLASS__, 'ajax_connect_api_key' ) );
		add_action( 'wp_ajax_epc_connect_credentials', array( __CLASS__, 'ajax_connect_credentials' ) );
		add_action( 'wp_ajax_epc_sign_up', array( __CLASS__, 'ajax_sign_up' ) );
		add_action( 'wp_ajax_epc_disconnect', array( __CLASS__, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_epc_save_modules', array( __CLASS__, 'ajax_save_modules' ) );
		add_action( 'wp_ajax_epc_get_templates', array( __CLASS__, 'ajax_get_templates' ) );
		add_action( 'wp_ajax_epc_get_pass_fields', array( __CLASS__, 'ajax_get_pass_fields' ) );
	}

	/**
	 * Register menu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'EpassCard', 'epasscard' ),
			__( 'EpassCard', 'epasscard' ),
			'manage_options',
			'epasscard',
			array( __CLASS__, 'render_connection_page' ),
			EPC_PLUGIN_URL . 'admin/images/epass-icon.webp',
			58
		);

		add_submenu_page(
			'epasscard',
			__( 'Connection', 'epasscard' ),
			__( 'Connection', 'epasscard' ),
			'manage_options',
			'epasscard',
			array( __CLASS__, 'render_connection_page' )
		);

		add_submenu_page(
			'epasscard',
			__( 'API Log', 'epasscard' ),
			__( 'API Log', 'epasscard' ),
			'manage_options',
			'epc-api-log',
			array( __CLASS__, 'render_api_log_page' )
		);
	}

	/**
	 * Whether the current screen is the Connection settings page.
	 *
	 * @return bool
	 */
	public static function is_connection_screen() {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection; capability enforced by menu callbacks.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';

		return 'epasscard' === $page;
	}

	/**
	 * Enqueue connection page assets.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		wp_register_style( 'epc-admin-menu-icon', false, array(), EPC_VERSION );
		wp_enqueue_style( 'epc-admin-menu-icon' );
		wp_add_inline_style(
			'epc-admin-menu-icon',
			'#adminmenu #toplevel_page_epasscard .wp-menu-image img{padding:6px 0 0;width:20px;height:20px;opacity:1;}'
		);

		if ( ! EPC_Admin_Shell::is_epc_screen( $hook ) ) {
			return;
		}

		$style_path = EPC_PLUGIN_DIR . 'admin/css/admin.css';
		wp_enqueue_style(
			'epc-admin',
			EPC_PLUGIN_URL . 'admin/css/admin.css',
			array( 'epc-admin-shell' ),
			file_exists( $style_path ) ? (string) filemtime( $style_path ) : EPC_VERSION
		);

		if ( ! self::is_connection_screen() ) {
			return;
		}

		$script_path = EPC_PLUGIN_DIR . 'admin/js/connection.js';
		wp_enqueue_script(
			'epc-connection',
			EPC_PLUGIN_URL . 'admin/js/connection.js',
			array( 'jquery' ),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : EPC_VERSION,
			true
		);

		wp_localize_script(
			'epc-connection',
			'epcConnection',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'epc_connection' ),
				'i18n'    => array(
					'connecting'    => __( 'Connecting…', 'epasscard' ),
					'signingUp'     => __( 'Creating account…', 'epasscard' ),
					'connected'     => __( 'Connected successfully.', 'epasscard' ),
					'disconnected'  => __( 'Disconnected.', 'epasscard' ),
					'error'         => __( 'Connection failed. Please check your details.', 'epasscard' ),
					'nameRequired'  => __( 'Please enter a name or business name.', 'epasscard' ),
					'nameTooShort'  => __( 'Name must be at least 2 characters.', 'epasscard' ),
					'nameTooLong'   => __( 'Name must be 255 characters or fewer.', 'epasscard' ),
					'emailRequired' => __( 'Please enter an email address.', 'epasscard' ),
					'emailInvalid'  => __( 'Please enter a valid email address.', 'epasscard' ),
					'savingModules'=> __( 'Saving integrations…', 'epasscard' ),
					'modulesSaved' => __( 'Integration settings saved. Reloading…', 'epasscard' ),
					'copied'       => __( 'Copied to clipboard.', 'epasscard' ),
					'copyFailed'   => __( 'Could not copy. Select the code and copy manually.', 'epasscard' ),
				),
			)
		);
	}

	/**
	 * Render Connection settings page.
	 *
	 * @return void
	 */
	public static function render_connection_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'epasscard' ) );
		}

		$connected = EPC_Connection::is_connected();
		$expiry    = EPC_Connection::get_expiry_display();
		$settings  = EPC_Connection::get_settings();
		$email     = isset( $settings['connected_email'] ) ? (string) $settings['connected_email'] : '';
		$package   = EPC_Connection::get_package_details();
		$modules   = EPC_Module_Loader::get_registry();
		$enabled   = EPC_Module_Settings::get_enabled_slugs();
		$email_settings = EPC_Pass_Email::get_settings();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice query arg for admin UI flash message.
		$notice         = isset( $_GET['epc_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['epc_notice'] ) ) : '';

		include EPC_PLUGIN_DIR . 'admin/views/connection.php';
	}

	/**
	 * Render API request log page.
	 *
	 * @return void
	 */
	public static function render_api_log_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'epasscard' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters; capability checked above.
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$success = isset( $_GET['success'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['success'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$per_page = 25;

		$result = EPC_Api_Log::query(
			array(
				'page'        => $page,
				'per_page'    => $per_page,
				'search'      => $search,
				'is_success'  => $success,
			)
		);

		$total_pages = max( 1, (int) ceil( $result['total'] / $per_page ) );
		$retention   = EPC_Api_Log::get_retention_days();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only flash/query args after capability check.
		$notice      = isset( $_GET['epc_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['epc_notice'] ) ) : '';
		$deleted     = isset( $_GET['deleted'] ) ? absint( wp_unslash( $_GET['deleted'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		include EPC_PLUGIN_DIR . 'admin/views/api-log.php';
	}

	/**
	 * AJAX: connect with API key.
	 *
	 * @return void
	 */
	public static function ajax_connect_api_key() {
		check_ajax_referer( 'epc_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['api_key'] ) ) : '';
		$valid   = EPC_Api_Client::validate_api_key( $api_key );

		if ( is_wp_error( $valid ) ) {
			wp_send_json_error( array( 'message' => $valid->get_error_message() ), 400 );
		}

		$email = EPC_Api_Client::extract_account_email( $valid );
		$saved = EPC_Connection::save_api_key( $api_key, $valid, $email );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Connected successfully.', 'epasscard' ),
				'expiry'  => EPC_Connection::get_expiry_display(),
			)
		);
	}

	/**
	 * AJAX: connect with email/password to generate API key.
	 *
	 * @return void
	 */
	public static function ajax_connect_credentials() {
		check_ajax_referer( 'epc_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '';
		// Passwords must not be altered by sanitize_text_field(); still unslash and cast safely.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Credential sent over HTTPS to remote API; sanitizing would corrupt passwords.
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

		$generated = EPC_Api_Client::generate_api_key( $email, $password );
		if ( is_wp_error( $generated ) ) {
			wp_send_json_error( array( 'message' => $generated->get_error_message() ), 400 );
		}

		$valid = EPC_Api_Client::validate_api_key( $generated['api_key'] );
		if ( is_wp_error( $valid ) ) {
			wp_send_json_error( array( 'message' => $valid->get_error_message() ), 400 );
		}

		$validated = array_merge( $valid, is_array( $generated['data'] ) ? $generated['data'] : array() );
		$package   = ! empty( $generated['package_details'] ) && is_array( $generated['package_details'] )
			? $generated['package_details']
			: EPC_Api_Client::extract_package_details( $validated );
		if ( ! empty( $package ) ) {
			$validated['package_details'] = $package;
		}

		$save_email = ! empty( $generated['email'] ) ? (string) $generated['email'] : $email;
		$saved      = EPC_Connection::save_api_key( $generated['api_key'], $validated, $save_email );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Connected successfully.', 'epasscard' ),
				'expiry'  => EPC_Connection::get_expiry_display(),
			)
		);
	}

	/**
	 * AJAX: create an EpassCard account and connect with the returned API key.
	 *
	 * @return void
	 */
	public static function ajax_sign_up() {
		check_ajax_referer( 'epc_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '';

		$signed_up = EPC_Api_Client::sign_up( $name, $email );
		if ( is_wp_error( $signed_up ) ) {
			wp_send_json_error( array( 'message' => $signed_up->get_error_message() ), 400 );
		}

		$valid     = EPC_Api_Client::validate_api_key( $signed_up['api_key'] );
		$validated = is_wp_error( $valid ) ? array() : $valid;
		$validated = array_merge( $validated, is_array( $signed_up['data'] ) ? $signed_up['data'] : array() );
		$package   = ! empty( $signed_up['package_details'] ) && is_array( $signed_up['package_details'] )
			? $signed_up['package_details']
			: EPC_Api_Client::extract_package_details( $validated );
		if ( ! empty( $package ) ) {
			$validated['package_details'] = $package;
		}
		$saved     = EPC_Connection::save_api_key( $signed_up['api_key'], $validated, $signed_up['email'] );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ), 500 );
		}

		$message = ! empty( $signed_up['message'] )
			? $signed_up['message']
			: __( 'Account created and connected successfully.', 'epasscard' );

		wp_send_json_success(
			array(
				'message' => $message,
				'expiry'  => EPC_Connection::get_expiry_display(),
			)
		);
	}

	/**
	 * AJAX: disconnect.
	 *
	 * @return void
	 */
	public static function ajax_disconnect() {
		check_ajax_referer( 'epc_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		EPC_Connection::disconnect();
		wp_send_json_success( array( 'message' => __( 'Disconnected.', 'epasscard' ) ) );
	}

	/**
	 * AJAX: save enabled integration modules.
	 *
	 * @return void
	 */
	public static function ajax_save_modules() {
		check_ajax_referer( 'epc_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$requested = array();

		if ( isset( $_POST['modules_json'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded then each slug sanitized with sanitize_key().
			$decoded = json_decode( wp_unslash( (string) $_POST['modules_json'] ), true );
			if ( is_array( $decoded ) ) {
				$requested = array_map( 'sanitize_key', $decoded );
			}
		} elseif ( isset( $_POST['modules'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_key after array/string normalize.
			$raw = wp_unslash( $_POST['modules'] );
			if ( is_array( $raw ) ) {
				$requested = array_map( 'sanitize_key', $raw );
			} elseif ( is_string( $raw ) && '' !== $raw ) {
				$requested = array( sanitize_key( $raw ) );
			}
		}

		$saved = EPC_Module_Settings::save_enabled_slugs( $requested );

		wp_send_json_success(
			array(
				'message' => __( 'Integration settings saved.', 'epasscard' ),
				'enabled' => $saved,
			)
		);
	}

	/**
	 * AJAX: template list (shared by modules).
	 *
	 * @return void
	 */
	public static function ajax_get_templates() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		if ( ! EPC_Api_Client::is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'EpassCard is not connected.', 'epasscard' ) ), 400 );
		}

		$page   = isset( $_GET['page_num'] ) ? max( 1, absint( wp_unslash( $_GET['page_num'] ) ) ) : 1;
		$result = EPC_Api_Client::get_templates( $page );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: pass fields for template (shared by modules).
	 *
	 * @return void
	 */
	public static function ajax_get_pass_fields() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$template_uid = isset( $_GET['template_uid'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['template_uid'] ) ) : '';
		$result       = EPC_Api_Client::get_pass_fields( $template_uid );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success( $result );
	}
}

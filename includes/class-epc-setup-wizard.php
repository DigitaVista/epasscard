<?php
/**
 * First-run setup wizard.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guides a new merchant from install to a first wallet pass.
 */
class EPC_Setup_Wizard {

	public const OPTION_KEY = 'epc_setup_wizard';

	public const PAGE_SLUG = 'epc-setup-wizard';

	public const REDIRECT_TRANSIENT = 'epc_setup_wizard_redirect';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_bootstrap' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_restart' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 12 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
		add_filter( 'admin_body_class', array( __CLASS__, 'admin_body_class' ) );
		add_action( 'in_admin_header', array( __CLASS__, 'suppress_admin_notices' ), 1000 );
		add_action( 'admin_init', array( __CLASS__, 'guard_wizard_screen' ), 0 );
		add_action( 'admin_notices', array( __CLASS__, 'render_resume_notice' ) );
		add_action( 'wp_ajax_epc_wizard_mark_welcome', array( __CLASS__, 'ajax_mark_welcome' ) );
		add_action( 'wp_ajax_epc_wizard_save_goal', array( __CLASS__, 'ajax_save_goal' ) );
		add_action( 'wp_ajax_epc_wizard_setup', array( __CLASS__, 'ajax_setup' ) );
		add_action( 'wp_ajax_epc_wizard_issue', array( __CLASS__, 'ajax_issue' ) );
		add_action( 'wp_ajax_epc_wizard_complete', array( __CLASS__, 'ajax_complete' ) );
		add_action( 'wp_ajax_epc_wizard_skip', array( __CLASS__, 'ajax_skip' ) );
		add_action( 'wp_ajax_epc_wizard_view', array( __CLASS__, 'ajax_view' ) );
		add_action( 'wp_ajax_epc_wizard_back', array( __CLASS__, 'ajax_back' ) );
		add_action( 'wp_ajax_epc_wizard_continue_connected', array( __CLASS__, 'ajax_continue_connected' ) );
		add_action( 'wp_ajax_epc_wizard_reset', array( __CLASS__, 'ajax_reset' ) );
		add_action( 'wp_ajax_epc_wizard_install_plugin', array( __CLASS__, 'ajax_install_plugin' ) );
	}

	/**
	 * Persist a one-time redirect after activation.
	 *
	 * @return void
	 */
	public static function flag_activation_redirect() {
		if ( ! self::should_run() ) {
			return;
		}

		set_transient( self::REDIRECT_TRANSIENT, 1, MINUTE_IN_SECONDS );
	}

	/**
	 * Seed wizard state for new vs existing installs.
	 *
	 * @return void
	 */
	public static function maybe_bootstrap() {
		if ( get_option( self::OPTION_KEY, null ) !== null ) {
			return;
		}

		if ( class_exists( 'EPC_Connection' ) && EPC_Connection::is_connected() ) {
			update_option( self::OPTION_KEY, self::completed_state(), false );
			return;
		}

		update_option( self::OPTION_KEY, self::default_state(), false );
	}

	/**
	 * Redirect to the wizard once after activation.
	 *
	 * @return void
	 */
	public static function maybe_redirect() {
		if ( ! get_transient( self::REDIRECT_TRANSIENT ) ) {
			return;
		}

		delete_transient( self::REDIRECT_TRANSIENT );

		if ( ! current_user_can( 'manage_options' ) || wp_doing_ajax() || is_network_admin() ) {
			return;
		}

		if ( ! self::should_run() ) {
			return;
		}

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Restart the wizard from a Settings link.
	 *
	 * @return void
	 */
	public static function maybe_restart() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Restart is verified below.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page || empty( $_GET['epc_restart'] ) ) {
			return;
		}

		check_admin_referer( 'epc_wizard_restart' );
		self::reset_fresh();
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Register the wizard page without a visible submenu item.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			null,
			__( 'Setup Wizard', 'epasscard' ),
			__( 'Setup Wizard', 'epasscard' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue wizard assets.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! self::is_wizard_screen( $hook ) ) {
			return;
		}

		wp_enqueue_media();

		$style_path = EPC_PLUGIN_DIR . 'admin/css/setup-wizard.css';
		wp_enqueue_style(
			'epc-setup-wizard',
			EPC_PLUGIN_URL . 'admin/css/setup-wizard.css',
			array( 'epc-admin-shell' ),
			file_exists( $style_path ) ? (string) filemtime( $style_path ) : EPC_VERSION
		);

		$script_path = EPC_PLUGIN_DIR . 'admin/js/setup-wizard.js';
		wp_enqueue_script(
			'epc-setup-wizard',
			EPC_PLUGIN_URL . 'admin/js/setup-wizard.js',
			array( 'jquery' ),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : EPC_VERSION,
			true
		);

		$state = self::get_state();
		$step  = self::resolve_step( $state );

		wp_localize_script(
			'epc-setup-wizard',
			'epcWizard',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'epc_wizard' ),
				'connectNonce'=> wp_create_nonce( 'epc_connection' ),
				'adminNonce'  => wp_create_nonce( 'epc_admin' ),
				'step'        => $step,
				'connected'   => EPC_Connection::is_connected(),
				'builderUrl'  => 'https://app.epasscard.com/pass-templates',
				'exitUrl'     => admin_url( 'admin.php?page=epasscard' ),
				'canInstall'  => current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' ),
				'i18n'        => array(
					'saving'      => __( 'Saving…', 'epasscard' ),
					'connecting'  => __( 'Connecting…', 'epasscard' ),
					'signingUp'   => __( 'Creating account…', 'epasscard' ),
					'issuing'     => __( 'Creating your pass…', 'epasscard' ),
					'error'       => __( 'Something went wrong. Please try again.', 'epasscard' ),
					'selectGoal'  => __( 'Choose what you want to issue first.', 'epasscard' ),
					'selectModule'=> __( 'Choose an installed plugin to connect.', 'epasscard' ),
					'nameRequired'=> __( 'Please enter a name or business name.', 'epasscard' ),
					'emailRequired'=> __( 'Please enter an email address.', 'epasscard' ),
					'emailInvalid'=> __( 'Please enter a valid email address.', 'epasscard' ),
					'selectTemplate'=> __( 'Select a pass template first.', 'epasscard' ),
					'selectEntity'=> __( 'Select an item to map first.', 'epasscard' ),
					'logoNeeded'  => __( 'Add a logo URL, or we will use your site icon.', 'epasscard' ),
					'localhostLogo'=> __( 'Localhost images cannot be used. Continuing with the default EpassCard logo.', 'epasscard' ),
					'resetConfirm'=> __( 'Reset setup and start over? This disconnects EpassCard, clears wizard progress, and removes saved pass mappings. Customer points and issued passes are kept.', 'epasscard' ),
					'resetting'   => __( 'Resetting setup…', 'epasscard' ),
					'goingBack'   => __( 'Going back…', 'epasscard' ),
					'installing'  => __( 'Installing…', 'epasscard' ),
					'activating'  => __( 'Activating…', 'epasscard' ),
					'installed'   => __( 'Plugin ready. Refreshing…', 'epasscard' ),
					'installDenied'=> __( 'You do not have permission to install plugins.', 'epasscard' ),
				),
			)
		);
	}

	/**
	 * Fullscreen body class on the wizard screen.
	 *
	 * @param string $classes Existing body classes.
	 * @return string
	 */
	public static function admin_body_class( $classes ) {
		if ( ! self::is_wizard_screen() ) {
			return $classes;
		}

		return $classes . ' epc-wizard-fullscreen';
	}

	/**
	 * Strip third-party admin notices so the immersive wizard stays clean.
	 *
	 * @return void
	 */
	public static function suppress_admin_notices() {
		if ( ! self::is_wizard_screen() ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
	}

	/**
	 * Keep merchants on the wizard when other plugins try to redirect (e.g. PMP setup).
	 *
	 * @return void
	 */
	public static function guard_wizard_screen() {
		if ( ! self::is_wizard_screen() ) {
			return;
		}

		add_filter( 'wp_redirect', array( __CLASS__, 'block_non_wizard_redirect' ), 100000 );
		add_filter( 'wp_safe_redirect', array( __CLASS__, 'block_non_wizard_redirect' ), 100000 );
	}

	/**
	 * Cancel redirects that would leave the setup wizard.
	 *
	 * @param string|false $location Redirect URL.
	 * @return string|false
	 */
	public static function block_non_wizard_redirect( $location ) {
		if ( ! is_string( $location ) || '' === $location ) {
			return $location;
		}

		if ( false !== strpos( $location, self::PAGE_SLUG ) ) {
			return $location;
		}

		return false;
	}

	/**
	 * Render the wizard page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'epasscard' ) );
		}

		extract( self::get_view_vars() ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- View locals for the wizard template.

		EPC_Admin_Shell::render_open(
			array(
				'context'        => 'setup-wizard',
				'title'          => __( 'Setup Wizard', 'epasscard' ),
				'subtitle'       => __( 'We will walk you through each step until you can add a pass to Apple Wallet or Google Wallet.', 'epasscard' ),
				'active_section' => 'setup-wizard',
			)
		);

		include EPC_PLUGIN_DIR . 'admin/views/setup-wizard.php';

		EPC_Admin_Shell::render_close();
	}

	/**
	 * Dashboard / plugins resume notice.
	 *
	 * @return void
	 */
	public static function render_resume_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! self::should_run() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		if ( self::PAGE_SLUG === $screen->id || false !== strpos( (string) $screen->id, self::PAGE_SLUG ) ) {
			return;
		}

		$allowed = array( 'dashboard', 'plugins', 'toplevel_page_epasscard' );
		if ( ! in_array( $screen->base, $allowed, true ) && false === strpos( $screen->id, 'epasscard' ) && false === strpos( $screen->id, 'epc-' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'EpassCard setup is not finished.', 'epasscard' ),
			esc_html__( 'A short wizard will connect your account, enable an integration, and create a test wallet pass.', 'epasscard' ),
			esc_url( self::url() ),
			esc_html__( 'Continue setup', 'epasscard' )
		);
	}

	/**
	 * AJAX: welcome acknowledged.
	 *
	 * @return void
	 */
	public static function ajax_mark_welcome() {
		self::require_ajax();
		$state                   = self::get_state();
		$state['welcome_done']   = true;
		$state['review_connect'] = false;
		$state['status']         = 'in_progress';
		self::save_state( $state );
		self::send_step_success();
	}

	/**
	 * AJAX: current wizard markup for the resolved step.
	 *
	 * @return void
	 */
	public static function ajax_view() {
		self::require_ajax();
		self::send_step_success();
	}

	/**
	 * AJAX: save use-case and enable the matching module.
	 *
	 * @return void
	 */
	public static function ajax_save_goal() {
		self::require_ajax();

		$goal   = isset( $_POST['goal'] ) ? sanitize_key( wp_unslash( (string) $_POST['goal'] ) ) : '';
		$slug   = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( (string) $_POST['module'] ) ) : '';
		$goals  = self::get_goals();

		if ( ! isset( $goals[ $goal ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose what you want to issue first.', 'epasscard' ) ), 400 );
		}

		$available = self::available_modules_for_goal( $goal );
		if ( '' === $slug ) {
			$first = reset( $available );
			$slug  = $first ? (string) $first['slug'] : '';
		}

		if ( '' === $slug || ! isset( $available[ $slug ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Install and activate the required plugin, then return to this step.', 'epasscard' ) ), 400 );
		}

		$enabled = EPC_Module_Settings::get_saved_slugs();
		$enabled[] = $slug;
		EPC_Module_Settings::save_enabled_slugs( $enabled );

		$state             = self::get_state();
		$state['goal']     = $goal;
		$state['module']   = $slug;
		$state['status']   = 'in_progress';
		$state['welcome_done'] = true;
		$state['review_connect'] = false;
		$state['edit_setup'] = false;
		self::save_state( $state );

		self::send_step_success( __( 'Integration enabled.', 'epasscard' ) );
	}

	/**
	 * AJAX: save mapping or loyalty starter setup.
	 *
	 * @return void
	 */
	public static function ajax_setup() {
		self::require_ajax();

		if ( ! EPC_Connection::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Connect EpassCard before this step.', 'epasscard' ) ), 400 );
		}

		$state  = self::get_state();
		$slug   = (string) ( $state['module'] ?? '' );
		$result = 'woocommerce-loyalty' === $slug
			? self::setup_loyalty()
			: self::setup_mapping( $slug );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$state['setup_done'] = true;
		$state['edit_setup'] = false;
		self::save_state( $state );

		self::send_step_success( __( 'Setup saved. Next we will create a test pass.', 'epasscard' ) );
	}

	/**
	 * AJAX: move one wizard step backward.
	 *
	 * @return void
	 */
	public static function ajax_back() {
		self::require_ajax();

		$state = self::get_state();
		$step  = self::resolve_step( $state );

		switch ( $step ) {
			case 'done':
			case 'result':
				$state['status']     = 'in_progress';
				$state['edit_setup'] = true;
				$state['pass_link']  = '';
				break;
			case 'setup':
				$state['module']     = '';
				$state['goal']       = '';
				$state['setup_done'] = false;
				$state['edit_setup'] = false;
				break;
			case 'choose':
				$state['review_connect'] = true;
				$state['module']         = '';
				$state['goal']           = '';
				break;
			case 'connect':
				$state['welcome_done']   = false;
				$state['review_connect'] = false;
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'You are already at the first step.', 'epasscard' ) ), 400 );
		}

		self::save_state( $state );
		self::send_step_success();
	}

	/**
	 * AJAX: leave the Account step while already connected.
	 *
	 * @return void
	 */
	public static function ajax_continue_connected() {
		self::require_ajax();

		if ( ! EPC_Connection::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Connect EpassCard before continuing.', 'epasscard' ) ), 400 );
		}

		$state                   = self::get_state();
		$state['welcome_done']   = true;
		$state['review_connect'] = false;
		$state['status']         = 'in_progress';
		self::save_state( $state );

		self::send_step_success();
	}

	/**
	 * AJAX: issue the first pass so the merchant can see a result.
	 *
	 * @return void
	 */
	public static function ajax_issue() {
		self::require_ajax();

		$state  = self::get_state();
		$result = self::issue_first_pass( $state );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$state['pass_link'] = (string) ( $result['pass_link'] ?? '' );
		$state['status']    = 'in_progress';
		self::save_state( $state );

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: mark wizard complete.
	 *
	 * @return void
	 */
	public static function ajax_complete() {
		self::require_ajax();
		$state           = self::get_state();
		$state['status'] = 'completed';
		self::save_state( $state );
		self::send_step_success( __( 'Setup complete. You are ready to go.', 'epasscard' ) );
	}

	/**
	 * AJAX: skip for now.
	 *
	 * @return void
	 */
	public static function ajax_skip() {
		self::require_ajax();
		$state           = self::get_state();
		$state['status'] = 'skipped';
		self::save_state( $state );
		wp_send_json_success( array( 'dashboard' => admin_url( 'admin.php?page=epasscard' ) ) );
	}

	/**
	 * AJAX: clear wizard setup and return to Welcome.
	 *
	 * @return void
	 */
	public static function ajax_reset() {
		self::require_ajax();
		self::reset_fresh();
		self::send_step_success( __( 'Setup was reset. You can start again.', 'epasscard' ) );
	}

	/**
	 * Wizard admin URL.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		$args['page'] = self::PAGE_SLUG;
		return admin_url( 'admin.php?' . http_build_query( $args ) );
	}

	/**
	 * Whether setup still needs the wizard.
	 *
	 * @return bool
	 */
	public static function should_run() {
		$state  = self::get_state();
		$status = (string) ( $state['status'] ?? 'pending' );
		return in_array( $status, array( 'pending', 'in_progress' ), true );
	}

	/**
	 * Current stored state.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_state() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, self::default_state() );
	}

	/**
	 * Template locals for the wizard view.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_view_vars() {
		$state       = self::get_state();
		$step        = self::resolve_step( $state );
		$goals       = self::get_goals();
		$pass        = self::get_result_pass( $state );
		$design      = class_exists( 'EPC_Loyalty_Pass_Design_Service' ) ? EPC_Loyalty_Pass_Design_Service::get_design() : array();
		$restart_url = wp_nonce_url( self::url( array( 'epc_restart' => 1 ) ), 'epc_wizard_restart' );
		$entities    = array();
		$module_obj  = null;
		$module_slug = sanitize_key( (string) ( $state['module'] ?? '' ) );
		if ( '' !== $module_slug ) {
			$registry = EPC_Module_Loader::get_registry();
			if ( isset( $registry[ $module_slug ] ) ) {
				$module_obj = $registry[ $module_slug ];
				$entities   = $module_obj->get_mappable_entities();
			}
		}

		$steps = array(
			'welcome' => __( 'Welcome', 'epasscard' ),
			'connect' => __( 'Account', 'epasscard' ),
			'choose'  => __( 'Use case', 'epasscard' ),
			'setup'   => __( 'Setup', 'epasscard' ),
			'result'  => __( 'Your pass', 'epasscard' ),
			'done'    => __( 'Done', 'epasscard' ),
		);

		$order      = array_keys( $steps );
		$step_index = array_search( $step, $order, true );
		if ( false === $step_index ) {
			$step_index = 0;
		}

		$pass_link  = ( $pass && ! empty( $pass->pass_link ) ) ? (string) $pass->pass_link : '';
		$blog       = (string) get_bloginfo( 'name' );
		$is_loyalty = 'woocommerce-loyalty' === $module_slug;
		$goal       = sanitize_key( (string) ( $state['goal'] ?? '' ) );
		if ( $is_loyalty ) {
			$goal = 'loyalty';
		}
		$starter    = self::starter_copy_for_goal( $goal, $blog );
		$builder    = self::builder_guidance_for_goal( $goal );
		$can_go_back = ! in_array( $step, array( 'welcome' ), true );
		$is_connected = EPC_Connection::is_connected();

		return compact(
			'state',
			'step',
			'goals',
			'pass',
			'design',
			'restart_url',
			'entities',
			'module_obj',
			'module_slug',
			'steps',
			'order',
			'step_index',
			'pass_link',
			'blog',
			'is_loyalty',
			'goal',
			'starter',
			'builder',
			'can_go_back',
			'is_connected'
		);
	}

	/**
	 * JSON payload that swaps wizard markup without a page reload.
	 *
	 * @param string $message Optional status message.
	 * @return void
	 */
	private static function send_step_success( $message = '' ) {
		$vars = self::get_view_vars();
		ob_start();
		extract( $vars ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Same locals as the page view.
		include EPC_PLUGIN_DIR . 'admin/views/setup-wizard-inner.php';
		$html = (string) ob_get_clean();

		wp_send_json_success(
			array(
				'message'   => $message,
				'step'      => (string) $vars['step'],
				'html'      => $html,
				'connected' => EPC_Connection::is_connected(),
			)
		);
	}

	/**
	 * Goal definitions and matching modules.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_goals() {
		return array(
			'loyalty'    => array(
				'label'       => __( 'Loyalty cards', 'epasscard' ),
				'description' => __( 'Customers earn points in WooCommerce and keep a loyalty card in their phone wallet.', 'epasscard' ),
				'icon'        => 'loyalty',
				'modules'     => array( 'woocommerce-loyalty' ),
			),
			'membership' => array(
				'label'       => __( 'Membership or subscription cards', 'epasscard' ),
				'description' => __( 'Issue a digital membership card when someone joins or renews.', 'epasscard' ),
				'icon'        => 'badge',
				'modules'     => array( 'memberpress', 'paid-memberships-pro', 'simple-membership', 'ultimate-membership-pro', 'woocommerce-subscriptions' ),
			),
			'events'     => array(
				'label'       => __( 'Event tickets', 'epasscard' ),
				'description' => __( 'Send attendees a wallet ticket when they register or book.', 'epasscard' ),
				'icon'        => 'confirmation_number',
				'modules'     => array( 'the-events-calendar', 'events-manager' ),
			),
			'gift-cards' => array(
				'label'       => __( 'Gift cards', 'epasscard' ),
				'description' => __( 'Turn WooCommerce gift cards into Apple Wallet and Google Wallet passes.', 'epasscard' ),
				'icon'        => 'card_giftcard',
				'modules'     => array( 'pw-gift-cards', 'yith-gift-cards' ),
			),
		);
	}

	/**
	 * Available modules for a goal.
	 *
	 * @param string $goal Goal slug.
	 * @return array<string, array{slug: string, label: string, dependency: string}>
	 */
	public static function available_modules_for_goal( $goal ) {
		$goals = self::get_goals();
		$out   = array();
		if ( ! isset( $goals[ $goal ] ) ) {
			return $out;
		}

		$registry = EPC_Module_Loader::get_registry();
		foreach ( $goals[ $goal ]['modules'] as $slug ) {
			if ( ! isset( $registry[ $slug ] ) || ! $registry[ $slug ]->is_available() ) {
				continue;
			}
			$out[ $slug ] = array(
				'slug'       => $slug,
				'label'      => $registry[ $slug ]->get_label(),
				'dependency' => $registry[ $slug ]->get_dependency_label(),
			);
		}

		return $out;
	}

	/**
	 * Plugin names a goal can work with, whether or not they are installed.
	 *
	 * @param string $goal Goal slug.
	 * @return array<int, string>
	 */
	public static function required_plugins_for_goal( $goal ) {
		$actions = self::required_plugin_actions_for_goal( $goal );
		$out     = array();
		foreach ( $actions as $action ) {
			$label = (string) ( $action['label'] ?? '' );
			if ( '' !== $label && ! in_array( $label, $out, true ) ) {
				$out[] = $label;
			}
		}

		return $out;
	}

	/**
	 * Actionable dependency rows for a goal (install / activate / get plugin).
	 *
	 * @param string $goal Goal slug.
	 * @return array<int, array<string, mixed>>
	 */
	public static function required_plugin_actions_for_goal( $goal ) {
		$goals = self::get_goals();
		$out   = array();
		if ( ! isset( $goals[ $goal ] ) ) {
			return $out;
		}

		$registry = EPC_Module_Loader::get_registry();
		$can_install = current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' );

		foreach ( $goals[ $goal ]['modules'] as $module_slug ) {
			if ( ! isset( $registry[ $module_slug ] ) ) {
				continue;
			}

			$packages = $registry[ $module_slug ]->get_dependency_install_info();
			if ( ! is_array( $packages ) ) {
				continue;
			}

			foreach ( $packages as $package ) {
				if ( ! is_array( $package ) ) {
					continue;
				}

				$key = sanitize_key( (string) ( $package['key'] ?? '' ) );
				if ( '' === $key ) {
					continue;
				}

				if ( isset( $out[ $key ] ) ) {
					continue;
				}

				$mode        = 'external' === ( $package['mode'] ?? '' ) ? 'external' : 'wporg';
				$slug        = sanitize_title( (string) ( $package['slug'] ?? '' ) );
				$plugin_file = ltrim( str_replace( '\\', '/', (string) ( $package['plugin_file'] ?? '' ) ), '/' );
				$url         = esc_url_raw( (string) ( $package['url'] ?? '' ) );
				$label       = (string) ( $package['label'] ?? $key );
				$status      = 'external' === $mode ? 'missing' : self::resolve_plugin_status( $plugin_file );

				if ( 'external' === $mode && $registry[ $module_slug ]->is_available() ) {
					$status = 'active';
				}

				$out[ $key ] = array(
					'key'         => $key,
					'label'       => $label,
					'mode'        => $mode,
					'slug'        => $slug,
					'plugin_file' => $plugin_file,
					'url'         => $url,
					'status'      => $status,
					'can_install' => $can_install && 'wporg' === $mode,
				);
			}
		}

		return array_values( $out );
	}

	/**
	 * Whether a plugin bootstrap file is missing, installed, or active.
	 *
	 * @param string $plugin_file Relative plugin file.
	 * @return string missing|installed|active
	 */
	public static function resolve_plugin_status( $plugin_file ) {
		$plugin_file = ltrim( str_replace( '\\', '/', (string) $plugin_file ), '/' );
		if ( '' === $plugin_file ) {
			return 'missing';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all = get_plugins();
		if ( ! isset( $all[ $plugin_file ] ) ) {
			// Fallback: match by directory prefix (renamed bootstrap files).
			$dir = strtok( $plugin_file, '/' );
			if ( $dir ) {
				foreach ( array_keys( $all ) as $file ) {
					if ( 0 === strpos( (string) $file, $dir . '/' ) ) {
						$plugin_file = (string) $file;
						break;
					}
				}
			}
		}

		if ( ! isset( $all[ $plugin_file ] ) && ! self::plugin_file_exists( $plugin_file ) ) {
			return 'missing';
		}

		if ( is_plugin_active( $plugin_file ) ) {
			return 'active';
		}

		return 'installed';
	}

	/**
	 * Whether a plugin file exists on disk.
	 *
	 * @param string $plugin_file Relative plugin file.
	 * @return bool
	 */
	private static function plugin_file_exists( $plugin_file ) {
		$path = WP_PLUGIN_DIR . '/' . ltrim( (string) $plugin_file, '/' );
		return file_exists( $path );
	}

	/**
	 * Allow-listed install packages keyed by package key.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function get_installable_packages() {
		$out      = array();
		$registry = EPC_Module_Loader::get_registry();

		foreach ( $registry as $module ) {
			$packages = $module->get_dependency_install_info();
			if ( ! is_array( $packages ) ) {
				continue;
			}
			foreach ( $packages as $package ) {
				if ( ! is_array( $package ) || 'wporg' !== ( $package['mode'] ?? '' ) ) {
					continue;
				}
				$key  = sanitize_key( (string) ( $package['key'] ?? '' ) );
				$slug = sanitize_title( (string) ( $package['slug'] ?? '' ) );
				$file = ltrim( str_replace( '\\', '/', (string) ( $package['plugin_file'] ?? '' ) ), '/' );
				if ( '' === $key || '' === $slug || '' === $file ) {
					continue;
				}
				$out[ $key ] = array(
					'key'         => $key,
					'label'       => (string) ( $package['label'] ?? $key ),
					'slug'        => $slug,
					'plugin_file' => $file,
				);
			}
		}

		return $out;
	}

	/**
	 * AJAX: install and/or activate a WordPress.org dependency.
	 *
	 * @return void
	 */
	public static function ajax_install_plugin() {
		check_ajax_referer( 'epc_wizard', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to install plugins.', 'epasscard' ),
				),
				403
			);
		}

		$key      = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( (string) $_POST['key'] ) ) : '';
		$packages = self::get_installable_packages();
		if ( '' === $key || ! isset( $packages[ $key ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown plugin.', 'epasscard' ) ), 400 );
		}

		$package = $packages[ $key ];

		// Plugins often wp_redirect()+exit on activate (Paid Memberships Pro, etc.).
		add_filter( 'wp_redirect', array( __CLASS__, 'block_all_redirects' ), 100000 );
		add_filter( 'wp_safe_redirect', array( __CLASS__, 'block_all_redirects' ), 100000 );

		$result = self::install_and_activate_package( $package );

		remove_filter( 'wp_redirect', array( __CLASS__, 'block_all_redirects' ), 100000 );
		remove_filter( 'wp_safe_redirect', array( __CLASS__, 'block_all_redirects' ), 100000 );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message'      => sprintf(
					/* translators: %s: plugin name */
					__( '%s is ready.', 'epasscard' ),
					$package['label']
				),
				'key'          => $key,
				'needs_reload' => true,
				'reloadUrl'    => self::url(),
			)
		);
	}

	/**
	 * Unconditionally cancel redirects during AJAX install/activate.
	 *
	 * @param string|false $location Redirect URL.
	 * @return false
	 */
	public static function block_all_redirects( $location ) {
		unset( $location );
		return false;
	}

	/**
	 * Install from WordPress.org if needed, then activate.
	 *
	 * @param array<string, string> $package Package meta.
	 * @return true|\WP_Error
	 */
	private static function install_and_activate_package( array $package ) {
		$slug        = (string) $package['slug'];
		$plugin_file = (string) $package['plugin_file'];

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$status      = self::resolve_plugin_status( $plugin_file );
		$plugin_file = self::resolve_plugin_file( $plugin_file );

		if ( 'missing' === $status ) {
			if ( ! function_exists( 'plugins_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}
			if ( ! class_exists( 'Plugin_Upgrader' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			}
			if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
			}

			$api = plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => array( 'sections' => false ),
				)
			);

			if ( is_wp_error( $api ) ) {
				return $api;
			}

			if ( empty( $api->download_link ) ) {
				return new WP_Error( 'epc_install_failed', __( 'Plugin download link was empty.', 'epasscard' ) );
			}

			ob_start();
			$skin      = new WP_Ajax_Upgrader_Skin();
			$upgrader  = new Plugin_Upgrader( $skin );
			$installed = $upgrader->install( $api->download_link );
			ob_end_clean();

			if ( is_wp_error( $installed ) ) {
				return $installed;
			}
			if ( true !== $installed ) {
				$error = $skin->get_errors();
				if ( is_wp_error( $error ) && $error->has_errors() ) {
					return $error;
				}
				return new WP_Error( 'epc_install_failed', __( 'Plugin install failed.', 'epasscard' ) );
			}

			if ( function_exists( 'wp_clean_plugins_cache' ) ) {
				wp_clean_plugins_cache( true );
			}

			$plugin_file = self::resolve_plugin_file( $plugin_file );
		}

		if ( '' === $plugin_file || ! self::plugin_file_exists( $plugin_file ) ) {
			return new WP_Error( 'epc_plugin_missing', __( 'Installed plugin file could not be found.', 'epasscard' ) );
		}

		if ( ! is_plugin_active( $plugin_file ) ) {
			$activated = self::activate_plugin_safely( $plugin_file );
			if ( is_wp_error( $activated ) ) {
				return $activated;
			}
		}

		return true;
	}

	/**
	 * Activate a plugin without letting redirects or stray output break AJAX.
	 *
	 * Silent activation skips setup-wizard hooks (Paid Memberships Pro, etc.).
	 *
	 * @param string $plugin_file Relative plugin bootstrap path.
	 * @return true|\WP_Error
	 */
	private static function activate_plugin_safely( $plugin_file ) {
		$plugin_file = ltrim( str_replace( '\\', '/', (string) $plugin_file ), '/' );

		$watching = true;
		register_shutdown_function(
			static function () use ( &$watching, $plugin_file ) {
				if ( ! $watching ) {
					return;
				}
				$error = error_get_last();
				if ( ! is_array( $error ) || empty( $error['type'] ) ) {
					return;
				}
				$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
				if ( ! in_array( (int) $error['type'], $fatal_types, true ) ) {
					return;
				}

				while ( ob_get_level() > 0 ) {
					ob_end_clean();
				}

				if ( ! headers_sent() ) {
					status_header( 200 );
					nocache_headers();
					header( 'Content-Type: application/json; charset=utf-8' );
				}

				echo wp_json_encode(
					array(
						'success' => false,
						'data'    => array(
							'message' => sprintf(
								/* translators: 1: plugin file, 2: error message */
								__( 'Could not activate %1$s: %2$s. Try activating it from Plugins, then return here.', 'epasscard' ),
								$plugin_file,
								(string) ( $error['message'] ?? '' )
							),
						),
					)
				);
			}
		);

		ob_start();
		$result = activate_plugin( $plugin_file, '', false, true );
		ob_end_clean();
		$watching = false;

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_plugin_active( $plugin_file ) ) {
			$resolved = self::resolve_plugin_file( $plugin_file );
			if ( $resolved !== $plugin_file ) {
				ob_start();
				$result = activate_plugin( $resolved, '', false, true );
				ob_end_clean();
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( is_plugin_active( $resolved ) ) {
					return true;
				}
			}

			return new WP_Error(
				'epc_activate_failed',
				__( 'Plugin was installed but could not be activated. Activate it from Plugins, then return here.', 'epasscard' )
			);
		}

		return true;
	}

	/**
	 * Resolve the actual plugin bootstrap path (handles alternate filenames).
	 *
	 * @param string $plugin_file Preferred relative path.
	 * @return string
	 */
	private static function resolve_plugin_file( $plugin_file ) {
		$plugin_file = ltrim( str_replace( '\\', '/', (string) $plugin_file ), '/' );
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all = get_plugins();
		if ( isset( $all[ $plugin_file ] ) ) {
			return $plugin_file;
		}

		$dir = strtok( $plugin_file, '/' );
		if ( $dir ) {
			foreach ( array_keys( $all ) as $file ) {
				if ( 0 === strpos( (string) $file, $dir . '/' ) ) {
					return (string) $file;
				}
			}
		}

		return $plugin_file;
	}

	/**
	 * Whether newly activated plugin classes are already loadable without reload.
	 *
	 * @param string $key Package key.
	 * @return bool
	 */
	private static function plugin_classes_ready( $key ) {
		switch ( $key ) {
			case 'woocommerce':
				return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
			case 'paid-memberships-pro':
				return defined( 'PMPRO_VERSION' ) || function_exists( 'pmpro_getAllLevels' );
			case 'simple-membership':
				return class_exists( 'SwpmMemberUtils' ) || defined( 'SIMPLE_WP_MEMBERSHIP_VER' );
			case 'the-events-calendar':
				return class_exists( 'Tribe__Events__Main' ) || defined( 'TRIBE_EVENTS_FILE' );
			case 'event-tickets':
				return class_exists( 'Tribe__Tickets__Main' ) || defined( 'EVENT_TICKETS_DIR' );
			case 'events-manager':
				return class_exists( 'EM_Event' ) || defined( 'EM_VERSION' );
			case 'pw-woocommerce-gift-cards':
				return class_exists( 'PW_Gift_Card' ) || defined( 'PWGC_VERSION' );
			case 'yith-woocommerce-gift-cards':
				return defined( 'YITH_YWGC_VERSION' ) || function_exists( 'YITH_YWGC' );
			default:
				return true;
		}
	}

	/**
	 * Decide which panel to show.
	 *
	 * @param array<string, mixed> $state Wizard state.
	 * @return string
	 */
	public static function resolve_step( array $state ) {
		if ( 'completed' === ( $state['status'] ?? '' ) ) {
			return 'done';
		}

		if ( empty( $state['welcome_done'] ) && ! EPC_Connection::is_connected() ) {
			return 'welcome';
		}

		if ( ! empty( $state['review_connect'] ) && EPC_Connection::is_connected() ) {
			return 'connect';
		}

		if ( ! EPC_Connection::is_connected() ) {
			return 'connect';
		}

		$slug = sanitize_key( (string) ( $state['module'] ?? '' ) );
		if ( '' === $slug || ! EPC_Module_Settings::is_enabled( $slug ) ) {
			return 'choose';
		}

		if ( ! empty( $state['edit_setup'] ) || ! self::is_setup_complete( $slug ) ) {
			return 'setup';
		}

		if ( 'completed' !== ( $state['status'] ?? '' ) ) {
			return 'result';
		}

		return 'done';
	}

	/**
	 * Whether mapping or loyalty design exists.
	 *
	 * @param string $slug Module slug.
	 * @return bool
	 */
	public static function is_setup_complete( $slug ) {
		$slug = sanitize_key( $slug );
		if ( 'woocommerce-loyalty' === $slug ) {
			$design = EPC_Loyalty_Pass_Design_Service::get_design();
			if ( '' === (string) ( $design['template_uid'] ?? '' ) ) {
				return false;
			}

			foreach ( EPC_Loyalty_Rule_Service::get_rules() as $rule ) {
				if ( ! empty( $rule['active'] ) ) {
					return true;
				}
			}

			return false;
		}

		$registry = EPC_Module_Loader::get_registry();
		if ( ! isset( $registry[ $slug ] ) ) {
			return false;
		}

		foreach ( $registry[ $slug ]->get_mappings() as $mapping ) {
			if ( is_array( $mapping ) && ! empty( $mapping['template_uid'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Issued pass for the result step.
	 *
	 * @param array<string, mixed> $state Wizard state.
	 * @return object|null
	 */
	public static function get_result_pass( array $state ) {
		$slug = sanitize_key( (string) ( $state['module'] ?? '' ) );
		if ( 'woocommerce-loyalty' === $slug ) {
			$row = EPC_DB::get_pass( $slug, get_current_user_id() );
			return $row ? $row : null;
		}

		if ( '' === $slug ) {
			return null;
		}

		$link = (string) ( $state['pass_link'] ?? '' );
		if ( '' === $link ) {
			return null;
		}

		return (object) array(
			'pass_link' => $link,
			'status'    => 'active',
		);
	}

	/**
	 * Whether the current admin screen is the wizard.
	 *
	 * @param string $hook Hook suffix.
	 * @return bool
	 */
	public static function is_wizard_screen( $hook = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG === $page ) {
			return true;
		}

		return false !== strpos( (string) $hook, self::PAGE_SLUG );
	}

	/**
	 * Default pending state.
	 *
	 * @return array<string, mixed>
	 */
	private static function default_state() {
		return array(
			'status'         => 'pending',
			'welcome_done'   => false,
			'goal'           => '',
			'module'         => '',
			'setup_done'     => false,
			'pass_link'      => '',
			'review_connect' => false,
			'edit_setup'     => false,
		);
	}

	/**
	 * State for merchants who already connected before this wizard existed.
	 *
	 * @return array<string, mixed>
	 */
	private static function completed_state() {
		$state           = self::default_state();
		$state['status'] = 'completed';
		$state['welcome_done'] = true;
		return $state;
	}

	/**
	 * Persist wizard state.
	 *
	 * @param array<string, mixed> $state State.
	 * @return void
	 */
	private static function save_state( array $state ) {
		update_option( self::OPTION_KEY, wp_parse_args( $state, self::default_state() ), false );
	}

	/**
	 * Clear wizard progress and the setup it created so onboarding can start at Welcome.
	 *
	 * Issued passes and loyalty balances are left in place.
	 *
	 * @return void
	 */
	private static function reset_fresh() {
		$state = self::get_state();
		$slug  = sanitize_key( (string) ( $state['module'] ?? '' ) );

		if ( '' !== $slug && class_exists( 'EPC_Module_Settings' ) ) {
			$enabled = array_values(
				array_filter(
					EPC_Module_Settings::get_saved_slugs(),
					static function ( $item ) use ( $slug ) {
						return $item !== $slug;
					}
				)
			);
			EPC_Module_Settings::save_enabled_slugs( $enabled );
		}

		if ( class_exists( 'EPC_Loyalty_Pass_Design_Service' ) ) {
			delete_option( EPC_Loyalty_Pass_Design_Service::OPTION_KEY );
		}

		if ( class_exists( 'EPC_Module_Loader' ) ) {
			foreach ( EPC_Module_Loader::get_registry() as $module ) {
				if ( is_object( $module ) && method_exists( $module, 'get_mappings_option_key' ) ) {
					delete_option( $module->get_mappings_option_key() );
				}
			}
		}

		if ( class_exists( 'EPC_Connection' ) ) {
			EPC_Connection::disconnect();
		}

		delete_transient( self::REDIRECT_TRANSIENT );
		update_option( self::OPTION_KEY, self::default_state(), false );
	}

	/**
	 * Guard AJAX handlers.
	 *
	 * @return void
	 */
	private static function require_ajax() {
		check_ajax_referer( 'epc_wizard', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}
	}

	/**
	 * Activate starter loyalty rules and create a StoreCard template.
	 *
	 * @return true|\WP_Error
	 */
	private static function setup_loyalty() {
		if ( ! function_exists( 'epc_is_woocommerce_active' ) || ! epc_is_woocommerce_active() ) {
			return new WP_Error( 'epc_wizard_wc', __( 'Activate WooCommerce to set up loyalty cards.', 'epasscard' ) );
		}

		$rules = EPC_Loyalty_Rule_Service::get_rules();
		foreach ( $rules as &$rule ) {
			if ( in_array( $rule['id'], array( 'points-per-spend', 'welcome-first-order' ), true ) ) {
				$rule['active'] = true;
			}
		}
		unset( $rule );
		EPC_Loyalty_Rule_Service::save_rules( $rules );

		$tiers = EPC_Loyalty_Reward_Service::get_tiers();
		foreach ( $tiers as &$tier ) {
			if ( in_array( $tier['id'], array( 'bronze', 'silver', 'gold' ), true ) ) {
				$tier['active'] = true;
			}
		}
		unset( $tier );
		EPC_Loyalty_Reward_Service::save_tiers( $tiers );

		$source = isset( $_POST['design_source'] ) ? sanitize_key( wp_unslash( (string) $_POST['design_source'] ) ) : 'form';
		if ( 'builder' === $source ) {
			$uid = isset( $_POST['template_uid'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['template_uid'] ) ) : '';
			return EPC_Loyalty_Pass_Design_Service::save_and_sync(
				array(
					'design_source' => 'builder',
					'template_uid'  => $uid,
				)
			);
		}

		$media = self::ensure_loyalty_media();
		if ( is_wp_error( $media ) ) {
			return $media;
		}

		$posted_logo = isset( $_POST['logo_url'] ) ? trim( (string) wp_unslash( $_POST['logo_url'] ) ) : '';
		$posted_strip = isset( $_POST['strip_url'] ) ? trim( (string) wp_unslash( $_POST['strip_url'] ) ) : '';
		$logo_url    = self::sanitize_public_image_url( $posted_logo );
		$strip_url   = self::sanitize_public_image_url( $posted_strip );
		$name      = isset( $_POST['template_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['template_name'] ) ) : '';
		$bg        = isset( $_POST['background'] ) ? sanitize_hex_color( wp_unslash( (string) $_POST['background'] ) ) : '';
		$text      = isset( $_POST['text'] ) ? sanitize_hex_color( wp_unslash( (string) $_POST['text'] ) ) : '';

		if ( '' === $logo_url ) {
			$logo_url = $media['logo_url'];
		}
		if ( '' === $strip_url ) {
			$strip_url = $media['strip_url'];
		}

		$result = EPC_Loyalty_Pass_Design_Service::save_and_sync(
			array(
				'design_source'     => 'form',
				'template_name'     => '' !== $name ? $name : '',
				'organization_name' => (string) get_bloginfo( 'name' ),
				'logo_url'          => $logo_url,
				'strip_url'         => $strip_url,
				'colors'            => array(
					'background' => $bg ? $bg : '#1E1B4B',
					'text'       => $text ? $text : '#FFFFFF',
				),
			)
		);

		if ( is_wp_error( $result ) && ( '' !== $posted_logo || '' !== $posted_strip ) && false !== stripos( $result->get_error_message(), 'validation' ) ) {
			return new WP_Error(
				'epc_wizard_media_validation',
				__( 'EpassCard could not download the logo or banner. Leave Logo URL empty to use the default images, or paste a public https URL (not localhost).', 'epasscard' )
			);
		}

		return $result;
	}

	/**
	 * Map the first (or posted) entity to a template with automatic field matching.
	 *
	 * Supports creating a starter template from the wizard form, or attaching an
	 * existing EpassCard template selected from the builder list.
	 *
	 * @param string $slug Module slug.
	 * @return true|\WP_Error
	 */
	private static function setup_mapping( $slug ) {
		$registry = EPC_Module_Loader::get_registry();
		if ( ! isset( $registry[ $slug ] ) ) {
			return new WP_Error( 'epc_wizard_module', __( 'That integration is not available.', 'epasscard' ) );
		}

		$module    = $registry[ $slug ];
		$entity_id = isset( $_POST['entity_id'] ) ? absint( wp_unslash( $_POST['entity_id'] ) ) : 0;
		$template  = isset( $_POST['template_uid'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['template_uid'] ) ) : '';
		$tpl_name  = isset( $_POST['template_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['template_name'] ) ) : '';
		if ( isset( $_POST['design_source'] ) ) {
			$source = sanitize_key( wp_unslash( (string) $_POST['design_source'] ) );
		} else {
			$source = '' !== $template ? 'builder' : 'form';
		}
		if ( ! in_array( $source, array( 'form', 'builder' ), true ) ) {
			$source = 'builder';
		}

		if ( $entity_id <= 0 ) {
			$entities  = $module->get_mappable_entities();
			$first     = isset( $entities[0]['id'] ) ? absint( $entities[0]['id'] ) : 0;
			$entity_id = $first;
		}

		if ( $entity_id <= 0 ) {
			return new WP_Error( 'epc_wizard_entity', __( 'Create a membership, product, event, or gift card first, then return to map it.', 'epasscard' ) );
		}

		if ( 'form' === $source ) {
			$created = self::create_starter_template_for_goal( $tpl_name );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$template = (string) $created['uid'];
			$tpl_name = (string) ( $created['template_name'] ?? $tpl_name );
			$pass_fields = isset( $created['pass_fields'] ) && is_array( $created['pass_fields'] )
				? $created['pass_fields']
				: array();
		} else {
			$san_uid = EPC_Api_Client::sanitize_uid( $template );
			if ( false === $san_uid ) {
				return new WP_Error( 'epc_wizard_template', __( 'Select a pass template from your EpassCard account.', 'epasscard' ) );
			}
			$template = $san_uid;

			$fields = EPC_Api_Client::get_pass_fields( $template );
			if ( is_wp_error( $fields ) ) {
				return $fields;
			}
			$pass_fields = isset( $fields['passFields'] ) && is_array( $fields['passFields'] ) ? $fields['passFields'] : array();
		}

		$sources = $module->get_mapping_source_fields();
		$mapping = self::auto_map_fields( $pass_fields, $sources );

		if ( 'builder' === $source && empty( $mapping ) ) {
			$guidance = self::builder_guidance_for_goal( sanitize_key( (string) ( self::get_state()['goal'] ?? 'membership' ) ) );
			$examples = isset( $guidance['fields'] ) ? implode( ', ', $guidance['fields'] ) : 'Name, Status';
			return new WP_Error(
				'epc_wizard_builder_mapping',
				sprintf(
					/* translators: %s: example field names. */
					__( 'The template was selected, but none of its fields could be mapped automatically. In the EpassCard template builder, name fields like %s — then refresh and try again. Or choose “Create a starter template” instead.', 'epasscard' ),
					$examples
				)
			);
		}

		$module->save_mapping(
			$entity_id,
			array(
				'template_uid'  => $template,
				'template_name' => $tpl_name,
				'template_id'   => 0,
				'field_mapping' => $mapping,
				'pass_fields'   => $pass_fields,
			)
		);

		return true;
	}

	/**
	 * Preview and default copy for a non-loyalty use-case starter template.
	 *
	 * @param string $goal Goal slug.
	 * @param string $blog Site name.
	 * @return array{template_name:string,preview_kicker:string,preview_title:string,header_label:string,header_value:string,secondary_label:string,secondary_value:string,barcode_alt:string}
	 */
	public static function starter_copy_for_goal( $goal, $blog = '' ) {
		$blog = sanitize_text_field( (string) $blog );
		if ( '' === $blog ) {
			$blog = (string) get_bloginfo( 'name' );
		}
		if ( '' === $blog ) {
			$blog = 'EpassCard';
		}

		switch ( sanitize_key( (string) $goal ) ) {
			case 'loyalty':
				return array(
					'template_name'   => sprintf(
						/* translators: %s: site name. */
						__( '%s Loyalty', 'epasscard' ),
						$blog
					),
					'preview_kicker'  => __( 'Loyalty', 'epasscard' ),
					'preview_title'   => __( 'Gold member', 'epasscard' ),
					'header_label'    => __( 'Points', 'epasscard' ),
					'header_value'    => '1,240',
					'secondary_label' => __( 'Tier', 'epasscard' ),
					'secondary_value' => __( 'Gold', 'epasscard' ),
					'barcode_alt'     => 'LYL-004821',
				);
			case 'events':
				return array(
					'template_name'   => sprintf(
						/* translators: %s: site name. */
						__( '%s Event Ticket', 'epasscard' ),
						$blog
					),
					'preview_kicker'  => __( 'Event', 'epasscard' ),
					'preview_title'   => __( 'Summer conference', 'epasscard' ),
					'header_label'    => __( 'Ticket', 'epasscard' ),
					'header_value'    => __( 'General', 'epasscard' ),
					'secondary_label' => __( 'Venue', 'epasscard' ),
					'secondary_value' => __( 'Main hall', 'epasscard' ),
					'barcode_alt'     => 'TKT-004821',
				);
			case 'gift-cards':
				return array(
					'template_name'   => sprintf(
						/* translators: %s: site name. */
						__( '%s Gift Card', 'epasscard' ),
						$blog
					),
					'preview_kicker'  => __( 'Gift card', 'epasscard' ),
					'preview_title'   => __( 'Store credit', 'epasscard' ),
					'header_label'    => __( 'Balance', 'epasscard' ),
					'header_value'    => '$50.00',
					'secondary_label' => __( 'From', 'epasscard' ),
					'secondary_value' => __( 'Jordan', 'epasscard' ),
					'barcode_alt'     => 'GC-004821',
				);
			case 'membership':
			default:
				return array(
					'template_name'   => sprintf(
						/* translators: %s: site name. */
						__( '%s Membership', 'epasscard' ),
						$blog
					),
					'preview_kicker'  => __( 'Membership', 'epasscard' ),
					'preview_title'   => __( 'Annual member', 'epasscard' ),
					'header_label'    => __( 'Status', 'epasscard' ),
					'header_value'    => __( 'Active', 'epasscard' ),
					'secondary_label' => __( 'Expires', 'epasscard' ),
					'secondary_value' => '2027-12-31',
					'barcode_alt'     => 'MEM-004821',
				);
		}
	}

	/**
	 * Copy for the “Use template builder” panel: required field names + checklist.
	 *
	 * @param string $goal Goal slug (loyalty|membership|events|gift-cards).
	 * @return array{notice:string,checklist:array<int,string>,fields:array<int,string>}
	 */
	public static function builder_guidance_for_goal( $goal ) {
		switch ( sanitize_key( (string) $goal ) ) {
			case 'loyalty':
				return array(
					'fields'    => array( 'Points', 'Name', 'Member No', 'Tier' ),
					'checklist' => array(
						__( 'Points balance', 'epasscard' ),
						__( 'Member name', 'epasscard' ),
						__( 'Member number', 'epasscard' ),
						__( 'Tier / rewards', 'epasscard' ),
					),
					'notice'    => __( 'Important: your template should include fields named like <strong>Points</strong>, <strong>Name</strong>, <strong>Member No</strong>, and <strong>Tier</strong> so we can fill them from loyalty data.', 'epasscard' ),
				);
			case 'events':
				return array(
					'fields'    => array( 'Name', 'Event', 'Starts', 'Venue', 'Ticket' ),
					'checklist' => array(
						__( 'Attendee name', 'epasscard' ),
						__( 'Event title', 'epasscard' ),
						__( 'Start time', 'epasscard' ),
						__( 'Venue / ticket', 'epasscard' ),
					),
					'notice'    => __( 'Important: your template should include fields named like <strong>Name</strong>, <strong>Event</strong>, <strong>Starts</strong>, <strong>Venue</strong>, and <strong>Ticket</strong> so we can fill them from event data.', 'epasscard' ),
				);
			case 'gift-cards':
				return array(
					'fields'    => array( 'Balance', 'Name', 'From', 'Code', 'Product' ),
					'checklist' => array(
						__( 'Card balance', 'epasscard' ),
						__( 'Recipient name', 'epasscard' ),
						__( 'From / sender', 'epasscard' ),
						__( 'Gift code / product', 'epasscard' ),
					),
					'notice'    => __( 'Important: your template should include fields named like <strong>Balance</strong>, <strong>Name</strong>, <strong>From</strong>, <strong>Code</strong>, and <strong>Product</strong> so we can fill them from gift card data.', 'epasscard' ),
				);
			case 'membership':
			default:
				return array(
					'fields'    => array( 'Name', 'Status', 'Expires', 'Membership', 'Member ID' ),
					'checklist' => array(
						__( 'Member name', 'epasscard' ),
						__( 'Membership status', 'epasscard' ),
						__( 'Expiry date', 'epasscard' ),
						__( 'Plan / member ID', 'epasscard' ),
					),
					'notice'    => __( 'Important: your template should include fields named like <strong>Name</strong>, <strong>Status</strong>, <strong>Expires</strong>, <strong>Membership</strong>, and <strong>Member ID</strong> so we can fill them from membership data.', 'epasscard' ),
				);
		}
	}

	/**
	 * Create a starter pass template for the current wizard goal and return fields.
	 *
	 * @param string $template_name Optional posted name.
	 * @return array{uid:string,template_name:string,pass_fields:array<int,array<string,mixed>>}|\WP_Error
	 */
	private static function create_starter_template_for_goal( $template_name = '' ) {
		$state = self::get_state();
		$goal  = sanitize_key( (string) ( $state['goal'] ?? 'membership' ) );
		$blog  = (string) get_bloginfo( 'name' );
		$copy  = self::starter_copy_for_goal( $goal, $blog );
		$name  = sanitize_text_field( (string) $template_name );
		if ( '' === $name ) {
			$name = (string) $copy['template_name'];
		}

		$media = self::ensure_loyalty_media();
		if ( is_wp_error( $media ) ) {
			return $media;
		}

		$posted_logo = isset( $_POST['logo_url'] ) ? trim( (string) wp_unslash( $_POST['logo_url'] ) ) : '';
		$logo_url    = self::sanitize_public_image_url( $posted_logo );
		if ( '' === $logo_url ) {
			$logo_url = $media['logo_url'];
		}
		$strip_url = $media['strip_url'];

		$bg   = isset( $_POST['background'] ) ? sanitize_hex_color( wp_unslash( (string) $_POST['background'] ) ) : '';
		$text = isset( $_POST['text'] ) ? sanitize_hex_color( wp_unslash( (string) $_POST['text'] ) ) : '';
		if ( ! $bg ) {
			$bg = '#1E1B4B';
		}
		if ( ! $text ) {
			$text = '#FFFFFF';
		}

		$payload = self::build_starter_template_payload(
			$goal,
			array(
				'template_name'     => $name,
				'organization_name' => '' !== $blog ? $blog : 'EpassCard',
				'logo_url'          => $logo_url,
				'strip_url'         => $strip_url,
				'background'        => $bg,
				'text'              => $text,
			)
		);

		$result = EPC_Api_Client::create_pass_template_v2( $payload );
		if ( is_wp_error( $result ) ) {
			if ( '' !== $posted_logo && false !== stripos( $result->get_error_message(), 'validation' ) ) {
				return new WP_Error(
					'epc_wizard_media_validation',
					__( 'EpassCard could not download the logo or banner. Leave Logo URL empty to use the default images, or paste a public https URL (not localhost).', 'epasscard' )
				);
			}
			return $result;
		}

		$uid = isset( $result['uid'] ) ? EPC_Api_Client::sanitize_uid( (string) $result['uid'] ) : false;
		if ( false === $uid ) {
			return new WP_Error( 'epc_wizard_template_uid', __( 'The starter template was created but no template UID was returned.', 'epasscard' ) );
		}

		$pass_fields = array();
		if ( ! empty( $result['fields'] ) && is_array( $result['fields'] ) ) {
			foreach ( $result['fields'] as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$pass_fields[] = $field;
			}
		}

		if ( empty( $pass_fields ) ) {
			$fields = EPC_Api_Client::get_pass_fields( $uid );
			if ( is_wp_error( $fields ) ) {
				return $fields;
			}
			$pass_fields = isset( $fields['passFields'] ) && is_array( $fields['passFields'] ) ? $fields['passFields'] : array();
		}

		return array(
			'uid'           => $uid,
			'template_name' => $name,
			'pass_fields'   => $pass_fields,
		);
	}

	/**
	 * Simplified v2 create payload for membership, event, or gift-card starters.
	 *
	 * @param string               $goal Goal slug.
	 * @param array<string,string> $opts Name, org, media, colors.
	 * @return array<string, mixed>
	 */
	private static function build_starter_template_payload( $goal, array $opts ) {
		$expire = class_exists( 'EPC_Loyalty_Pass_Design_Service' )
			? EPC_Loyalty_Pass_Design_Service::resolve_expire_date( '' )
			: gmdate( 'Y-m-d H:i:s', strtotime( '+99 years' ) );

		$base = array(
			'template_name'     => (string) ( $opts['template_name'] ?? 'Pass' ),
			'organization_name' => (string) ( $opts['organization_name'] ?? 'EpassCard' ),
			'certificate'       => 'pass.com.epasscard.public',
			'logo'              => (string) ( $opts['logo_url'] ?? '' ),
			'icon'              => (string) ( $opts['logo_url'] ?? '' ),
			'strip_image'       => (string) ( $opts['strip_url'] ?? '' ),
			'pass_limit'        => 0,
			'expire_date'       => $expire,
			'colors'            => array_filter(
				array(
					'background' => (string) ( $opts['background'] ?? '#1E1B4B' ),
					'text'       => (string) ( $opts['text'] ?? '#FFFFFF' ),
				),
				static function ( $value ) {
					return '' !== $value;
				}
			),
		);

		switch ( sanitize_key( (string) $goal ) ) {
			case 'events':
				return array_merge(
					$base,
					array(
						'card_type'        => 'Event',
						'header_fields'    => array(
							array(
								'label' => __( 'Ticket', 'epasscard' ),
								'value' => '{Ticket}',
							),
						),
						'secondary_fields' => array(
							array(
								'label' => __( 'Name', 'epasscard' ),
								'value' => '{Name}',
							),
							array(
								'label' => __( 'Venue', 'epasscard' ),
								'value' => '{Venue}',
							),
						),
						'back_fields'      => array(
							array(
								'label' => __( 'Event', 'epasscard' ),
								'value' => '{Event}',
							),
							array(
								'label' => __( 'Starts', 'epasscard' ),
								'value' => '{Starts}',
							),
							array(
								'label' => __( 'Email', 'epasscard' ),
								'value' => '{Email}',
							),
						),
						'barcode'          => array(
							'format' => 'QR',
							'value'  => '{Ticket Code}',
						),
						'fields'           => array(
							array(
								'name'     => 'Ticket',
								'type'     => 'text',
								'required' => false,
								'unique'   => false,
							),
							array(
								'name'     => 'Name',
								'type'     => 'text',
								'required' => true,
								'unique'   => false,
							),
							array(
								'name'     => 'Venue',
								'type'     => 'text',
								'required' => false,
								'unique'   => false,
							),
							array(
								'name'     => 'Event',
								'type'     => 'text',
								'required' => true,
								'unique'   => false,
							),
							array(
								'name'     => 'Starts',
								'type'     => 'text',
								'required' => false,
								'unique'   => false,
							),
							array(
								'name'     => 'Email',
								'type'     => 'email',
								'required' => false,
								'unique'   => false,
							),
							array(
								'name'     => 'Ticket Code',
								'type'     => 'text',
								'required' => true,
								'unique'   => true,
							),
						),
					)
				);
			case 'gift-cards':
				return array_merge(
					$base,
					array(
						'card_type'        => 'Coupon',
						'header_fields'    => array(
							array(
								'label' => __( 'Balance', 'epasscard' ),
								'value' => '{Balance}',
							),
						),
						'secondary_fields' => array(
							array(
								'label' => __( 'Name', 'epasscard' ),
								'value' => '{Name}',
							),
							array(
								'label' => __( 'From', 'epasscard' ),
								'value' => '{From}',
							),
						),
						'back_fields'      => array(
							array(
								'label' => __( 'Gift card', 'epasscard' ),
								'value' => '{Product}',
							),
							array(
								'label' => __( 'Email', 'epasscard' ),
								'value' => '{Email}',
							),
						),
						'barcode'          => array(
							'format' => 'QR',
							'value'  => '{Code}',
						),
						'fields'           => array(
							array(
								'name'     => 'Balance',
								'type'     => 'text',
								'required' => true,
								'unique'   => false,
							),
							array(
								'name'     => 'Name',
								'type'     => 'text',
								'required' => false,
								'unique'   => false,
							),
							array(
								'name'     => 'From',
								'type'     => 'text',
								'required' => false,
								'unique'   => false,
							),
							array(
								'name'     => 'Product',
								'type'     => 'text',
								'required' => false,
								'unique'   => false,
							),
							array(
								'name'     => 'Email',
								'type'     => 'email',
								'required' => false,
								'unique'   => false,
							),
							array(
								'name'     => 'Code',
								'type'     => 'text',
								'required' => true,
								'unique'   => true,
							),
						),
					)
				);
			case 'membership':
			default:
				return array_merge(
					$base,
					array(
						'card_type'        => 'Generic',
						'header_fields'    => array(
							array(
								'label' => __( 'Status', 'epasscard' ),
								'value' => '{Status}',
							),
						),
						'secondary_fields' => array(
							array(
								'label' => __( 'Name', 'epasscard' ),
								'value' => '{Name}',
							),
							array(
								'label' => __( 'Expires', 'epasscard' ),
								'value' => '{Expires}',
							),
						),
						'back_fields'      => array(
							array(
								'label' => __( 'Membership', 'epasscard' ),
								'value' => '{Membership}',
							),
							array(
								'label' => __( 'Email', 'epasscard' ),
								'value' => '{Email}',
							),
						),
						'barcode'          => array(
							'format' => 'QR',
							'value'  => '{Member ID}',
						),
						'fields'           => array(
							array(
								'name'     => 'Status',
								'type'     => 'text',
								'required' => false,
								'unique'   => false,
							),
							array(
								'name'     => 'Name',
								'type'     => 'text',
								'required' => true,
								'unique'   => false,
							),
							array(
								'name'     => 'Expires',
								'type'     => 'text',
								'required' => false,
								'unique'   => false,
							),
							array(
								'name'     => 'Membership',
								'type'     => 'text',
								'required' => false,
								'unique'   => false,
							),
							array(
								'name'     => 'Email',
								'type'     => 'email',
								'required' => false,
								'unique'   => false,
							),
							array(
								'name'     => 'Member ID',
								'type'     => 'text',
								'required' => true,
								'unique'   => true,
							),
						),
					)
				);
		}
	}

	/**
	 * Best-effort map of pass fields to module source fields.
	 *
	 * @param array<int, array<string, mixed>> $pass_fields Pass fields.
	 * @param array<string, string>            $sources     Source slug => label.
	 * @return array<string, array<string, string>>
	 */
	private static function auto_map_fields( array $pass_fields, array $sources ) {
		$out = array();

		foreach ( $pass_fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$uid = isset( $field['uid'] ) ? EPC_Api_Client::sanitize_uid( (string) $field['uid'] ) : false;
			if ( false === $uid ) {
				continue;
			}

			$label = strtolower(
				trim(
					(string) ( $field['field_name'] ?? $field['name'] ?? $field['label'] ?? $field['fieldName'] ?? '' )
				)
			);
			$source = self::guess_source_field( $label, $sources );
			if ( '' === $source ) {
				continue;
			}

			$out[ $uid ] = array(
				'type'   => 'source',
				'source' => $source,
			);
		}

		return $out;
	}

	/**
	 * Guess a source field slug from a pass field label.
	 *
	 * @param string               $label   Pass field label.
	 * @param array<string, string> $sources Source fields.
	 * @return string
	 */
	private static function guess_source_field( $label, array $sources ) {
		$needles = array(
			'user_full_name'       => array( 'name', 'member', 'customer', 'holder', 'attendee' ),
			'user_email'           => array( 'email', 'e-mail' ),
			'recipient_email'      => array( 'recipient' ),
			'points_balance'       => array( 'point', 'balance', 'points' ),
			'lifetime_points'      => array( 'lifetime' ),
			'tier'                 => array( 'tier', 'level' ),
			'next_tier'            => array( 'next tier' ),
			'member_id'            => array( 'member no', 'member id', 'membership id', 'barcode', 'qr' ),
			'membership_id'        => array( 'member id', 'membership id' ),
			'membership_title'     => array( 'membership', 'plan', 'product', 'level' ),
			'membership_status'    => array( 'status' ),
			'membership_expires'   => array( 'expir', 'expires', 'end date', 'valid' ),
			'expiry'               => array( 'expir', 'end date', 'valid' ),
			'status'               => array( 'status' ),
			'event_title'          => array( 'event' ),
			'event_start'          => array( 'starts', 'start', 'when' ),
			'venue_name'           => array( 'venue', 'location' ),
			'ticket_name'          => array( 'ticket' ),
			'security_code'        => array( 'ticket code', 'security', 'qr', 'code', 'barcode' ),
			'attendee_id'          => array( 'attendee id' ),
			'balance'              => array( 'balance', 'amount', 'value' ),
			'balance_formatted'    => array( 'balance' ),
			'card_number'          => array( 'code', 'card number', 'gift code' ),
			'from_name'            => array( 'from' ),
			'product_name'         => array( 'product', 'gift card' ),
		);

		// Exact common starter-template field names first.
		$exact = array(
			'name'         => array( 'user_full_name', 'user_display_name' ),
			'email'        => array( 'user_email', 'recipient_email' ),
			'status'       => array( 'membership_status', 'status', 'card_status', 'attendee_status' ),
			'expires'      => array( 'membership_expires', 'expire_date', 'expiry' ),
			'membership'   => array( 'membership_title' ),
			'member id'    => array( 'membership_id', 'member_id' ),
			'event'        => array( 'event_title' ),
			'starts'       => array( 'event_start' ),
			'venue'        => array( 'venue_name' ),
			'ticket'       => array( 'ticket_name' ),
			'ticket code'  => array( 'security_code', 'ticket_id', 'attendee_id' ),
			'balance'      => array( 'balance_formatted', 'balance' ),
			'from'         => array( 'from_name' ),
			'product'      => array( 'product_name' ),
			'code'         => array( 'card_number' ),
		);
		if ( isset( $exact[ $label ] ) ) {
			foreach ( $exact[ $label ] as $slug ) {
				if ( isset( $sources[ $slug ] ) ) {
					return $slug;
				}
			}
		}

		foreach ( $sources as $slug => $source_label ) {
			$hay = $label . ' ' . strtolower( (string) $source_label ) . ' ' . str_replace( '_', ' ', $slug );
			if ( isset( $needles[ $slug ] ) ) {
				foreach ( $needles[ $slug ] as $needle ) {
					if ( false !== strpos( $hay, $needle ) || false !== strpos( $label, $needle ) ) {
						return $slug;
					}
				}
			}
			if ( '' !== $label && false !== strpos( strtolower( (string) $source_label ), $label ) ) {
				return $slug;
			}
		}

		foreach ( $needles as $slug => $needles_for ) {
			if ( ! isset( $sources[ $slug ] ) ) {
				continue;
			}
			foreach ( $needles_for as $needle ) {
				if ( false !== strpos( $label, $needle ) ) {
					return $slug;
				}
			}
		}

		return '';
	}

	/**
	 * Create the first pass the merchant can open.
	 *
	 * @param array<string, mixed> $state Wizard state.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function issue_first_pass( array $state ) {
		$slug = sanitize_key( (string) ( $state['module'] ?? '' ) );
		if ( 'woocommerce-loyalty' === $slug ) {
			$issued = EPC_Loyalty_Pass_Design_Service::issue_test_pass( get_current_user_id() );
			if ( is_wp_error( $issued ) ) {
				return $issued;
			}

			$pass = EPC_DB::get_pass( $slug, get_current_user_id() );
			$link = $pass && ! empty( $pass->pass_link ) ? (string) $pass->pass_link : '';
			if ( '' === $link ) {
				return new WP_Error( 'epc_wizard_link', __( 'The pass was created but no wallet link was returned. Check API Log.', 'epasscard' ) );
			}

			return array(
				'message'   => __( 'Your test loyalty pass is ready.', 'epasscard' ),
				'pass_link' => $link,
			);
		}

		$module_url = admin_url( 'admin.php?page=epc-' . $slug );

		return array(
			'message'    => __( 'Mapping is saved. Open the integration to create a pass for a real member, ticket, or gift card.', 'epasscard' ),
			'pass_link'  => '',
			'manual'     => true,
			'module_url' => $module_url,
		);
	}

	/**
	 * Public logo/strip URLs the EpassCard API can fetch.
	 *
	 * Localhost, .test, and other private URLs are skipped because the
	 * EpassCard servers download these images themselves.
	 *
	 * @return array{logo_url: string, strip_url: string}
	 */
	private static function ensure_loyalty_media() {
		$logo = self::sanitize_public_image_url( get_site_icon_url( 512 ) );
		if ( '' === $logo ) {
			$custom = (int) get_theme_mod( 'custom_logo' );
			if ( $custom > 0 ) {
				$from_logo = wp_get_attachment_image_url( $custom, 'full' );
				$logo      = is_string( $from_logo ) ? self::sanitize_public_image_url( $from_logo ) : '';
			}
		}
		if ( '' === $logo ) {
			$logo = self::default_public_logo_url();
		}

		$strip = self::sanitize_public_image_url( self::maybe_create_strip_image() );
		if ( '' === $strip ) {
			$strip = self::default_public_strip_url();
		}

		return array(
			'logo_url'  => $logo,
			'strip_url' => $strip,
		);
	}

	/**
	 * Accept only complete, publicly reachable http(s) image URLs.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private static function sanitize_public_image_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || in_array( $url, array( 'http://', 'https://' ), true ) ) {
			return '';
		}

		$url  = esc_url_raw( $url );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $url || ! is_string( $host ) || '' === $host ) {
			return '';
		}

		$host = strtolower( $host );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) ) {
			return '';
		}

		if ( preg_match( '/\.(test|local|localhost|internal)$/', $host ) ) {
			return '';
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$public = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
			if ( false === $public ) {
				return '';
			}
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Default logo as a PNG data URI so EpassCard does not have to download it.
	 *
	 * Remote plugin-asset URLs are not used here: they often cannot be fetched,
	 * and an empty Logo URL should still create the starter card.
	 *
	 * @return string
	 */
	private static function default_public_logo_url() {
		return (string) apply_filters(
			'epc_wizard_public_logo_url',
			self::file_data_uri( EPC_PLUGIN_DIR . 'admin/images/epass-icon.png' )
		);
	}

	/**
	 * Default strip as a PNG data URI so EpassCard does not have to download it.
	 *
	 * @return string
	 */
	private static function default_public_strip_url() {
		return (string) apply_filters(
			'epc_wizard_public_strip_url',
			self::generated_strip_data_uri()
		);
	}

	/**
	 * Encode a local PNG or JPEG as a data URI.
	 *
	 * @param string $path Absolute file path.
	 * @return string
	 */
	private static function file_data_uri( $path ) {
		if ( ! is_readable( $path ) ) {
			return '';
		}

		$bytes = file_get_contents( $path );
		if ( ! is_string( $bytes ) || '' === $bytes ) {
			return '';
		}

		$mime = 'image/png';
		if ( function_exists( 'wp_check_filetype' ) ) {
			$type = wp_check_filetype( $path );
			if ( in_array( $type['type'] ?? '', array( 'image/png', 'image/jpeg' ), true ) ) {
				$mime = (string) $type['type'];
			}
		}

		return 'data:' . $mime . ';base64,' . base64_encode( $bytes );
	}

	/**
	 * Solid store-card strip, encoded so the API never fetches a local upload.
	 *
	 * @return string
	 */
	private static function generated_strip_data_uri() {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) {
			return '';
		}

		$image = imagecreatetruecolor( 1125, 369 );
		if ( ! $image ) {
			return '';
		}

		$background = imagecolorallocate( $image, 30, 27, 75 );
		imagefilledrectangle( $image, 0, 0, 1124, 368, $background );
		ob_start();
		imagepng( $image );
		$png = ob_get_clean();
		imagedestroy( $image );

		if ( ! is_string( $png ) || '' === $png ) {
			return '';
		}

		return 'data:image/png;base64,' . base64_encode( $png );
	}

	/**
	 * Create a simple strip PNG in uploads when GD is available.
	 *
	 * @return string
	 */
	private static function maybe_create_strip_image() {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) {
			return '';
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		$dir = trailingslashit( (string) $uploads['basedir'] ) . 'epasscard';
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$path = $dir . '/wizard-strip.png';
		if ( ! file_exists( $path ) ) {
			$im = imagecreatetruecolor( 1125, 432 );
			if ( ! $im ) {
				return '';
			}
			$bg = imagecolorallocate( $im, 30, 27, 75 );
			imagefilledrectangle( $im, 0, 0, 1125, 432, $bg );
			imagepng( $im, $path );
			imagedestroy( $im );
		}

		return trailingslashit( (string) $uploads['baseurl'] ) . 'epasscard/wizard-strip.png';
	}
}

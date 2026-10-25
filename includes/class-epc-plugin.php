<?php
/**
 * Main plugin orchestrator.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Classes load on first use (see EPC_Autoloader); only the helper functions load up front.
require_once EPC_PLUGIN_DIR . 'includes/class-epc-autoloader.php';
require_once EPC_PLUGIN_DIR . 'includes/epc-dependencies.php';
EPC_Autoloader::register();

/**
 * Singleton plugin class.
 */
final class EPC_Plugin {

	/**
	 * Instance.
	 *
	 * @var EPC_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Loaded modules.
	 *
	 * @var array<string, EPC_Module>
	 */
	private $modules = array();

	/**
	 * All registered module instances (enabled or not), loaded on first use.
	 *
	 * @var array<string, EPC_Module>|null
	 */
	private $all_modules = null;

	/**
	 * Get singleton.
	 *
	 * @return EPC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'plugins_loaded', array( $this, 'boot' ), 20 );
	}

	/**
	 * Boot admin and modules.
	 *
	 * @return void
	 */
	public function boot() {
		// Read the stored version first so the database class only loads when an upgrade is due.
		if ( version_compare( (string) get_option( 'epc_db_version', '0' ), EPC_DB_VERSION, '<' ) ) {
			EPC_DB::maybe_upgrade();
		}

		// Every request: cards, emails, front-end links and profile changes.
		EPC_Pass_Email::init();
		EPC_Frontend::init();
		EPC_Loyalty_Frontend::init();
		EPC_User_Pass_Sync::init();
		EPC_User_Fields::init();
		EPC_Pass_Status::init();
		EPC_Default_Design::init();
		// Registered by class name, so these classes load only when one of their hooks runs:
		// privacy export/erase and user deletion (EPC_Loyalty_Customer_Service::init_privacy()),
		// and the REST routes and PHP API (EPC_Developer_Api::init()).
		add_filter( 'wp_privacy_personal_data_exporters', array( 'EPC_Loyalty_Customer_Service', 'register_privacy_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( 'EPC_Loyalty_Customer_Service', 'register_privacy_eraser' ) );
		add_action( 'deleted_user', array( 'EPC_Loyalty_Customer_Service', 'delete_user_data' ) );
		add_action( 'rest_api_init', array( 'EPC_Developer_Api', 'register_routes' ) );
		add_action( 'epc_pass_created', array( 'EPC_Developer_Api', 'on_pass_created' ), 10, 2 );

		// Scheduled jobs and their admin screens: only where they can run (wp-admin including
		// admin-ajax.php and admin-post.php, WP-Cron and WP-CLI). Their hooks are cron events,
		// admin_*, wp_ajax_* and admin_post_* hooks, and filters that only act while one of
		// their jobs runs in that same request.
		if ( self::is_background_request() ) {
			EPC_Api_Log::init();
			EPC_Api_Log::schedule_cron();
			EPC_Connection::init();
			EPC_Connection::schedule_cron();
			EPC_Pass_Notifications::init();
			EPC_Pass_Notifications::schedule_cron();
			EPC_Pass_Issues::init();
			EPC_Retry_Failed::init();
			EPC_Existing_Customers::init();
		}

		// wp-admin only (admin pages, admin-ajax.php and admin-post.php): every hook of these
		// classes is an admin_*, wp_ajax_* or admin_post_* hook.
		if ( is_admin() ) {
			EPC_Template_Starter::init();
			EPC_Admin_App::init();
			EPC_Admin_Home::init();
			EPC_Review_Prompt::init();
			EPC_Help::init();
			EPC_Support_Chat::init();
			EPC_Admin_Setup::init();
			EPC_Admin_Try::init();
			EPC_Admin_Design::init();
			EPC_Admin_Cards::init();
			EPC_Admin_Settings::init();
			EPC_Admin_Menu::init();
			EPC_Admin_Shell::init();
			EPC_Setup_Help_Notice::init();
			EPC_Setup_Wizard::init();
		}

		$this->modules = EPC_Module_Loader::load_modules();
	}

	/**
	 * Whether this request is wp-admin (including admin-ajax.php and admin-post.php), WP-Cron
	 * or WP-CLI: where EpassCard's scheduled jobs and admin actions run.
	 *
	 * @return bool
	 */
	public static function is_background_request() {
		return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Get loaded module by slug.
	 *
	 * @param string $slug Module slug.
	 * @return EPC_Module|null
	 */
	public function get_module( $slug ) {
		return $this->modules[ $slug ] ?? null;
	}

	/**
	 * All loaded modules.
	 *
	 * @return array<string, EPC_Module>
	 */
	public function get_modules() {
		return $this->modules;
	}

	/**
	 * All registered modules regardless of enabled state.
	 *
	 * @return array<string, EPC_Module>
	 */
	public function get_all_modules() {
		// Loaded on first use: only screens that list every integration need all module files.
		if ( null === $this->all_modules ) {
			$this->all_modules = EPC_Module_Loader::get_registry();
		}
		return $this->all_modules;
	}
}
